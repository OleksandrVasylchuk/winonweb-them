<?php
/**
 * The contact form's POST flow, end to end, without sending a byte of mail.
 *
 * ContactForm::handle() always ends in wp_safe_redirect() + exit. The redirect
 * passes through the `wp_redirect` filter first, so the test throws from that
 * filter to capture the destination and stop execution before the exit.
 * wp_mail() is short-circuited with `pre_wp_mail`, which is the hook WordPress
 * provides for exactly this.
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.Security.NonceVerification -- The test builds the request it then submits.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- One exception class next to the helpers that throw and catch it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing WordPress's own admin-post hook is the test.

require __DIR__ . '/bootstrap.php';

use Wow\Signal\Modules\ContactForm;

/**
 * Thrown from the wp_redirect filter to capture where handle() wanted to go.
 */
final class Wow_Signal_Redirect_Exception extends RuntimeException {

	/**
	 * Where the redirect pointed.
	 *
	 * @var string
	 */
	public string $location;

	/**
	 * Keep the destination.
	 *
	 * @param string $location Redirect target.
	 */
	public function __construct( string $location ) {
		parent::__construct( 'redirect to ' . $location );
		$this->location = $location;
	}
}

add_filter(
	'wp_redirect',
	static function ( $location ): string {
		throw new Wow_Signal_Redirect_Exception( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by the test; never rendered.
	},
	0
);

/**
 * What wp_mail() should answer, and how often it was asked.
 *
 * @var array{answer: bool, calls: int, last: array<string, mixed>}
 */
$wow_mail = array(
	'answer' => true,
	'calls'  => 0,
	'last'   => array(),
);

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, array $atts ) use ( &$wow_mail ) {
		++$wow_mail['calls'];
		$wow_mail['last'] = $atts;

		return $wow_mail['answer'];
	},
	10,
	2
);

/**
 * Submit the form the way a browser would and return the redirect, parsed.
 *
 * @param array<string, string> $fields POST fields; defaults fill in a valid submission.
 * @param string                $ip     Client address used as the rate-limit bucket.
 * @return array{url: string, query: array<string, string>, fragment: string}
 */
function wow_submit( array $fields, string $ip ): array {
	$defaults = array(
		'action'          => ContactForm::ACTION,
		'wow_signal_form' => 'default',
		'wow_name'        => 'Test Person',
		'wow_email'       => 'person@example.com',
		'wow_subject'     => 'Integration test',
		'wow_message'     => 'A message long enough to be a message.',
		'wow_website'     => '',
		'wow_rendered_at' => (string) ( time() - 30 ),
	);

	$_POST    = array_merge( $defaults, $fields );
	$_REQUEST = $_POST;

	foreach ( $_POST as $key => $value ) {
		if ( null === $value ) {
			unset( $_POST[ $key ], $_REQUEST[ $key ] );
		}
	}

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['REQUEST_URI']    = '/wp-admin/admin-post.php';
	$_SERVER['HTTP_REFERER']   = home_url( '/contact/' );
	$_SERVER['REMOTE_ADDR']    = $ip;

	$location = '';

	try {
		do_action( 'admin_post_nopriv_' . ContactForm::ACTION );
		wow_assert( false, 'handle() redirected' );
	} catch ( Wow_Signal_Redirect_Exception $e ) {
		$location = $e->location;
	}

	$parts = wp_parse_url( $location );
	$query = array();

	if ( is_array( $parts ) && isset( $parts['query'] ) ) {
		parse_str( (string) $parts['query'], $query );
	}

	return array(
		'url'      => $location,
		'query'    => array_map( 'strval', $query ),
		'fragment' => is_array( $parts ) ? (string) ( $parts['fragment'] ?? '' ) : '',
	);
}

/**
 * Read the feedback a redirect token points at, the way the block does.
 *
 * @param string $token Token from the redirect URL.
 * @return array{errors: array<string, string>, values: array<string, string>}
 */
function wow_feedback( string $token ): array {
	$_GET[ ContactForm::TOKEN_ARG ] = $token;

	$feedback = ContactForm::consume_feedback();

	unset( $_GET[ ContactForm::TOKEN_ARG ] );

	return $feedback;
}

wow_test(
	'Contact form: a valid anonymous submission is delivered',
	static function () use ( &$wow_mail ): void {
		wp_set_current_user( 0 );
		$wow_mail['answer'] = true;
		$wow_mail['calls']  = 0;

		$result = wow_submit( array(), '203.0.113.10' );

		wow_assert( 'sent' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'redirect carries wow-contact=sent', $result['url'] );
		wow_assert( ContactForm::ACTION . '-status' === $result['fragment'], 'redirect lands on #wow_signal_contact-status', $result['fragment'] );
		wow_assert( str_starts_with( $result['url'], home_url( '/contact/' ) ), 'redirect goes back to the referring page', $result['url'] );
		wow_assert( 1 === $wow_mail['calls'], 'wp_mail was called exactly once', $wow_mail['calls'] );
		wow_assert( get_option( 'admin_email' ) === ( $wow_mail['last']['to'] ?? '' ), 'mail goes to the admin address, never one from the request', $wow_mail['last']['to'] ?? '' );

		$headers = implode( "\n", (array) ( $wow_mail['last']['headers'] ?? array() ) );
		wow_assert( str_contains( $headers, 'Reply-To: Test Person <person@example.com>' ), 'visitor address is in Reply-To', $headers );
	}
);

wow_test(
	'Contact form: an invalid email is sent back with a field error',
	static function () use ( &$wow_mail ): void {
		wp_set_current_user( 0 );
		$wow_mail['calls'] = 0;

		$result = wow_submit( array( 'wow_email' => 'not-an-address' ), '203.0.113.11' );

		wow_assert( 'error' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'redirect carries wow-contact=error', $result['url'] );
		wow_assert( ContactForm::ACTION === $result['fragment'], 'redirect lands on the form', $result['fragment'] );

		$token = (string) ( $result['query'][ ContactForm::TOKEN_ARG ] ?? '' );
		wow_assert( 1 === preg_match( '/^[a-f0-9]{20}$/', $token ), 'redirect carries a 20-hex token', $token );

		$feedback = wow_feedback( $token );
		wow_assert( isset( $feedback['errors']['email'] ), 'feedback has an error on the email field', $feedback['errors'] );
		wow_assert( str_contains( (string) ( $feedback['errors']['email'] ?? '' ), 'does not look right' ), 'email error is the real validation message', $feedback['errors']['email'] ?? '' );
		wow_assert( 'not-an-address' === ( $feedback['values']['email'] ?? '' ), 'submitted value is preserved for redisplay', $feedback['values'] );
		wow_assert( 0 === $wow_mail['calls'], 'nothing was mailed', $wow_mail['calls'] );
	}
);

wow_test(
	'Contact form: a request without the render timestamp is treated as a bot',
	static function () use ( &$wow_mail ): void {
		wp_set_current_user( 0 );
		$wow_mail['calls'] = 0;

		$result = wow_submit( array( 'wow_rendered_at' => null ), '203.0.113.12' );

		wow_assert( 'sent' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'bot is told the message was sent', $result['url'] );
		wow_assert( 0 === $wow_mail['calls'], 'no mail went out', $wow_mail['calls'] );
		wow_assert( ! isset( $result['query'][ ContactForm::TOKEN_ARG ] ), 'no error token is issued to a bot' );

		// The honeypot takes the same exit.
		$result = wow_submit( array( 'wow_website' => 'http://spam.example' ), '203.0.113.12' );
		wow_assert( 'sent' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'honeypot also gets a fake success', $result['url'] );
		wow_assert( 0 === $wow_mail['calls'], 'still no mail', $wow_mail['calls'] );
	}
);

wow_test(
	'Contact form: the sixth message from one address inside the window is refused',
	static function () use ( &$wow_mail ): void {
		wp_set_current_user( 0 );
		$wow_mail['answer'] = true;
		$wow_mail['calls']  = 0;

		$ip = '203.0.113.13';

		for ( $i = 1; $i <= 5; $i++ ) {
			$result = wow_submit( array( 'wow_message' => 'Message number ' . $i ), $ip );
			wow_assert( 'sent' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'message ' . $i . ' is delivered', $result['url'] );
		}

		wow_assert( 5 === $wow_mail['calls'], 'five mails went out', $wow_mail['calls'] );
		wow_assert( 5 === (int) get_transient( 'wow_signal_rl_' . md5( $ip ) ), 'counter stands at five', get_transient( 'wow_signal_rl_' . md5( $ip ) ) );

		$result = wow_submit( array( 'wow_message' => 'Message number 6' ), $ip );

		wow_assert( 'error' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'sixth message is refused', $result['url'] );
		wow_assert( 5 === $wow_mail['calls'], 'sixth message was not mailed', $wow_mail['calls'] );

		$feedback = wow_feedback( (string) ( $result['query'][ ContactForm::TOKEN_ARG ] ?? '' ) );
		wow_assert( str_contains( (string) ( $feedback['errors']['_form'] ?? '' ), 'Too many messages' ), 'form-level error explains the limit', $feedback['errors'] );

		// Another address is not affected.
		$result = wow_submit( array(), '203.0.113.14' );
		wow_assert( 'sent' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'a different address is still served', $result['url'] );
	}
);

wow_test(
	'Contact form: a failed wp_mail reports an error and does not count toward the limit',
	static function () use ( &$wow_mail ): void {
		wp_set_current_user( 0 );
		$wow_mail['answer'] = false;
		$wow_mail['calls']  = 0;

		$ip = '203.0.113.15';

		$result = wow_submit( array(), $ip );

		wow_assert( 'error' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'redirect carries wow-contact=error', $result['url'] );
		wow_assert( 1 === $wow_mail['calls'], 'wp_mail was attempted', $wow_mail['calls'] );

		$feedback = wow_feedback( (string) ( $result['query'][ ContactForm::TOKEN_ARG ] ?? '' ) );
		wow_assert( str_contains( (string) ( $feedback['errors']['_form'] ?? '' ), 'server error' ), 'form-level error names a server error', $feedback['errors'] );
		wow_assert( 'Test Person' === ( $feedback['values']['name'] ?? '' ), 'submitted values come back for redisplay', $feedback['values'] );
		wow_assert( false === get_transient( 'wow_signal_rl_' . md5( $ip ) ), 'failed delivery did not start the rate-limit counter', get_transient( 'wow_signal_rl_' . md5( $ip ) ) );

		$wow_mail['answer'] = true;
	}
);

wow_test(
	'Contact form: a logged-in user without a valid nonce is told the session expired',
	static function () use ( &$wow_mail ): void {
		$wow_mail['calls'] = 0;

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( (int) $admins[0]->ID );

		$result = wow_submit( array(), '203.0.113.16' );

		wow_assert( 'error' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'submission without a nonce is refused', $result['url'] );
		wow_assert( 0 === $wow_mail['calls'], 'nothing was mailed', $wow_mail['calls'] );

		$feedback = wow_feedback( (string) ( $result['query'][ ContactForm::TOKEN_ARG ] ?? '' ) );
		wow_assert( str_contains( (string) ( $feedback['errors']['_form'] ?? '' ), 'session expired' ), 'error says the session expired', $feedback['errors'] );

		// With a real nonce the same user gets through.
		$result = wow_submit( array( ContactForm::NONCE_FIELD => wp_create_nonce( ContactForm::ACTION ) ), '203.0.113.16' );
		wow_assert( 'sent' === ( $result['query'][ ContactForm::RESULT_ARG ] ?? '' ), 'with a valid nonce the message is delivered', $result['url'] );
		wow_assert( 1 === $wow_mail['calls'], 'one mail went out', $wow_mail['calls'] );
	}
);

wow_finish();
