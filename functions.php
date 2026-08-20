<?php
/**
 * WOW — Signal theme bootstrap.
 *
 * The whole theme is a block theme: templates are HTML, design tokens live in
 * theme.json, and PHP only does the things markup cannot — registering blocks,
 * hardening output, and wiring performance defaults.
 *
 * @package Wow\Signal
 * @author  WOW — Win On Web <https://www.winonweb.dev/>
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal;

defined( 'ABSPATH' ) || exit;

/**
 * Theme version, used to bust asset caches.
 *
 * Read from style.css so there is exactly one place to bump a release.
 */
if ( ! defined( 'WOW_SIGNAL_VERSION' ) ) {
	$wow_signal_theme = wp_get_theme( get_template() );
	$wow_signal_ver   = $wow_signal_theme->get( 'Version' );

	define( 'WOW_SIGNAL_VERSION', is_string( $wow_signal_ver ) && '' !== $wow_signal_ver ? $wow_signal_ver : '0.0.0' );

	unset( $wow_signal_theme, $wow_signal_ver );
}

define( 'WOW_SIGNAL_DIR', get_template_directory() );
define( 'WOW_SIGNAL_URI', get_template_directory_uri() );
define( 'WOW_SIGNAL_TEXTDOMAIN', 'wow-signal' );

/**
 * PSR-4 autoloader for Wow\Signal\* => inc/*.
 *
 * The theme deliberately does not require Composer at runtime: a client can
 * upload the ZIP and activate it. Composer is a development dependency only
 * (PHPCS + WordPress Coding Standards).
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function autoload( string $class_name ): void {
	$prefix = __NAMESPACE__ . '\\';

	if ( ! str_starts_with( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$path     = WOW_SIGNAL_DIR . '/inc/' . str_replace( '\\', '/', $relative ) . '.php';

	// Never let a crafted class name escape the inc/ directory.
	$real = realpath( $path );
	$root = realpath( WOW_SIGNAL_DIR . '/inc' );

	if ( false === $real || false === $root || ! str_starts_with( $real, $root ) ) {
		return;
	}

	require_once $real;
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );

Theme::instance()->boot();
