<?php
/**
 * Security headers and shared request-hardening helpers.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Response headers plus the sanitising helpers the rest of the theme uses.
 */
final class Security implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'send_headers', array( $this, 'security_headers' ) );
		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'wp_kses_allowed_html', array( $this, 'harden_kses' ), 10, 2 );
	}

	/**
	 * Send conservative security headers.
	 *
	 * No Content-Security-Policy is set here on purpose: a theme cannot know
	 * which plugins a site runs, and a broken CSP is worse than none. The
	 * README documents the recommended policy for the studio to apply at the
	 * server or CDN layer.
	 *
	 * @return void
	 */
	public function security_headers(): void {
		if ( headers_sent() || is_admin() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		// `same-origin` would sever payment and OAuth popups (PayPal, Stripe,
		// "Sign in with…") from their opener; allow-popups keeps them working.
		header( 'Cross-Origin-Opener-Policy: same-origin-allow-popups' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), browsing-topics=(), interest-cohort=()' );
	}

	/**
	 * Strip event handlers and javascript: URLs from post-context HTML.
	 *
	 * `wp_kses_post()` already removes `on*` attributes, but being explicit
	 * documents the intent and survives future changes to the default list.
	 *
	 * @param array<string, mixed> $tags    Allowed tags.
	 * @param string               $context Context name.
	 * @return array<string, mixed>
	 */
	public function harden_kses( array $tags, string $context ): array {
		if ( 'post' !== $context ) {
			return $tags;
		}

		foreach ( $tags as $tag => $attributes ) {
			if ( ! is_array( $attributes ) ) {
				continue;
			}

			foreach ( array_keys( $attributes ) as $attribute ) {
				if ( is_string( $attribute ) && str_starts_with( strtolower( $attribute ), 'on' ) ) {
					unset( $tags[ $tag ][ $attribute ] );
				}
			}
		}

		return $tags;
	}

	/**
	 * Verify a nonce for a front-end POST request.
	 *
	 * @param string $action     Nonce action.
	 * @param string $field_name Name of the nonce field.
	 * @return bool True when the nonce is valid.
	 */
	public static function verify_nonce( string $action, string $field_name ): bool {
		if ( ! isset( $_POST[ $field_name ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) );

		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Read a POST field and sanitise it with the given callback.
	 *
	 * The caller owns the request check. Admin forms verify a nonce first;
	 * the public contact form deliberately treats a failed nonce as a soft
	 * signal for logged-out visitors (page caches serve stale nonces) and
	 * relies on its honeypot, time trap and rate limit instead. The PHPCS
	 * annotation documents that the decision happens at the call site.
	 *
	 * @param string   $key      Field name.
	 * @param callable $callback Sanitising callback.
	 * @param string   $default_value Value returned when the field is absent.
	 * @return string
	 */
	public static function post_field( string $key, callable $callback, string $default_value = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first; see ContactForm::handle().
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return $default_value;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		$raw = wp_unslash( $_POST[ $key ] );

		return (string) call_user_func( $callback, (string) $raw );
	}

	/**
	 * Recursively sanitise an array of untrusted input.
	 *
	 * @param mixed         $data     Value to clean.
	 * @param callable|null $callback Scalar sanitiser.
	 * @return mixed
	 */
	public static function sanitize_deep( $data, ?callable $callback = null ) {
		$callback = $callback ?? 'sanitize_text_field';

		if ( is_array( $data ) ) {
			$clean = array();

			foreach ( $data as $key => $value ) {
				$clean[ sanitize_key( (string) $key ) ] = self::sanitize_deep( $value, $callback );
			}

			return $clean;
		}

		return is_scalar( $data ) ? call_user_func( $callback, (string) $data ) : '';
	}
}
