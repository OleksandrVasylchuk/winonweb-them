<?php
/**
 * Moving between a page's languages, made convenient.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\SiteAssembler;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * The admin side of the theme's translations, without the plugin.
 *
 * An import ties every page to its versions in the other languages through
 * two meta rows — which language it is, and which page it is a translation
 * of. The site ran on that: the switcher in the header, the links inside the
 * pages. The admin did not: finding "this page, but in Russian" meant reading
 * slugs in the Pages list like an archaeologist.
 *
 * So the list gets a language column with jump links between versions and a
 * filter per language, and the page editor gets a Translations box that says
 * where this page's other languages are — or that they have not been built,
 * and where to build them. What this deliberately is not is Polylang: no
 * language taxonomy, no auto-created drafts for hand-made pages, no string
 * translation. The import made the pages; this makes walking between them
 * take one click.
 */
final class Translations implements Module {

	/**
	 * The filter's query argument.
	 *
	 * @var string
	 */
	private const FILTER = 'qs_language';

	/**
	 * Attach the module's hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_page_posts_columns', array( $this, 'column' ) );
		add_action( 'manage_page_posts_custom_column', array( $this, 'cell' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'filter' ) );
		add_action( 'add_meta_boxes_page', array( $this, 'box' ) );
	}

	/**
	 * The Language column, only on a site that has languages.
	 *
	 * @param array<string, string> $columns List table columns.
	 * @return array<string, string>
	 */
	public function column( array $columns ): array {
		if ( array() === self::languages() ) {
			return $columns;
		}

		$rebuilt = array();

		foreach ( $columns as $key => $label ) {
			$rebuilt[ $key ] = $label;

			if ( 'title' === $key ) {
				$rebuilt['qs_language'] = __( 'Language', 'qwerty-soft-signal' );
			}
		}

		return $rebuilt;
	}

	/**
	 * One row's cell: its own language, and a link to each of its versions.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Post ID.
	 * @return void
	 */
	public function cell( string $column, int $id ): void {
		if ( 'qs_language' !== $column ) {
			return;
		}

		$language = (string) get_post_meta( $id, SiteAssembler::LANG_META, true );

		if ( '' === $language ) {
			echo '<span aria-hidden="true">—</span>';

			return;
		}

		$said = array( '<strong>' . esc_html( strtoupper( $language ) ) . '</strong>' );

		foreach ( self::siblings( $id ) as $code => $sibling ) {
			$said[] = '<a href="' . esc_url( (string) get_edit_post_link( $sibling ) ) . '">' . esc_html( strtoupper( $code ) ) . '</a>';
		}

		echo wp_kses_post( implode( ' · ', $said ) );
	}

	/**
	 * The filter above the list: show one language at a time.
	 *
	 * @param string $type Post type of the list being drawn.
	 * @return void
	 */
	public function dropdown( string $type = '' ): void {
		if ( 'page' !== $type ) {
			return;
		}

		$languages = self::languages();

		if ( count( $languages ) < 2 ) {
			return;
		}

		$chosen = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which rows to look at changes nothing.

		echo '<select name="' . esc_attr( self::FILTER ) . '">';
		echo '<option value="">' . esc_html__( 'Every language', 'qwerty-soft-signal' ) . '</option>';

		foreach ( $languages as $language ) {
			echo '<option value="' . esc_attr( $language ) . '" ' . selected( $chosen, $language, false ) . '>' . esc_html( strtoupper( $language ) ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Apply the chosen language to the list's query.
	 *
	 * @param WP_Query $query The list table's query.
	 * @return void
	 */
	public function filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'page' !== $query->get( 'post_type' ) ) {
			return;
		}

		$chosen = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which rows to look at changes nothing.

		if ( '' === $chosen ) {
			return;
		}

		$query->set(
			'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The language filter is what is being asked for; the meta is indexed by key.
			array(
				array(
					'key'   => SiteAssembler::LANG_META,
					'value' => $chosen,
				),
			)
		);
	}

	/**
	 * The Translations box on a page's editing screen.
	 *
	 * @param WP_Post $post The page being edited.
	 * @return void
	 */
	public function box( WP_Post $post ): void {
		if ( '' === (string) get_post_meta( $post->ID, SiteAssembler::LANG_META, true ) ) {
			return;
		}

		add_meta_box(
			'qs-translations',
			__( 'Translations', 'qwerty-soft-signal' ),
			array( $this, 'render_box' ),
			'page',
			'side',
			'high'
		);
	}

	/**
	 * What the box says: this page in every language the site knows.
	 *
	 * @param WP_Post $post The page being edited.
	 * @return void
	 */
	public function render_box( WP_Post $post ): void {
		$own      = (string) get_post_meta( $post->ID, SiteAssembler::LANG_META, true );
		$siblings = self::siblings( $post->ID );

		echo '<ul style="margin:0">';

		foreach ( self::languages() as $language ) {
			echo '<li>';

			if ( $language === $own ) {
				echo '<strong>' . esc_html( strtoupper( $language ) ) . '</strong> — ' . esc_html__( 'this page', 'qwerty-soft-signal' );
			} elseif ( isset( $siblings[ $language ] ) ) {
				echo esc_html( strtoupper( $language ) ) . ' — <a href="' . esc_url( (string) get_edit_post_link( $siblings[ $language ] ) ) . '">' . esc_html( get_the_title( $siblings[ $language ] ) ) . '</a>';
			} else {
				echo esc_html( strtoupper( $language ) ) . ' — ' . esc_html__( 'not built yet', 'qwerty-soft-signal' );
			}

			echo '</li>';
		}

		echo '</ul>';

		if ( count( $siblings ) + 1 < count( self::languages() ) || array() === self::languages() ) {
			echo '<p style="margin-bottom:0"><a href="' . esc_url( admin_url( 'themes.php?page=qwerty-soft-signal-import' ) ) . '">' . esc_html__( 'Build a missing language from Design import.', 'qwerty-soft-signal' ) . '</a></p>';
		}
	}

	/**
	 * Every language the site's pages carry.
	 *
	 * @return array<int, string>
	 */
	private static function languages(): array {
		static $found = null;

		if ( is_array( $found ) ) {
			return $found;
		}

		global $wpdb;

		$found = array_map(
			'strval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One DISTINCT over an indexed meta key, cached for the request; no core API answers "which languages exist".
				$wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value", SiteAssembler::LANG_META )
			)
		);

		return $found;
	}

	/**
	 * This page in its other languages.
	 *
	 * @param int $id Page ID.
	 * @return array<string, int> Language code => page ID.
	 */
	private static function siblings( int $id ): array {
		$group = (string) get_post_meta( $id, SiteAssembler::GROUP_META, true );

		if ( '' === $group ) {
			return array();
		}

		static $map = null;

		if ( null === $map ) {
			global $wpdb;

			$map = array();

			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One join over two indexed meta keys, cached for the request; per-row queries in a list table would be the slow version.
				$wpdb->prepare(
					"SELECT g.post_id, g.meta_value AS grp, l.meta_value AS lang
					 FROM {$wpdb->postmeta} g
					 JOIN {$wpdb->postmeta} l ON l.post_id = g.post_id AND l.meta_key = %s
					 WHERE g.meta_key = %s",
					SiteAssembler::LANG_META,
					SiteAssembler::GROUP_META
				)
			);

			foreach ( $rows as $row ) {
				$map[ (string) $row->grp ][ (string) $row->lang ] = (int) $row->post_id;
			}
		}

		$found = $map[ $group ] ?? array();

		foreach ( $found as $language => $sibling ) {
			$status = (string) get_post_status( (int) $sibling );

			if ( (int) $sibling === $id || ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
				unset( $found[ $language ] );
			}
		}

		return $found;
	}
}
