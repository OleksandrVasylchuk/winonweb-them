<?php
/**
 * Qwerty Soft — Signal theme bootstrap.
 *
 * The whole theme is a block theme: templates are HTML, design tokens live in
 * theme.json, and PHP only does the things markup cannot — registering blocks,
 * hardening output, and wiring performance defaults.
 *
 * @package Qwerty\Soft
 * @author  Qwerty Soft <https://qwerty-soft.com/>
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft;

defined( 'ABSPATH' ) || exit;

/**
 * Theme version, used to bust asset caches.
 *
 * Read from style.css so there is exactly one place to bump a release.
 */
if ( ! defined( 'QSOFT_VERSION' ) ) {
	$qwerty_soft_theme = wp_get_theme( get_template() );
	$qwerty_soft_ver   = $qwerty_soft_theme->get( 'Version' );

	define( 'QSOFT_VERSION', is_string( $qwerty_soft_ver ) && '' !== $qwerty_soft_ver ? $qwerty_soft_ver : '0.0.0' );

	unset( $qwerty_soft_theme, $qwerty_soft_ver );
}

define( 'QSOFT_DIR', get_template_directory() );
define( 'QSOFT_URI', get_template_directory_uri() );
define( 'QSOFT_TEXTDOMAIN', 'qwerty-soft-signal' );

/**
 * PSR-4 autoloader for Qwerty\Soft\* => inc/*.
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
	$path     = QSOFT_DIR . '/inc/' . str_replace( '\\', '/', $relative ) . '.php';

	// Never let a crafted class name escape the inc/ directory.
	$real = realpath( $path );
	$root = realpath( QSOFT_DIR . '/inc' );

	if ( false === $real || false === $root || ! str_starts_with( $real, $root ) ) {
		return;
	}

	require_once $real;
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );

Theme::instance()->boot();
