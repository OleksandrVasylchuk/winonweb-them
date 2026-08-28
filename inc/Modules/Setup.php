<?php
/**
 * Theme supports, image sizes and translations.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Declares what the theme supports and the image sizes its patterns rely on.
 */
final class Setup implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'after_setup_theme', array( $this, 'supports' ) );
		add_action( 'after_setup_theme', array( $this, 'image_sizes' ) );
		add_action( 'init', array( $this, 'load_translations' ) );
		add_filter( 'image_size_names_choose', array( $this, 'expose_image_sizes' ) );
	}

	/**
	 * Declare theme supports.
	 *
	 * Block themes get most of this implicitly, but declaring it keeps the
	 * behaviour explicit and survives WordPress changing its defaults.
	 *
	 * @return void
	 */
	public function supports(): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'wide-blocks' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );

		/*
		 * One stylesheet, and it exists for one reason: the editor draws the
		 * post content as a constrained column, and a section lifted from a
		 * design is never a column. Everything else the editor needs is in
		 * theme.json, where it belongs.
		 */
		add_editor_style( 'assets/css/editor.css' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'custom-logo' );
		add_theme_support( 'automatic-feed-links' );

		add_theme_support(
			'html5',
			array(
				'search-form',
				'comment-form',
				'comment-list',
				'gallery',
				'caption',
				'style',
				'script',
				'navigation-widgets',
			)
		);
	}

	/**
	 * Register the crops the patterns are designed around.
	 *
	 * @return void
	 */
	public function image_sizes(): void {
		// 3:2 card used by the services, cases and blog grids.
		add_image_size( 'qwerty-soft-signal-card', 720, 480, true );

		// 16:9 banner used by hero media and single post featured images.
		add_image_size( 'qwerty-soft-signal-wide', 1600, 900, true );

		// 1:1 avatar used by testimonials and team patterns.
		add_image_size( 'qwerty-soft-signal-square', 640, 640, true );
	}

	/**
	 * Make the theme crops selectable in the media modal.
	 *
	 * @param array<string, string> $sizes Registered size labels.
	 * @return array<string, string>
	 */
	public function expose_image_sizes( array $sizes ): array {
		return array_merge(
			$sizes,
			array(
				'qwerty-soft-signal-card'   => __( 'Signal card (3:2)', 'qwerty-soft-signal' ),
				'qwerty-soft-signal-wide'   => __( 'Signal wide (16:9)', 'qwerty-soft-signal' ),
				'qwerty-soft-signal-square' => __( 'Signal square (1:1)', 'qwerty-soft-signal' ),
			)
		);
	}

	/**
	 * Load the theme text domain.
	 *
	 * Deliberately on `init` rather than `after_setup_theme`: since WordPress
	 * 6.7 loading a text domain before `init` triggers a _doing_it_wrong()
	 * notice about just-in-time translation loading.
	 *
	 * @return void
	 */
	public function load_translations(): void {
		load_theme_textdomain( 'qwerty-soft-signal', QSOFT_DIR . '/languages' );
	}
}
