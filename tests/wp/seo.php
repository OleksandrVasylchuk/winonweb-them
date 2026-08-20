<?php
/**
 * The SEO head: nothing leaks from a password-protected post, and a normal
 * post gets a description that fits.
 *
 * The head is rendered by firing wp_head on a query for the post, the same
 * way a template would, so what is asserted is the real output of the real
 * hooks rather than a private method's return value.
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Pointing the main query at a test post is how a template is simulated.

require __DIR__ . '/bootstrap.php';

/**
 * Render wp_head for one post, as a visitor who knows no passwords.
 *
 * @param int $post_id Post to query.
 * @return string
 */
function wow_render_head( int $post_id ): string {
	global $wp_query, $wp_the_query;

	// A logged-out visitor: an administrator is allowed to read protected content.
	wp_set_current_user( 0 );
	unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );

	$query = new WP_Query(
		array(
			'p'         => $post_id,
			'post_type' => 'post',
		)
	);

	$wp_query     = $query;
	$wp_the_query = $query;

	$_SERVER['REQUEST_URI'] = (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );

	ob_start();
	do_action( 'wp_head' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rendering WordPress's own head is the test.

	return (string) ob_get_clean();
}

/**
 * The content attribute of a named meta tag, or null when there is none.
 *
 * @param string $head Rendered head.
 * @param string $name Value of the name= or property= attribute.
 * @return string|null
 */
function wow_meta( string $head, string $name ): ?string {
	$pattern = '#<meta\s+(?:name|property)="' . preg_quote( $name, '#' ) . '"\s+content="([^"]*)"#i';

	return 1 === preg_match( $pattern, $head, $found ) ? html_entity_decode( $found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : null;
}

wow_test(
	'SEO: a password-protected post leaks nothing into the head',
	static function (): void {
		$secret = 'Quarterly numbers: revenue up 41 percent, churn down, and the codename is Heron.';

		$post_id = (int) wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Board update',
				'post_password' => 'hunter2',
				'post_content'  => '<!-- wp:paragraph --><p>' . $secret . '</p><!-- /wp:paragraph -->',
				'post_excerpt'  => 'An excerpt that is also confidential.',
			),
			true
		);

		if ( ! wow_assert( $post_id > 0, 'protected post was created' ) ) {
			return;
		}

		wow_assert( post_password_required( $post_id ), 'the post really is password protected for this visitor' );

		$head = wow_render_head( $post_id );

		wow_assert( is_singular( 'post' ), 'the main query is the protected post' );
		wow_assert( null === wow_meta( $head, 'description' ), 'no <meta name="description">', wow_meta( $head, 'description' ) );
		wow_assert( null === wow_meta( $head, 'og:description' ), 'no og:description', wow_meta( $head, 'og:description' ) );
		wow_assert( null === wow_meta( $head, 'twitter:description' ), 'no twitter:description', wow_meta( $head, 'twitter:description' ) );
		wow_assert( ! str_contains( $head, 'Heron' ) && ! str_contains( $head, '41 percent' ), 'post content appears nowhere in the head' );
		wow_assert( ! str_contains( $head, 'also confidential' ), 'post excerpt appears nowhere in the head' );
		wow_assert( null !== wow_meta( $head, 'og:title' ), 'the harmless tags are still emitted (og:title)' );
	}
);

wow_test(
	'SEO: a normal post gets a description of at most 160 characters',
	static function (): void {
		$sentence = 'This paragraph exists to be long enough that the description has to be clipped somewhere sensible rather than copied whole. ';
		$content  = '<!-- wp:heading --><h2>A heading that must not lead the description</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>' . str_repeat( $sentence, 4 ) . '</p><!-- /wp:paragraph -->';

		$post_id = (int) wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'A public post',
				'post_content' => $content,
			),
			true
		);

		if ( ! wow_assert( $post_id > 0, 'public post was created' ) ) {
			return;
		}

		$head        = wow_render_head( $post_id );
		$description = wow_meta( $head, 'description' );

		if ( ! wow_assert( is_string( $description ) && '' !== $description, 'a <meta name="description"> is emitted' ) ) {
			return;
		}

		wow_assert( mb_strlen( $description ) <= 160, sprintf( 'description is at most 160 characters (%d)', mb_strlen( $description ) ), $description );
		wow_assert( str_starts_with( $description, 'This paragraph exists' ), 'description starts with the first paragraph, not the heading', $description );
		wow_assert( ! str_contains( $description, '<' ), 'description has no markup', $description );
		wow_assert( wow_meta( $head, 'og:description' ) === $description, 'og:description matches' );
		wow_assert( 'article' === wow_meta( $head, 'og:type' ), 'a post is an article', wow_meta( $head, 'og:type' ) );
		wow_assert( str_contains( $head, 'application/ld+json' ), 'structured data is emitted' );
		wow_assert( ! str_contains( $head, '</script><' ) || 1 === preg_match( '#"@context":"https:\\\\/\\\\/schema.org"#', $head ), 'JSON-LD keeps slashes escaped so </script> cannot break out' );

		// An excerpt, when present, wins over the content.
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => 'The short version, written by hand.',
			)
		);

		$head = wow_render_head( $post_id );
		wow_assert( 'The short version, written by hand.' === wow_meta( $head, 'description' ), 'a hand-written excerpt is used verbatim', wow_meta( $head, 'description' ) );
	}
);

wow_finish();
