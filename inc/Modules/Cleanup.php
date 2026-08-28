<?php
/**
 * Removes markup and endpoints the theme does not need.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Trims <head>, drops the emoji polyfill and closes XML-RPC.
 */
final class Cleanup implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'clean_head' ) );
		add_action( 'init', array( $this, 'disable_emojis' ) );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_headers', array( $this, 'strip_pingback_header' ) );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * Remove head output that leaks version data or is simply unused.
	 *
	 * The global-styles stylesheet is deliberately left alone: it carries every
	 * theme.json token, the focus ring and the reduced-motion query.
	 *
	 * @return void
	 */
	public function clean_head(): void {
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	}

	/**
	 * Drop the emoji polyfill: every browser the theme supports renders
	 * emoji natively, and the script costs a request plus main-thread time.
	 *
	 * @return void
	 */
	public function disable_emojis(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}

	/**
	 * Remove the X-Pingback header that advertises a disabled endpoint.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, string>
	 */
	public function strip_pingback_header( array $headers ): array {
		unset( $headers['X-Pingback'] );

		return $headers;
	}
}
