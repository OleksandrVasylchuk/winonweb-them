<?php
/**
 * Pattern categories.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the categories the /patterns files are filed under.
 *
 * The patterns themselves are auto-discovered by WordPress from /patterns —
 * no registration code is needed for the files, only for their categories.
 */
final class Patterns implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_categories' ) );
	}

	/**
	 * Register the theme's pattern categories.
	 *
	 * @return void
	 */
	public function register_categories(): void {
		$categories = array(
			'qs-hero'       => array(
				'label'       => __( 'Qwerty Soft — Hero', 'qwerty-soft-signal' ),
				'description' => __( 'Opening sections for the top of a page.', 'qwerty-soft-signal' ),
			),
			'qs-content'    => array(
				'label'       => __( 'Qwerty Soft — Content', 'qwerty-soft-signal' ),
				'description' => __( 'Services, process, features and text sections.', 'qwerty-soft-signal' ),
			),
			'qs-proof'      => array(
				'label'       => __( 'Qwerty Soft — Proof', 'qwerty-soft-signal' ),
				'description' => __( 'Case studies, metrics, testimonials and client logos.', 'qwerty-soft-signal' ),
			),
			'qs-conversion' => array(
				'label'       => __( 'Qwerty Soft — Conversion', 'qwerty-soft-signal' ),
				'description' => __( 'Pricing, FAQ, contact and call-to-action sections.', 'qwerty-soft-signal' ),
			),
			'qs-page'       => array(
				'label'       => __( 'Qwerty Soft — Full pages', 'qwerty-soft-signal' ),
				'description' => __( 'Complete page layouts you can drop in and edit.', 'qwerty-soft-signal' ),
			),
		);

		foreach ( $categories as $slug => $args ) {
			register_block_pattern_category( $slug, $args );
		}
	}
}
