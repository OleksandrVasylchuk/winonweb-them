<?php
/**
 * The one screen where the site's own words are edited.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\BlockWriter;
use Qwerty\Soft\Support\SiteOptions;

defined( 'ABSPATH' ) || exit;

/**
 * Site content: the screen the header and footer read their words from.
 *
 * Put under Appearance rather than in a menu of its own, beside Menus, because
 * that is where somebody looking for "the text in the footer" goes. The values
 * themselves live in SiteOptions; this is only the door to them.
 */
final class DesignOptions implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'acf/init', array( $this, 'add_page' ) );
	}

	/**
	 * Add the options page, but only when there is something to put on it.
	 *
	 * @return void
	 */
	public function add_page(): void {
		if ( ! function_exists( 'acf_add_options_page' ) ) {
			return;
		}

		/*
		 * An empty screen is worse than no screen. Until an import has written
		 * a chrome block there are no fields to show, and a menu item leading
		 * to a blank page reads as something broken rather than as something
		 * not yet used.
		 */
		if ( ! SiteOptions::has_fields() ) {
			return;
		}

		acf_add_options_page(
			array(
				'page_title'      => __( 'Site content', 'qwerty-soft-signal' ),
				'menu_title'      => __( 'Site content', 'qwerty-soft-signal' ),
				'menu_slug'       => BlockWriter::OPTIONS_PAGE,
				'parent_slug'     => 'themes.php',
				'capability'      => 'edit_theme_options',
				'updated_message' => __( 'Site content saved. Every page showing the header or footer now says this.', 'qwerty-soft-signal' ),
			)
		);
	}
}
