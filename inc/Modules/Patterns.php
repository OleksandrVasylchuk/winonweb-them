<?php
/**
 * Pattern categories.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;

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
			'wow-hero'       => array(
				'label'       => __( 'WOW — Hero', 'wow-signal' ),
				'description' => __( 'Opening sections for the top of a page.', 'wow-signal' ),
			),
			'wow-content'    => array(
				'label'       => __( 'WOW — Content', 'wow-signal' ),
				'description' => __( 'Services, process, features and text sections.', 'wow-signal' ),
			),
			'wow-proof'      => array(
				'label'       => __( 'WOW — Proof', 'wow-signal' ),
				'description' => __( 'Case studies, metrics, testimonials and client logos.', 'wow-signal' ),
			),
			'wow-conversion' => array(
				'label'       => __( 'WOW — Conversion', 'wow-signal' ),
				'description' => __( 'Pricing, FAQ, contact and call-to-action sections.', 'wow-signal' ),
			),
			'wow-page'       => array(
				'label'       => __( 'WOW — Full pages', 'wow-signal' ),
				'description' => __( 'Complete page layouts you can drop in and edit.', 'wow-signal' ),
			),
		);

		foreach ( $categories as $slug => $args ) {
			register_block_pattern_category( $slug, $args );
		}
	}
}
