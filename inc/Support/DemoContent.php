<?php
/**
 * The starter site, built from the theme's own page patterns.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Block_Patterns_Registry;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the three full-page patterns into an actual site.
 *
 * A block theme on a fresh install shows one empty page, which is a fair
 * description of the theme and a terrible first impression of the product. The
 * patterns that fix that are already in the box; all that is missing is
 * somebody pressing insert three times, setting a front page and building a
 * menu. This does that.
 *
 * Nothing here calls an API or needs a key. Every page it creates carries
 * SiteAssembler::OWNED_META, so the "remove everything this theme added" button
 * on the import screen already knows how to take it all back out again.
 */
final class DemoContent {

	/**
	 * Pages to create: pattern slug => [ post slug, title ].
	 *
	 * Order matters — the first entry becomes the front page and the menu is
	 * built in this order.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	private const PAGES = array(
		'qwerty-soft-signal/page-home'     => array( 'home', 'Home' ),
		'qwerty-soft-signal/page-services' => array( 'services', 'Services' ),
		'qwerty-soft-signal/page-contact'  => array( 'contact', 'Contact' ),
	);

	/**
	 * The template these pages need.
	 *
	 * All three open with a full-width hero. The default page template prints
	 * the post title above the content and constrains it, which puts the word
	 * "Home" above the headline and squeezes a full-bleed section into a
	 * column. The theme already ships the right template — "Landing page (no
	 * title, full width)" — and these pages have to ask for it.
	 */
	private const TEMPLATE = 'page-landing';

	/**
	 * Is the starter content already on this site?
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		return array() !== self::ours();
	}

	/**
	 * Create the starter pages, the menu and the front page.
	 *
	 * @return array{pages:array<int,string>,front:string,menu:string,skipped:array<int,string>}
	 */
	public static function install(): array {
		$report = array(
			'pages'   => array(),
			'front'   => '',
			'menu'    => 'none',
			'skipped' => array(),
		);

		$registry = WP_Block_Patterns_Registry::get_instance();
		$created  = array();

		foreach ( self::PAGES as $pattern_slug => $page ) {
			list( $slug, $title ) = $page;

			$pattern = $registry->get_registered( $pattern_slug );

			if ( null === $pattern || ! isset( $pattern['content'] ) || '' === trim( (string) $pattern['content'] ) ) {
				$report['skipped'][] = $pattern_slug;
				continue;
			}

			$existing = get_page_by_path( $slug );

			if ( $existing instanceof WP_Post ) {
				$report['skipped'][] = $slug;
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => self::title( $slug, $title ),
					'post_name'    => $slug,
					'post_content' => (string) $pattern['content'],
					'meta_input'   => array(
						SiteAssembler::OWNED_META => 1,
						'_wp_page_template'       => self::TEMPLATE,
					),
				),
				true
			);

			if ( is_wp_error( $id ) || 0 === $id ) {
				$report['skipped'][] = $slug;
				continue;
			}

			$created[ $slug ]  = (int) $id;
			$report['pages'][] = $slug;
		}

		/*
		 * Only claim the front page with a page this run actually created, and
		 * only when nothing else has claimed it. A site that already has a
		 * front page has one for a reason, and silently moving it is the kind
		 * of "helpful" that costs somebody an afternoon.
		 */
		if ( isset( $created['home'] ) && 'page' !== get_option( 'show_on_front' ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $created['home'] );
			$report['front'] = 'home';
		}

		$report['menu'] = self::menu( $created );

		return $report;
	}

	/**
	 * A translated page title, falling back to the English source.
	 *
	 * The titles are listed as plain strings in PAGES so the array stays
	 * readable; translation happens here, where the context can be given.
	 *
	 * @param string $slug  Page slug.
	 * @param string $title English title.
	 * @return string
	 */
	private static function title( string $slug, string $title ): string {
		switch ( $slug ) {
			case 'home':
				return _x( 'Home', 'Starter page title', 'qwerty-soft-signal' );
			case 'services':
				return _x( 'Services', 'Starter page title', 'qwerty-soft-signal' );
			case 'contact':
				return _x( 'Contact', 'Starter page title', 'qwerty-soft-signal' );
		}

		return $title;
	}

	/**
	 * Make sure the header has a navigation listing the new pages.
	 *
	 * The header part uses an unreferenced core/navigation block, which adopts
	 * whichever menu exists. There is a trap here: the first time that block
	 * renders — long before anybody opens the setup screen — core silently
	 * creates a fallback menu whose whole content is `wp:page-list`. A check
	 * for "does a menu already exist" therefore always finds one, and refusing
	 * to act on that basis makes this step dead code on every real install.
	 *
	 * That fallback is also not good enough to leave: a page list is every
	 * top-level page in alphabetical order, so a fresh site shows WordPress's
	 * own "Sample Page" in the header and puts Home second. So core's untouched
	 * fallback is filled in with real links, and a menu anybody has actually
	 * edited is never touched.
	 *
	 * @param array<string, int> $pages Slug => post ID.
	 * @return string One of: created, filled, existing, none.
	 */
	private static function menu( array $pages ): string {
		if ( array() === $pages ) {
			return 'none';
		}

		$existing = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);

		if ( array() !== $existing ) {
			$content = trim( (string) $existing[0]->post_content );

			// Anything other than core's bare fallback belongs to somebody.
			if ( '<!-- wp:page-list /-->' !== $content && '' !== $content ) {
				return 'existing';
			}

			$filled = wp_update_post(
				array(
					'ID'           => $existing[0]->ID,
					'post_content' => self::links( $pages ),
				),
				true
			);

			return is_wp_error( $filled ) ? 'existing' : 'filled';
		}

		$menu = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => _x( 'Primary', 'Starter menu name', 'qwerty-soft-signal' ),
				'post_content' => self::links( $pages ),
				'meta_input'   => array( SiteAssembler::OWNED_META => 1 ),
			),
			true
		);

		return ! is_wp_error( $menu ) && 0 !== $menu ? 'created' : 'none';
	}

	/**
	 * Navigation link blocks for a set of pages, in the order given.
	 *
	 * @param array<string, int> $pages Slug => post ID.
	 * @return string Block markup.
	 */
	private static function links( array $pages ): string {
		$links = '';

		foreach ( $pages as $id ) {
			$attributes = array(
				'label' => wp_strip_all_tags( (string) get_the_title( $id ) ),
				'type'  => 'page',
				'id'    => (int) $id,
				'url'   => (string) get_permalink( $id ),
				'kind'  => 'post-type',
			);

			// Block attributes are JSON, so they are encoded as JSON — not HTML.
			$links .= '<!-- wp:navigation-link ' . wp_json_encode( $attributes, JSON_UNESCAPED_UNICODE ) . ' /-->';
		}

		return $links;
	}

	/**
	 * Posts this class created.
	 *
	 * @return array<int, int> Post IDs.
	 */
	private static function ours(): array {
		return array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'      => array( 'page', 'wp_navigation' ),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => SiteAssembler::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Setup bookkeeping, not a front-end query.
				)
			)
		);
	}
}
