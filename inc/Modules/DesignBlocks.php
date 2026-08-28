<?php
/**
 * Gives the generated design blocks their fields.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The half of a generated block that ACF has to be told about.
 *
 * `register_block_type()` reads `block.json` and gives the block its name,
 * category and render template. It does not give it fields: ACF holds those in
 * a field group, and a group that lives only as a file is invisible until
 * somebody registers it.
 *
 * The importer writes `fields.json` beside each block for exactly that, and
 * this reads them back. Local JSON is used rather than the database on
 * purpose: a design block is a file, so its fields should be a file too, and
 * an import that is deleted should take its fields with it rather than leaving
 * orphaned groups in an editor's sidebar.
 */
final class DesignBlocks implements Module {

	/**
	 * Where the generated blocks live, under the theme.
	 */
	private const DIR = '/blocks/design';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * `acf/init` rather than `init`: field groups registered before ACF
		 * has booted are silently dropped, which shows up as a block whose
		 * sidebar is empty and whose markup renders with every value blank.
		 */
		add_action( 'acf/init', array( $this, 'register_fields' ) );
		add_filter( 'debug_information', array( $this, 'report' ) );
	}

	/**
	 * Register the field group of every generated block.
	 *
	 * @return void
	 */
	public function register_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		foreach ( $this->groups() as $group ) {
			acf_add_local_field_group( $group );
		}
	}

	/**
	 * Every field group written beside a generated block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function groups(): array {
		$found = glob( \Qwerty\Soft\Support\BlockWriter::dir() . '/*/fields.json' );

		if ( ! is_array( $found ) ) {
			return array();
		}

		$groups = array();

		foreach ( $found as $file ) {
			$group = $this->read( $file );

			if ( null !== $group ) {
				$groups[] = $group;
			}
		}

		return $groups;
	}

	/**
	 * One field group, if the file holds one.
	 *
	 * @param string $file Path to a fields.json.
	 * @return array<string, mixed>|null
	 */
	private function read( string $file ): ?array {
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by the importer.

		if ( false === $raw ) {
			return null;
		}

		$group = json_decode( $raw, true );

		/*
		 * A half-written file is possible: a build that was interrupted
		 * between opening a block's directory and finishing it leaves one
		 * behind. Registering it would be worse than skipping it, because ACF
		 * would take a group with no key and attach it to every screen.
		 */
		if ( ! is_array( $group ) || empty( $group['key'] ) || empty( $group['location'] ) ) {
			return null;
		}

		return $group;
	}

	/**
	 * Say on Site Health whether the dependency these blocks need is present.
	 *
	 * @param array<string, mixed> $info What Site Health has so far.
	 * @return array<string, mixed>
	 */
	public function report( array $info ): array {
		$groups = $this->groups();

		if ( array() === $groups || ! isset( $info['qwerty-soft-signal']['fields'] ) ) {
			return $info;
		}

		$count = count( $groups );

		/*
		 * Said plainly because the symptom is misleading. Without ACF the
		 * blocks still register and still render — every field simply reads
		 * empty — so the pages look stripped rather than broken, and nothing
		 * on the page points at the cause.
		 *
		 * Written as a branch rather than a ternary inside sprintf() so that
		 * the translator comments survive extraction: nested in the argument
		 * list, the two ran together into one unusable note in the POT.
		 */
		if ( function_exists( 'acf_add_local_field_group' ) ) {
			/* translators: %d: how many blocks. */
			$said = _n( '%d block, fields registered.', '%d blocks, fields registered.', $count, 'qwerty-soft-signal' );
		} else {
			/* translators: %d: how many blocks. */
			$said = _n(
				'%d block needs ACF Pro, which is not active. It renders with every field empty until it is.',
				'%d blocks need ACF Pro, which is not active. They render with every field empty until it is.',
				$count,
				'qwerty-soft-signal'
			);
		}

		$info['qwerty-soft-signal']['fields']['design_blocks'] = array(
			'label' => __( 'Imported design blocks', 'qwerty-soft-signal' ),
			'value' => sprintf( $said, $count ),
		);

		return $info;
	}
}
