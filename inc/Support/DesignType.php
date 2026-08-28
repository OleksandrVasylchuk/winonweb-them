<?php
/**
 * The record behind a listing: six cards become six things somebody can add to.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A post type the importer worked out from a design.
 *
 * The difference this makes is the difference between handing over a site and
 * handing over a mockup. Six report cards held as content mean adding a seventh
 * report by editing a page; held as records they mean pressing Add New, and the
 * card the designer drew is the template that draws it.
 *
 * The type is described in a file beside the block that needs it, for the same
 * reason the block is a file: it belongs to the site the design was built for,
 * it is removed when the import is, and a theme update never touches it.
 */
final class DesignType {

	/**
	 * Longest a post type key may be. WordPress refuses anything longer.
	 */
	private const KEY_LIMIT = 20;

	/**
	 * Where the descriptions live, under the theme.
	 */
	public const DIR = '/blocks/design';

	/**
	 * The post type key for a thing the model named.
	 *
	 * Deliberately derived from the word rather than from the section, so that
	 * two sections both showing reports — the home page's three and the reports
	 * page's six — end up reading one list instead of keeping two that drift
	 * apart.
	 *
	 * @param string $singular What one of them is called.
	 * @return string A key, or empty when the word cannot make one.
	 */
	public static function key( string $singular ): string {
		$slug = strtolower( trim( $singular ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '_', $slug );
		$slug = trim( $slug, '_' );

		if ( '' === $slug ) {
			return '';
		}

		return substr( 'qs_' . $slug, 0, self::KEY_LIMIT );
	}

	/**
	 * Describe a post type, ready to be written beside a block.
	 *
	 * @param string                           $singular What one record is called.
	 * @param array<int, array<string, mixed>> $fields  One row's fields, from the plan.
	 * @return array<string, mixed>|null Null when there is nothing usable to describe.
	 */
	public static function describe( string $singular, array $fields ): ?array {
		$key = self::key( $singular );

		if ( '' === $key || array() === $fields ) {
			return null;
		}

		$one   = ucfirst( trim( $singular ) );
		$many  = self::plural( $one );
		$shape = array();

		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$shape[] = array(
				'name'  => $name,
				'type'  => (string) ( $field['type'] ?? 'text' ),
				'label' => (string) ( $field['label'] ?? ucfirst( str_replace( '_', ' ', $name ) ) ),
			);
		}

		return array(
			'key'      => $key,
			'singular' => $one,
			'plural'   => $many,
			'fields'   => $shape,
		);
	}

	/**
	 * A plural that reads correctly for the words designs actually use.
	 *
	 * Not a general pluraliser and not trying to be. It covers the shapes that
	 * turn up in a handoff — "Report", "Case study", "Company" — and otherwise
	 * adds an s, which is what a person would do.
	 *
	 * @param string $one The singular.
	 * @return string
	 */
	public static function plural( string $one ): string {
		$lower = strtolower( $one );

		// "Analysis" and "Thesis" turn up in this kind of design often enough.
		if ( 1 === preg_match( '/sis$/', $lower ) ) {
			return substr( $one, 0, -2 ) . 'es';
		}

		if ( 1 === preg_match( '/(s|x|z|ch|sh)$/', $lower ) ) {
			return $one . 'es';
		}

		if ( 1 === preg_match( '/[^aeiou]y$/', $lower ) ) {
			return substr( $one, 0, -1 ) . 'ies';
		}

		return $one . 's';
	}

	/**
	 * Register one described type with WordPress.
	 *
	 * @param array<string, mixed> $type What describe() produced.
	 * @return void
	 */
	public static function register( array $type ): void {
		$key = (string) ( $type['key'] ?? '' );

		if ( '' === $key || post_type_exists( $key ) ) {
			return;
		}

		$one  = (string) ( $type['singular'] ?? $key );
		$many = (string) ( $type['plural'] ?? $one . 's' );

		register_post_type(
			$key,
			array(
				'labels'       => array(
					'name'          => $many,
					'singular_name' => $one,
					/* translators: %s: what one record is called, such as "Report". */
					'add_new_item'  => sprintf( __( 'Add %s', 'qwerty-soft-signal' ), $one ),
					/* translators: %s: what one record is called, such as "Report". */
					'edit_item'     => sprintf( __( 'Edit %s', 'qwerty-soft-signal' ), $one ),
				),
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-media-document',

				/*
				 * A title and an editor, because a record is a page in its own
				 * right as well as a card in a grid: the design draws the card,
				 * and the type carries whatever the designer put on it as
				 * fields. The excerpt is what a card falls back to when a field
				 * is empty.
				 */
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => str_replace( '_', '-', substr( $key, 3 ) ) ),
			)
		);
	}

	/**
	 * The ACF field group that puts a record's own fields on its editing screen.
	 *
	 * @param array<string, mixed> $type What describe() produced.
	 * @return array<string, mixed>
	 */
	public static function group( array $type ): array {
		$key    = (string) ( $type['key'] ?? '' );
		$fields = array();

		foreach ( (array) ( $type['fields'] ?? array() ) as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$one = array(
				'key'   => 'field_' . $key . '_' . $name,
				'label' => (string) ( $field['label'] ?? ucfirst( $name ) ),
				'name'  => $name,
				'type'  => 'text',
			);

			switch ( (string) ( $field['type'] ?? 'text' ) ) {
				case 'textarea':
					$one['type'] = 'textarea';
					$one['rows'] = 3;
					break;

				case 'image':
					$one['type']          = 'image';
					$one['return_format'] = 'array';
					break;

				case 'link':
					$one['type']          = 'link';
					$one['return_format'] = 'array';
					break;
			}

			$fields[] = $one;
		}

		return array(
			'key'      => 'group_' . $key,
			'title'    => (string) ( $type['singular'] ?? $key ),
			'fields'   => $fields,
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => $key,
					),
				),
			),
			'active'   => true,
		);
	}

	/**
	 * Every type an import described, read back from the theme.
	 *
	 * @return array<string, array<string, mixed>> Keyed by post type.
	 */
	public static function all(): array {
		$found = glob( BlockWriter::dir() . '/*/type.json' );

		if ( ! is_array( $found ) ) {
			return array();
		}

		$types = array();

		foreach ( $found as $file ) {
			$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by the importer.

			if ( false === $raw ) {
				continue;
			}

			$type = json_decode( $raw, true );

			if ( ! is_array( $type ) || empty( $type['key'] ) ) {
				continue;
			}

			/*
			 * Two sections describing the same thing is the normal case and the
			 * point of asking the model for a word rather than deriving one
			 * from the section: the home page's three reports and the reports
			 * page's six are one list. The richer description wins, so a
			 * section that shows more of a record decides its fields.
			 */
			$key = (string) $type['key'];

			if ( ! isset( $types[ $key ] ) || count( (array) $type['fields'] ) > count( (array) $types[ $key ]['fields'] ) ) {
				$types[ $key ] = $type;
			}
		}

		return $types;
	}
}
