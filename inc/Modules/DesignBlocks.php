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

		/*
		 * Editing on the canvas. ACF Pro draws a generated block as its
		 * preview and forces that whenever the editor canvas is an iframe —
		 * which, since WordPress 6.3, is every screen — so its own form never
		 * appears on the canvas and the fields sit in the sidebar alone. The
		 * writer marks every field's element in the markup; this script reads
		 * the marks and lets the words, pictures and links be edited where
		 * they are drawn, writing back to the same block data the sidebar
		 * edits. Both stay; neither is the only way in.
		 */
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_canvas' ), 20 );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_canvas_styles' ) );
		add_filter( 'render_block', array( $this, 'unmark' ), 10, 2 );

		/*
		 * A class on the body of a site built from a design. The design's own
		 * `body{…}` rules are lifted onto it (`DesignStylesheet::scoped()`), so
		 * they sit one class above the theme's global styles, which print
		 * after the design's stylesheet and used to win every tie — a design
		 * silent on font-size took the theme's 17px and grew 156px down the
		 * page. The editor's canvas carries `.editor-styles-wrapper` on its
		 * body and is matched by the same selector.
		 */
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Mark the body of a site that carries generated design blocks.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function body_class( array $classes ): array {
		if ( array() !== $this->groups() ) {
			$classes[] = \Qwerty\Soft\Support\BlockWriter::SITE_CLASS;
		}

		return $classes;
	}

	/**
	 * The script that makes a design block editable where it is drawn.
	 *
	 * @return void
	 */
	public function enqueue_canvas(): void {
		if ( ! function_exists( 'acf_register_block_type' ) || array() === $this->groups() ) {
			return;
		}

		$file = QSOFT_DIR . '/assets/js/design-canvas.js';

		if ( ! is_readable( $file ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'qs-design-canvas',
			QSOFT_URI . '/assets/js/design-canvas.js',
			array( 'acf-blocks', 'wp-data', 'wp-i18n', 'wp-blocks', 'wp-element', 'jquery', 'media-editor' ),
			(string) filemtime( $file ),
			true
		);

		wp_localize_script(
			'qs-design-canvas',
			'qsDesignCanvas',
			array(
				'tags'   => \Qwerty\Soft\Support\SectionPlan::RICH_TAGS,
				'blocks' => $this->field_labels(),
				'i18n'   => array(
					'edit'      => __( 'Click to edit', 'qwerty-soft-signal' ),
					'image'     => __( 'Click to replace the picture', 'qwerty-soft-signal' ),
					'link'      => __( 'Link address', 'qwerty-soft-signal' ),
					'linkText'  => __( 'Link text', 'qwerty-soft-signal' ),
					'newTab'    => __( 'Open in a new tab', 'qwerty-soft-signal' ),
					'apply'     => __( 'Apply', 'qwerty-soft-signal' ),
					'addRow'    => __( 'Add a row after this one', 'qwerty-soft-signal' ),
					'removeRow' => __( 'Remove this row', 'qwerty-soft-signal' ),
					'choose'    => __( 'Choose a picture', 'qwerty-soft-signal' ),
					'use'       => __( 'Use this picture', 'qwerty-soft-signal' ),
					'fields'    => __( 'Fields', 'qwerty-soft-signal' ),
					'close'     => __( 'Close', 'qwerty-soft-signal' ),
					'wider'     => __( 'Widen the panel', 'qwerty-soft-signal' ),
					'narrower'  => __( 'Narrow the panel', 'qwerty-soft-signal' ),
					'row'       => __( 'Row', 'qwerty-soft-signal' ),
					'addRowEnd' => __( 'Add row', 'qwerty-soft-signal' ),
					'replace'   => __( 'Replace', 'qwerty-soft-signal' ),
					'noFields'  => __( 'This section has no editable fields.', 'qwerty-soft-signal' ),
					'hint'      => __( 'Or click any text, picture or link in the section to edit it there.', 'qwerty-soft-signal' ),
				),
			)
		);
	}

	/**
	 * What each generated block's fields are called, for the panel on the canvas.
	 *
	 * The labels live in fields.json, which ACF reads and the canvas script
	 * cannot. Read once here into `{ block name: { fields, rows, items } }`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function field_labels(): array {
		$labels = array();

		foreach ( $this->groups() as $group ) {
			$block = '';

			foreach ( (array) ( $group['location'] ?? array() ) as $rules ) {
				foreach ( (array) $rules as $rule ) {
					if ( 'block' === ( $rule['param'] ?? '' ) ) {
						$block = (string) ( $rule['value'] ?? '' );
					}
				}
			}

			if ( '' === $block ) {
				continue;
			}

			$fields    = array();
			$rows      = array();
			$items     = '';
			$items_key = '';

			/*
			 * Keys as well as labels. In the editor ACF rewrites a block's
			 * data from field names to field keys — `heading` becomes
			 * `field_qs_…_heading`, a repeater becomes `{ "row-0": { key:
			 * value } }` — so the canvas has to address values by key to be
			 * heard at all.
			 */
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$name = (string) ( $field['name'] ?? '' );
				$type = (string) ( $field['type'] ?? 'text' );

				// A divider holds no value and has no name to address one by.
				if ( in_array( $type, array( 'tab', 'accordion' ), true ) ) {
					continue;
				}

				if ( 'repeater' === $type ) {
					$items     = (string) ( $field['label'] ?? '' );
					$items_key = (string) ( $field['key'] ?? '' );

					foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
						$rows[ (string) ( $sub['name'] ?? '' ) ] = array(
							'label' => (string) ( $sub['label'] ?? '' ),
							'type'  => (string) ( $sub['type'] ?? 'text' ),
							'key'   => (string) ( $sub['key'] ?? '' ),
						);
					}

					continue;
				}

				$fields[ $name ] = array(
					'label' => (string) ( $field['label'] ?? '' ),
					'type'  => $type,
					'key'   => (string) ( $field['key'] ?? '' ),
				);
			}

			$labels[ $block ] = array(
				'fields'   => $fields,
				'rows'     => $rows,
				'items'    => $items,
				'itemsKey' => $items_key,
			);
		}

		return $labels;
	}

	/**
	 * The outlines and controls the canvas draws around editable elements.
	 *
	 * `enqueue_block_assets` is the hook whose styles reach inside the
	 * editor's iframe, which is where the preview is drawn; it also fires on
	 * the front end, where none of this belongs.
	 *
	 * @return void
	 */
	public function enqueue_canvas_styles(): void {
		if ( ! is_admin() || array() === $this->groups() ) {
			return;
		}

		$file = QSOFT_DIR . '/assets/css/design-canvas.css';

		if ( ! is_readable( $file ) ) {
			return;
		}

		wp_enqueue_style( 'qs-design-canvas', QSOFT_URI . '/assets/css/design-canvas.css', array(), (string) filemtime( $file ) );
	}

	/**
	 * Take the editor's marks off a block a visitor is looking at.
	 *
	 * @param string               $content Rendered block.
	 * @param array<string, mixed> $block   The parsed block.
	 * @return string
	 */
	public function unmark( string $content, array $block ): string {
		$name = (string) ( $block['blockName'] ?? '' );

		if ( ! str_starts_with( $name, 'qs/design-' ) || false === strpos( $content, 'data-qs-' ) ) {
			return $content;
		}

		if ( \Qwerty\Soft\Support\DesignField::editing() ) {
			return $content;
		}

		return \Qwerty\Soft\Support\DesignField::unmarked( $content );
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
		$found = array();

		foreach ( \Qwerty\Soft\Support\BlockWriter::dirs() as $dir ) {
			$found = array_merge( $found, (array) glob( $dir . '/*/fields.json' ) );
		}

		if ( array() === $found ) {
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
