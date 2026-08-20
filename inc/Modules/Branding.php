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
	 * Palette slugs the login screen uses, with the theme.json defaults.
	 *
	 * The live value comes from global styles at render time, so a style
	 * variation or a Brand-step palette repaints the login screen too. The
	 * literal is only the safety net for a slug the active palette lacks.
	 */
	private const COLORS = array(
		'base'          => '#0a0a18',
		'surface'       => '#12122b',
		'border'        => '#2f2f5c',
		'border-strong' => '#646f9e',
		'contrast'      => '#f2f5fb',
		'muted'         => '#b6bfd3',
		'accent'        => '#22d3ee',
	);

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
		$c = $this->palette();

		// Sizes mirror theme.json: radius.soft / radius.card, shadow.card, the
		// heading weight and the control radius.
		$css = sprintf(
			'body.login{background:%1$s;color:%5$s}
			body.login h1 a{background-image:none;width:auto;height:auto;text-indent:0;font-size:22px;font-weight:800;color:%5$s;text-decoration:none;line-height:1.2}
			body.login form{background:%2$s;border:1px solid %3$s;border-radius:20px;box-shadow:0 12px 32px -12px rgba(3,3,12,.65)}
			body.login label,body.login form .input,body.login input[type=text],body.login input[type=password]{color:%5$s}
			body.login form .input,body.login input[type=text],body.login input[type=password]{background:%1$s;border:1px solid %4$s;border-radius:6px}
			body.login .button-primary{background:%7$s;border-color:%7$s;color:%1$s;font-weight:700;border-radius:999px;text-shadow:none;box-shadow:none}
			body.login .button-primary:hover,body.login .button-primary:focus{background:%5$s;border-color:%5$s;color:%1$s}
			body.login #nav a,body.login #backtoblog a{color:%6$s}
			body.login #nav a:hover,body.login #backtoblog a:hover{color:%7$s}
			body.login :focus-visible{outline:3px solid %7$s;outline-offset:2px}
			body.login .privacy-policy-page-link a{color:%6$s}',
			$c['base'],
			$c['surface'],
			$c['border'],
			$c['border-strong'],
			$c['contrast'],
			$c['muted'],
			$c['accent']
		);

		wp_register_style( 'wow-signal-login', false, array(), WOW_SIGNAL_VERSION );
		wp_enqueue_style( 'wow-signal-login' );
		wp_add_inline_style( 'wow-signal-login', $css );
	}

	/**
	 * The colours the login screen needs, read from the active global styles.
	 *
	 * Theme origin first, then custom (a style variation or the user's own
	 * palette) on top, so whatever the Site Editor shows is what the login
	 * screen paints. Values are CSS-sanitised because they end up in a style
	 * element verbatim.
	 *
	 * @return array<string, string> Slug => CSS colour.
	 */
	private function palette(): array {
		$colors  = self::COLORS;
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );

		if ( ! is_array( $palette ) ) {
			return $colors;
		}

		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			if ( ! isset( $palette[ $origin ] ) || ! is_array( $palette[ $origin ] ) ) {
				continue;
			}

			foreach ( $palette[ $origin ] as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['slug'], $entry['color'] ) ) {
					continue;
				}

				$slug  = (string) $entry['slug'];
				$color = safecss_filter_attr( 'color:' . (string) $entry['color'] );

				/*
				 * Accept exactly one colour declaration. A value that smuggled a
				 * second declaration through the filter would otherwise land in
				 * the stylesheet verbatim.
				 */
				if ( isset( $colors[ $slug ] ) && 1 === preg_match( '/^color:([^;{}]+)$/', $color, $found ) ) {
					$colors[ $slug ] = trim( $found[1] );
				}
			}
		}

		return $colors;
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
