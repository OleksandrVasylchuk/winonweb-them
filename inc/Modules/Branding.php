<?php
/**
 * Admin and login branding.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the theme palette to the login screen and admin chrome.
 *
 * Everything here is cosmetic and admin-only: no front-end weight, and the
 * whole module can be dropped by a child theme through the wow_signal/modules
 * filter without affecting the site.
 */
final class Branding implements Module {

	/**
	 * Brand accent, matching the `accent` token in theme.json.
	 */
	private const ACCENT = '#22d3ee';

	/**
	 * Brand background, matching the `base` token in theme.json.
	 */
	private const BASE = '#0a0a18';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'login_enqueue_scripts', array( $this, 'login_styles' ) );
		add_filter( 'login_headerurl', array( $this, 'login_url' ) );
		add_filter( 'login_headertext', array( $this, 'login_text' ) );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ) );
	}

	/**
	 * Paint the login screen with the theme palette.
	 *
	 * @return void
	 */
	public function login_styles(): void {
		$css = sprintf(
			'body.login{background:%1$s;color:#f2f5fb}
			body.login h1 a{background-image:none;width:auto;height:auto;text-indent:0;font-size:22px;font-weight:800;color:#f2f5fb;text-decoration:none;line-height:1.2}
			body.login form{background:#12122b;border:1px solid #2f2f5c;border-radius:20px;box-shadow:0 12px 32px -12px rgba(3,3,12,.65)}
			body.login label,body.login form .input,body.login input[type=text],body.login input[type=password]{color:#f2f5fb}
			body.login form .input,body.login input[type=text],body.login input[type=password]{background:%1$s;border:1px solid #646f9e;border-radius:6px}
			body.login .button-primary{background:%2$s;border-color:%2$s;color:%1$s;font-weight:700;border-radius:999px;text-shadow:none;box-shadow:none}
			body.login .button-primary:hover,body.login .button-primary:focus{background:#f2f5fb;border-color:#f2f5fb;color:%1$s}
			body.login #nav a,body.login #backtoblog a{color:#b6bfd3}
			body.login #nav a:hover,body.login #backtoblog a:hover{color:%2$s}
			body.login :focus-visible{outline:3px solid %2$s;outline-offset:2px}
			body.login .privacy-policy-page-link a{color:#b6bfd3}',
			self::BASE,
			self::ACCENT
		);

		wp_register_style( 'wow-signal-login', false, array(), WOW_SIGNAL_VERSION );
		wp_enqueue_style( 'wow-signal-login' );
		wp_add_inline_style( 'wow-signal-login', $css );
	}

	/**
	 * Point the login logo at the site, not wordpress.org.
	 *
	 * @return string
	 */
	public function login_url(): string {
		return home_url( '/' );
	}

	/**
	 * Use the site name as the login logo text.
	 *
	 * @return string
	 */
	public function login_text(): string {
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Replace the admin footer text with a build credit.
	 *
	 * @param string $text Default footer text.
	 * @return string
	 */
	public function admin_footer_text( string $text ): string {
		unset( $text );

		return sprintf(
			/* translators: %s: linked studio name. */
			esc_html__( 'Built with WOW — Signal by %s', 'wow-signal' ),
			'<a href="https://www.winonweb.dev/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Win On Web', 'wow-signal' ) . '</a>'
		);
	}
}
