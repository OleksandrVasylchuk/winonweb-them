<?php
/**
 * Registers the record types an import worked out from a design.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\DesignType;

defined( 'ABSPATH' ) || exit;

/**
 * The lists a site owner adds to.
 *
 * A design's six report cards are not content of the section that shows them —
 * they are the site's own records, and the card is a template for one. Making
 * them a post type is what turns "edit the page and copy a card" into "press
 * Add New", and it is the difference between handing over a site and handing
 * over a mockup.
 *
 * The types are described in files beside the blocks that read them, for the
 * same reason the blocks are files: they belong to this site rather than to the
 * theme, they go when the import goes, and an update never touches them.
 */
final class DesignTypes implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Early on `init`, because a post type registered after the block that
		 * queries it has already run is a post type nothing can see.
		 */
		add_action( 'init', array( $this, 'register_types' ), 5 );
		add_action( 'acf/init', array( $this, 'register_fields' ) );
	}

	/**
	 * Register every type an import described.
	 *
	 * @return void
	 */
	public function register_types(): void {
		foreach ( DesignType::all() as $type ) {
			DesignType::register( $type );
		}
	}

	/**
	 * Put each type's own fields on its editing screen.
	 *
	 * @return void
	 */
	public function register_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		foreach ( DesignType::all() as $type ) {
			$group = DesignType::group( $type );

			if ( array() !== $group['fields'] ) {
				acf_add_local_field_group( $group );
			}
		}
	}
}
