<?php
/**
 * Putting the generated blocks back, from the site that is already using them.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuild `blocks/design/` for blocks the site refers to but the theme has lost.
 *
 * The blocks an import generates are files in the theme. They are excluded from
 * the release ZIP and are not in the repository, which is right — they belong to
 * one site — but it means a copied database, a redeployed theme directory or a
 * careless `rm` leaves every page pointing at blocks that are not there. What
 * the reader gets is "your site doesn't include support for this block", on
 * every section of every page, with the content still safely in the database and
 * no way to reach it.
 *
 * Rebuilding does not need the design read again, and above all does not need
 * the model. Everything the writer was given is recoverable:
 *
 * - the markup, from the unpacked design, matched by the same digest that named
 *   the block in the first place;
 * - the field names, from the placement itself — an ACF block writes its values
 *   into the block comment under the names the template reads;
 * - the structure, from `SectionPlan`, which is code and answers identically
 *   every time it is asked.
 *
 * Only the model's two judgements are lost: what the block is called in the
 * inserter, and whether a repeating part was the site's own records. The first
 * falls back to the heading the splitter read, and the second is settled by the
 * placement — a block that stored rows had a repeater.
 */
final class BlockRepair {

	/**
	 * Where generated blocks live, under the theme.
	 */
	private const DIR = '/blocks/design';

	/**
	 * Blocks the site refers to that the theme cannot draw.
	 *
	 * @return array<int, string> Block slugs, without the `design-` prefix's namespace.
	 */
	public static function missing(): array {
		$missing = array();

		foreach ( array_keys( self::placements() ) as $slug ) {
			if ( ! BlockWriter::current( BlockWriter::dir() . '/' . $slug ) ) {
				$missing[] = $slug;
			}
		}

		return $missing;
	}

	/**
	 * Rebuild every block the site is missing.
	 *
	 * @return array{written:int,missing:array<int, string>,root:string,pages:int}
	 */
	public static function run(): array {
		$report = array(
			'written' => 0,
			'missing' => array(),
			'root'    => '',
			'pages'   => 0,
		);

		$wanted = self::missing();
		$root   = self::root();

		if ( '' === $root ) {
			$report['missing'] = $wanted;

			return $report;
		}

		$report['root'] = $root;
		$placements     = self::placements();
		$media          = self::media_map();
		$left           = array_fill_keys( $wanted, true );

		/*
		 * Which canonical source each page wears, learned again from the
		 * archive, so a rebuilt block names the same handle the build gave
		 * it — a handoff carrying two sites has two, and a repair that
		 * routed everything to the primary would dress the prototype's
		 * blocks in the baseline's stylesheet.
		 */
		$files  = self::files();
		$origin = (string) get_option( SiteOptions::ORIGIN, '' );

		if ( '' !== $origin && ! in_array( $origin, $files, true ) ) {
			$files[] = $origin;
		}

		$absolute = array();

		foreach ( $files as $file ) {
			$path = realpath( trailingslashit( $root ) . ltrim( $file, '/' ) );

			if ( false !== $path ) {
				$absolute[ str_replace( '\\', '/', $path ) ] = $file;
			}
		}

		$routes = array();

		foreach ( DesignStylesheet::routes( $root, array_keys( $absolute ) ) as $path => $key ) {
			$routes[ (string) $absolute[ $path ] ] = (string) $key;
		}

		BlockWriter::route_styles( $routes );

		/*
		 * The chrome first, and whether or not anything is reported missing.
		 *
		 * It is what a visitor sees before anything else, it is the one part
		 * that never needed the model — `wrap_chrome()` reads it with
		 * `SectionPlan` alone — and its name is a digest of how the reading
		 * came out. Correct a fault in that reading and the name moves, which
		 * leaves the part pointing at a block that exists and is wrong. Nothing
		 * reports that as missing, so it is checked every time.
		 */
		self::repair_chrome( $root, $media, $left, $report );

		foreach ( self::files() as $file ) {
			if ( array() === $left ) {
				break;
			}

			$before = $report['written'];

			self::repair_page( $root, $file, $placements, $media, $left, $report );

			if ( $report['written'] > $before ) {
				++$report['pages'];
			}
		}

		$report['missing'] = array_keys( $left );

		/*
		 * And the links, last, exactly as a build ends. A block restored from
		 * the archive carries the archive's own addresses; until this runs,
		 * every one of them points at a file name rather than at a page.
		 */
		if ( $report['written'] > 0 ) {
			$report['unresolved'] = SiteAssembler::relink();
		}

		return $report;
	}

	/**
	 * Rebuild the header and footer blocks, if they are among the missing.
	 *
	 * @param string                                  $root   Design root.
	 * @param array<string, array{id:int,url:string}> $media  Imported media.
	 * @param array<string, true>                     $left   Slugs still wanted; repaired ones are removed.
	 * @param array<string, mixed>                    $report Running report, written to.
	 * @return void
	 */
	private static function repair_chrome( string $root, array $media, array &$left, array &$report ): void {
		$file = (string) get_option( SiteOptions::ORIGIN, '' );

		if ( '' === $file || ! is_readable( trailingslashit( $root ) . $file ) ) {
			return;
		}

		$split    = SectionSplitter::split( trailingslashit( $root ) . $file, $root );
		$page_dir = (string) dirname( $file );
		$styles   = CssIndex::from_directory( $root );

		foreach ( array( 'header', 'footer' ) as $area ) {
			$part = self::part_for( $area );

			if ( null === $part || ! str_contains( (string) $part->post_content, 'wp:qs/design-site-' . $area . '-' ) ) {
				continue;
			}

			$html = (string) ( $split[ $area ]['html'] ?? '' );

			if ( '' === trim( $html ) ) {
				continue;
			}

			/*
			 * The header's navigation is emptied before the section is read, so
			 * the digest that names the block is of the markup after that.
			 */
			$menu   = 'header' === $area;
			$source = $menu ? SiteAssembler::hollow_nav( $html ) : $html;
			$slug   = BlockWriter::chrome_slug( $area, $source );

			if ( '' === $slug ) {
				continue;
			}

			$plan   = SectionPlan::of( $source );
			$linked = SiteBuilder::relink_media( $source, $media, $page_dir );
			$linked = SiteBuilder::relink_css_urls( $linked, $media, $page_dir );

			if ( ! BlockWriter::current( BlockWriter::dir() . '/' . $slug ) ) {
				$written = BlockWriter::write(
					$linked,
					$plan,
					$slug,
					$menu ? __( 'Site header', 'qwerty-soft-signal' ) : __( 'Site footer', 'qwerty-soft-signal' ),
					SiteBuilder::relink_css_urls( $styles->rules_for( $source ), $media, $page_dir ),
					BlockWriter::dir() . '/' . $slug,
					$file,
					'option',
					$menu
				);

				if ( null === $written ) {
					continue;
				}

				++$report['written'];
			}

			/*
			 * The chrome's values live on the options page rather than on the
			 * placement, so a block rebuilt under a new name would find nothing
			 * to say. Seeding leaves anything already written alone: an
			 * afternoon spent rewriting the footer survives this.
			 */
			$values = BlockWriter::values( $source, $plan );
			$values = SiteAssembler::resolve_images( $values, $plan, $media, $page_dir );

			SiteOptions::seed( BlockWriter::option_values( $slug, $plan, $values ) );

			/*
			 * And the part is pointed at it. The chrome is the one place where
			 * the name is allowed to move: there is exactly one header, the
			 * digest changes whenever the code that reads it is corrected, and
			 * a part still naming yesterday's block is a site with no header.
			 */
			$markup = '<!-- wp:qs/design-' . $slug . ' /-->';

			if ( trim( (string) $part->post_content ) !== $markup ) {
				wp_update_post(
					array(
						'ID'           => $part->ID,
						'post_content' => wp_slash( $markup ),
					)
				);
			}

			foreach ( array_keys( $left ) as $one ) {
				if ( str_starts_with( (string) $one, 'site-' . $area . '-' ) ) {
					unset( $left[ $one ] );
				}
			}
		}
	}

	/**
	 * The template part this import made for one area.
	 *
	 * @param string $area `header` or `footer`.
	 * @return \WP_Post|null
	 */
	private static function part_for( string $area ): ?\WP_Post {
		$found = get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'name'           => $area,
				'meta_key'       => SiteAssembler::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping.
			)
		);

		return array() === $found ? null : $found[0];
	}

	/**
	 * Rebuild whichever of one page's blocks are missing.
	 *
	 * @param string                                   $root       Design root.
	 * @param string                                   $file       Page file, relative to the root.
	 * @param array<string, array<string, mixed>|null> $placements Field values by slug.
	 * @param array<string, array{id:int,url:string}>  $media      Imported media.
	 * @param array<string, true>                      $left       Slugs still wanted; repaired ones are removed.
	 * @param array<string, mixed>                     $report     Running report, written to.
	 * @return void
	 */
	private static function repair_page( string $root, string $file, array $placements, array $media, array &$left, array &$report ): void {
		$path = trailingslashit( $root ) . $file;

		if ( ! is_readable( $path ) ) {
			return;
		}

		$split    = SectionSplitter::split( $path, $root );
		$page_dir = (string) dirname( $file );
		$styles   = CssIndex::from_directory( $root );

		foreach ( (array) ( $split['sections'] ?? array() ) as $section ) {
			$html = (string) ( $section['html'] ?? '' );

			if ( '' === trim( $html ) ) {
				continue;
			}

			$slug = BlockWriter::section_slug( $file, (string) $section['label'], $html );

			if ( '' === $slug || ! isset( $left[ $slug ] ) ) {
				continue;
			}

			$plan   = self::named( SectionPlan::of( $html ), $html, $placements[ $slug ] ?? null );
			$linked = SiteBuilder::relink_media( $html, $media, $page_dir );
			$linked = SiteBuilder::relink_css_urls( $linked, $media, $page_dir );

			$written = BlockWriter::write(
				$linked,
				$plan,
				$slug,
				(string) $section['label'],
				SiteBuilder::relink_css_urls( $styles->rules_for( $html ), $media, $page_dir ),
				BlockWriter::dir() . '/' . $slug,
				$file,
				'block'
			);

			if ( null !== $written ) {
				unset( $left[ $slug ] );
				++$report['written'];
			}
		}
	}

	/**
	 * Put the placement's own field names back on the plan.
	 *
	 * `PlanReview` renames one for one and keeps every path and type, so the
	 * reading here is the same reading, in the same order — only the words
	 * differ. Taking the words from the placement is therefore exact, and it is
	 * what makes a repaired block draw the content that is already there rather
	 * than a section of empty fields.
	 *
	 * @param array<string, mixed>      $plan What the plan reads now.
	 * @param string                    $html The section's own markup.
	 * @param array<string, mixed>|null $data What the placement stored, if anything.
	 * @return array<string, mixed>
	 */
	private static function named( array $plan, string $html, ?array $data ): array {
		if ( null === $data || array() === $data ) {
			return $plan;
		}

		$top = array();
		$row = array();

		foreach ( array_keys( $data ) as $key ) {
			$key = (string) $key;

			// ACF writes a `_name` beside every value, holding the field key.
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^items_(\d+)_(.+)$/', $key, $found ) ) {
				if ( '0' === $found[1] ) {
					$row[ $found[2] ] = $data[ $key ];
				}

				continue;
			}

			// The repeater itself is the writer's, not the plan's.
			if ( 'items' !== $key ) {
				$top[ $key ] = $data[ $key ];
			}
		}

		$values = BlockWriter::values( $html, $plan );
		$rows   = isset( $values['items'] ) && is_array( $values['items'] ) ? $values['items'] : array();

		$plan['fields'] = self::rename( (array) ( $plan['fields'] ?? array() ), $values, $top );

		$item = $plan['item'] ?? null;

		if ( is_array( $item ) ) {
			$item['fields'] = self::rename( (array) ( $item['fields'] ?? array() ), (array) ( $rows[0] ?? array() ), $row );
			$plan['item']   = $item;

			/*
			 * And what the repeating part turned out to be. A placement that
			 * stored rows had a repeater; one that stored none had the model's
			 * word that the design's cards were the site's own records, or that
			 * they were furniture. Either way it did not write rows, and
			 * writing a repeater now would put an empty one on the page.
			 */
			$plan['kind'] = isset( $data['items'] ) ? 'repeat' : 'single';
		}

		return $plan;
	}

	/**
	 * Give one list of fields the names the placement stored for them.
	 *
	 * Matched on what each field says rather than on where it sits.
	 *
	 * Position alone was the obvious reading — `PlanReview` renames one for one
	 * and keeps the order — and it holds only while the code that reads a
	 * section reads it the same way it did on the day of the build. Correct a
	 * fault in that reading and every field after the correction takes its
	 * neighbour's name, which is worse than losing one: the page fills up with
	 * the wrong words, and nothing anywhere says so.
	 *
	 * What a field says does not move. Addresses do — the build rewrote them to
	 * permalinks — so a link is matched on its text.
	 *
	 * @param array<int, array<string, mixed>> $fields What the plan reads now.
	 * @param array<string, mixed>             $says   Those fields' values, from the markup.
	 * @param array<string, mixed>             $stored What the placement holds, by its own names.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rename( array $fields, array $says, array $stored ): array {
		$by_value = array();

		foreach ( $stored as $name => $value ) {
			$signature = self::signature( $value );

			if ( '' === $signature ) {
				continue;
			}

			// An answer given twice identifies nothing.
			$by_value[ $signature ] = isset( $by_value[ $signature ] ) ? '' : (string) $name;
		}

		$taken   = array();
		$unnamed = array();

		foreach ( $fields as $index => $field ) {
			$signature = self::signature( $says[ (string) ( $field['name'] ?? '' ) ] ?? null );
			$name      = '' === $signature ? '' : (string) ( $by_value[ $signature ] ?? '' );

			if ( '' === $name || isset( $taken[ $name ] ) ) {
				$unnamed[] = $index;
				continue;
			}

			$fields[ $index ]['name'] = $name;
			$taken[ $name ]           = true;
		}

		/*
		 * What is left over is matched by position, which is what the fields
		 * with nothing to say — an empty heading, a picture — have to fall back
		 * on. Both lists are still in the order the section was read in, so the
		 * two line up as long as the reading has not changed shape.
		 */
		$spare = array_values( array_diff( array_keys( $stored ), array_keys( $taken ) ) );

		foreach ( $unnamed as $offset => $index ) {
			if ( isset( $spare[ $offset ] ) ) {
				$fields[ $index ]['name'] = (string) $spare[ $offset ];
			}
		}

		return $fields;
	}

	/**
	 * What a field value says, reduced to something two readings can be compared on.
	 *
	 * @param mixed $value A stored value or one read from the markup.
	 * @return string
	 */
	private static function signature( $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['title'] ?? ( $value['alt'] ?? '' );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) ?? '' );
	}
	/**
	 * Every generated block the site refers to, with the values one placement stored.
	 *
	 * @return array<string, array<string, mixed>|null> Slug to field values.
	 */
	private static function placements(): array {
		global $wpdb;

		$found = array();

		$rows = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One scan of the content, run only when repairing.
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_status != 'trash'
			   AND post_type IN ( 'page', 'post', 'wp_template', 'wp_template_part', 'wp_block' )
			   AND post_content LIKE '%wp:qs/design-%'"
		);

		foreach ( $rows as $content ) {
			if ( 1 !== preg_match_all( '#<!--\s+wp:qs/design-([a-z0-9-]+)\s*(\{.*?\})?\s*/-->#s', (string) $content, $all, PREG_SET_ORDER ) && array() === $all ) {
				continue;
			}

			foreach ( $all as $one ) {
				$slug = (string) $one[1];
				$data = null;

				if ( isset( $one[2] ) && '' !== $one[2] ) {
					$attributes = json_decode( (string) $one[2], true );

					if ( is_array( $attributes ) && isset( $attributes['data'] ) && is_array( $attributes['data'] ) ) {
						$data = $attributes['data'];
					}
				}

				/*
				 * The richest placement wins. The same block on two pages can
				 * carry different content, and one of them may have been
				 * emptied by hand — the one that still holds every field is the
				 * one that names them all.
				 */
				if ( ! isset( $found[ $slug ] ) || count( (array) $data ) > count( (array) $found[ $slug ] ) ) {
					$found[ $slug ] = $data;
				}
			}
		}

		return $found;
	}

	/**
	 * The design files this site's pages were built from.
	 *
	 * @return array<int, string> Relative paths, each named once.
	 */
	private static function files(): array {
		global $wpdb;

		$found = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Import bookkeeping, read only when repairing.
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				SiteAssembler::OWNED_META,
				'%.html'
			)
		);

		return array_values( array_filter( array_map( 'strval', $found ) ) );
	}

	/**
	 * The unpacked design these pages came from.
	 *
	 * @return string Absolute path, or empty when it is no longer on disk.
	 */
	private static function root(): string {
		$base = DesignArchive::base_dir();

		if ( ! is_string( $base ) || ! is_dir( $base ) ) {
			return '';
		}

		$origin = (string) get_option( SiteOptions::ORIGIN, '' );
		$found  = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );

		if ( ! is_array( $found ) ) {
			return '';
		}

		foreach ( $found as $dir ) {
			if ( '' !== $origin && is_readable( trailingslashit( $dir ) . $origin ) ) {
				return rtrim( str_replace( '\\', '/', $dir ), '/' );
			}
		}

		// No origin recorded: one unpacked design is unambiguous, several are not.
		return 1 === count( $found ) ? rtrim( str_replace( '\\', '/', $found[0] ), '/' ) : '';
	}

	/**
	 * Attachment addresses by the design path each came from.
	 *
	 * @return array<string, array{id:int,url:string}>
	 */
	private static function media_map(): array {
		$map = array();

		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'meta_key'       => '_qwerty_soft_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping.
			)
		);

		foreach ( $found as $attachment ) {
			$rel = (string) get_post_meta( $attachment->ID, '_qwerty_soft_source', true );
			$url = wp_get_attachment_url( $attachment->ID );

			if ( '' !== $rel && is_string( $url ) ) {
				$map[ $rel ] = array(
					'id'  => (int) $attachment->ID,
					'url' => $url,
				);
			}
		}

		return $map;
	}

	/**
	 * A chrome file the design keeps on its own, when the page has none.
	 *
	 * @param string             $root  Design root.
	 * @param array<int, string> $names Base names to look for, in order.
	 * @return string Markup, or empty.
	 */
	private static function chrome_file( string $root, array $names ): string {
		$found = glob( trailingslashit( $root ) . '**/*.html' );
		$files = is_array( $found ) ? $found : array();

		foreach ( (array) glob( trailingslashit( $root ) . '*.html' ) as $one ) {
			$files[] = (string) $one;
		}

		foreach ( $names as $name ) {
			foreach ( $files as $file ) {
				if ( str_contains( strtolower( str_replace( array( '-', '_', '.' ), '', basename( (string) $file, '.html' ) ) ), $name ) ) {
					$raw = file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the unpacked design.

					if ( false !== $raw ) {
						return $raw;
					}
				}
			}
		}

		return '';
	}

	/**
	 * The header with its navigation emptied, exactly as the build read it.
	 *
	 * @param string $html Header markup.
	 * @return string
	 */
	private static function hollow( string $html ): string {
		return SiteAssembler::hollow_nav_of( $html );
	}
}
