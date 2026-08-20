<?php
/**
 * Contact form submission handling.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Server side of the wow/contact-form block.
 *
 * Threat model, and how each part is answered:
 *
 * - CSRF                → wp_nonce_field() + wp_verify_nonce(). Enforced for
 *                         logged-in users, whose session is what CSRF targets.
 *                         For anonymous visitors the nonce is advisory only:
 *                         full-page caches serve the same HTML (and the same
 *                         nonce) for 12–24 hours, after which every visitor
 *                         would be told their session expired. A logged-out
 *                         request has no ambient authority to forge, so the
 *                         honeypot, time trap and rate limit carry the load.
 * - Header injection    → the submitter's address is validated with
 *                         is_email() and only ever used in Reply-To.
 * - Open relay          → the recipient is NEVER read from the request. It
 *                         comes from site options and a server-side filter.
 * - Spam bots           → an off-screen honeypot field plus a minimum
 *                         time-to-submit, neither of which blocks a human.
 * - Flooding            → per-IP rate limit held in a transient.
 * - XSS on redisplay    → input is sanitised on the way in and escaped again
 *                         on the way out.
 */
final class ContactForm implements Module {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'wow_signal_contact';

	/**
	 * Nonce field name.
	 */
	public const NONCE_FIELD = 'wow_signal_contact_nonce';

	/**
	 * Query argument carrying the result back to the page.
	 */
	public const RESULT_ARG = 'wow-contact';

	/**
	 * Query argument carrying the transient token for errors.
	 */
	public const TOKEN_ARG = 'wow-contact-token';

	/**
	 * Minimum seconds between rendering and submitting a form.
	 */
	private const MIN_FILL_SECONDS = 3;

	/**
	 * Maximum submissions allowed per IP inside the window.
	 */
	private const RATE_LIMIT = 5;

	/**
	 * Rate limit window, in seconds.
	 */
	private const RATE_WINDOW = 600;

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Validate and process a submission, then redirect back to the form.
	 *
	 * Always redirects — never renders — so a refresh cannot resubmit.
	 *
	 * @return void
	 */
	public function handle(): void {
		$referer = wp_get_referer();
		$back    = is_string( $referer ) && '' !== $referer ? $referer : home_url( '/' );

		/*
		 * A stale nonce is only a hard failure for logged-in users. Logged-out
		 * pages are commonly served from a full-page cache, so the nonce baked
		 * into the HTML outlives its 12–24 hour validity and would otherwise
		 * lock every anonymous visitor out with "session expired". Anonymous
		 * requests carry no privileges worth forging, so they fall through to
		 * the bot checks below instead.
		 */
		if ( is_user_logged_in() && ! Security::verify_nonce( self::ACTION, self::NONCE_FIELD ) ) {
			$this->redirect_with_errors( $back, array( '_form' => __( 'Your session expired. Please try sending the message again.', 'wow-signal' ) ), array() );
		}

		$form_id = Security::post_field( 'wow_signal_form', 'sanitize_key', 'default' );

		$submitted = array(
			'name'    => Security::post_field( 'wow_name', 'sanitize_text_field' ),
			'email'   => Security::post_field( 'wow_email', 'sanitize_text_field' ),
			'subject' => Security::post_field( 'wow_subject', 'sanitize_text_field' ),
			'message' => Security::post_field( 'wow_message', 'sanitize_textarea_field' ),
		);

		// Honeypot: a real browser leaves this off-screen field empty.
		$honeypot = Security::post_field( 'wow_website', 'sanitize_text_field' );

		// Time trap: humans do not complete a form in under three seconds. A
		// missing timestamp counts as too fast — a browser always sends it.
		$rendered_at = (int) Security::post_field( 'wow_rendered_at', 'absint', '0' );
		$too_fast    = $rendered_at <= 0 || ( time() - $rendered_at ) < self::MIN_FILL_SECONDS;

		if ( '' !== $honeypot || $too_fast ) {
			/*
			 * Report success to the bot. Telling it what tripped the filter
			 * would only help it get past the filter next time.
			 */
			$this->redirect_with_result( $back, 'sent' );
		}

		if ( ! $this->within_rate_limit() ) {
			$this->redirect_with_errors(
				$back,
				array( '_form' => __( 'Too many messages sent from this connection. Please try again in a few minutes.', 'wow-signal' ) ),
				$submitted
			);
		}

		$errors = $this->validate( $submitted );

		if ( array() !== $errors ) {
			$this->redirect_with_errors( $back, $errors, $submitted );
		}

		if ( ! $this->send( $submitted, $form_id ) ) {
			$this->redirect_with_errors(
				$back,
				array( '_form' => __( 'The message could not be sent because of a server error. Please email us directly.', 'wow-signal' ) ),
				$submitted
			);
		}

		// Only a message that actually went out counts towards the limit, so
		// neither a few typos nor a broken mailer can lock a human out.
		$this->count_submission();

		$this->redirect_with_result( $back, 'sent' );
	}

	/**
	 * Validate the sanitised fields.
	 *
	 * Errors are keyed by field name so the block can attach each message to
	 * the input it belongs to with aria-describedby, rather than dumping one
	 * undifferentiated list at the top of the form (WCAG 3.3.1, 3.3.3).
	 *
	 * @param array<string, string> $fields Sanitised input.
	 * @return array<string, string> Field name => human-readable message.
	 */
	private function validate( array $fields ): array {
		$errors = array();

		if ( '' === trim( $fields['name'] ) ) {
			$errors['name'] = __( 'Please enter your name.', 'wow-signal' );
		}

		if ( '' === trim( $fields['email'] ) ) {
			$errors['email'] = __( 'Please enter your email address.', 'wow-signal' );
		} elseif ( ! is_email( $fields['email'] ) ) {
			$errors['email'] = __( 'That email address does not look right. Please check it, for example name@company.com.', 'wow-signal' );
		}

		$message = trim( $fields['message'] );

		if ( '' === $message ) {
			$errors['message'] = __( 'Please tell us what you need.', 'wow-signal' );
		} elseif ( mb_strlen( $message ) > 5000 ) {
			$errors['message'] = __( 'Your message is longer than 5000 characters. Please shorten it.', 'wow-signal' );
		}

		return $errors;
	}

	/**
	 * Send the notification email.
	 *
	 * @param array<string, string> $fields  Validated input.
	 * @param string                $form_id Form identifier from the block.
	 * @return bool
	 */
	private function send( array $fields, string $form_id ): bool {
		$default_recipient = (string) get_option( 'admin_email' );

		/**
		 * Filter the address a contact form delivers to.
		 *
		 * The recipient is resolved server-side on purpose: accepting it from
		 * the request would turn the form into an open relay.
		 *
		 * @since 1.0.0
		 *
		 * @param string $recipient Email address.
		 * @param string $form_id   Identifier set on the block.
		 */
		$recipient = (string) apply_filters( 'wow_signal/contact_recipient', $default_recipient, $form_id );

		if ( ! is_email( $recipient ) ) {
			return false;
		}

		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = '' !== trim( $fields['subject'] )
			? $fields['subject']
			/* translators: %s: site name. */
			: sprintf( __( 'New message from %s', 'wow-signal' ), $site );

		$body = implode(
			"\n",
			array(
				__( 'Name:', 'wow-signal' ) . ' ' . $fields['name'],
				__( 'Email:', 'wow-signal' ) . ' ' . $fields['email'],
				'',
				__( 'Message:', 'wow-signal' ),
				$fields['message'],
				'',
				'---',
				/* translators: %s: site URL. */
				sprintf( __( 'Sent from the contact form on %s', 'wow-signal' ), home_url( '/' ) ),
			)
		);

		/*
		 * From uses the site's own domain so SPF and DMARC pass. The visitor's
		 * address goes in Reply-To, where a newline can no longer be smuggled
		 * because is_email() has already rejected anything containing one.
		 */
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = '' !== $host ? preg_replace( '/^www\./', '', $host ) : 'localhost';

		/*
		 * sanitize_text_field() has already removed the line breaks that make
		 * header injection possible. This strips the remaining characters that
		 * carry meaning inside an address header — angle brackets, quotes and
		 * the comma, colon and semicolon separators — so a display name can
		 * never be read as a second recipient by a lenient MTA.
		 */
		$reply_name = trim( (string) preg_replace( '/[<>",;:\r\n]+/', ' ', $fields['name'] ) );
		$reply_name = '' !== $reply_name ? $reply_name : __( 'Website visitor', 'wow-signal' );

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <wordpress@%s>', $site, $host ),
			sprintf( 'Reply-To: %s <%s>', $reply_name, sanitize_email( $fields['email'] ) ),
		);

		return (bool) wp_mail( $recipient, wp_specialchars_decode( $subject, ENT_QUOTES ), $body, $headers );
	}

	/**
	 * Transient key for the per-IP submission counter.
	 *
	 * @return string
	 */
	private function rate_limit_key(): string {
		return 'wow_signal_rl_' . md5( $this->client_ip() );
	}

	/**
	 * Check the per-IP submission counter without touching it.
	 *
	 * @return bool True when the request is under the limit.
	 */
	private function within_rate_limit(): bool {
		return (int) get_transient( $this->rate_limit_key() ) < self::RATE_LIMIT;
	}

	/**
	 * Increment the per-IP submission counter.
	 *
	 * Called only once a submission has passed validation, so failed attempts
	 * do not eat into the allowance.
	 *
	 * @return void
	 */
	private function count_submission(): void {
		$key   = $this->rate_limit_key();
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}

	/**
	 * Best-effort client IP, used only as a rate-limit bucket.
	 *
	 * Proxy headers are deliberately ignored by default: they are trivially
	 * spoofed, and trusting them would let an attacker bypass the limit at
	 * will. A site that sits behind a proxy it controls can supply the real
	 * address through the `wow_signal/contact_client_ip` filter:
	 *
	 *     add_filter( 'wow_signal/contact_client_ip', static function ( $ip ) {
	 *         return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
	 *     } );
	 *
	 * The filtered value must be a valid IP address; anything else falls back
	 * to REMOTE_ADDR.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$remote = filter_var( $remote, FILTER_VALIDATE_IP );
		$remote = is_string( $remote ) ? $remote : 'unknown';

		/**
		 * Filter the client IP used as the contact form rate-limit bucket.
		 *
		 * Only use this when a trusted proxy (Cloudflare, a load balancer)
		 * sits in front of the site and sets a header the visitor cannot
		 * spoof. Invalid values are ignored.
		 *
		 * @since 1.0.0
		 *
		 * @param string $ip The IP address from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'wow_signal/contact_client_ip', $remote );

		$valid = filter_var( $ip, FILTER_VALIDATE_IP );

		return is_string( $valid ) ? $valid : $remote;
	}

	/**
	 * Redirect back with a plain result flag.
	 *
	 * @param string $url    Destination.
	 * @param string $result Result slug.
	 * @return void
	 */
	private function redirect_with_result( string $url, string $result ): void {
		$target = add_query_arg( self::RESULT_ARG, $result, remove_query_arg( array( self::RESULT_ARG, self::TOKEN_ARG ), $url ) );

		// Success lands on the status notice itself so it is in view on a phone.
		$anchor = 'sent' === $result ? self::ACTION . '-status' : self::ACTION;

		wp_safe_redirect( $target . '#' . $anchor, 303 );
		exit;
	}

	/**
	 * Store errors plus the submitted values, then redirect back to them.
	 *
	 * The payload lives in a short-lived transient rather than the URL so a
	 * visitor's message never ends up in browser history or server logs.
	 *
	 * @param string                $url    Destination.
	 * @param array<string, string> $errors Field name => message.
	 * @param array<string, string> $values Previously submitted values.
	 * @return void
	 */
	private function redirect_with_errors( string $url, array $errors, array $values ): void {
		// Lowercase hex: it must survive sanitize_key()-style lowercasing.
		$token = bin2hex( random_bytes( 10 ) );

		set_transient(
			'wow_signal_contact_' . $token,
			array(
				'errors' => $errors,
				'values' => $values,
			),
			5 * MINUTE_IN_SECONDS
		);

		$target = remove_query_arg( array( self::RESULT_ARG, self::TOKEN_ARG ), $url );
		$target = add_query_arg(
			array(
				self::RESULT_ARG => 'error',
				self::TOKEN_ARG  => $token,
			),
			$target
		);

		wp_safe_redirect( $target . '#' . self::ACTION, 303 );
		exit;
	}

	/**
	 * Read the stored feedback for the current request.
	 *
	 * The result is memoised for the rest of the request and the transient is
	 * only deleted at shutdown. Plugins routinely run the_content before the
	 * page is output (SEO plugins building og:description, for instance); if
	 * the first read consumed the transient, the visible render would show
	 * nothing.
	 *
	 * @return array{errors: array<string, string>, values: array<string, string>}
	 */
	public static function consume_feedback(): array {
		/**
		 * Feedback already decoded during this request, keyed by token.
		 *
		 * @var array<string, array{errors: array<string, string>, values: array<string, string>}> $cache
		 */
		static $cache = array();

		$empty = array(
			'errors' => array(),
			'values' => array(),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a single-use token from a redirect.
		$token = isset( $_GET[ self::TOKEN_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_ARG ] ) ) : '';

		if ( 1 !== preg_match( '/^[a-f0-9]{20}$/', $token ) ) {
			return $empty;
		}

		if ( isset( $cache[ $token ] ) ) {
			return $cache[ $token ];
		}

		$key    = 'wow_signal_contact_' . $token;
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) ) {
			$cache[ $token ] = $empty;

			return $empty;
		}

		add_action(
			'shutdown',
			static function () use ( $key ): void {
				delete_transient( $key );
			}
		);

		$cache[ $token ] = array(
			'errors' => isset( $stored['errors'] ) && is_array( $stored['errors'] ) ? array_map( 'strval', $stored['errors'] ) : array(),
			'values' => isset( $stored['values'] ) && is_array( $stored['values'] ) ? array_map( 'strval', $stored['values'] ) : array(),
		);

		return $cache[ $token ];
	}

	/**
	 * Whether the current request is showing a success message.
	 *
	 * @return bool
	 */
	public static function is_success(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
		$result = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';

		return 'sent' === $result;
	}
}
