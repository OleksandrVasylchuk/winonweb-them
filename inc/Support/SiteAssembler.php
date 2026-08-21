<?php
/**
 * Builds a whole site from an unpacked design.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One pass from an archive to a working site.
 *
 * Everything here is decided from the design rather than guessed: the pages
 * are its HTML files, the menu is its header's links, the front page is its
 * index. Nothing is invented, and nothing is destroyed — a run creates drafts
 * and leaves whatever was on the site alone unless it created it itself.
 */
final class SiteAssembler {

	/**
	 * Meta key marking content this importer created.
	 */
	public const OWNED_META = '_wow_signal_imported';

	/**
	 * Option holding the front-page settings as they were before an import.
	 */
	private const PREVIOUS_FRONT = 'wow_signal_import_previous_front';

	/**
	 * Option holding the site title and logo as they were before an import.
	 */
	private const PREVIOUS_IDENTITY = 'wow_signal_import_previous_identity';

	/**
	 * Option holding the page that becomes the front page once it is published.
	 */
	private const PENDING_FRONT = 'wow_signal_import_pending_front';

	/**
	 * Make a published page the front page, remembering what was there before.
	 *
	 * The stash is written once: a second run must not overwrite it with the
	 * first run's own front page.
	 *
	 * @param int $page Page ID.
	 * @return void
	 */
	private static function set_front_page( int $page ): void {
		if ( false === get_option( self::PREVIOUS_FRONT ) ) {
			update_option(
				self::PREVIOUS_FRONT,
				array(
					'show_on_front' => (string) get_option( 'show_on_front', 'posts' ),
					'page_on_front' => (int) get_option( 'page_on_front', 0 ),
				),
				false
			);
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		delete_option( self::PENDING_FRONT );
	}

	/**
	 * Build the site from one language of a design, in one call.
	 *
	 * A convenience over the four steps below, run back to back. The import
	 * screen drives the steps itself so a long design cannot outlive a PHP
	 * request; everything else — tests, WP-CLI, a one-off script — calls this
	 * and gets the same report the stepwise run ends with.
	 *
	 * @param string               $root    Design root directory.
	 * @param array<string, mixed> $index   DesignArchive::index() result.
	 * @param array<string, mixed> $options language, publish, includes, smart, refine, model, effort.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build( string $root, array $index, array $options = array() ) {
		$job = self::start( $root, $index, $options );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		foreach ( $job['pages'] as $page ) {
			// A page that will not convert is reported, not fatal; finish() says so if none did.
			self::page( $job, (string) $page['file'] );
		}

		self::chrome( $job );

		return self::finish( $job );
	}

	/**
	 * Step one: everything the pages depend on.
	 *
	 * Returns the job record the remaining steps are handed. It is plain data
	 * — no objects — so it can sit in user meta between requests: the pages
	 * still to build, the imported media map, the palette, and the report as
	 * far as it has got.
	 *
	 * @param string               $root    Design root directory.
	 * @param array<string, mixed> $index   DesignArchive::index() result.
	 * @param array<string, mixed> $options language, publish, includes, smart, refine, model, effort.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( string $root, array $index, array $options = array() ) {
		$language = isset( $options['language'] ) ? (string) $options['language'] : '';
		$publish  = ! empty( $options['publish'] );
		$includes = isset( $options['includes'] ) && is_array( $options['includes'] )
			? self::clean_includes( $options['includes'] )
			: array();

		$pages = self::pages_for( $index, $language );

		if ( array() === $pages ) {
			return new WP_Error(
				'wow_signal_no_pages',
				__( 'No pages were found in that language.', 'wow-signal' )
			);
		}

		/*
		 * The design's own colours and type come first, because everything
		 * built afterwards refers to palette slugs rather than literal values.
		 * Without this step every imported site would wear the theme's own
		 * look instead of the one in the archive.
		 */
		$tokens = DesignTokens::extract( $root );
		DesignTokens::apply( $tokens );

		$fonts = $tokens['fonts'];

		if ( class_exists( DesignFonts::class ) ) {
			$fonts = DesignFonts::import( $root );
		}

		// Pictures pasted into the markup become files before anything reads the pages.
		SiteBuilder::materialise_data_uris( $root );

		$media = SiteBuilder::import_media( $root );
		$media = is_wp_error( $media ) ? array() : $media;

		/*
		 * The design's own stylesheet, now that every picture it refers to
		 * has a Media Library URL to be repointed at. The converter keeps the
		 * design's class names on the blocks it builds; this is what those
		 * classes still mean.
		 */
		$stylesheet = array(
			'bytes'   => 0,
			'rules'   => 0,
			'dropped' => 0,
		);

		if ( class_exists( DesignStylesheet::class ) ) {
			$stylesheet = DesignStylesheet::import( $root, $media );
		}

		return array(
			'root'     => $root,
			'publish'  => $publish,
			'includes' => $includes,
			'pages'    => array_values( $pages ),
			'colors'   => $tokens['colors'],
			'media'    => $media,
			'routes'   => array(),

			/*
			 * Whether this build asks a model to correct each section, and
			 * whether it also asks for the rendered result to be reviewed.
			 * Both are off unless the build screen turned them on; a build
			 * that leaves them off never reaches the network at all.
			 */
			'smart'    => ! empty( $options['smart'] ) && SmartConverter::possible(),
			'refine'   => ! empty( $options['refine'] ),
			'model'    => isset( $options['model'] ) ? (string) $options['model'] : AnthropicClient::DEFAULT_MODEL,
			'effort'   => isset( $options['effort'] ) ? (string) $options['effort'] : 'high',
			'report'   => array(
				'pages'      => array(),
				'media'      => count( $media ),
				'menu'       => 0,
				'parts'      => array(),
				'front'      => 0,
				'palette'    => $tokens['colors'],
				'fonts'      => $fonts,
				'stylesheet' => $stylesheet,
				'concerns'   => self::palette_warnings( $tokens['colors'] ),
			),
		);
	}

	/**
	 * Step two, once per page: convert one design file and create its page.
	 *
	 * Safe to repeat. A file that already produced a page in this job has that
	 * page updated rather than a second one created, so a step retried after a
	 * dropped connection leaves no duplicates behind.
	 *
	 * @param array<string, mixed> $job  Job record from start(), updated in place.
	 * @param string               $file Page file, relative to the design root.
	 * @return array<string, mixed>|WP_Error The page row, or why it was skipped.
	 */
	public static function page( array &$job, string $file ) {
		$page = null;

		foreach ( (array) $job['pages'] as $candidate ) {
			if ( (string) $candidate['file'] === $file ) {
				$page = $candidate;
				break;
			}
		}

		if ( null === $page ) {
			return new WP_Error( 'wow_signal_no_page', __( 'That page is not part of this build.', 'wow-signal' ) );
		}

		$includes = isset( $job['includes'][ $file ] ) && is_array( $job['includes'][ $file ] )
			? $job['includes'][ $file ]
			: null;

		$existing = isset( $job['routes'][ $file ]['id'] ) ? (int) $job['routes'][ $file ]['id'] : 0;

		$smart = self::smart_converter( $job );

		$made = self::build_page(
			(string) $job['root'],
			$page,
			null !== $smart ? $smart->structural() : self::converter( $job ),
			(array) $job['media'],
			! empty( $job['publish'] ),
			$includes,
			$existing,
			$smart
		);

		if ( null !== $smart ) {
			self::record_calls( $job, $smart->calls() );
		}

		if ( is_wp_error( $made ) ) {
			$job['report']['concerns'][] = $file . ' — ' . $made->get_error_message();

			return $made;
		}

		$job['routes'][ $file ] = $made;

		return $made;
	}

	/**
	 * Add one page's model calls to the job's running tally.
	 *
	 * Kept on the job rather than billed as it goes, so a build that is
	 * abandoned half way leaves the same trail as one that finished, and the
	 * report at the end can state one figure for the whole thing.
	 *
	 * @param array<string, mixed>             $job   Job record, updated in place.
	 * @param array<int, array<string, mixed>> $calls Calls from SmartConverter.
	 * @return void
	 */
	private static function record_calls( array &$job, array $calls ): void {
		$tally = isset( $job['report']['ai'] ) && is_array( $job['report']['ai'] )
			? $job['report']['ai']
			: array(
				'calls'     => 0,
				'failed'    => 0,
				'discarded' => 0,
				'input'     => 0,
				'output'    => 0,
				'cost'      => 0.0,
				'notional'  => 0.0,
				'transport' => '',
				'errors'    => array(),
			);

		foreach ( $calls as $call ) {
			++$tally['calls'];

			if ( empty( $call['ok'] ) ) {
				++$tally['failed'];

				$error = (string) ( $call['error'] ?? '' );

				if ( '' !== $error && ! in_array( $error, $tally['errors'], true ) ) {
					$tally['errors'][] = $error;
				}

				continue;
			}

			if ( isset( $call['kept'] ) && false === $call['kept'] ) {
				++$tally['discarded'];
			}

			$usage = is_array( $call['usage'] ?? null ) ? $call['usage'] : array();
			$model = (string) ( $call['model'] ?? '' );

			$tally['transport'] = (string) ( $call['transport'] ?? $tally['transport'] );
			$tally['input']    += (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['cache_read_input_tokens'] ?? 0 ) + (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
			$tally['output']   += (int) ( $usage['output_tokens'] ?? 0 );

			// A subscription run through the CLI is billed to nobody; its figure is a comparison only.
			if ( ! ModelGateway::is_billable( (string) ( $call['transport'] ?? 'api' ) ) ) {
				$tally['notional'] += (float) ( $call['notional'] ?? 0.0 );
			} else {
				$tally['cost'] += Spend::cost( $usage, $model );
			}
		}

		$job['report']['ai'] = $tally;
	}

	/**
	 * The guided converter for this job, when the build asked for one.
	 *
	 * Null whenever the build did not ask, or when nothing on this machine can
	 * reach a model — in which case every page converts structurally, exactly
	 * as it did before this route existed.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return SmartConverter|null
	 */
	private static function smart_converter( array $job ): ?SmartConverter {
		if ( empty( $job['smart'] ) || ! SmartConverter::possible() ) {
			return null;
		}

		$root = (string) $job['root'];

		/*
		 * One index for both, not one each. Parsing a design's stylesheets is
		 * the expensive part of a page step, and the structural converter and
		 * the brief need exactly the same answers out of it.
		 */
		$css = CssIndex::from_directory( $root );

		$converter = new BlockConverter();
		$converter->use_design( DesignTokens::section_backgrounds( $root ), (array) $job['colors'], $css );

		return new SmartConverter(
			$root,
			$converter,
			$css,
			array(
				'model'  => (string) ( $job['model'] ?? AnthropicClient::DEFAULT_MODEL ),
				'effort' => (string) ( $job['effort'] ?? 'high' ),
				'refine' => ! empty( $job['refine'] ),
			)
		);
	}

	/**
	 * Step three: the menu and the header and footer template parts.
	 *
	 * @param array<string, mixed> $job Job record, updated in place.
	 * @return array{menu:int,parts:array<int,string>,parts_detail:array<int,array<string,mixed>>,menu_link:string}
	 */
	public static function chrome( array &$job ): array {
		$empty = array(
			'menu'         => 0,
			'parts'        => array(),
			'parts_detail' => array(),
			'menu_link'    => '',
		);

		// Nothing built means nothing to link a menu to; finish() reports it.
		if ( array() === $job['routes'] ) {
			return $empty;
		}

		$chrome = self::build_chrome(
			(string) $job['root'],
			(string) $job['pages'][0]['file'],
			(array) $job['routes'],
			self::converter( $job )
		);

		$job['report']['menu']         = $chrome['menu'];
		$job['report']['parts']        = $chrome['parts'];
		$job['report']['parts_detail'] = $chrome['parts_detail'];
		$job['report']['menu_link']    = $chrome['menu_link'];

		return $chrome;
	}

	/**
	 * Step four: make the links work, set the front page, hand back the report.
	 *
	 * @param array<string, mixed> $job Job record, updated in place.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function finish( array &$job ) {
		$routes = (array) $job['routes'];

		if ( array() === $routes ) {
			return new WP_Error( 'wow_signal_nothing_built', __( 'None of the pages could be converted.', 'wow-signal' ) );
		}

		$report = $job['report'];

		// Now that every page has a permalink, make the links between them work.
		$unresolved = self::relink_pages( $routes );

		foreach ( $unresolved as $target => $count ) {
			$report['concerns'][] = sprintf(
				/* translators: 1: link target, 2: how many links point at it. */
				_n(
					'%2$d link points at "%1$s", which is not a page on this site. Check the design or remove the link.',
					'%2$d links point at "%1$s", which is not a page on this site. Check the design or remove the links.',
					$count,
					'wow-signal'
				),
				$target,
				$count
			);
		}

		$front = self::front_page( $routes );

		if ( 0 !== $front ) {
			$report['front'] = $front;

			/*
			 * A draft cannot be the front page: visitors would get a 404 where
			 * the home used to be. Until it is published the choice is only
			 * remembered, and publish() applies it the moment it goes live.
			 */
			if ( 'publish' === get_post_status( $front ) ) {
				self::set_front_page( $front );
			} else {
				update_option( self::PENDING_FRONT, $front, false );
			}
		}

		/*
		 * Pages are listed in the order they were built, fresh from the
		 * database: a row made at step two does not know it was published or
		 * relinked since.
		 */
		$report['pages'] = array();

		foreach ( (array) $job['pages'] as $page ) {
			$file = (string) $page['file'];

			if ( ! isset( $routes[ $file ] ) ) {
				continue;
			}

			$report['pages'][] = self::page_row(
				(int) $routes[ $file ]['id'],
				$file,
				(int) $routes[ $file ]['sections'],
				(array) $routes[ $file ]['concerns'],
				(int) ( $routes[ $file ]['improved'] ?? 0 ),
				array_map( 'strval', (array) ( $routes[ $file ]['changed'] ?? array() ) )
			);
		}

		/*
		 * A guided build that could not reach the model for some sections
		 * still produced a site — from the structural conversion, which is
		 * what those sections fell back to. Saying so is the difference
		 * between a report that is true and one that is merely reassuring.
		 */
		$ai = isset( $report['ai'] ) && is_array( $report['ai'] ) ? $report['ai'] : array();

		if ( (int) ( $ai['failed'] ?? 0 ) > 0 ) {
			$report['concerns'][] = sprintf(
				/* translators: 1: how many attempts failed, 2: the reason given for the first of them. */
				_n(
					'%1$d section could not be sent to the model and was converted structurally instead: %2$s',
					'%1$d sections could not be sent to the model and were converted structurally instead: %2$s',
					(int) $ai['failed'],
					'wow-signal'
				),
				(int) $ai['failed'],
				(string) ( $ai['errors'][0] ?? '' )
			);
		}

		$job['report'] = $report;

		return $report;
	}

	/**
	 * Convert one page's sections without creating anything.
	 *
	 * The review screen's "preview before build": the same splitter, the same
	 * converter and the same media map the build uses, so what is shown is
	 * what a build would make, section for section.
	 *
	 * @param string                                  $root  Design root.
	 * @param string                                  $file  Page file, relative to the root.
	 * @param array<string, array{id:int,url:string}> $media Imported media map.
	 * @return array{title:string,sections:array<int,array<string,mixed>>,notes:array<int,string>}
	 */
	public static function preview( string $root, string $file, array $media ): array {
		$tokens    = DesignTokens::extract( $root );
		$converter = new BlockConverter();

		$converter->use_design( DesignTokens::section_backgrounds( $root ), $tokens['colors'], $root );

		$converted = self::convert_sections( $root, $file, $converter, $media, null );

		return array(
			'title'    => self::title_for( (string) $converted['split']['title'], $file, implode( "\n", array_column( $converted['sections'], 'markup' ) ) ),
			'sections' => $converted['sections'],
			'notes'    => self::splitter_notes( $converted['split'] ),
		);
	}

	/**
	 * A converter set up for this job's design.
	 *
	 * Built afresh for every step: the converter holds nothing between pages
	 * that a new instance would not derive again from the design, and an
	 * object cannot be stored in the job record anyway.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return BlockConverter
	 */
	private static function converter( array $job ): BlockConverter {
		$converter = new BlockConverter();

		$converter->use_design( DesignTokens::section_backgrounds( (string) $job['root'] ), (array) $job['colors'], (string) $job['root'] );

		return $converter;
	}

	/**
	 * Section choices from the browser, reduced to file => list of positions.
	 *
	 * @param array<mixed, mixed> $includes Raw request value.
	 * @return array<string, array<int, int>>
	 */
	private static function clean_includes( array $includes ): array {
		$clean = array();

		foreach ( $includes as $file => $positions ) {
			if ( ! is_string( $file ) || ! is_array( $positions ) ) {
				continue;
			}

			$clean[ $file ] = array_values(
				array_unique(
					array_map(
						'intval',
						array_filter( $positions, 'is_numeric' )
					)
				)
			);
		}

		return $clean;
	}

	/**
	 * Publish pages a build created, and only those.
	 *
	 * @param array<int, int> $ids Page IDs.
	 * @return array<int, array<string, mixed>> Rows for every ID that was ours.
	 */
	public static function publish( array $ids ): array {
		$rows = array();

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$post = get_post( $id );

			if ( null === $post || 'page' !== $post->post_type || '' === (string) get_post_meta( $id, self::OWNED_META, true ) ) {
				continue;
			}

			if ( 'publish' !== $post->post_status ) {
				wp_publish_post( $id );
			}

			$rows[] = self::page_row( $id, (string) get_post_meta( $id, self::OWNED_META, true ), 0, array() );
		}

		if ( array() !== $rows ) {
			self::refresh_menus();

			// The home page the build chose goes live with the page itself.
			$pending = (int) get_option( self::PENDING_FRONT, 0 );

			if ( $pending > 0 && 'publish' === get_post_status( $pending ) ) {
				self::set_front_page( $pending );
			}
		}

		return $rows;
	}

	/**
	 * One page of the build report, read from the database.
	 *
	 * @param int                $id       Page ID.
	 * @param string             $file     Source file.
	 * @param int                $sections Sections kept.
	 * @param array<int, string> $concerns Concerns raised while converting.
	 * @param int                $improved How many of those sections the model corrected.
	 * @param array<int, string> $changed  What it changed, one phrase each.
	 * @return array<string, mixed>
	 */
	private static function page_row( int $id, string $file, int $sections, array $concerns, int $improved = 0, array $changed = array() ): array {
		$status = (string) get_post_status( $id );
		$url    = (string) get_permalink( $id );

		return array(
			'id'        => $id,
			'file'      => $file,
			'title'     => (string) get_the_title( $id ),
			'slug'      => (string) get_post_field( 'post_name', $id ),
			'url'       => $url,
			'sections'  => $sections,
			'improved'  => $improved,
			'changed'   => $changed,
			'concerns'  => $concerns,
			'status'    => $status,
			'link'      => 'publish' === $status ? $url : (string) get_preview_post_link( $id ),
			'edit_link' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
		);
	}

	/**
	 * Say so when the design's own colours fail the contrast minimum.
	 *
	 * The design's values are used as they are — they are the client's brand,
	 * not ours to quietly repaint. But an editor deserves to know before
	 * launch that a pairing their design uses is unreadable, while it is still
	 * cheap to change in the Site Editor.
	 *
	 * @param array<string, string> $colors Palette.
	 * @return array<int, string>
	 */
	private static function palette_warnings( array $colors ): array {
		$pairs = array(
			array( 'contrast', 'base', 4.5, __( 'body text on the page background', 'wow-signal' ) ),
			array( 'muted', 'base', 4.5, __( 'secondary text on the page background', 'wow-signal' ) ),
			array( 'contrast', 'surface', 4.5, __( 'text on cards', 'wow-signal' ) ),
			array( 'border-strong', 'base', 3.0, __( 'form field borders', 'wow-signal' ) ),
		);

		$warnings = array();

		foreach ( $pairs as $pair ) {
			list( $front, $back, $minimum, $label ) = $pair;

			if ( ! isset( $colors[ $front ], $colors[ $back ] ) ) {
				continue;
			}

			$ratio = DesignTokens::contrast( $colors[ $front ], $colors[ $back ] );

			if ( $ratio >= $minimum ) {
				continue;
			}

			$warnings[] = sprintf(
				/* translators: 1: what the pair is used for, 2: measured ratio, 3: required ratio. */
				__( 'The design\'s colours give %1$s a contrast of %2$s:1, below the %3$s:1 that WCAG 2.2 AA asks for. Adjust it in Appearance → Editor → Styles before launch.', 'wow-signal' ),
				$label,
				number_format_i18n( $ratio, 2 ),
				number_format_i18n( $minimum, 1 )
			);
		}

		return $warnings;
	}

	/**
	 * The pages worth building, in a sensible order.
	 *
	 * @param array<string, mixed> $index    Index.
	 * @param string               $language Language directory, or empty for all.
	 * @return array<int, array<string, mixed>>
	 */
	private static function pages_for( array $index, string $language ): array {
		$pages = array();

		foreach ( (array) $index['pages'] as $page ) {
			if ( '' !== $language && ! self::in_language( (string) $page['file'], $language ) ) {
				continue;
			}

			/*
			 * The index's section count is a quick scan for the review screen,
			 * not the splitter's answer. Pages built out of plain divs report
			 * zero there and convert perfectly well, so the decision to skip
			 * belongs to build_page, which has actually looked.
			 */
			$pages[] = $page;
		}

		// The index page first: it becomes the front page and owns the h1.
		usort(
			$pages,
			static function ( array $a, array $b ): int {
				$rank = static fn( array $p ): int => self::is_index( (string) $p['file'] ) ? 0 : 1;

				return array( $rank( $a ), (string) $a['file'] ) <=> array( $rank( $b ), (string) $b['file'] );
			}
		);

		return $pages;
	}

	/**
	 * Whether a file sits in the given language directory.
	 *
	 * @param string $file     Relative path.
	 * @param string $language Language code.
	 * @return bool
	 */
	private static function in_language( string $file, string $language ): bool {
		return 1 === preg_match( '#(^|/)' . preg_quote( $language, '#' ) . '/#', $file );
	}

	/**
	 * Whether a file is a directory index.
	 *
	 * @param string $file Relative path.
	 * @return bool
	 */
	private static function is_index( string $file ): bool {
		return 1 === preg_match( '#(^|/)(index|home)\.[a-z.]*html$#i', $file );
	}

	/**
	 * Convert one design page and create it.
	 *
	 * @param string               $root      Design root.
	 * @param array<string, mixed> $page      Page record from the index.
	 * @param BlockConverter       $converter Converter.
	 * @param array<string, mixed> $media     Imported media map.
	 * @param bool                 $publish   Publish rather than draft.
	 * @param array<int, int>|null $includes  Section positions to keep, or null for all.
	 * @param int                  $existing  Page already made for this file, to update.
	 * @param SmartConverter|null  $smart     Guided converter, when the build asked for one.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function build_page( string $root, array $page, BlockConverter $converter, array $media, bool $publish, ?array $includes = null, int $existing = 0, ?SmartConverter $smart = null ) {
		$file      = (string) $page['file'];
		$converted = self::convert_sections( $root, $file, $converter, $media, $includes, $smart );
		$split     = $converted['split'];

		$markup   = array();
		$concerns = array();
		$changed  = array();
		$kept     = 0;
		$improved = 0;

		foreach ( $converted['sections'] as $section ) {
			if ( ! empty( $section['excluded'] ) ) {
				continue;
			}

			$concerns = array_merge( $concerns, (array) $section['concerns'] );

			if ( '' === (string) $section['markup'] ) {
				continue;
			}

			$markup[] = (string) $section['markup'];
			++$kept;

			if ( 'structural' !== (string) ( $section['source'] ?? 'structural' ) ) {
				++$improved;
			}

			$changed = array_merge( $changed, array_map( 'strval', (array) ( $section['changed'] ?? array() ) ) );
		}

		if ( array() === $markup ) {
			return new WP_Error( 'wow_signal_empty_page', __( 'Nothing on this page could be converted.', 'wow-signal' ) );
		}

		$concerns = array_merge( $concerns, self::splitter_notes( $split ) );

		$content = self::ensure_h1( implode( "\n\n", $markup ) );
		$title   = self::title_for( (string) $split['title'], $file, $content );

		// A page with no heading at all still needs one; its own title is it.
		if ( ! str_contains( $content, '<h1' ) ) {
			$content = self::title_band( $title ) . "\n\n" . $content;

			$concerns[] = __( 'This page had no heading of its own, so its title was added as the top heading.', 'wow-signal' );
		}

		$payload = array(
			'post_type'     => 'page',
			'post_status'   => $publish ? 'publish' : 'draft',
			'post_title'    => $title,
			'post_name'     => self::slug_for( $file ),

			/*
			 * Slashed on purpose: wp_insert_post() unslashes what it is
			 * given, and block attributes are JSON — an unslashed ™
			 * arrives as the literal "u2122" and a \" ends the attribute
			 * early. Every generated insert on this path does the same.
			 */
			'post_content'  => wp_slash( $content ),
			'page_template' => 'page-landing',
			'meta_input'    => array( self::OWNED_META => $file ),
		);

		/*
		 * A retried step updates the page it made the first time. The check
		 * on the meta is what stops a stale job record from overwriting a page
		 * somebody made by hand with the same ID after a reset.
		 */
		if ( $existing > 0 && (string) get_post_meta( $existing, self::OWNED_META, true ) === $file ) {
			$payload['ID'] = $existing;
			$id            = wp_update_post( $payload, true );
		} else {
			$id = wp_insert_post( $payload, true );
		}

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array(
			'id'       => (int) $id,
			'file'     => $file,
			'title'    => $title,
			'slug'     => get_post_field( 'post_name', (int) $id ),
			'url'      => (string) get_permalink( (int) $id ),
			'sections' => $kept,
			'improved' => $improved,
			'changed'  => array_values( array_unique( $changed ) ),
			'concerns' => array_values( array_unique( $concerns ) ),
		);
	}

	/**
	 * Split a page and convert every section, keeping the outcome of each.
	 *
	 * One routine for the build and the preview, so the two cannot disagree:
	 * a section the preview shows is the section the build keeps, converted
	 * by the same code against the same images.
	 *
	 * @param string                                  $root      Design root.
	 * @param string                                  $file      Page file, relative to the root.
	 * @param BlockConverter                          $converter Converter.
	 * @param array<string, array{id:int,url:string}> $media     Imported media map.
	 * @param array<int, int>|null                    $includes  Positions to keep, or null for all.
	 * @param SmartConverter|null                     $smart     Guided converter, when the build asked for one.
	 * @return array{split:array<string,mixed>,sections:array<int,array<string,mixed>>}
	 */
	private static function convert_sections( string $root, string $file, BlockConverter $converter, array $media, ?array $includes, ?SmartConverter $smart = null ) {
		$split    = SectionSplitter::split( trailingslashit( $root ) . $file );
		$page_dir = (string) dirname( $file );
		$sections = array();

		if ( null !== $smart ) {
			$smart->for_page( $file );
		}

		foreach ( $split['sections'] as $offset => $section ) {
			$position = (int) $section['position'];
			$excluded = null !== $includes && ! in_array( $position, $includes, true );

			$row = array(
				'position' => $position,
				'label'    => (string) $section['label'],
				'html'     => (string) $section['html'],
				'markup'   => '',
				'concerns' => array(),
				'excluded' => $excluded,
				'source'   => 'structural',
				'changed'  => array(),
			);

			if ( $excluded ) {
				$sections[] = $row;
				continue;
			}

			$result = null !== $smart
				? $smart->convert(
					$section,
					0 === $offset,
					array(
						'page' => (string) $split['title'],
						'lang' => (string) $split['lang'],
					)
				)
				: $converter->convert( $section, 0 === $offset );

			$row['source']  = (string) ( $result['source'] ?? 'structural' );
			$row['changed'] = array_map( 'strval', (array) ( $result['changed'] ?? array() ) );

			if ( '' === trim( $result['markup'] ) ) {
				$sections[] = $row;
				continue;
			}

			$validator = new BlockMarkupValidator();

			if ( ! $validator->check( $result['markup'] ) ) {
				$row['concerns'][] = sprintf(
					/* translators: 1: section label, 2: reason. */
					__( 'Section "%1$s" was left out: %2$s', 'wow-signal' ),
					(string) $section['label'],
					implode( ' ', $validator->errors() )
				);

				$sections[] = $row;
				continue;
			}

			$row['markup']   = SiteBuilder::relink_media( $result['markup'], $media, $page_dir );
			$row['concerns'] = array_values( array_map( 'strval', (array) $result['concerns'] ) );

			$sections[] = $row;
		}

		return array(
			'split'    => $split,
			'sections' => $sections,
		);
	}

	/**
	 * Anything the splitter wants the editor to know about a page.
	 *
	 * Newer splitters report what they expanded, where the design left a
	 * placeholder, and general notes. None of these keys has to be present;
	 * whichever are become lines in the report.
	 *
	 * @param array<string, mixed> $split SectionSplitter::split() result.
	 * @return array<int, string>
	 */
	private static function splitter_notes( array $split ): array {
		$notes = array();

		// Free-text notes the splitter already phrased for the editor.
		foreach ( (array) ( $split['notes'] ?? array() ) as $note ) {
			if ( is_string( $note ) && '' !== trim( $note ) ) {
				$notes[] = $note;
			}
		}

		// Placeholders: a map of visual name => screenshot path.
		$placeholders = (array) ( $split['placeholders'] ?? array() );

		if ( array() !== $placeholders ) {
			$pairs = array();

			foreach ( $placeholders as $name => $path ) {
				$pairs[] = is_string( $path ) && '' !== $path ? (string) $name . ' → ' . $path : (string) $name;
			}

			$notes[] = sprintf(
				/* translators: %s: comma-separated list of "visual → screenshot" pairs. */
				__( 'JS-rendered visuals were stood in for by screenshots from the design: %s', 'wow-signal' ),
				implode( ', ', $pairs )
			);
		}

		// Expansion stats: { loops, items, unresolved[] }.
		$expanded = (array) ( $split['expanded'] ?? array() );
		$loops    = (int) ( $expanded['loops'] ?? 0 );
		$items    = (int) ( $expanded['items'] ?? 0 );

		if ( $loops > 0 ) {
			$notes[] = sprintf(
				/* translators: 1: number of lists, 2: number of items. */
				_n(
					'%1$d list was filled from the design’s own data (%2$d items).',
					'%1$d lists were filled from the design’s own data (%2$d items).',
					$loops,
					'wow-signal'
				),
				$loops,
				$items
			);
		}

		$unresolved = array_values( array_filter( (array) ( $expanded['unresolved'] ?? array() ), 'is_string' ) );

		if ( array() !== $unresolved ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated list of template variable names. */
				__( 'The design left placeholders to fill in: %s', 'wow-signal' ),
				implode( ', ', $unresolved )
			);
		}

		return $notes;
	}

	/**
	 * A page title a person would recognise.
	 *
	 * Component exports often have no `<title>` at all, and a page called
	 * "About.dc.html" in the menu helps nobody. Fall back to the page's own
	 * first heading, then to the file name made readable.
	 *
	 * @param string $title   Title from the document, if any.
	 * @param string $file    Relative path.
	 * @param string $content Converted block markup.
	 * @return string
	 */
	private static function title_for( string $title, string $file, string $content ): string {
		$title = trim( $title );
		$stem  = (string) preg_replace( '/\.[a-z.]*html$/i', '', basename( $file ) );

		$looks_like_a_file = '' === $title
			|| strtolower( $title ) === strtolower( basename( $file ) )
			|| 1 === preg_match( '/\.html?$/i', $title );

		if ( ! $looks_like_a_file ) {
			return $title;
		}

		if ( 1 === preg_match( '#<h[12][^>]*>(.+?)</h[12]>#is', $content, $heading ) ) {
			$text = trim( wp_strip_all_tags( $heading[1] ) );

			if ( '' !== $text && mb_strlen( $text ) <= 90 ) {
				return $text;
			}
		}

		// "SecurityPatchManagement" and "schedule-call" both become readable.
		$readable = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', ' ', $stem );
		$readable = trim( (string) preg_replace( '/[-_]+/', ' ', $readable ) );

		return '' === $readable ? $stem : ucfirst( $readable );
	}

	/**
	 * An opening band carrying nothing but the page's title.
	 *
	 * Kept as a band rather than a bare heading so it sits inside the same
	 * padded, full-width rhythm as every other section on the page.
	 *
	 * @param string $title Page title.
	 * @return string
	 */
	private static function title_band( string $title ): string {
		return "<!-- wp:group {\"tagName\":\"section\",\"metadata\":{\"name\":\"Page title\"},\"align\":\"full\",\"backgroundColor\":\"base\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|90\",\"bottom\":\"var:preset|spacing|70\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<section class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--90);padding-bottom:var(--wp--preset--spacing--70)">'
			. "<!-- wp:heading {\"level\":1,\"fontSize\":\"display\"} -->\n"
			. '<h1 class="wp-block-heading has-display-font-size">' . esc_html( $title ) . '</h1>'
			. "\n<!-- /wp:heading -->"
			. "</section>\n<!-- /wp:group -->";
	}

	/**
	 * Make sure the page has exactly one first-level heading.
	 *
	 * A design page whose opening section leads with an h2 leaves the page
	 * with no h1 at all, which is both a search-engine and a screen-reader
	 * problem. Promote the first heading rather than inventing one.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	private static function ensure_h1( string $content ): string {
		if ( str_contains( $content, '<h1' ) ) {
			return $content;
		}

		$done = false;

		return (string) preg_replace_callback(
			'#<!-- wp:heading (\{.*?\}) -->\s*<h([2-6])([^>]*)>(.*?)</h\2>\s*<!-- /wp:heading -->#s',
			static function ( array $heading ) use ( &$done ): string {
				if ( $done ) {
					return $heading[0];
				}

				$done  = true;
				$attrs = json_decode( $heading[1], true );
				$attrs = is_array( $attrs ) ? $attrs : array();

				$attrs['level']    = 1;
				$attrs['fontSize'] = 'display';

				$class = 'wp-block-heading has-display-font-size';

				return '<!-- wp:heading ' . wp_json_encode( $attrs ) . " -->\n"
					. '<h1 class="' . $class . '">' . $heading[4] . '</h1>'
					. "\n<!-- /wp:heading -->";
			},
			$content,
			1
		);
	}

	/**
	 * A permalink slug for a design file.
	 *
	 * @param string $file Relative path.
	 * @return string
	 */
	private static function slug_for( string $file ): string {
		if ( self::is_index( $file ) ) {
			return 'home';
		}

		$name = preg_replace( '/\.[a-z.]*html$/i', '', basename( $file ) ) ?? $file;

		return sanitize_title( $name );
	}

	/**
	 * Rewrite links between the pages that were just created.
	 *
	 * A design links page to page by file name. Left alone those become 404s
	 * the moment the site is live, and nothing looks more broken than a
	 * navigation that does not navigate.
	 *
	 * @param array<string, array<string, mixed>> $routes File to created page.
	 * @return array<string, int> Targets that matched no page, and how often.
	 */
	private static function relink_pages( array $routes ): array {
		$lookup     = array();
		$unresolved = array();

		foreach ( $routes as $file => $made ) {
			$lookup[ strtolower( basename( (string) $file ) ) ] = (string) $made['url'];
		}

		foreach ( $routes as $made ) {
			$post = get_post( (int) $made['id'] );

			if ( null === $post ) {
				continue;
			}

			$content = self::relink_markup( (string) $post->post_content, $lookup, $unresolved );

			if ( $content !== $post->post_content ) {
				wp_update_post(
					array(
						'ID'           => (int) $made['id'],
						'post_content' => wp_slash( $content ),
					)
				);
			}
		}

		return $unresolved;
	}

	/**
	 * The page that should be the front page.
	 *
	 * @param array<string, array<string, mixed>> $routes Created pages.
	 * @return int
	 */
	private static function front_page( array $routes ): int {
		foreach ( $routes as $file => $made ) {
			if ( self::is_index( (string) $file ) ) {
				return (int) $made['id'];
			}
		}

		/*
		 * No index.html. A design exported component by component names its
		 * homepage things like "SecTeer Homepage.html", so look for the word,
		 * and failing that take the richest page — a homepage is almost always
		 * the one with the most sections.
		 */
		foreach ( $routes as $file => $made ) {
			if ( 1 === preg_match( '/\b(home|homepage|main|landing|start)\b/i', str_replace( array( '-', '_' ), ' ', basename( (string) $file ) ) ) ) {
				return (int) $made['id'];
			}
		}

		$best  = 0;
		$score = 0;

		foreach ( $routes as $made ) {
			if ( (int) $made['sections'] > $score ) {
				$score = (int) $made['sections'];
				$best  = (int) $made['id'];
			}
		}

		return $score >= 3 ? $best : 0;
	}

	/**
	 * Build the menu and the header and footer template parts.
	 *
	 * @param string                              $root      Design root.
	 * @param string                              $file      A page to read the chrome from.
	 * @param array<string, array<string, mixed>> $routes Created pages.
	 * @param BlockConverter|null                 $converter Converter primed with the design; the footer is converted with it.
	 */
	private static function build_chrome( string $root, string $file, array $routes, ?BlockConverter $converter = null ): array {
		$split = SectionSplitter::split( trailingslashit( $root ) . $file, $root );
		$menu  = 0;
		$parts = array();

		$header_html = (string) ( $split['header']['html'] ?? '' );
		$links       = self::nav_links( $header_html, $routes );

		/*
		 * Some exports keep the navigation in its own file rather than in
		 * every page's header. Look for it by name before giving up on having
		 * a menu at all.
		 */
		if ( array() === $links ) {
			$header_html = self::chrome_file( $root, array( 'sitenav', 'siteheader', 'nav', 'header', 'menu' ) );
			$links       = self::nav_links( $header_html, $routes );
		}

		if ( array() !== $links ) {
			$menu = self::create_menu( $links );
		}

		$identity = self::identity( $header_html, $root, (string) dirname( $file ) );

		if ( 0 !== $menu ) {
			$parts[] = self::write_part( 'header', self::header_markup( $menu, $identity['logo'] > 0 ) );
		}

		$footer_html = (string) ( $split['footer']['html'] ?? '' );

		if ( '' === trim( $footer_html ) ) {
			$footer_html = self::chrome_file( $root, array( 'sitefooter', 'footer' ) );
		}

		/*
		 * The footer is converted like any section, so its columns, brand
		 * block and small print come through as the design drew them. Only
		 * when nothing converts does the plain link list stand in.
		 */
		$footer = '';

		if ( null !== $converter && is_array( $split['footer'] ?? null ) ) {
			$footer = self::footer_from_design( $split['footer'], $routes, $converter );
		}

		if ( '' === $footer ) {
			$footer = self::footer_markup( $footer_html, $routes );
		}

		if ( '' !== $footer ) {
			$parts[] = self::write_part( 'footer', $footer );
		}

		$parts  = array_values( array_filter( $parts ) );
		$detail = array();

		foreach ( $parts as $area ) {
			$detail[] = array(
				'area'      => $area,
				'title'     => 'header' === $area ? __( 'Header', 'wow-signal' ) : __( 'Footer', 'wow-signal' ),
				'edit_link' => admin_url( 'site-editor.php?postType=wp_template_part&postId=' . rawurlencode( get_stylesheet() . '//' . $area ) ),
			);
		}

		return array(
			'menu'         => $menu,
			'parts'        => $parts,
			'parts_detail' => $detail,
			'menu_link'    => 0 !== $menu ? admin_url( 'site-editor.php?postType=wp_navigation&postId=' . $menu ) : '',
		);
	}

	/**
	 * Navigation links from the design's header, mapped to real pages.
	 *
	 * @param string                              $html   Header markup.
	 * @param array<string, array<string, mixed>> $routes Created pages.
	 * @return array<int, array{label:string,url:string,id:int}>
	 */
	private static function nav_links( string $html, array $routes ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$lookup = array();

		foreach ( $routes as $file => $made ) {
			$lookup[ strtolower( basename( (string) $file ) ) ] = $made;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );
		$links = array();
		$seen  = array();

		foreach ( $xpath->query( '//a' ) as $anchor ) {
			if ( ! $anchor instanceof DOMElement ) {
				continue;
			}

			$label = self::link_label( $anchor );
			$href  = trim( $anchor->getAttribute( 'href' ) );

			if ( '' === $label || '' === $href || mb_strlen( $label ) > 40 ) {
				continue;
			}

			$pieces   = explode( '#', $href, 2 );
			$target   = strtolower( basename( $pieces[0] ) );
			$fragment = isset( $pieces[1] ) ? sanitize_title( $pieces[1] ) : '';

			if ( ! isset( $lookup[ $target ] ) ) {
				continue;
			}

			$made = $lookup[ $target ];

			/*
			 * The logo links home and the header already renders the site
			 * title beside the menu, so keeping it would show the site's name
			 * twice in a row.
			 */
			if ( self::is_index( (string) $made['file'] ) && self::is_brand( $anchor ) ) {
				continue;
			}

			// Two anchors into the same page, such as its proof and newsletter sections, are two menu entries.
			$key = $made['id'] . '#' . $fragment;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$links[] = array(
				'label' => $label,
				'url'   => (string) $made['url'] . ( '' !== $fragment ? '#' . $fragment : '' ),
				'id'    => (int) $made['id'],
			);
		}

		return $links;
	}

	/**
	 * Read a stand-alone chrome file, if the design shipped one.
	 *
	 * @param string             $root  Design root.
	 * @param array<int, string> $hints File-name fragments to look for.
	 * @return string Raw markup, or an empty string.
	 */
	private static function chrome_file( string $root, array $hints ): string {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $candidate ) {
			if ( ! $candidate->isFile() || 1 !== preg_match( '/\.html?$/i', $candidate->getFilename() ) ) {
				continue;
			}

			$name = strtolower( str_replace( array( '-', '_', ' ' ), '', $candidate->getFilename() ) );

			foreach ( $hints as $hint ) {
				if ( str_starts_with( $name, $hint ) || str_contains( $name, $hint . '.' ) ) {
					return (string) file_get_contents( $candidate->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
				}
			}
		}

		return '';
	}

	/**
	 * A menu label read the way a person would say it.
	 *
	 * A logo mark is often two elements — "RK" beside "Robert Khoubian" — and
	 * concatenating them gives "RKRobert Khoubian". Separating on element
	 * boundaries and collapsing the whitespace fixes that everywhere without
	 * knowing anything about this particular design.
	 *
	 * @param DOMElement $anchor Anchor.
	 * @return string
	 */
	private static function link_label( DOMElement $anchor ): string {
		$parts = array();

		foreach ( $anchor->childNodes as $child ) {
			$text = trim( (string) $child->textContent );

			if ( '' !== $text ) {
				$parts[] = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
			}
		}

		if ( array() === $parts ) {
			return trim( (string) preg_replace( '/\s+/u', ' ', (string) $anchor->textContent ) );
		}

		/*
		 * A menu entry that is a name followed by a sentence is a mega-menu
		 * item: "NIS2 Guides" plus "Practical NIS2 guidance". Joining them
		 * gives an unreadable menu, so keep the name and drop the blurb.
		 */
		if ( count( $parts ) > 1 && mb_strlen( $parts[0] ) <= 28 ) {
			return $parts[0];
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
	}

	/**
	 * Whether an anchor is the site's logo rather than a menu entry.
	 *
	 * @param DOMElement $anchor Anchor.
	 * @return bool
	 */
	private static function is_brand( DOMElement $anchor ): bool {
		$class = strtolower( $anchor->getAttribute( 'class' ) . ' ' . $anchor->getAttribute( 'id' ) );

		foreach ( array( 'brand', 'logo', 'wordmark', 'site-title', 'home-link' ) as $hint ) {
			if ( str_contains( $class, $hint ) ) {
				return true;
			}
		}

		return $anchor->getElementsByTagName( 'img' )->length > 0
			|| $anchor->getElementsByTagName( 'svg' )->length > 0;
	}

	/**
	 * Create a navigation menu holding the given links.
	 *
	 * @param array<int, array{label:string,url:string,id:int}> $links Links.
	 * @return int Navigation post ID.
	 */
	private static function create_menu( array $links ): int {
		$items = array();

		foreach ( $links as $link ) {
			$items[] = self::menu_item( (int) $link['id'], (string) $link['label'], (string) $link['url'] );
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => __( 'Main navigation', 'wow-signal' ),
				'post_content' => wp_slash( implode( "\n\n", $items ) ),
				'meta_input'   => array( self::OWNED_META => 'navigation' ),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * One menu entry, written so it renders whatever the page's status.
	 *
	 * Core's navigation-link block draws nothing for a page that is still a
	 * draft, and a build makes drafts by default — so a freshly built site
	 * showed an empty menu. A draft is linked as a custom URL instead, and
	 * refresh_menus() upgrades it to a page link once it is published.
	 *
	 * @param int    $id    Page ID.
	 * @param string $label Menu label.
	 * @param string $url   Page URL.
	 * @return string
	 */
	private static function menu_item( int $id, string $label, string $url ): string {
		$published = 'publish' === get_post_status( $id );

		$attrs = $published
			? array(
				'label' => $label,
				'type'  => 'page',
				'id'    => $id,
				'url'   => $url,
				'kind'  => 'post-type',
			)
			: array(
				'label' => $label,
				'url'   => $url,
				'kind'  => 'custom',
			);

		return '<!-- wp:navigation-link ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * Point menu entries at their pages once those pages are published.
	 *
	 * @return int Entries upgraded.
	 */
	public static function refresh_menus(): int {
		$urls = array();

		foreach ( self::owned_posts( array( 'page' ) ) as $page ) {
			if ( 'publish' === $page->post_status ) {
				$urls[ (string) get_permalink( $page ) ]               = (int) $page->ID;
				$urls[ (string) home_url( '/?page_id=' . $page->ID ) ] = (int) $page->ID;
			}
		}

		$upgraded = 0;

		foreach ( self::owned_posts( array( 'wp_navigation' ) ) as $menu ) {
			$blocks  = parse_blocks( (string) $menu->post_content );
			$changed = false;

			foreach ( $blocks as &$block ) {
				if ( 'core/navigation-link' !== ( $block['blockName'] ?? '' ) || 'custom' !== ( $block['attrs']['kind'] ?? '' ) ) {
					continue;
				}

				$url = (string) ( $block['attrs']['url'] ?? '' );

				if ( ! isset( $urls[ $url ] ) ) {
					continue;
				}

				$block['attrs'] = array(
					'label' => (string) ( $block['attrs']['label'] ?? '' ),
					'type'  => 'page',
					'id'    => $urls[ $url ],
					'url'   => (string) get_permalink( $urls[ $url ] ),
					'kind'  => 'post-type',
				);

				$changed = true;
				++$upgraded;
			}

			unset( $block );

			if ( $changed ) {
				wp_update_post(
					array(
						'ID'           => $menu->ID,
						'post_content' => wp_slash( serialize_blocks( $blocks ) ),
					)
				);
			}
		}

		return $upgraded;
	}

	/**
	 * Take the site's name and logo from the design's header.
	 *
	 * A freshly installed site is called after its folder or database, and
	 * that name would otherwise sit in the header of the imported design. The
	 * header's brand link (or the first logo image) is the name the designer
	 * meant. An owner who has already named the site is left alone; what was
	 * there before is stashed once so reset() can put it back.
	 *
	 * @param string $header_html Header markup from the design.
	 * @param string $root        Design root.
	 * @param string $page_dir    Directory of the page the header came from.
	 * @return array{logo:int,name:string}
	 */
	private static function identity( string $header_html, string $root, string $page_dir ): array {
		$result = array(
			'logo' => 0,
			'name' => '',
		);

		if ( '' === trim( $header_html ) ) {
			return $result;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $header_html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );
		$name  = '';
		$logo  = '';

		foreach ( $xpath->query( '//a' ) as $anchor ) {
			if ( $anchor instanceof DOMElement && self::is_brand( $anchor ) ) {
				/*
				 * A brand is often a monogram beside the name — "RK" and
				 * "Robert Khoubian". The longest piece is the name; the
				 * aria-label, when the designer wrote one, is better still.
				 */
				$name = '';

				foreach ( $anchor->childNodes as $piece ) {
					$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $piece->textContent ) );

					if ( mb_strlen( $text ) > mb_strlen( $name ) ) {
						$name = $text;
					}
				}

				$aria = trim( (string) preg_replace( '/\s+(home|homepage|start)$/i', '', $anchor->getAttribute( 'aria-label' ) ) );

				if ( '' !== $aria && mb_strlen( $aria ) <= 60 ) {
					$name = $aria;
				}

				$img  = $anchor->getElementsByTagName( 'img' )->item( 0 );
				$logo = $img instanceof DOMElement ? trim( $img->getAttribute( 'src' ) ) : '';

				// A brand link that is only a picture names the site in the picture's alt.
				if ( '' === $name && $img instanceof DOMElement ) {
					$name = trim( $img->getAttribute( 'alt' ) );
				}
				break;
			}
		}

		if ( '' === $logo ) {
			$img  = $xpath->query( '//img' )->item( 0 );
			$logo = $img instanceof DOMElement ? trim( $img->getAttribute( 'src' ) ) : '';

			if ( '' === $name && $img instanceof DOMElement ) {
				$name = trim( $img->getAttribute( 'alt' ) );
			}
		}

		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );

		if ( mb_strlen( $name ) > 60 ) {
			$name = '';
		}

		$logo_id = 0;

		if ( '' !== $logo && 1 !== preg_match( '#^(https?:)?//#i', $logo ) && ! str_starts_with( $logo, 'data:' ) ) {
			$map = SiteBuilder::import_media( $root );

			if ( ! is_wp_error( $map ) ) {
				$linked = SiteBuilder::relink_media( '<img src="' . esc_attr( $logo ) . '">', $map, $page_dir );

				if ( 1 === preg_match( '/src="([^"]+)"/', $linked, $found ) ) {
					$logo_id = (int) attachment_url_to_postid( html_entity_decode( $found[1] ) );
				}
			}
		}

		$blogname  = (string) get_option( 'blogname' );
		$untouched = in_array(
			strtolower( $blogname ),
			array_map(
				'strtolower',
				array(
					defined( 'DB_NAME' ) ? (string) DB_NAME : '',
					basename( untrailingslashit( ABSPATH ) ),
					basename( untrailingslashit( (string) home_url() ) ),
					'wordpress',
					'my wordpress',
					'site title',
					'',
				)
			),
			true
		);

		if ( false === get_option( self::PREVIOUS_IDENTITY ) ) {
			update_option(
				self::PREVIOUS_IDENTITY,
				array(
					'blogname'    => $blogname,
					'custom_logo' => (int) get_theme_mod( 'custom_logo', 0 ),
				),
				false
			);
		}

		if ( '' !== $name && $untouched ) {
			update_option( 'blogname', $name );
			$result['name'] = $name;
		}

		if ( $logo_id > 0 ) {
			set_theme_mod( 'custom_logo', $logo_id );
			$result['logo'] = $logo_id;
		}

		return $result;
	}

	/**
	 * Header markup pointing at the created menu.
	 *
	 * @param int  $menu     Navigation post ID.
	 * @param bool $has_logo Whether the design supplied a logo image.
	 * @return string
	 */
	private static function header_markup( int $menu, bool $has_logo = false ): string {
		$brand = $has_logo
			? "<!-- wp:site-logo {\"width\":160,\"shouldSyncIcon\":false} /-->\n\n"
			: "<!-- wp:site-title {\"level\":0,\"fontSize\":\"medium\"} /-->\n\n";

		return "<!-- wp:group {\"tagName\":\"div\",\"className\":\"wow-header\",\"align\":\"full\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|30\",\"bottom\":\"var:preset|spacing|30\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<div class="wp-block-group wow-header alignfull" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">'
			. "<!-- wp:group {\"align\":\"wide\",\"layout\":{\"type\":\"flex\",\"justifyContent\":\"space-between\",\"flexWrap\":\"wrap\"}} -->\n"
			. '<div class="wp-block-group alignwide">'
			. $brand
			. '<!-- wp:navigation {"ref":' . $menu . ',"overlayMenu":"mobile","fontSize":"small"} /-->'
			. "</div>\n<!-- /wp:group -->"
			. "</div>\n<!-- /wp:group -->";
	}

	/**
	 * The design's footer, converted like any section and relinked.
	 *
	 * @param array<string, mixed>                $section   Footer section from SectionSplitter.
	 * @param array<string, array<string, mixed>> $routes    Created pages.
	 * @param BlockConverter                      $converter Converter primed with the design.
	 * @return string Block markup, or an empty string when nothing converts.
	 */
	private static function footer_from_design( array $section, array $routes, BlockConverter $converter ): string {
		$result = $converter->convert( $section, false );
		$markup = trim( (string) ( $result['markup'] ?? '' ) );

		if ( '' === $markup ) {
			return '';
		}

		$validator = new BlockMarkupValidator();

		if ( ! $validator->check( $markup ) ) {
			return '';
		}

		$lookup = array();

		foreach ( $routes as $file => $made ) {
			$lookup[ strtolower( basename( (string) $file ) ) ] = (string) $made['url'];
		}

		$unresolved = array();

		return self::dated( self::relink_markup( $markup, $lookup, $unresolved ) );
	}

	/**
	 * Swap the design's frozen copyright line for one that keeps its year.
	 *
	 * A design is exported in a particular January and says so: "© 2026 Fixture
	 * Co", written into the footer as literal text. Converted faithfully, every
	 * site built from that archive is wrong from the next New Year onwards, and
	 * nobody notices for eleven months.
	 *
	 * The theme has a block for exactly this. Swapping the paragraph for it
	 * keeps what the line says — the year comes from the site's own clock and
	 * timezone, the name from the site title, which the import has already set
	 * from the design — and stops it going stale. A footer whose design never
	 * had a copyright line gets the block appended instead, because the year is
	 * the part that rots and every footer should have one.
	 *
	 * @param string $markup Converted footer markup.
	 * @return string
	 */
	private static function dated( string $markup ): string {
		if ( str_contains( $markup, 'wp:wow/colophon' ) ) {
			return $markup;
		}

		/*
		 * Parsed, not matched. A regular expression over block markup has to
		 * find where one block's attributes end, and a lazy `{.*?}` will
		 * happily run from an early block to a later one and swallow
		 * everything between — which is how the first attempt at this quietly
		 * deleted half a footer. WordPress ships the parser that answers the
		 * question properly.
		 */
		$blocks = parse_blocks( $markup );

		if ( self::swap_copyright( $blocks ) ) {
			return serialize_blocks( $blocks );
		}

		// No copyright line in the design: give the footer one that cannot go stale.
		self::append_colophon( $blocks );

		return serialize_blocks( $blocks );
	}

	/**
	 * Replace the first frozen copyright paragraph with the colophon block.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks, edited in place.
	 * @return bool Whether one was found and replaced.
	 */
	private static function swap_copyright( array &$blocks ): bool {
		foreach ( $blocks as $index => $block ) {
			if ( 'core/paragraph' === ( $block['blockName'] ?? '' ) ) {
				$text = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );

				/*
				 * A year is required. "All rights reserved" on its own is a
				 * sentence the design wrote and will still be true next year;
				 * a date is the part that rots.
				 */
				$marked = 1 === preg_match( '/(©|&copy;|\(c\)|copyright)/i', $text );
				$dated  = 1 === preg_match( '/\b(19|20)\d{2}\b/', $text );

				if ( $marked && $dated ) {
					$keep = array();

					// The design's own colour and size for that line are worth keeping.
					foreach ( array( 'textColor', 'fontSize', 'className', 'style' ) as $key ) {
						if ( isset( $block['attrs'][ $key ] ) ) {
							$keep[ $key ] = $block['attrs'][ $key ];
						}
					}

					$blocks[ $index ] = array(
						'blockName'    => 'wow/colophon',
						'attrs'        => $keep,
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					);

					return true;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner = $block['innerBlocks'];

				if ( self::swap_copyright( $inner ) ) {
					$blocks[ $index ]['innerBlocks'] = $inner;

					/*
					 * innerContent holds the literal chunks between child
					 * blocks, with null standing for "the next child goes
					 * here". The children changed but their count did not, so
					 * the layout of that list is still correct.
					 */
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Put a colophon at the end of the footer's innermost container.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks, edited in place.
	 * @return void
	 */
	private static function append_colophon( array &$blocks ): void {
		$colophon = array(
			'blockName'    => 'wow/colophon',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		foreach ( $blocks as $index => $block ) {
			if ( 'core/group' === ( $block['blockName'] ?? '' ) && is_array( $block['innerBlocks'] ?? null ) ) {
				$blocks[ $index ]['innerBlocks'][]  = $colophon;
				$blocks[ $index ]['innerContent'][] = null;

				return;
			}
		}

		$blocks[] = $colophon;
	}

	/**
	 * Point a fragment's design links at the pages that were made from them.
	 *
	 * @param string                $content    Block markup.
	 * @param array<string, string> $lookup     Design file name => page URL.
	 * @param array<string, int>    $unresolved Counts of targets with no page, updated in place.
	 * @return string
	 */
	private static function relink_markup( string $content, array $lookup, array &$unresolved ): string {
		return (string) preg_replace_callback(
			'#href="([^"]+)"#i',
			static function ( array $link ) use ( $lookup, &$unresolved ): string {
				$href = $link[1];

				if ( 1 === preg_match( '#^(https?:)?//|^(mailto|tel):|^\##i', $href ) ) {
					return $link[0];
				}

				$parts  = explode( '#', $href, 2 );
				$target = strtolower( basename( $parts[0] ) );

				if ( ! isset( $lookup[ $target ] ) ) {
					if ( 1 === preg_match( '/\.html?$/i', $target ) ) {
						$unresolved[ $target ] = ( $unresolved[ $target ] ?? 0 ) + 1;
					}

					return $link[0];
				}

				$url = $lookup[ $target ] . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );

				return 'href="' . esc_url( $url ) . '"';
			},
			$content
		);
	}

	/**
	 * Footer markup carrying the design's closing links (fallback).
	 *
	 * @param string                              $html   Footer markup from the design.
	 * @param array<string, array<string, mixed>> $routes Created pages.
	 * @return string
	 */
	private static function footer_markup( string $html, array $routes ): string {
		$links = self::nav_links( $html, $routes );
		$items = array();

		foreach ( $links as $link ) {
			$items[] = "<!-- wp:paragraph {\"fontSize\":\"small\"} -->\n"
				. '<p class="has-small-font-size"><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></p>'
				. "\n<!-- /wp:paragraph -->";
		}

		$columns = '';

		if ( array() !== $items ) {
			$columns = "<!-- wp:group {\"layout\":{\"type\":\"flex\",\"flexWrap\":\"wrap\"}} -->\n"
				. '<div class="wp-block-group">' . implode( "\n\n", $items ) . "</div>\n<!-- /wp:group -->\n\n";
		}

		return "<!-- wp:group {\"tagName\":\"div\",\"className\":\"wow-footer\",\"align\":\"full\",\"backgroundColor\":\"surface\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|70\",\"bottom\":\"var:preset|spacing|70\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<div class="wp-block-group wow-footer alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">'
			. "<!-- wp:group {\"align\":\"wide\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<div class="wp-block-group alignwide">'
			. $columns
			. '<!-- wp:wow/colophon /-->'
			. "</div>\n<!-- /wp:group -->"
			. "</div>\n<!-- /wp:group -->";
	}

	/**
	 * Store a template part so the Site Editor picks it up.
	 *
	 * @param string $area   header or footer.
	 * @param string $markup Block markup.
	 * @return string Part slug, or an empty string on failure.
	 */
	private static function write_part( string $area, string $markup ): string {
		$theme = get_stylesheet();

		$existing = get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'post_status'    => 'any',
				'name'           => $area,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One row, import only.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);

		$payload = array(
			'post_type'    => 'wp_template_part',
			'post_status'  => 'publish',
			'post_title'   => 'header' === $area ? __( 'Header', 'wow-signal' ) : __( 'Footer', 'wow-signal' ),
			'post_name'    => $area,
			'post_content' => wp_slash( $markup ),
			'meta_input'   => array( self::OWNED_META => 'part:' . $area ),
		);

		if ( array() !== $existing ) {
			$payload['ID'] = $existing[0]->ID;
			$id            = wp_update_post( $payload, true );
		} else {
			$id = wp_insert_post( $payload, true );
		}

		if ( is_wp_error( $id ) ) {
			return '';
		}

		wp_set_object_terms( (int) $id, $theme, 'wp_theme' );
		wp_set_object_terms( (int) $id, $area, 'wp_template_part_area' );

		return $area;
	}

	/**
	 * Remove everything a previous run created.
	 *
	 * Import is meant to be tried, judged and tried again. Without a clean way
	 * back, a second attempt lands on top of the first and the site becomes a
	 * pile nobody can unpick.
	 *
	 * @return array{pages:int,parts:int,menus:int,media:int,fonts:int}
	 */
	public static function reset(): array {
		$counts = array(
			'pages' => 0,
			'parts' => 0,
			'menus' => 0,
			'media' => 0,
			'fonts' => 0,
		);

		$owned = self::owned_posts();

		$deleted_front = false;
		$front_page    = (int) get_option( 'page_on_front', 0 );

		foreach ( $owned as $post ) {
			if ( $front_page > 0 && (int) $post->ID === $front_page ) {
				$deleted_front = true;
			}

			switch ( $post->post_type ) {
				case 'page':
					++$counts['pages'];
					break;
				case 'wp_template_part':
					++$counts['parts'];
					break;
				case 'wp_navigation':
					++$counts['menus'];
					break;
			}

			wp_delete_post( $post->ID, true );
		}

		foreach ( self::owned_media() as $id ) {
			wp_delete_attachment( $id, true );
			++$counts['media'];
		}

		// Give the theme its own palette and type back, and take the design's CSS out of Additional CSS.
		DesignTokens::reset();

		if ( class_exists( DesignStylesheet::class ) ) {
			DesignStylesheet::reset();
		}

		if ( class_exists( DesignFonts::class ) ) {
			$counts['fonts'] = (int) DesignFonts::reset();
		}

		/*
		 * The front page goes back to whatever it was before the import. A
		 * site that had no stash and whose front page was not one of ours is
		 * left exactly as it is.
		 */
		$previous = get_option( self::PREVIOUS_FRONT );

		if ( is_array( $previous ) ) {
			$was_page = 'page' === ( $previous['show_on_front'] ?? '' ) && ! empty( $previous['page_on_front'] )
				&& null !== get_post( (int) $previous['page_on_front'] );

			update_option( 'show_on_front', $was_page ? 'page' : 'posts' );

			if ( $was_page ) {
				update_option( 'page_on_front', (int) $previous['page_on_front'] );
			} else {
				delete_option( 'page_on_front' );
			}

			delete_option( self::PREVIOUS_FRONT );
		} elseif ( $deleted_front ) {
			update_option( 'show_on_front', 'posts' );
			delete_option( 'page_on_front' );
		}

		delete_option( self::PENDING_FRONT );

		// The site's name and logo go back to what they were before the import.
		$identity = get_option( self::PREVIOUS_IDENTITY );

		if ( is_array( $identity ) ) {
			update_option( 'blogname', (string) ( $identity['blogname'] ?? get_option( 'blogname' ) ) );

			$logo = (int) ( $identity['custom_logo'] ?? 0 );

			if ( $logo > 0 && null !== get_post( $logo ) ) {
				set_theme_mod( 'custom_logo', $logo );
			} else {
				remove_theme_mod( 'custom_logo' );
			}

			delete_option( self::PREVIOUS_IDENTITY );
		}

		return $counts;
	}

	/**
	 * What a previous import left on the site, by kind.
	 *
	 * @return array{pages:int,parts:int,menus:int,media:int,fonts:int}
	 */
	public static function summary(): array {
		$counts = array(
			'pages' => 0,
			'parts' => 0,
			'menus' => 0,
			'media' => count( self::owned_media() ),
			'fonts' => class_exists( DesignFonts::class ) ? (int) DesignFonts::count() : 0,
		);

		foreach ( self::owned_posts() as $post ) {
			switch ( $post->post_type ) {
				case 'page':
					++$counts['pages'];
					break;
				case 'wp_template_part':
					++$counts['parts'];
					break;
				case 'wp_navigation':
					++$counts['menus'];
					break;
			}
		}

		return $counts;
	}

	/**
	 * Posts this importer created.
	 *
	 * @param array<int, string>|null $types Post types to include; every owned type when null.
	 * @return array<int, \WP_Post>
	 */
	private static function owned_posts( ?array $types = null ): array {
		return (array) get_posts(
			array(
				'post_type'      => $types ?? array( 'page', 'wp_template_part', 'wp_navigation', 'wp_block' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => self::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping, not a front-end query.
			)
		);
	}

	/**
	 * Attachment IDs this importer created.
	 *
	 * @return array<int, int>
	 */
	private static function owned_media(): array {
		return array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_wow_signal_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping.
				)
			)
		);
	}
}
