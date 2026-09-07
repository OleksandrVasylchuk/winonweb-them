<?php
/**
 * Builds a whole site from an unpacked design.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

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
	public const OWNED_META = '_qwerty_soft_imported';

	/**
	 * The language a page was imported in, as a two-letter code.
	 *
	 * @var string
	 */
	public const LANG_META = '_qwerty_soft_language';

	/**
	 * What joins a page to the same page in every other language.
	 *
	 * @var string
	 */
	public const GROUP_META = '_qwerty_soft_translation_of';

	/**
	 * Option holding the front-page settings as they were before an import.
	 */
	private const PREVIOUS_FRONT = 'qwerty_soft_import_previous_front';

	/**
	 * Option holding the site title and logo as they were before an import.
	 */
	private const PREVIOUS_IDENTITY = 'qwerty_soft_import_previous_identity';

	/**
	 * Option holding the page that becomes the front page once it is published.
	 */
	private const PENDING_FRONT = 'qwerty_soft_import_pending_front';

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

		$exclude = isset( $options['exclude'] ) && is_array( $options['exclude'] )
			? array_flip( array_map( 'strval', $options['exclude'] ) )
			: array();

		$pages = self::pages_for( $index, $language, ! empty( $options['utility'] ), $exclude );

		/*
		 * With one language chosen it is that one; with all of them, the one
		 * the design itself leads with — whichever holds the index page
		 * nearest the root. That language keeps the root of the site.
		 */
		$primary = '' !== $language ? $language : self::leading_language( $pages );

		if ( array() === $pages ) {
			return new WP_Error(
				'qwerty_soft_no_pages',
				__( 'No pages were found in that language.', 'qwerty-soft-signal' )
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

		// Which canonical source each page's blocks wear — see compile_sources().
		$sheets = array();

		foreach ( $pages as $page ) {
			if ( ! empty( $page['shell'] ) ) {
				Lessons::note( 'shell_skipped' );
			}
		}

		if ( class_exists( DesignStylesheet::class ) ) {
			/*
			 * Only what the pages being built actually link. A handoff holding
			 * five projects has five complete stylesheets in it, each
			 * redefining `:root`, `body` and `.card`; installing all of them
			 * puts the site in whichever project sorted last.
			 */
			$linked = array();

			foreach ( $pages as $page ) {
				$path = realpath( trailingslashit( $root ) . ltrim( (string) $page['file'], '/' ) );

				if ( false !== $path ) {
					$linked[] = str_replace( '\\', '/', $path );
				}
			}

			/*
			 * Where the design's own CSS goes depends on what is drawing the
			 * page. A converted page is core blocks wearing the design's class
			 * names, and nothing on disk belongs to it, so its stylesheet has
			 * to reach the site as Additional CSS. A wrapped page carries its
			 * own markup in `render.php`, so the stylesheet — and the script,
			 * which Additional CSS has no answer for at all — belong beside
			 * the blocks, loaded by the same per-block mechanism and dropped
			 * with them when an import is removed.
			 *
			 * Installing both was the bug: the Additional CSS copy is a
			 * concatenation across the handoff's several projects, it loads
			 * after everything a block enqueues, and its duplicate rules
			 * therefore won the cascade over the current design's own.
			 */
			if ( self::wrapping() ) {
				DesignStylesheet::reset();

				/*
				 * One canonical file per stylesheet source, not one altogether.
				 * This handoff carries the website baseline and an older
				 * AI-roadmap prototype side by side; concatenated, the
				 * prototype's `:root` and `.brand-mark` overwrote the
				 * baseline's on every page. Grouped by what each page links,
				 * each source loads only where its own pages stand.
				 */
				$grouped = DesignStylesheet::compile_sources( $root, $media, $linked );
				$written = BlockWriter::write_canonical( $grouped['sources'] );

				foreach ( $grouped['sources'] as $source ) {
					if ( '' !== (string) ( $source['key'] ?? '' ) ) {
						Lessons::note( 'extra_source' );
					}
				}

				foreach ( $pages as $page ) {
					$path = realpath( trailingslashit( $root ) . ltrim( (string) $page['file'], '/' ) );

					if ( false === $path ) {
						continue;
					}

					$path = str_replace( '\\', '/', $path );

					if ( isset( $grouped['routes'][ $path ] ) ) {
						$sheets[ (string) $page['file'] ] = (string) $grouped['routes'][ $path ];
					}
				}

				$stylesheet['bytes'] = $written['css'];
			} else {
				$stylesheet = DesignStylesheet::import( $root, $media, $linked );
			}
		}

		return array(
			'root'             => $root,
			'primary_language' => $primary,
			'publish'          => $publish,
			'includes'         => $includes,
			'pages'            => array_values( $pages ),
			'colors'           => $tokens['colors'],
			'media'            => $media,
			'sheets'           => $sheets,

			// The archive's product catalogue, when DesignNeeds found one — imported at finish() if the shop is there.
			'catalog'          => DesignNeeds::catalog( $root ),
			'routes'           => array(),

			/*
			 * Whether this build asks a model to correct each section, and
			 * whether it also asks for the rendered result to be reviewed.
			 * Both are off unless the build screen turned them on; a build
			 * that leaves them off never reaches the network at all.
			 */
			'smart'            => ! empty( $options['smart'] ) && SmartConverter::possible(),
			'refine'           => ! empty( $options['refine'] ),
			'model'            => isset( $options['model'] ) ? (string) $options['model'] : AnthropicClient::DEFAULT_MODEL,
			'effort'           => isset( $options['effort'] ) ? (string) $options['effort'] : 'high',
			'report'           => array(
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
	 * Whether the running build's archive ships a product catalogue.
	 *
	 * Set per request from the job, like the stylesheet routes: when true,
	 * the catalogue import owns the shop's products and a listing's cards
	 * are not seeded on top of them as near-duplicates.
	 *
	 * @var bool
	 */
	private static bool $catalog = false;

	/**
	 * Whether the running build asked for a model at all.
	 *
	 * Set per request from the job's `smart` option, the same way the
	 * catalogue is. The review of each section's reading is a model call,
	 * and a build the operator sent straight through — the free one — was
	 * still making one per fresh section, because the review asked only
	 * whether a model could be reached and never whether it was wanted.
	 *
	 * @var bool
	 */
	private static bool $smart = false;

	/**
	 * The review calls made so far in this step, for the report's tally.
	 *
	 * Collected here because the review runs several calls below the page
	 * step, with no job in reach; page() drains them into the same tally the
	 * converter's calls go to.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $reviews = array();

	/**
	 * Learn what this step needs to know from the job.
	 *
	 * Each step is its own request, so anything the deep parts of a build
	 * read from a static has to be set again at the top of every step.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return void
	 */
	private static function remember( array $job ): void {
		self::$catalog = ! empty( $job['catalog']['file'] );
		self::$smart   = ! empty( $job['smart'] );
		self::$reviews = array();
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
		// Each step is its own request, so the writer relearns which canonical source each page wears.
		BlockWriter::route_styles( (array) ( $job['sheets'] ?? array() ) );

		self::remember( $job );

		$page = null;

		foreach ( (array) $job['pages'] as $candidate ) {
			if ( (string) $candidate['file'] === $file ) {
				$page = $candidate;
				break;
			}
		}

		if ( null === $page ) {
			return new WP_Error( 'qwerty_soft_no_page', __( 'That page is not part of this build.', 'qwerty-soft-signal' ) );
		}

		$includes = isset( $job['includes'][ $file ] ) && is_array( $job['includes'][ $file ] )
			? $job['includes'][ $file ]
			: null;

		/*
		 * The page this file already has, if it has one.
		 *
		 * Read from the job first, and from the site when the job does not know
		 * — which is every second build, because a new job starts with no
		 * routes at all. Taking only the job's word produced a second page for
		 * a file that already had one: `/videos/` published, and `/videos-2/`
		 * beside it as a draft, with the menu pointing at the older of the two.
		 *
		 * The claim this theme makes about rebuilding is that a page built
		 * twice updates rather than duplicates. This is what makes it true.
		 */
		$existing = isset( $job['routes'][ $file ]['id'] ) ? (int) $job['routes'][ $file ]['id'] : 0;

		if ( $existing <= 0 ) {
			$existing = self::page_for_file( $file );
		}

		$smart = self::smart_converter( $job );

		$made = self::build_page(
			(string) $job['root'],
			$page,
			null !== $smart ? $smart->structural() : self::converter( $job ),
			(array) $job['media'],
			! empty( $job['publish'] ),
			$includes,
			$existing,
			$smart,
			(string) ( $job['primary_language'] ?? '' )
		);

		if ( null !== $smart ) {
			self::record_calls( $job, $smart->calls() );
		}

		// The model's other job on this page: checking each fresh section's reading.
		if ( array() !== self::$reviews ) {
			self::record_calls( $job, self::$reviews );
			self::$reviews = array();
		}

		if ( is_wp_error( $made ) ) {
			$job['report']['concerns'][] = $file . ' — ' . $made->get_error_message();

			return $made;
		}

		$job['routes'][ $file ] = $made;

		return $made;
	}

	/**
	 * Write the pairs `npm run audit:pixels` compares: each built page and its design file.
	 *
	 * @param array<string, mixed>             $job   Job record.
	 * @param array<int, array<string, mixed>> $pages The report's page rows.
	 * @return void
	 */
	private static function write_pixel_manifest( array $job, array $pages ): void {
		$root    = rtrim( str_replace( '\\', '/', (string) ( $job['root'] ?? '' ) ), '/' );
		$entries = array();

		foreach ( $pages as $row ) {
			$id   = (int) ( $row['id'] ?? 0 );
			$file = (string) ( $row['file'] ?? '' );

			if ( $id <= 0 || '' === $file || ! is_file( $root . '/' . $file ) ) {
				continue;
			}

			$entries[] = array(
				'name'   => BlockWriter::slug( (string) ( $row['slug'] ?? basename( $file, '.html' ) ) ),
				'live'   => (string) get_permalink( $id ),
				'origin' => $root . '/' . ltrim( $file, '/' ),
			);
		}

		if ( array() === $entries || ! wp_mkdir_p( QSOFT_DIR . '/artifacts' ) ) {
			return;
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The theme's own artifacts directory, git-ignored.
			QSOFT_DIR . '/artifacts/pixel-manifest.json',
			(string) wp_json_encode( $entries, JSON_PRETTY_PRINT ) . "\n"
		);
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
		// Same as page(): the writer needs the source map before it writes the header and footer blocks.
		BlockWriter::route_styles( (array) ( $job['sheets'] ?? array() ) );

		self::remember( $job );

		$empty = array(
			'menu'         => 0,
			'parts'        => array(),
			'parts_detail' => array(),
			'menu_link'    => '',
		);

		/*
		 * No longer a reason to stop. The chrome is built before the pages
		 * now, so there is never anything to link a menu to at this point —
		 * the menu is made on the design's own hrefs and rewritten to real
		 * pages by finish(), the same way the links inside pages and blocks
		 * already are.
		 *
		 * What is still worth refusing is a job with no pages planned at all,
		 * because then there will be nothing to rewrite them to either.
		 */
		if ( array() === (array) ( $job['pages'] ?? array() ) ) {
			return $empty;
		}

		$chrome = self::build_chrome(
			(string) $job['root'],
			self::front_file( $job ),
			(array) $job['routes'],
			self::converter( $job ),
			(array) $job['media']
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
			return new WP_Error( 'qwerty_soft_nothing_built', __( 'None of the pages could be converted.', 'qwerty-soft-signal' ) );
		}

		$report = $job['report'];

		/*
		 * The menu, made here rather than with the chrome.
		 *
		 * The header is built first now, before any page exists, so a menu made
		 * with it could only hold the design's own addresses — and `esc_url()`
		 * reads a bare `research.html` as a host and renders
		 * `http://research.html`. Every visitor during the build saw a menu of
		 * links to nowhere.
		 *
		 * Waiting costs nothing, because the wrapped header looks the menu up
		 * when it draws. Until this runs it renders without a navigation, and
		 * then gains one that is right the first time.
		 */
		$menu = self::menu_from_design( $job, $routes );

		if ( $menu > 0 ) {
			$report['menu']      = $menu;
			$report['menu_link'] = admin_url( 'site-editor.php?postType=wp_navigation&postId=' . $menu );
		}

		// Now that every page has a permalink, make the links between them work.
		$unresolved = self::relink_pages( $routes );

		/*
		 * And upgrade the menu from addresses to page links. The entries were
		 * made before the pages existed, so they are custom links pointing at
		 * URLs; a page link is what keeps the menu correct when somebody later
		 * renames a page or changes the permalink structure.
		 */
		self::refresh_menus();

		foreach ( $unresolved as $target => $count ) {
			$report['concerns'][] = sprintf(
				/* translators: 1: link target, 2: how many links point at it. */
				_n(
					'%2$d link points at "%1$s", which is not a page on this site. Check the design or remove the link.',
					'%2$d links point at "%1$s", which is not a page on this site. Check the design or remove the links.',
					$count,
					'qwerty-soft-signal'
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
				array_map( 'strval', (array) ( $routes[ $file ]['changed'] ?? array() ) ),
				(int) ( $routes[ $file ]['wrapped'] ?? 0 )
			);
		}

		/*
		 * The archive's catalogue, into the shop, now that the shop's pages
		 * exist to show it. One import per build, safe to repeat: a product
		 * is found again by its code and updated in place. Without
		 * WooCommerce the catalogue simply waits — the advisor on the import
		 * screen says so and offers the install.
		 */
		if ( ! empty( $job['catalog']['file'] ) && post_type_exists( 'product' ) ) {
			$counts = CatalogImport::run( (string) $job['root'], (string) $job['catalog']['file'], (array) ( $job['media'] ?? array() ) );

			if ( $counts['made'] + $counts['updated'] > 0 ) {
				ImportLog::add(
					'build',
					sprintf(
						/* translators: 1: products created, 2: products updated, 3: how many got a picture. */
						__( 'Imported the product catalogue: %1$d products made, %2$d updated, %3$d with a picture.', 'qwerty-soft-signal' ),
						(int) $counts['made'],
						(int) $counts['updated'],
						(int) $counts['images']
					)
				);

				$report['catalog'] = $counts;
			}
		}

		/*
		 * The measurement every earlier fault would have failed. "Built 10
		 * sections" was true of pages that rendered a seventh of the design's
		 * copy; this says how much of the design each page actually shows,
		 * flags the thin ones, and leaves the numbers in the journal the next
		 * import starts from.
		 */
		$fidelity_low  = 100;
		$fidelity_high = 0;

		foreach ( $report['pages'] as $index => $row ) {
			$measured = Fidelity::of( (string) $job['root'], (string) $row['file'], (int) $row['id'] );

			if ( ! is_array( $measured ) ) {
				continue;
			}

			$report['pages'][ $index ]['fidelity'] = (int) $measured['ratio'];

			$fidelity_low  = min( $fidelity_low, (int) $measured['ratio'] );
			$fidelity_high = max( $fidelity_high, (int) $measured['ratio'] );

			$worry = Fidelity::concern( $measured, (string) $row['title'] );

			if ( '' !== $worry ) {
				$report['concerns'][] = $worry;
			}
		}

		if ( $fidelity_high > 0 ) {
			ImportLog::add(
				'build',
				sprintf(
					/* translators: 1: lowest page percentage, 2: highest page percentage. */
					__( 'Measured against the design: the pages render %1$d–%2$d%% of its copy.', 'qwerty-soft-signal' ),
					$fidelity_low,
					$fidelity_high
				)
			);
		}

		/*
		 * The pixels, which words and elements cannot see. Written for
		 * `npm run audit:pixels` on every build, so the comparison is one
		 * command away; run here, page by page with the blocks corrected
		 * between looks, when the build asked to be checked.
		 */
		self::write_pixel_manifest( $job, $report['pages'] );

		if ( ! empty( $job['refine'] ) ) {
			foreach ( $report['pages'] as $index => $row ) {
				$looked = PixelReview::page( $job, $row );

				if ( ! is_array( $looked ) ) {
					break;
				}

				$report['pages'][ $index ]['pixels'] = array(
					'before'  => $looked['before'],
					'after'   => $looked['after'],
					'changed' => $looked['changed'],
				);

				// The report the review just wrote, now that there is one to link to.
				$pixel_report = self::pixel_report_url( (string) ( $row['slug'] ?? '' ) );

				if ( '' !== $pixel_report ) {
					$report['pages'][ $index ]['pixel_report'] = $pixel_report;
				}

				self::record_calls( $job, (array) $looked['calls'] );
			}
		}

		Lessons::record(
			array(
				'design'       => (string) ( $job['slug'] ?? '' ),
				'kind'         => str_contains( implode( ' ', array_keys( $routes ) ), 'qs-rendered/' ) ? 'application' : 'static',
				'pages'        => count( $routes ),
				'fidelity_min' => $fidelity_low,
				'fidelity_max' => $fidelity_high,
				'concerns'     => count( (array) ( $report['concerns'] ?? array() ) ),
			)
		);

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
					'qwerty-soft-signal'
				),
				(int) $ai['failed'],
				(string) ( $ai['errors'][0] ?? '' )
			);
		}

		$job['report'] = $report;

		/*
		 * Kept past the end of the job, so a screen opened after the build —
		 * or reloaded once it finished — can still say what the hour produced
		 * instead of showing an empty upload form.
		 */
		ImportSession::remember_report( $report );

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
	 * @param int                $wrapped  How many sections became blocks of their own.
	 * @return array<string, mixed>
	 */
	private static function page_row( int $id, string $file, int $sections, array $concerns, int $improved = 0, array $changed = array(), int $wrapped = 0 ): array {
		return self::refresh_row(
			array(
				'id'       => $id,
				'file'     => $file,
				'sections' => $sections,
				'improved' => $improved,
				'wrapped'  => $wrapped,
				'changed'  => $changed,
				'concerns' => $concerns,
			)
		);
	}

	/**
	 * Read a report row's live facts back off the site: title, status, links.
	 *
	 * A row is written when a page is made and shown for as long as the
	 * report is kept, during which the page is published, renamed or
	 * reviewed. So everything about the row that the site can answer is
	 * asked again each time it is shown, and only what the build alone knew
	 * — how many sections it kept, what it worried about — is carried over.
	 *
	 * The title is text, not markup. `get_the_title()` hands back the stored
	 * entities, the screen sets them as text, and "vCISO &amp;#038; AI" was
	 * the result; decoding here is what puts one ampersand on the screen.
	 *
	 * @param array<string, mixed> $row A report row, or the beginnings of one.
	 * @return array<string, mixed>
	 */
	public static function refresh_row( array $row ): array {
		$id     = (int) ( $row['id'] ?? 0 );
		$status = (string) get_post_status( $id );
		$url    = (string) get_permalink( $id );
		$slug   = (string) get_post_field( 'post_name', $id );

		$row['id']        = $id;
		$row['title']     = self::plain_title( (string) get_the_title( $id ) );
		$row['slug']      = $slug;
		$row['url']       = $url;
		$row['status']    = $status;
		$row['link']      = 'publish' === $status ? $url : (string) get_preview_post_link( $id );
		$row['edit_link'] = admin_url( 'post.php?post=' . $id . '&action=edit' );

		$report = self::pixel_report_url( $slug );

		if ( '' !== $report ) {
			$row['pixel_report'] = $report;
		} else {
			unset( $row['pixel_report'] );
		}

		return $row;
	}

	/**
	 * A title as a person reads it: no tags, no entities.
	 *
	 * @param string $title A title as WordPress stores it or a design wrote it.
	 * @return string
	 */
	public static function plain_title( string $title ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Where the pixel review's report for a page can be opened, if it wrote one.
	 *
	 * The review photographs a page under `artifacts/pixels/review/{slug}`,
	 * which the theme serves as files; the report beside the photographs is
	 * the comparison a person can look at. Only offered when the file is
	 * actually there — a link to a report that was never written is worse
	 * than none.
	 *
	 * @param string $slug The page's slug.
	 * @return string URL, or empty when no report exists.
	 */
	public static function pixel_report_url( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}

		$name = BlockWriter::slug( $slug );
		$path = 'artifacts/pixels/review/' . $name . '/report.html';

		if ( '' === $name || ! is_file( QSOFT_DIR . '/' . $path ) ) {
			return '';
		}

		return QSOFT_URI . '/' . $path;
	}

	/**
	 * The pages an import has put on this site, read back from the site.
	 *
	 * The last resort behind the stored report, and the answer to a screen
	 * that has nothing to show because the build it is about finished before
	 * anything thought to write a report down — or an hour ago, in another
	 * tab, on a laptop that has since been closed. Every page an import made
	 * carries the file it came from, so the list can always be rebuilt from
	 * what is actually there rather than from what was once remembered.
	 *
	 * One row per source file, newest first: a design imported five times has
	 * five pages per file on the site, and the current one is the last.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function imported_pages(): array {
		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),

				/*
				 * Newest first, so the hundred read are the hundred that
				 * matter: what a cap can drop here is the older duplicate of
				 * a file whose current page has already been seen.
				 */
				'posts_per_page'   => 100,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'meta_key'         => self::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping; the key is indexed and the set is small.
				'suppress_filters' => false,
			)
		);

		$newest = array();

		foreach ( (array) $ids as $id ) {
			$file = (string) get_post_meta( (int) $id, self::OWNED_META, true );

			/*
			 * Only the pages of the design. The same meta marks the language
			 * stubs, the navigation and the template parts, none of which are
			 * a page somebody wants a row for.
			 */
			if ( '' === $file || 'navigation' === $file
				|| 0 === strpos( $file, 'language:' ) || 0 === strpos( $file, 'part:' ) ) {
				continue;
			}

			if ( isset( $newest[ $file ] ) ) {
				continue;
			}

			$newest[ $file ] = (int) $id;
		}

		$rows = array();

		foreach ( $newest as $file => $id ) {
			$rows[] = self::page_row( $id, (string) $file, 0, array() );
		}

		return $rows;
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
			array( 'contrast', 'base', 4.5, __( 'body text on the page background', 'qwerty-soft-signal' ) ),
			array( 'muted', 'base', 4.5, __( 'secondary text on the page background', 'qwerty-soft-signal' ) ),
			array( 'contrast', 'surface', 4.5, __( 'text on cards', 'qwerty-soft-signal' ) ),
			array( 'border-strong', 'base', 3.0, __( 'form field borders', 'qwerty-soft-signal' ) ),
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
				__( 'The design\'s colours give %1$s a contrast of %2$s:1, below the %3$s:1 that WCAG 2.2 AA asks for. Adjust it in Appearance → Editor → Styles before launch.', 'qwerty-soft-signal' ),
				$label,
				number_format_i18n( $ratio, 2 ),
				number_format_i18n( $minimum, 1 )
			);
		}

		return $warnings;
	}

	/**
	 * Whether a page belongs to the site or to the tooling around it.
	 *
	 * A handoff ships the screens that run the site next to the ones people
	 * visit: a translation queue, a report uploader, a settings panel, a
	 * customer download area. They are real pages and they convert perfectly
	 * well; they are also almost never what somebody importing a design is
	 * asking for, so they are left out unless they are asked for.
	 *
	 * The same test runs on the screen, where the list folds them away. It
	 * lives here as well because the two used to disagree: the screen hid a
	 * page as tooling while the build made it anyway, purely because it
	 * happened to sit under the chosen language.
	 *
	 * @param string $file Page file, relative to the design root.
	 * @return bool
	 */
	public static function is_utility( string $file ): bool {
		return 1 === preg_match(
			'#(^|/)(admin|admin_private|customer|private|internal|prototype|prototypes|dashboard)(/|$)#i',
			$file
		);
	}

	/**
	 * The pages worth building, in a sensible order.
	 *
	 * @param array<string, mixed> $index    Index.
	 * @param string               $language Language directory, or empty for all.
	 * @param bool                 $utility  Whether to include the tooling screens.
	 * @param array<string, int>   $exclude  Files to leave out, as a lookup.
	 * @return array<int, array<string, mixed>>
	 */
	private static function pages_for( array $index, string $language, bool $utility = false, array $exclude = array() ): array {
		$pages = array();

		foreach ( (array) $index['pages'] as $page ) {
			$file    = (string) $page['file'];
			$tooling = self::is_utility( $file );

			/*
			 * A version of a page the screen decided against. Two files can
			 * want the same address — a site and a blueprint that ships one of
			 * its pages with a new section in it — and only one may have it.
			 * Which one is a judgement, so it is made where a person can see
			 * it and arrives here as a list.
			 */
			if ( isset( $exclude[ $file ] ) ) {
				continue;
			}

			if ( ! $utility && $tooling ) {
				continue;
			}

			if ( '' !== $language && ! self::in_language( $file, $language ) ) {
				/*
				 * A tooling screen is usually filed under no language at all —
				 * `admin/translation-queue.html`, not `en/admin/…`. Judging it
				 * by the language filter meant asking for the admin screens
				 * and getting none of them, with nothing on the screen to say
				 * why. One that does live under a language is still that
				 * language's, and is left to it.
				 */
				if ( ! $tooling || '' !== self::language_of( $file ) ) {
					continue;
				}
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
	 * The language the design leads with.
	 *
	 * @param array<int, array<string, mixed>> $pages Pages being built, index first.
	 * @return string
	 */
	private static function leading_language( array $pages ): string {
		foreach ( $pages as $page ) {
			$language = self::language_of( (string) $page['file'] );

			if ( '' !== $language ) {
				return $language;
			}
		}

		return '';
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
	 * @param string               $primary   The language that keeps the root of the site.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function build_page( string $root, array $page, BlockConverter $converter, array $media, bool $publish, ?array $includes = null, int $existing = 0, ?SmartConverter $smart = null, string $primary = '' ) {
		$file      = (string) $page['file'];
		$converted = self::convert_sections( $root, $file, $converter, $media, $includes, $smart );
		$split     = $converted['split'];

		$markup   = array();
		$concerns = array();
		$changed  = array();
		$kept     = 0;
		$improved = 0;
		$wrapped  = 0;

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

			$source = (string) ( $section['source'] ?? 'structural' );

			/*
			 * Counted apart, because they are not the same claim. A wrapped
			 * section had no model call at all, and reporting it as one Claude
			 * corrected would tell an editor a review happened that did not —
			 * on a bill, no less.
			 */
			if ( 'wrapped' === $source ) {
				++$wrapped;
			} elseif ( 'structural' !== $source ) {
				++$improved;
			}

			$changed = array_merge( $changed, array_map( 'strval', (array) ( $section['changed'] ?? array() ) ) );
		}

		if ( array() === $markup ) {
			return new WP_Error( 'qwerty_soft_empty_page', __( 'Nothing on this page could be converted.', 'qwerty-soft-signal' ) );
		}

		$concerns = array_merge( $concerns, self::splitter_notes( $split ) );

		$content = self::ensure_h1( implode( "\n\n", $markup ) );
		$title   = self::title_for( (string) $split['title'], $file, $content );

		/*
		 * A page with no heading at all still needs one; its own title is it.
		 *
		 * Looked for in the design's markup rather than in the page's, because
		 * a wrapped section keeps its heading inside the block's own template
		 * and the page holds nothing but block comments. Reading only the page
		 * found no `<h1>` anywhere, so every imported page opened with a band
		 * repeating its title above the design's own hero — the first thing on
		 * the screen, on every page, saying what the next section already said.
		 */
		if ( ! str_contains( $content, '<h1' ) && ! self::heads_itself( $converted['sections'] ) ) {
			$content = self::title_band( $title ) . "\n\n" . $content;

			$concerns[] = __( 'This page had no heading of its own, so its title was added as the top heading.', 'qwerty-soft-signal' );
		}

		/*
		 * Which language this page is, and what it hangs under.
		 *
		 * The primary language stays at the root, exactly where a
		 * single-language import puts it. Every other language gets a page of
		 * its own named after the code — `/ru/` — and its pages become
		 * children of it, so the addresses come out `/ru/reports/` the way the
		 * design wrote them and the way anyone reading the site expects. It is
		 * plain WordPress page hierarchy; nothing here needs a plugin to
		 * understand it.
		 */
		$language = self::language_of( $file );
		$parent   = self::language_parent( $primary, $publish, $language );

		$payload = array(
			'post_type'     => 'page',
			'post_status'   => $publish ? 'publish' : 'draft',
			'post_title'    => $title,
			'post_name'     => self::slug_for( $file ),
			'post_parent'   => $parent,

			/*
			 * Slashed on purpose: wp_insert_post() unslashes what it is
			 * given, and block attributes are JSON — an unslashed ™
			 * arrives as the literal "u2122" and a \" ends the attribute
			 * early. Every generated insert on this path does the same.
			 */
			'post_content'  => wp_slash( $content ),

			/*
			 * An imported page brings its own container. The landing template
			 * wraps content in a constrained main, which is right for a page
			 * built out of the theme's patterns and wrong for one built out of
			 * a design: WordPress then narrows every section to the theme's
			 * wide size, and the design's own `.container` — the width its
			 * author chose — never gets to decide anything.
			 */
			'page_template' => BlockConverter::faithful() ? 'page-design' : 'page-landing',
			'meta_input'    => array(
				self::OWNED_META => $file,
				self::LANG_META  => $language,
				self::GROUP_META => self::group_of( $file ),
			),
		);

		/*
		 * The language landing page is the branch itself, not a child of it:
		 * `ru/index.html` is what `/ru/` should show.
		 */
		if ( $parent > 0 && self::is_index( $file ) ) {
			$payload['ID']          = $parent;
			$payload['post_name']   = $language;
			$payload['post_parent'] = 0;
		}

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
			'wrapped'  => $wrapped,
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

		/*
		 * Built once per page, not once per section. `from_directory()` reads
		 * and indexes every stylesheet in the archive; doing that thirty-four
		 * times for a thirty-four-section page would cost more than the
		 * conversion it replaced.
		 */
		$wrapping = self::wrapping();
		$styles   = $wrapping ? CssIndex::from_directory( $root ) : null;

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

			/*
			 * The wrapping path, and the reason there is no model call under
			 * it. A section is not being read and rewritten — it is being
			 * copied into a block of its own with fields over the parts an
			 * editor changes. That is a structural job, it is done in code, and
			 * it takes milliseconds where the conversion took minutes.
			 */
			if ( $wrapping && null !== $styles ) {
				$wrapped = self::wrap_section( $split, $file, $section, $styles, $media, $page_dir );

				if ( null !== $wrapped ) {
					$row['markup']   = $wrapped['markup'];
					$row['source']   = 'wrapped';
					$row['concerns'] = $wrapped['concerns'];

					$sections[] = $row;
					continue;
				}

				/*
				 * Falling through rather than failing, but never in silence.
				 *
				 * The old path still produces a page, so a section the writer
				 * cannot read is worth converting rather than dropping. What
				 * is not acceptable is doing it quietly: a build that fell back
				 * for every section looked exactly like a successful one, said
				 * "Claude corrected 10 of them", and left somebody to work out
				 * from the pages themselves that no block had been made.
				 */
				ImportLog::add(
					'build',
					sprintf(
						/* translators: %s: section label. */
						__( 'Section "%s" could not be made into a block of its own, so it was converted instead.', 'qwerty-soft-signal' ),
						(string) $section['label']
					)
				);

				$row['concerns'][] = sprintf(
					/* translators: %s: section label. */
					__( 'Section "%s" could not be kept as the design wrote it and was converted into theme blocks instead.', 'qwerty-soft-signal' ),
					(string) $section['label']
				);
			}

			/*
			 * A line per section, before the slow part rather than after it.
			 *
			 * Each section is a model call, sometimes three, and a page of
			 * thirty-four of them takes a quarter of an hour. Reporting only
			 * on the finished page left the screen showing one unchanging
			 * line for all of it, which is what "is it stuck?" looks like.
			 * The same write is the heartbeat another tick reads to know the
			 * step is alive.
			 */
			if ( null !== $smart ) {
				ImportLog::add(
					'build',
					sprintf(
						/* translators: 1: section number, 2: sections on the page, 3: section label. */
						__( 'Section %1$d of %2$d — %3$s', 'qwerty-soft-signal' ),
						$offset + 1,
						count( $split['sections'] ),
						(string) $section['label']
					)
				);

				ImportSession::beat();
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
					__( 'Section "%1$s" was left out: %2$s', 'qwerty-soft-signal' ),
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
	 * Wrap a piece of chrome as a block whose fields live with the site.
	 *
	 * The menu is deliberately not a field. A navigation is a list of
	 * pages, WordPress already has a thing for that, and turning it into a
	 * repeater field would mean an editor adding a page in one screen and
	 * remembering to add it to the menu in another. So the header's links stay
	 * a real menu; everything else in the header and footer — the strapline,
	 * the small print, the address — becomes site content.
	 *
	 * @param string                                  $root  Design root.
	 * @param string                                  $file  Page the chrome was read from.
	 * @param string                                  $area  header or footer.
	 * @param string                                  $html  The chrome's markup.
	 * @param array<string, array{id:int,url:string}> $media Imported media map.
	 * @param bool                                    $menu  Whether this chrome holds the site's navigation.
	 * @return string Block markup for the template part, or empty.
	 */
	private static function wrap_chrome( string $root, string $file, string $area, string $html, array $media, bool $menu = false ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		/*
		 * The navigation comes out before the section is read, so its links
		 * never become fields. Otherwise the header would hold the design's
		 * five links as five text boxes, and adding a page would mean editing
		 * a block as well as the menu — a site that drifts out of agreement
		 * with itself the first time somebody publishes something.
		 */
		if ( $menu ) {
			$html = self::hollow_nav( $html );
		}

		$plan = SectionPlan::of( $html );
		$slug = BlockWriter::slug( 'site-' . $area . '-' . substr( md5( $html ), 0, 6 ) );

		if ( '' === $slug ) {
			return '';
		}

		$page_dir = (string) dirname( $file );
		$dir      = BlockWriter::dir() . '/' . $slug;
		$styles   = CssIndex::from_directory( $root );

		$linked = SiteBuilder::relink_media( $html, $media, $page_dir );
		$linked = SiteBuilder::relink_css_urls( $linked, $media, $page_dir );

		if ( ! BlockWriter::current( $dir ) ) {
			$written = BlockWriter::write(
				$linked,
				$plan,
				$slug,
				'header' === $area ? __( 'Site header', 'qwerty-soft-signal' ) : __( 'Site footer', 'qwerty-soft-signal' ),
				SiteBuilder::relink_css_urls( $styles->rules_for( $html ), $media, $page_dir ),
				$dir,
				$file,
				'option',
				$menu
			);

			if ( null === $written ) {
				return '';
			}
		}

		$values = BlockWriter::values( $html, $plan );
		$values = self::resolve_images( $values, $plan, $media, $page_dir );

		SiteOptions::seed( BlockWriter::option_values( $slug, $plan, $values ) );

		/*
		 * Remembered so the last step can rewrite the footer's links. They are
		 * link fields, so their addresses are option values rather than hrefs
		 * in the markup — and the pass that fixes hrefs walked straight past
		 * them, leaving every footer link pointing at the archive.
		 */
		update_option( SiteOptions::ORIGIN, $file, false );

		/*
		 * No `data` on the comment. The values are the site's, not this
		 * placement's, so the block reads them from the options page every
		 * time it renders — which is what makes one edit reach every page.
		 */
		return '<!-- wp:qs/design-' . $slug . ' /-->';
	}

	/**
	 * Whether one node sits inside another.
	 *
	 * @param \DOMNode $node    The node to place.
	 * @param \DOMNode $subject The node it might be inside.
	 * @return bool
	 */
	private static function within( \DOMNode $node, \DOMNode $subject ): bool {
		for ( $parent = $node->parentNode; null !== $parent; $parent = $parent->parentNode ) {
			if ( $parent === $subject ) {
				return true;
			}
		}

		return false;
	}
	/**
	 * Point every imported link at the page it means, on a site already built.
	 *
	 * The build does this at the end of a run, from the routes it is holding.
	 * A repair has no run and no routes — but the pages carry the archive path
	 * each came from, which is the same information written down, so the same
	 * pass can be made over a finished site. Without it a restored block keeps
	 * the design's own `about.html`, and `esc_url()` reads a bare file name as
	 * a host: every link in the header, the footer and every section resolves
	 * to `http://about.html`.
	 *
	 * @return array<string, int> Link targets that matched no page, and how often.
	 */
	public static function relink(): array {
		$routes = array();

		foreach ( self::owned_posts( array( 'page' ) ) as $page ) {
			$file = (string) get_post_meta( $page->ID, self::OWNED_META, true );

			if ( '' === $file ) {
				continue;
			}

			$routes[ $file ] = array(
				'id'  => (int) $page->ID,
				'url' => (string) get_permalink( $page->ID ),
			);
		}

		return array() === $routes ? array() : self::relink_pages( $routes );
	}
	/**
	 * Point a generated template's fallback addresses at the pages that exist.
	 *
	 * A field's fallback is the design's own value, held as a PHP literal so a
	 * block with nothing stored still draws the section as delivered. For a
	 * link that value is an address, and an address out of the archive means
	 * nothing here: the pass that rewrites `href=""` never sees it, because by
	 * then it is a string in a function call rather than an attribute.
	 *
	 * @param string               $php        The template.
	 * @param array<string, mixed> $index    Link index built from the routes.
	 * @param string               $dir        Where the block's section stood in the archive.
	 * @param array<string, int>   $unresolved Collected links that matched nothing.
	 * @return string
	 */
	private static function relink_literals( string $php, array $index, string $dir, array &$unresolved ): string {
		return (string) preg_replace_callback(
			"#'url' => '([^']*)'#",
			static function ( array $found ) use ( $index, $dir, &$unresolved ): string {
				$href = $found[1];

				if ( '' === $href || 1 === preg_match( '#^(https?:)?//|^(mailto|tel):|^\##i', $href ) ) {
					return $found[0];
				}

				$parts = explode( '#', $href, 2 );
				$url   = self::built_url( $index, $dir, $parts[0] );

				if ( '' === $url ) {
					if ( 1 === preg_match( '/\.html?$/i', $parts[0] ) ) {
						$name                = strtolower( basename( $parts[0] ) );
						$unresolved[ $name ] = ( $unresolved[ $name ] ?? 0 ) + 1;
					}

					return $found[0];
				}

				$url = $url . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );

				// Written back into a single-quoted PHP string, so it is escaped for one.
				return "'url' => '" . addcslashes( esc_url_raw( $url ), "'\\\\" ) . "'";
			},
			$php
		);
	}

	/**
	 * The page a previous import already made for one file of a design.
	 *
	 * Every imported page carries the archive path it came from, which is what
	 * makes a second build able to recognise its own work rather than repeat
	 * it. A trashed one does not count: somebody deleted that page on purpose,
	 * and rebuilding into it would bring it back without being asked.
	 *
	 * @param string $file Page file, relative to the design root.
	 * @return int The page, or zero.
	 */
	private static function page_for_file( string $file ): int {
		if ( '' === $file ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'meta_key'       => self::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping, not a front-end query.
				'meta_value'     => $file, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The archive path is the identity of an imported page.
			)
		);

		return array() === $found ? 0 : (int) $found[0]->ID;
	}

	/**
	 * Turn the cards a listing drew into records somebody can add to.
	 *
	 * This is what the whole listing idea is for. Six report cards held as
	 * content mean adding a seventh report by editing a page; held as records
	 * they mean pressing Add New, and the card the designer drew is the
	 * template that draws it.
	 *
	 * Existing records are left alone. A rebuild of the same design must not
	 * duplicate a list somebody has since been adding to, and matching on the
	 * title is enough: two reports with the same name are the same report.
	 *
	 * @param string                           $type   The post type key.
	 * @param array<int, array<string, mixed>> $rows  One entry per card, as values() read them.
	 * @param array<int, array<string, mixed>> $shape    The row's fields, from the plan.
	 * @param string                           $language Language the seeding page is in; stamped on each record.
	 * @return int How many records were created.
	 */
	private static function seed_records( string $type, array $rows, array $shape, string $language = '' ): int {
		if ( '' === $type || array() === $rows ) {
			return 0;
		}

		/*
		 * The first text field is what a record is called. It is the only part
		 * of a card that has to become something other than a field: WordPress
		 * lists records by title, and a list of "Auto Draft" is not a list.
		 */
		$titles = '';

		foreach ( $shape as $field ) {
			if ( 'text' === (string) ( $field['type'] ?? '' ) ) {
				$titles = (string) $field['name'];
				break;
			}
		}

		$made = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = '' !== $titles ? trim( (string) ( $row[ $titles ] ?? '' ) ) : '';

			if ( '' === $title ) {
				continue;
			}

			/*
			 * One record per title AND language, not one per title. A Russian
			 * store card that keeps its product's Latin name — "Digital
			 * Toolkit" — used to match the English record and seed nothing,
			 * and the Russian listing, filtered to Russian records, came up
			 * short. A same-titled record with no language stamp is the old
			 * spelling of "mine": it gets the stamp instead of a twin.
			 */
			$twins = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'any',
					'posts_per_page'   => 10,
					'title'            => $title,
					'suppress_filters' => false,
				)
			);

			$mine = null;

			foreach ( $twins as $twin ) {
				$stamped = (string) get_post_meta( (int) $twin->ID, self::LANG_META, true );

				if ( $stamped === $language || ( '' === $stamped && null === $mine ) ) {
					$mine = $twin;

					if ( $stamped === $language ) {
						break;
					}
				}
			}

			if ( null !== $mine ) {
				if ( '' !== $language && '' === (string) get_post_meta( (int) $mine->ID, self::LANG_META, true ) ) {
					update_post_meta( (int) $mine->ID, self::LANG_META, $language );
				}

				continue;
			}

			$meta = array( self::OWNED_META => 'record:' . $type );

			if ( '' !== $language ) {
				$meta[ self::LANG_META ] = $language;
			}

			$id = wp_insert_post(
				array(
					'post_type'   => $type,
					'post_status' => 'publish',
					'post_title'  => $title,
					'meta_input'  => $meta,
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				continue;
			}

			foreach ( $row as $name => $value ) {
				if ( (string) $name === $titles ) {
					continue;
				}

				update_post_meta( (int) $id, (string) $name, $value );
			}

			++$made;
		}

		return $made;
	}

	/**
	 * Make the site's menu from the design's header, against the built pages.
	 *
	 * Run at the end because that is when the pages exist. Only one menu is
	 * ever kept: a rebuild replaces the entries of the one already there rather
	 * than leaving a second "Main navigation" behind for somebody to wonder
	 * about.
	 *
	 * @param array<string, mixed>                $job    Job record.
	 * @param array<string, array<string, mixed>> $routes Created pages.
	 * @return int The menu, or zero when the design has no navigation.
	 */
	private static function menu_from_design( array $job, array $routes ): int {
		$root  = (string) ( $job['root'] ?? '' );
		$front = self::front_file( $job );

		if ( '' === $root || '' === $front || array() === $routes ) {
			return 0;
		}

		/*
		 * One menu per language, because the design has one. The Russian
		 * header is not the English header translated — it even lists
		 * different pages — and one shared menu put "Reports" on a page whose
		 * design says «Отчеты». Each language's menu is read from that
		 * language's own front page and resolved only against that language's
		 * routes, which is also what stops three `about.html`s — one per
		 * language — from answering with whichever sorted last.
		 */
		$group   = self::group_of( $front );
		$primary = self::language_of( $front );
		$fronts  = array( $primary => $front );

		foreach ( $routes as $file => $made ) {
			$language = self::language_of( (string) $file );

			if ( '' !== $language && $language !== $primary && self::group_of( (string) $file ) === $group ) {
				$fronts[ $language ] = (string) $file;
			}
		}

		$menus = array();

		foreach ( $fronts as $language => $file ) {
			$mine = array();

			foreach ( $routes as $each => $made ) {
				if ( self::language_of( (string) $each ) === (string) $language ) {
					$mine[ $each ] = $made;
				}
			}

			$split = SectionSplitter::split( trailingslashit( $root ) . $file, $root );
			$html  = (string) ( $split['header']['html'] ?? '' );
			$links = self::nav_links( $html, $mine );

			if ( array() === $links && (string) $language === $primary ) {
				$html  = self::chrome_file( $root, array( 'sitenav', 'siteheader', 'nav', 'header', 'menu' ) );
				$links = self::nav_links( $html, $mine );
			}

			if ( array() === $links ) {
				continue;
			}

			$made = self::write_menu( (string) $language, $links );

			if ( $made > 0 ) {
				$menus[ (string) $language ] = $made;
			}
		}

		update_option( SiteOptions::MENUS, $menus, false );

		$main = (int) ( $menus[ $primary ] ?? 0 );

		if ( $main > 0 ) {
			update_option( SiteOptions::MENU, $main, false );
		}

		return $main;
	}

	/**
	 * Write one language's navigation: rewrite the one it has, or make it.
	 *
	 * An existing menu is rewritten rather than joined by a second one — a
	 * site that accumulates a navigation per rebuild is a site somebody has
	 * to tidy by hand. A navigation from before languages were stamped
	 * answers for the first language that asks, which is always the primary.
	 *
	 * @param string                           $language Language code; may be '' on a single-language design.
	 * @param array<int, array<string, mixed>> $links    What nav_links() read.
	 * @return int The navigation's ID, or zero.
	 */
	private static function write_menu( string $language, array $links ): int {
		$items = array();

		foreach ( $links as $link ) {
			$items[] = self::menu_item( (int) $link['id'], (string) $link['label'], (string) $link['url'] );
		}

		$mine = null;

		foreach ( self::owned_posts( array( 'wp_navigation' ) ) as $menu ) {
			$stamped = (string) get_post_meta( (int) $menu->ID, self::LANG_META, true );

			if ( $stamped === $language || ( '' === $stamped && null === $mine ) ) {
				$mine = $menu;

				if ( $stamped === $language ) {
					break;
				}
			}
		}

		if ( null !== $mine ) {
			wp_update_post(
				array(
					'ID'           => (int) $mine->ID,
					'post_content' => wp_slash( implode( "\n\n", $items ) ),
				)
			);

			if ( '' !== $language ) {
				update_post_meta( (int) $mine->ID, self::LANG_META, $language );
			}

			return (int) $mine->ID;
		}

		$id = self::create_menu( $links );

		if ( $id > 0 && '' !== $language ) {
			update_post_meta( $id, self::LANG_META, $language );

			wp_update_post(
				array(
					'ID'         => $id,
					/* translators: %s: language code, such as RU. */
					'post_title' => sprintf( __( 'Main navigation (%s)', 'qwerty-soft-signal' ), strtoupper( $language ) ),
				)
			);
		}

		return $id;
	}

	/**
	 * The first page of the job that actually has a body.
	 *
	 * A JavaScript handoff lists its `index.html` shells among the pages and
	 * they sort first, but a shell's body is one empty root element. The
	 * chrome and the menu both used to read `pages[0]` and found no header,
	 * no footer and no navigation in it — every built page came out wearing
	 * the theme's generic chrome instead of the design's own.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return string The page's file, archive-relative; '' when there are no pages.
	 */
	private static function front_file( array $job ): string {
		$pages = (array) ( $job['pages'] ?? array() );

		foreach ( $pages as $candidate ) {
			if ( empty( $candidate['shell'] ) ) {
				return (string) $candidate['file'];
			}
		}

		return (string) ( $pages[0]['file'] ?? '' );
	}

	/**
	 * Empty the header's navigation, leaving a mark where a real menu goes.
	 *
	 * Which element is the navigation is decided by what it holds rather than
	 * by what it is called: a `<nav>` when the design used one, otherwise
	 * whichever element carries the most links to other pages of the design.
	 * Handoffs are inconsistent about the tag and consistent about the shape.
	 *
	 * @param string $html The header's markup.
	 * @return string The same markup with one element emptied.
	 */
	public static function hollow_nav( string $html ): string {
		$dom = new DOMDocument();

		libxml_use_internal_errors( true );
		$ok = $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		if ( ! $ok ) {
			return $html;
		}

		$xpath      = new DOMXPath( $dom );
		$best       = null;
		$most       = 0;
		$candidates = array();

		foreach ( $xpath->query( '//nav | //ul | //div' ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$links = 0;

			foreach ( $xpath->query( './/a', $node ) as $anchor ) {
				if ( $anchor instanceof DOMElement && 1 === preg_match( '/\.html?($|[#?])/i', $anchor->getAttribute( 'href' ) ) ) {
					++$links;
				}
			}

			// A `<nav>` wins on equal numbers.
			if ( $links >= 2 ) {
				$candidates[] = array(
					'node'  => $node,
					'score' => $links * 2 + ( 'nav' === strtolower( $node->tagName ) ? 1 : 0 ),
				);
			}
		}

		/*
		 * An element that holds another candidate is not the navigation; it is
		 * the thing the navigation sits in. Counting links alone made the
		 * header's own wrapper the winner — it holds the brand link and the
		 * call to action as well as the menu, so it always has the most — and
		 * emptying it took the brand, the mobile toggle and the language chips
		 * out of every page along with the design's list of links.
		 */
		foreach ( $candidates as $candidate ) {
			foreach ( $candidates as $other ) {
				if ( $other['node'] !== $candidate['node'] && self::within( $other['node'], $candidate['node'] ) ) {
					continue 2;
				}
			}

			if ( $candidate['score'] >= $most ) {
				$most = $candidate['score'];
				$best = $candidate['node'];
			}
		}

		if ( ! $best instanceof DOMElement ) {
			return $html;
		}

		/*
		 * Only the links to other pages come out, and the mark goes where the
		 * first of them stood. Emptying the element wholesale also took the
		 * furniture standing beside the menu — this design keeps its language
		 * chips inside the same `<nav>`, and every page lost the ability to
		 * switch language so that WordPress could own a list of six links.
		 */
		$mark  = $dom->createComment( 'qs:menu' );
		$links = array();

		foreach ( $xpath->query( './/a', $best ) as $anchor ) {
			if ( $anchor instanceof DOMElement && 1 === preg_match( '/\.html?($|[#?])/i', $anchor->getAttribute( 'href' ) ) ) {
				$links[] = $anchor;
			}
		}

		if ( array() === $links ) {
			return $html;
		}

		foreach ( $links as $offset => $anchor ) {
			/*
			 * A link written as a list item takes the item with it. Leaving the
			 * `<li>` behind would leave the design's bullets and gaps standing
			 * around a menu that is no longer there.
			 */
			$doomed = $anchor;

			while ( $doomed->parentNode instanceof DOMElement
				&& $doomed->parentNode !== $best
				&& 1 === $doomed->parentNode->childNodes->length ) {
				$doomed = $doomed->parentNode;
			}

			if ( 0 === $offset ) {
				$doomed->parentNode->replaceChild( $mark, $doomed );
				continue;
			}

			$doomed->parentNode->removeChild( $doomed );
		}

		$body  = $xpath->query( '//body' )->item( 0 );
		$saved = '';

		foreach ( $body->childNodes as $child ) {
			$saved .= (string) $dom->saveHTML( $child );
		}

		return $saved;
	}

	/**
	 * Whether the model checks each reading before a block is made from it.
	 *
	 * On when the build asked for a model and one can be reached, because
	 * the difference it makes is the difference between a sidebar an editor
	 * can use and one full of `label_2`. Off when the build was sent
	 * straight through — that option is the promise of a build that costs
	 * nothing, and a review per section is a bill — off by filter for a
	 * build that must not touch the network, and off automatically when
	 * there is no route to a model. In every off case the structural names
	 * stand and everything else is identical.
	 *
	 * @return bool
	 */
	public static function reviewing(): bool {
		/**
		 * Filter whether the model reviews each section's reading.
		 *
		 * @param bool $reviewing True to ask the model what the fields should be called.
		 */
		return (bool) apply_filters( 'qwerty_soft/review_plans', self::$smart && ModelGateway::ready() );
	}

	/**
	 * Whether a section becomes a block of its own rather than a translation.
	 *
	 * On by default, because translating is what produced pages that matched
	 * neither the design nor the theme. The filter is the way back for a site
	 * that genuinely wants an import rebuilt in the theme's own blocks.
	 *
	 * @return bool
	 */
	public static function wrapping(): bool {
		/**
		 * Filter whether the importer wraps sections instead of converting them.
		 *
		 * @param bool $wrapping True to keep the design's markup in a block of its own.
		 */
		return (bool) apply_filters( 'qwerty_soft/wrap_sections', true );
	}

	/**
	 * Turn one section into a block, and return the markup that places it.
	 *
	 * @param array<string, mixed>                    $split    The page the section came from.
	 * @param string                                  $file     Page file, relative to the design root.
	 * @param array<string, mixed>                    $section  The section.
	 * @param CssIndex                                $styles   The design's stylesheets.
	 * @param array<string, array{id:int,url:string}> $media    Imported media map.
	 * @param string                                  $page_dir Where the page sits, for relative images.
	 * @return array{markup:string,concerns:array<int,string>}|null
	 */
	private static function wrap_section( array $split, string $file, array $section, CssIndex $styles, array $media, string $page_dir ): ?array {
		$html = (string) $section['html'];

		if ( '' === trim( $html ) ) {
			return null;
		}

		$plan     = SectionPlan::of( $html );
		$title    = (string) $section['label'];
		$singular = '';

		/*
		 * A name that says where it came from, and a digest so that two
		 * sections with the same heading on the same page cannot claim one
		 * directory. The digest is of the markup, so rebuilding an unchanged
		 * design reuses the block rather than piling up near-duplicates.
		 *
		 * The digest goes on LAST, after the length limit has had its say. It
		 * used to ride inside one long string that the slug then truncated —
		 * and a page whose file name alone filled the forty characters
		 * truncated the digest clean off, so every section of
		 * `china-kazakhstan-frozen-potato-ranking-…` collapsed into a single
		 * block directory and the page rendered one section four times.
		 */
		$digest = substr( md5( $html ), 0, 6 );
		$slug   = BlockWriter::slug( basename( $file, '.html' ) . '-' . (string) $section['label'] );
		$slug   = BlockWriter::slug( substr( $slug, 0, 33 ) . '-' . $digest );

		if ( '' === $slug ) {
			return null;
		}

		$dir   = BlockWriter::dir() . '/' . $slug;
		$fresh = ! BlockWriter::current( $dir );

		/*
		 * The model's one remaining job, and it runs only for a block that is
		 * about to be written.
		 *
		 * Structure is decided in code — a heading is editable because it is a
		 * heading — but code cannot judge. It names a field after whatever
		 * class the designer used, which gives an editor a sidebar of
		 * `subheading`, `btn` and `label_2`, and it counts six siblings without
		 * knowing whether they are the site's reports or a fixed set of tiles.
		 * Those are the questions a person would answer while explaining the
		 * section, and they are what is asked here.
		 *
		 * Asking again for a block that already exists would be worse than
		 * wasteful. The template is not rewritten, so it keeps the names it was
		 * built with, and a model asked twice is under no obligation to answer
		 * identically — the values would then be written under names the
		 * template does not read, and every field on a rebuilt page would come
		 * out empty.
		 */
		if ( $fresh && self::reviewing() ) {
			$checked  = PlanReview::of( $html, $plan, $title );
			$plan     = (array) $checked['plan'];
			$title    = (string) $checked['title'];
			$singular = (string) $checked['item'];

			// A call is a call, answered or not; the report prices both.
			if ( is_array( $checked['call'] ?? null ) ) {
				self::$reviews[] = $checked['call'];
			}
		}

		if ( ! $fresh ) {
			$plan = BlockWriter::adopt( $plan, $dir );

			// The model is not asked twice; the block remembers its own answer.
			if ( '' === $singular ) {
				$singular = BlockWriter::singular_of( $dir );
			}
		}

		/*
		 * A listing that cannot name its record is not a listing. With no
		 * singular there is no post type to seed, so nothing puts the cards
		 * into records — and values() leaves a listing's rows out of the
		 * block on purpose. Between the two, the section rendered its frame
		 * around an empty grid: the roles, the journey steps, every card the
		 * designer wrote, gone. A fixed set of cards stored with the block is
		 * what the section actually is.
		 *
		 * Fresh blocks only: an existing block already told adopt() what it
		 * is, and a real listing legitimately has no singular on a rebuild —
		 * the review that named it does not run twice.
		 */
		if ( $fresh && 'listing' === ( $plan['kind'] ?? '' ) && '' === trim( $singular ) ) {
			Lessons::note( 'listing_downgraded' );

			$plan['kind'] = 'repeat';
		}

		/*
		 * The pictures, before the markup is frozen into a template.
		 *
		 * A wrapped section keeps the archive's own addresses, and the archive
		 * is not where the site serves from — `img/band.jpg` inside a block
		 * directory resolves to nothing. Both spellings have to be caught: the
		 * `src` on an `<img>`, and the `url()` inside an inline style, which is
		 * where every full-bleed band in this design keeps its picture.
		 */
		$html = SiteBuilder::relink_media( $html, $media, $page_dir );
		$html = SiteBuilder::relink_css_urls( $html, $media, $page_dir );

		/*
		 * An existing block is left exactly as it is. Editing a generated
		 * render.php by hand is expected — it is ordinary theme code once it
		 * is written — and a rebuild that overwrote it would throw that away
		 * without asking.
		 *
		 * The exception is a block written by an older version of the writer,
		 * which can be wrong in ways no editor caused. The first ones named
		 * only ACF as their renderer and so drew nothing at all on a site
		 * without the plugin; leaving those alone would mean the fix never
		 * reached the pages that needed it.
		 */
		if ( $fresh ) {
			$written = BlockWriter::write(
				$html,
				$plan,
				$slug,
				$title,
				SiteBuilder::relink_css_urls( $styles->rules_for( (string) $section['html'] ), $media, $page_dir ),
				$dir,
				$file,
				'block',
				false,
				$singular
			);

			if ( null === $written ) {
				return null;
			}
		}

		/*
		 * Read from the original, not from the relinked copy: a field's value
		 * is resolved to an attachment id, and resolving is done against the
		 * archive's own path.
		 */
		$values = BlockWriter::values( (string) $section['html'], $plan );
		$values = self::resolve_images( $values, $plan, $media, $page_dir );

		/*
		 * A listing's cards become records, and the block then draws whichever
		 * of them the site currently has. So the cards' own values are taken
		 * out of the block: leaving them would put the same six reports in the
		 * page as well as in the list, and editing one would not change the
		 * other.
		 */
		if ( 'listing' === ( $plan['kind'] ?? '' ) && '' !== $singular ) {
			$cards = self::resolve_images(
				array( 'items' => BlockWriter::rows_of( (string) $section['html'], $plan ) ),
				$plan,
				$media,
				$page_dir
			);

			$record_type = DesignType::for_singular( $singular );

			if ( 'product' !== $record_type || ! self::$catalog ) {
				self::seed_records(
					$record_type,
					(array) ( $cards['items'] ?? array() ),
					(array) ( $plan['item']['fields'] ?? array() ),
					self::language_of( $file )
				);
			}
		}

		return array(
			'markup'   => BlockWriter::instance( $slug, $plan, $values ),
			'concerns' => array(),
		);
	}

	/**
	 * Swap the archive's own image paths for the attachments they were imported as.
	 *
	 * @param array<string, mixed>                    $values   What the section said.
	 * @param array<string, mixed>                    $plan     What SectionPlan made of it.
	 * @param array<string, array{id:int,url:string}> $media    Imported media map.
	 * @param string                                  $page_dir Where the page sits.
	 * @return array<string, mixed>
	 */
	public static function resolve_images( array $values, array $plan, array $media, string $page_dir ): array {
		$images = array();

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			if ( 'image' === (string) ( $field['type'] ?? '' ) ) {
				$images[] = (string) $field['name'];
			}
		}

		foreach ( $images as $name ) {
			if ( ! isset( $values[ $name ] ) || ! is_string( $values[ $name ] ) ) {
				continue;
			}

			$found = SiteBuilder::attachment_for( (string) $values[ $name ], $media, $page_dir );

			/*
			 * An id, not a URL. An ACF image field holding a bare address
			 * renders but cannot be changed from the library, which is the one
			 * thing the field was added for.
			 */
			$values[ $name ] = null === $found ? '' : (int) $found['id'];
		}

		$rows = isset( $values['items'] ) && is_array( $values['items'] ) ? $values['items'] : null;
		$item = $plan['item'] ?? null;

		if ( null === $rows || ! is_array( $item ) ) {
			return $values;
		}

		$row_images = array();

		foreach ( (array) ( $item['fields'] ?? array() ) as $field ) {
			if ( 'image' === (string) ( $field['type'] ?? '' ) ) {
				$row_images[] = (string) $field['name'];
			}
		}

		foreach ( $rows as $index => $row ) {
			foreach ( $row_images as $name ) {
				if ( ! isset( $row[ $name ] ) || ! is_string( $row[ $name ] ) ) {
					continue;
				}

				$found = SiteBuilder::attachment_for( (string) $row[ $name ], $media, $page_dir );

				$rows[ $index ][ $name ] = null === $found ? '' : (int) $found['id'];
			}
		}

		$values['items'] = $rows;

		return $values;
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
				__( 'JS-rendered visuals were stood in for by screenshots from the design: %s', 'qwerty-soft-signal' ),
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
					'qwerty-soft-signal'
				),
				$loops,
				$items
			);
		}

		$unresolved = array_values( array_filter( (array) ( $expanded['unresolved'] ?? array() ), 'is_string' ) );

		if ( array() !== $unresolved ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated list of template variable names. */
				__( 'The design left placeholders to fill in: %s', 'qwerty-soft-signal' ),
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
			// Text, not markup: a heading written as "Reports &amp; Store" names the page "Reports & Store".
			$text = self::plain_title( $heading[1] );

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
	 * Whether the design's own sections already open with a first-level heading.
	 *
	 * @param array<int, array<string, mixed>> $sections The sections as read.
	 * @return bool
	 */
	private static function heads_itself( array $sections ): bool {
		foreach ( $sections as $section ) {
			if ( ! empty( $section['excluded'] ) || '' === (string) ( $section['markup'] ?? '' ) ) {
				continue;
			}

			if ( str_contains( (string) ( $section['html'] ?? '' ), '<h1' ) ) {
				return true;
			}
		}

		return false;
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
	 * The language a design file belongs to, or '' when it is not in one.
	 *
	 * @param string $file Archive-relative path.
	 * @return string
	 */
	private static function language_of( string $file ): string {
		return class_exists( DesignArchive::class ) ? DesignArchive::language_in( $file ) : '';
	}

	/**
	 * What ties a page to the same page in the other languages.
	 *
	 * The path with the language folder taken out of it. `en/reports.html`,
	 * `ru/reports.html` and `zh/reports.html` all come back as
	 * `reports.html`, which is exactly the relationship a reader means by
	 * "the same page in Russian" — and it is what the language switcher, the
	 * hreflang tags and anything else joining translations up can be built on
	 * without a taxonomy or a plugin.
	 *
	 * @param string $file Archive-relative path.
	 * @return string
	 */
	private static function group_of( string $file ): string {
		$language = self::language_of( $file );
		$path     = self::archive_path( $file );

		if ( '' === $language ) {
			return $path;
		}

		$out = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( $segment !== $language ) {
				$out[] = $segment;
			}
		}

		return implode( '/', $out );
	}

	/**
	 * The page a language's pages hang under, made on first use.
	 *
	 * Zero for the primary language, which stays at the root of the site.
	 *
	 * @param string $primary  The language that keeps the root of the site.
	 * @param bool   $publish  Publish rather than draft.
	 * @param string $language Language code.
	 * @return int
	 */
	private static function language_parent( string $primary, bool $publish, string $language ): int {
		if ( '' === $language || $language === $primary ) {
			return 0;
		}

		$existing = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'any',
				'name'             => $language,
				'post_parent'      => 0,
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		if ( array() !== $existing ) {
			return (int) $existing[0]->ID;
		}

		$id = wp_insert_post(
			array(
				'post_type'     => 'page',
				'post_status'   => $publish ? 'publish' : 'draft',
				'post_title'    => strtoupper( $language ),
				'post_name'     => $language,
				'post_parent'   => 0,
				'page_template' => BlockConverter::faithful() ? 'page-design' : 'page-landing',
				'meta_input'    => array(
					self::OWNED_META => 'language:' . $language,
					self::LANG_META  => $language,
				),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * A permalink slug for a design file.
	 *
	 * @param string $file Relative path.
	 * @return string
	 */
	public static function slug_for( string $file ): string {
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
		$unresolved = array();
		$index      = self::link_index( $routes );

		foreach ( $routes as $file => $made ) {
			$post = get_post( (int) $made['id'] );

			if ( null === $post ) {
				continue;
			}

			/*
			 * Where this page sat in the archive, because that is what its own
			 * links were written against: `reports.html` inside `en/` means the
			 * English one, and `../ru/reports.html` means the Russian one.
			 */
			$dir     = self::archive_dir( (string) $file );
			$content = self::relink_markup( (string) $post->post_content, $index, $dir, $unresolved );
			$content = self::relink_block_data( $content, $index, $dir, $unresolved );

			if ( $content !== $post->post_content ) {
				wp_update_post(
					array(
						'ID'           => (int) $made['id'],
						'post_content' => wp_slash( $content ),
					)
				);
			}
		}

		self::relink_blocks( $index, $unresolved );
		self::relink_options( $index, $unresolved );
		self::relink_menus( $index, $unresolved );

		return $unresolved;
	}

	/**
	 * Point the menu at the pages that were built.
	 *
	 * The menu is made before them, on the design's own hrefs, which is what
	 * lets the header and footer exist from the third step instead of the last
	 * — so at this point every entry still says `reports.html`. Rewriting them
	 * here is the other half of that trade.
	 *
	 * `refresh_menus()` finishes the job afterwards by turning a custom link
	 * into a real page link once the page is published; this only has to get
	 * the address right.
	 *
	 * @param array<string, mixed> $index      Link index built from the routes.
	 * @param array<int, string>   $unresolved Collected links that matched nothing.
	 * @return void
	 */
	private static function relink_menus( array $index, array &$unresolved ): void {
		$origin = (string) get_option( SiteOptions::ORIGIN, '' );
		$dir    = '' !== $origin ? self::archive_dir( $origin ) : '';

		foreach ( self::owned_posts( array( 'wp_navigation' ) ) as $menu ) {
			$blocks  = parse_blocks( (string) $menu->post_content );
			$changed = false;

			foreach ( $blocks as &$block ) {
				if ( 'core/navigation-link' !== ( $block['blockName'] ?? '' ) ) {
					continue;
				}

				$href = (string) ( $block['attrs']['url'] ?? '' );

				if ( '' === $href || 1 === preg_match( '#^(https?:)?//|^(mailto|tel):|^\##i', $href ) ) {
					continue;
				}

				$parts = explode( '#', $href, 2 );
				$url   = self::built_url( $index, $dir, $parts[0] );

				if ( '' === $url ) {
					if ( 1 === preg_match( '/\.html?$/i', $parts[0] ) ) {
						$leaf                = strtolower( basename( $parts[0] ) );
						$unresolved[ $leaf ] = ( $unresolved[ $leaf ] ?? 0 ) + 1;
					}

					continue;
				}

				$block['attrs']['url'] = $url . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );

				$changed = true;
			}

			unset( $block );

			if ( ! $changed ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'           => (int) $menu->ID,
					'post_content' => wp_slash( serialize_blocks( $blocks ) ),
				)
			);
		}
	}

	/**
	 * Point the site's own stored links at the pages that were built.
	 *
	 * A button in the footer is a link field, so its address is an option row
	 * rather than an `href` in any markup — invisible to the pass that rewrites
	 * pages and invisible to the one that rewrites templates. Left out, every
	 * link in a wrapped footer still pointed into the archive.
	 *
	 * @param array<string, mixed> $index      Link index built from the routes.
	 * @param array<int, string>   $unresolved Collected links that matched nothing.
	 * @return void
	 */
	private static function relink_options( array $index, array &$unresolved ): void {
		$origin = (string) get_option( SiteOptions::ORIGIN, '' );

		if ( '' === $origin ) {
			return;
		}

		$dir = self::archive_dir( $origin );

		foreach ( SiteOptions::all() as $name => $value ) {
			if ( ! is_array( $value ) || ! isset( $value['url'] ) || ! is_string( $value['url'] ) ) {
				continue;
			}

			$href = $value['url'];

			if ( '' === $href || 1 === preg_match( '#^(https?:)?//|^(mailto|tel):|^\##i', $href ) ) {
				continue;
			}

			$parts = explode( '#', $href, 2 );
			$url   = self::built_url( $index, $dir, $parts[0] );

			if ( '' === $url ) {
				if ( 1 === preg_match( '/\.html?$/i', $parts[0] ) ) {
					$leaf                = strtolower( basename( $parts[0] ) );
					$unresolved[ $leaf ] = ( $unresolved[ $leaf ] ?? 0 ) + 1;
				}

				continue;
			}

			$value['url'] = $url . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );

			update_option( (string) $name, $value, false );
		}
	}

	/**
	 * Point the links inside generated blocks at the pages that were built.
	 *
	 * A wrapped section keeps the design's own anchors, and they live in the
	 * block's `render.php` rather than in any post — so the pass that rewrites
	 * a page's links never saw them, and every navigation link in a wrapped
	 * build still pointed at `reports.html`.
	 *
	 * Which page a block came from is read back out of its manifest, because
	 * the design's links are relative and `reports.html` means the English one
	 * from inside `en/` and the Russian one from inside `ru/`.
	 *
	 * @param array<string, mixed> $index      Link index built from the routes.
	 * @param array<int, string>   $unresolved Collected links that matched nothing.
	 * @return void
	 */
	private static function relink_blocks( array $index, array &$unresolved ): void {
		$found = glob( BlockWriter::dir() . '/*/block.json' );

		if ( ! is_array( $found ) ) {
			return;
		}

		foreach ( $found as $manifest ) {
			$raw = file_get_contents( $manifest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by this importer.

			if ( false === $raw ) {
				continue;
			}

			$json   = json_decode( $raw, true );
			$origin = is_array( $json ) ? (string) ( $json['qsDesignOrigin'] ?? '' ) : '';

			if ( '' === $origin ) {
				continue;
			}

			$template = dirname( $manifest ) . '/render.php';

			if ( ! is_file( $template ) ) {
				continue;
			}

			$body = file_get_contents( $template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by this importer.

			if ( false === $body ) {
				continue;
			}

			$linked = self::relink_markup( $body, $index, self::archive_dir( $origin ), $unresolved );
			$linked = self::relink_literals( $linked, $index, self::archive_dir( $origin ), $unresolved );

			if ( $linked !== $body ) {
				file_put_contents( $template, $linked ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A file inside the theme, written by this importer.
			}
		}
	}

	/**
	 * Delete the blocks an import generated.
	 *
	 * These are files in the theme rather than rows in the database, which
	 * breaks the promise that an import can be taken back out in one press —
	 * so removing them is part of the undo rather than a separate housekeeping
	 * job. Only `blocks/design/` is touched: the theme's own six blocks sit a
	 * level above it and are never generated.
	 *
	 * @return int How many blocks were removed.
	 */
	private static function remove_blocks(): int {
		$root = BlockWriter::dir();

		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$dir = (string) $dir;

			/*
			 * Guarded rather than trusted. This deletes files, and a glob that
			 * somehow escaped the directory it was rooted at would delete the
			 * wrong ones — so the path is resolved and checked against the
			 * root before anything is unlinked.
			 */
			$real = realpath( $dir );
			$base = realpath( $root );

			if ( false === $real || false === $base || ! str_starts_with( $real, $base ) ) {
				continue;
			}

			foreach ( (array) glob( $real . '/*' ) as $file ) {
				if ( is_file( (string) $file ) ) {
					wp_delete_file( (string) $file );
				}
			}

			if ( @rmdir( $real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A directory somebody added a file to is left alone rather than reported.
				++$removed;
			}
		}

		@rmdir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Removed only when it is already empty.

		return $removed;
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
	 * @param string                                  $root      Design root.
	 * @param string                                  $file      A page to read the chrome from.
	 * @param array<string, array<string, mixed>>     $routes Created pages.
	 * @param BlockConverter|null                     $converter Converter primed with the design; the footer is converted with it.
	 * @param array<string, array{id:int,url:string}> $media     Imported media map.
	 */
	private static function build_chrome( string $root, string $file, array $routes, ?BlockConverter $converter = null, array $media = array() ): array {
		$split = SectionSplitter::split( trailingslashit( $root ) . $file, $root );
		$menu  = 0;
		$parts = array();

		/*
		 * Where the chrome stood in the archive, recorded before anything is
		 * made from it. The design's links are relative — `about.html` written
		 * inside `en/` means the English one — so the last step cannot rewrite
		 * them to real pages without knowing this. Set here rather than only
		 * where the footer is wrapped, because the menu needs it whether or not
		 * there was a footer to wrap.
		 */
		update_option( SiteOptions::ORIGIN, $file, false );

		$header_html = (string) ( $split['header']['html'] ?? '' );
		$links       = self::nav_links( $header_html, $routes );

		/*
		 * Some exports keep the navigation in its own file rather than in
		 * every page's header. Look for it by name before giving up on having
		 * a menu at all — but only take it when it actually holds one: the
		 * old unconditional swap replaced a real header whose links merely
		 * failed to match with an empty string, and the site got the theme's
		 * generic chrome instead of the design's.
		 */
		if ( array() === $links ) {
			$named = self::chrome_file( $root, array( 'sitenav', 'siteheader', 'nav', 'header', 'menu' ) );
			$found = self::nav_links( $named, $routes );

			if ( array() !== $found ) {
				$header_html = $named;
				$links       = $found;
			}
		}

		/*
		 * The menu is made at the end, not here.
		 *
		 * The chrome is built before any page exists, so a menu made now could
		 * only hold the design's own addresses — and a custom link of
		 * `research.html` is not a URL: `esc_url()` reads the bare name as a
		 * host and renders `http://research.html`. Every visitor during the
		 * build would see a menu of links to nowhere.
		 *
		 * It costs nothing to wait, because the wrapped header looks the menu
		 * up when it draws rather than holding an id it was born with. Until
		 * finish() makes one, the header renders without a navigation and then
		 * gains it, correct, in one step.
		 */
		if ( array() !== $routes && array() !== $links ) {
			/*
			 * One menu per site, not one per build.
			 *
			 * A menu this importer already made is taken as the menu, and
			 * finish() rewrites its entries. Making a fresh one here instead
			 * left a navigation behind on every rebuild — four of them after
			 * two runs — with the header pointing at whichever the last step
			 * happened to pick. The only reason to make one this early is the
			 * plain header below, which bakes the id into its markup.
			 */
			$made = self::owned_posts( array( 'wp_navigation' ) );
			$menu = array() === $made ? self::create_menu( $links ) : (int) $made[0]->ID;
		}

		$identity = self::identity( $header_html, $root, (string) dirname( $file ) );

		if ( 0 !== $menu || ( self::wrapping() && '' !== trim( $header_html ) ) ) {
			/*
			 * The header, wrapped like everything else.
			 *
			 * It used to be the one part of a design that was thrown away and
			 * rebuilt: `header_markup()` emits the theme's own site title
			 * beside a navigation block, which loses the brand mark, the call
			 * to action, the language chips and every class the design's
			 * stylesheet aims at the top of the page. The first thing anybody
			 * looks at was the one thing that never matched the mockup.
			 *
			 * The menu stays a real menu inside it — the design's list of
			 * links is emptied and a navigation block rendered in its place —
			 * so adding a page still means adding it once.
			 */
			$wrapped = self::wrapping()
				? self::wrap_chrome( $root, $file, 'header', $header_html, $media, true )
				: '';

			$parts[] = self::write_part(
				'header',
				'' !== $wrapped ? $wrapped : self::header_markup( $menu, $identity['logo'] > 0 )
			);
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

		/*
		 * Wrapped rather than converted, and its fields put on the options
		 * page rather than on the block.
		 *
		 * The footer is on every page. Held as block content it would have to
		 * be edited inside a template part, and a second copy of it — a footer
		 * on a landing page, say — would drift away from the first. Held as
		 * site content it is one telephone number, changed once, under
		 * Appearance → Site content.
		 */
		if ( self::wrapping() && '' !== trim( $footer_html ) ) {
			$footer = self::wrap_chrome( $root, $file, 'footer', $footer_html, $media );
		}

		if ( '' === $footer && null !== $converter && is_array( $split['footer'] ?? null ) ) {
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
				'title'     => 'header' === $area ? __( 'Header', 'qwerty-soft-signal' ) : __( 'Footer', 'qwerty-soft-signal' ),
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

			/*
			 * An application's links are routes, not files: `/services`,
			 * `/my-project/projects`. The page each route became knows the
			 * slug it was given, and matching on it is what lets the header
			 * of a rendered SPA keep its navigation — matched by file name
			 * alone, every one of those links dropped and the header was
			 * thrown away as having no menu.
			 */
			$slug = strtolower( (string) ( $made['slug'] ?? '' ) );

			if ( '' !== $slug && ! isset( $lookup[ $slug ] ) ) {
				$lookup[ $slug ] = $made;
			}
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

			/*
			 * The language switcher lives inside the design's nav, but it is
			 * not navigation — the header draws its chips separately, from
			 * the options. Read into the menu as links they doubled: the menu
			 * ended in "RU · 中文" twice over, once as chips and once as
			 * entries.
			 */
			$around = $anchor->parentNode instanceof DOMElement ? $anchor->parentNode->getAttribute( 'class' ) : '';

			if ( 1 === preg_match( '/(^|[\s_-])lang(uage)?([\s_-]|$)/i', $anchor->getAttribute( 'class' ) . ' ' . $around ) ) {
				continue;
			}

			$pieces   = explode( '#', $href, 2 );
			$target   = strtolower( basename( $pieces[0] ) );
			$fragment = isset( $pieces[1] ) ? sanitize_title( $pieces[1] ) : '';

			// The whole path as a slug, the way a route's page was named from it.
			$slugged = trim( (string) preg_replace( '#[^a-z0-9]+#', '-', strtolower( trim( $pieces[0], '/' ) ) ), '-' );

			/*
			 * Two ways of reading the same navigation, because the menu is now
			 * built before the pages it points at.
			 *
			 * With pages built, a link is kept when it names one of them. With
			 * none built yet — which is every build, since the chrome comes
			 * first — anything that names an HTML file is kept as the archive
			 * wrote it, and finish() rewrites those to real pages once they
			 * exist. Keeping only what the design linked is what stops the
			 * menu filling with in-page anchors.
			 */

			/*
			 * The whole path first, the bare file name second. Matched the
			 * other way round, `/my-project/projects` answers to its last
			 * folder and lands on the Projects page — which the menu already
			 * holds, so the entry deduplicated away and "My Project" vanished.
			 */
			$made = ( '' !== $slugged ? $lookup[ $slugged ] ?? null : null ) ?? $lookup[ $target ] ?? null;

			if ( null === $made ) {
				if ( array() !== $routes || 1 !== preg_match( '/\.html?$/i', $target ) ) {
					continue;
				}

				$made = array(
					'id'   => 0,
					'file' => $target,
					'url'  => $pieces[0],
				);
			}

			/*
			 * The logo links home and the header already renders the site
			 * title beside the menu, so keeping it would show the site's name
			 * twice in a row.
			 */
			if ( self::is_index( (string) $made['file'] ) && self::is_brand( $anchor ) ) {
				continue;
			}

			/*
			 * Two anchors into the same page, such as its proof and newsletter
			 * sections, are two menu entries. Keyed by the file rather than by
			 * an id, because before the pages exist every id is zero.
			 */
			$key = (string) $made['file'] . '#' . $fragment;

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
				'post_title'   => __( 'Main navigation', 'qwerty-soft-signal' ),
				'post_content' => wp_slash( implode( "\n\n", $items ) ),
				'meta_input'   => array( self::OWNED_META => 'navigation' ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		/*
		 * Remembered, because a wrapped header looks the menu up when it draws
		 * rather than holding an id it was born with. The header is generated
		 * once and never rewritten, while every rebuild makes a fresh one.
		 */
		update_option( SiteOptions::MENU, (int) $id, false );

		return (int) $id;
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

		return "<!-- wp:group {\"tagName\":\"div\",\"className\":\"qs-header\",\"align\":\"full\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|30\",\"bottom\":\"var:preset|spacing|30\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<div class="wp-block-group qs-header alignfull" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">'
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

		$unresolved = array();

		/*
		 * Chrome markup is lifted from one page but shown on all of them, so
		 * there is no single directory to resolve against; the name index does
		 * the work, and an unambiguous name is what a header links to anyway.
		 */
		return self::dated( self::relink_markup( $markup, self::link_index( $routes ), '', $unresolved ) );
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
		if ( str_contains( $markup, 'wp:qs/colophon' ) ) {
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
						'blockName'    => 'qs/colophon',
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
			'blockName'    => 'qs/colophon',
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
	 * @param string                                                                $content    Block markup.
	 * @param array{path:array<string,string>,name:array<string,array<int,string>>} $index      Index from link_index().
	 * @param string                                                                $dir        Archive directory the markup came from, or '' when it came from several.
	 * @param array<string, int>                                                    $unresolved Counts of targets with no page, updated in place.
	 * @return string
	 */
	private static function relink_markup( string $content, array $index, string $dir, array &$unresolved ): string {
		return (string) preg_replace_callback(
			'#href="([^"]+)"#i',
			static function ( array $link ) use ( $index, $dir, &$unresolved ): string {
				$href = $link[1];

				if ( 1 === preg_match( '#^(https?:)?//|^(mailto|tel):|^\##i', $href ) ) {
					return $link[0];
				}

				$parts  = explode( '#', $href, 2 );
				$target = $parts[0];
				$url    = self::built_url( $index, $dir, $target );

				if ( '' === $url ) {
					if ( 1 === preg_match( '/\.html?$/i', $target ) ) {
						$name                = strtolower( basename( $target ) );
						$unresolved[ $name ] = ( $unresolved[ $name ] ?? 0 ) + 1;
					}

					return $link[0];
				}

				return 'href="' . esc_url( $url . ( isset( $parts[1] ) ? '#' . $parts[1] : '' ) ) . '"';
			},
			$content
		);
	}

	/**
	 * Rewrite the addresses a wrapped block carries as field values.
	 *
	 * A button inside a wrapped section is a link field, so its address is not
	 * an `href` in the markup — it is a value in the block comment's JSON, and
	 * the pass that rewrites `href="…"` walked straight past it. The symptom
	 * was a build whose pages linked correctly everywhere except on the
	 * buttons, which is the one place a visitor actually clicks.
	 *
	 * @param string               $content    Post content.
	 * @param array<string, mixed> $index      Link index built from the routes.
	 * @param string               $dir        Where the page sat in the archive.
	 * @param array<int, string>   $unresolved Collected links that matched nothing.
	 * @return string
	 */
	private static function relink_block_data( string $content, array $index, string $dir, array &$unresolved ): string {
		return (string) preg_replace_callback(
			'#(<!-- wp:qs/design-[a-z0-9-]+ )(\{.*?\})( /-->)#s',
			static function ( array $found ) use ( $index, $dir, &$unresolved ): string {
				$attributes = json_decode( $found[2], true );

				if ( ! is_array( $attributes ) || ! isset( $attributes['data'] ) || ! is_array( $attributes['data'] ) ) {
					return $found[0];
				}

				$changed = false;

				foreach ( $attributes['data'] as $name => $value ) {
					if ( ! is_array( $value ) || ! isset( $value['url'] ) || ! is_string( $value['url'] ) ) {
						continue;
					}

					$href = $value['url'];

					if ( '' === $href || 1 === preg_match( '#^(https?:)?//|^(mailto|tel):|^\##i', $href ) ) {
						continue;
					}

					$parts = explode( '#', $href, 2 );
					$url   = self::built_url( $index, $dir, $parts[0] );

					if ( '' === $url ) {
						if ( 1 === preg_match( '/\.html?$/i', $parts[0] ) ) {
							$leaf                = strtolower( basename( $parts[0] ) );
							$unresolved[ $leaf ] = ( $unresolved[ $leaf ] ?? 0 ) + 1;
						}

						continue;
					}

					$attributes['data'][ $name ]['url'] = $url . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );

					$changed = true;
				}

				if ( ! $changed ) {
					return $found[0];
				}

				return $found[1] . (string) wp_json_encode( $attributes ) . $found[3];
			},
			$content
		);
	}

	/**
	 * Two ways of finding a built page: by where it sat, and by what it is called.
	 *
	 * @param array<string, array<string, mixed>> $routes Created pages, keyed by archive path.
	 * @return array{path:array<string,string>,name:array<string,array<int,string>>}
	 */
	private static function link_index( array $routes ): array {
		$by_path = array();
		$by_name = array();

		foreach ( $routes as $file => $made ) {
			$path             = self::archive_path( (string) $file );
			$by_path[ $path ] = (string) $made['url'];

			$by_name[ strtolower( basename( $path ) ) ][] = (string) $made['url'];
		}

		return array(
			'path' => $by_path,
			'name' => $by_name,
		);
	}

	/**
	 * The page a relative link points at, or an empty string.
	 *
	 * Exact match on the resolved path first. A design that ships the same
	 * page in three languages has three files called `reports.html`, and
	 * matching on the name alone sent every link in all three to whichever one
	 * happened to be built last — the whole Russian site linking to English
	 * pages. The name is still tried afterwards, because an archive that keeps
	 * its pages in one folder and links them sloppily is common and harmless;
	 * it is only used when exactly one page answers to that name.
	 *
	 * @param array{path:array<string,string>,name:array<string,array<int,string>>} $index  Index from link_index().
	 * @param string                                                                $dir    Archive directory of the page holding the link.
	 * @param string                                                                $target Href, without any fragment.
	 * @return string
	 */
	private static function built_url( array $index, string $dir, string $target ): string {
		$resolved = self::archive_path( '' === $dir || str_starts_with( $target, '/' ) ? $target : $dir . '/' . $target );

		if ( isset( $index['path'][ $resolved ] ) ) {
			return $index['path'][ $resolved ];
		}

		/*
		 * A link to a directory is a link to its index page. The header's
		 * language chips say `../ru/`, and left as written they resolve
		 * against wherever the visitor happens to stand — from a
		 * subdirectory install's front page that walks OUT of the site and
		 * lands on `http://localhost/ru/`, which is nobody's page at all.
		 */
		if ( '' !== $resolved && str_ends_with( trim( $target ), '/' ) ) {
			if ( isset( $index['path'][ $resolved . '/index.html' ] ) ) {
				return $index['path'][ $resolved . '/index.html' ];
			}

			/*
			 * A two-letter language directory whose pages were not built —
			 * a single-language import. The chip still has to stay inside
			 * this site: the language home under the site's own address is
			 * where a later multilingual build will put it.
			 */
			if ( 1 === preg_match( '#(^|/)([a-z]{2}(?:-[a-z]{2})?)$#', $resolved, $found ) ) {
				return home_url( '/' . $found[2] . '/' );
			}
		}

		$name = strtolower( basename( $resolved ) );

		return isset( $index['name'][ $name ] ) && 1 === count( $index['name'][ $name ] )
			? $index['name'][ $name ][0]
			: '';
	}

	/**
	 * An archive path with its separators, dot segments and case settled.
	 *
	 * @param string $path Path as written.
	 * @return string
	 */
	private static function archive_path( string $path ): string {
		$out = array();

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}

			$out[] = $segment;
		}

		return strtolower( implode( '/', $out ) );
	}

	/**
	 * The archive directory a page sits in.
	 *
	 * @param string $file Archive-relative path of the page.
	 * @return string
	 */
	private static function archive_dir( string $file ): string {
		$path  = self::archive_path( $file );
		$slash = strrpos( $path, '/' );

		return false === $slash ? '' : substr( $path, 0, $slash );
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

		return "<!-- wp:group {\"tagName\":\"div\",\"className\":\"qs-footer\",\"align\":\"full\",\"backgroundColor\":\"surface\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|70\",\"bottom\":\"var:preset|spacing|70\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<div class="wp-block-group qs-footer alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">'
			. "<!-- wp:group {\"align\":\"wide\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<div class="wp-block-group alignwide">'
			. $columns
			. '<!-- wp:qs/colophon /-->'
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
			'post_title'   => 'header' === $area ? __( 'Header', 'qwerty-soft-signal' ) : __( 'Footer', 'qwerty-soft-signal' ),
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
	 * @return array{pages:int,parts:int,menus:int,media:int,fonts:int,products:int,terms:int}
	 */
	public static function reset(): array {
		$counts = array(
			'pages'    => 0,
			'parts'    => 0,
			'menus'    => 0,
			'media'    => 0,
			'fonts'    => 0,
			'blocks'   => 0,
			'options'  => 0,
			'records'  => 0,
			'products' => 0,
			'terms'    => 0,
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
				case 'product':
					++$counts['products'];
					break;
				default:
					++$counts['records'];
					break;
			}

			wp_delete_post( $post->ID, true );
		}

		$counts['terms'] = self::remove_terms();

		foreach ( self::owned_media() as $id ) {
			wp_delete_attachment( $id, true );
			++$counts['media'];
		}

		$counts['blocks']  = self::remove_blocks();
		$counts['options'] = SiteOptions::reset();

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
	 * @return array{pages:int,parts:int,menus:int,media:int,fonts:int,blocks:int}
	 */
	public static function summary(): array {
		$blocks = glob( BlockWriter::dir() . '/*/block.json' );

		$counts = array(
			'pages'    => 0,
			'parts'    => 0,
			'menus'    => 0,
			'media'    => count( self::owned_media() ),
			'fonts'    => class_exists( DesignFonts::class ) ? (int) DesignFonts::count() : 0,

			/*
			 * Counted so that "delete everything this import added" can be
			 * honest about what it will delete. These are files in the theme,
			 * and a person deciding whether to press that button should know
			 * that theme code goes with the pages.
			 */
			'blocks'   => is_array( $blocks ) ? count( $blocks ) : 0,

			/*
			 * Counted the same way reset() removes them: from the register the
			 * import wrote, so the two numbers cannot drift apart and a person
			 * pressing delete is told what will actually go.
			 */
			'options'  => count( (array) get_option( SiteOptions::REGISTER, array() ) ),
			'records'  => 0,

			/*
			 * Products the catalogue import made, and the categories it made
			 * for them. Both are counted here for the same reason as the
			 * blocks: the panel says what the button will delete, and a
			 * catalogue is the largest thing an import ever leaves behind.
			 */
			'products' => 0,
			'terms'    => count( self::owned_terms() ),
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
				case 'product':
					++$counts['products'];
					break;
				default:
					++$counts['records'];
					break;
			}
		}

		return $counts;
	}

	/**
	 * Terms the catalogue import created.
	 *
	 * Stamped when they are made, so a category the client had before the
	 * import is never a candidate for deletion — only the ones this import
	 * invented for its own products.
	 *
	 * @return array<int, \WP_Term>
	 */
	private static function owned_terms(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => self::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping, not a front-end query.
			)
		);

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Remove the categories the catalogue import invented.
	 *
	 * Called after the products themselves are gone, so a term that somehow
	 * still holds something — a product the client wrote by hand into one of
	 * our categories — is left alone with what it holds.
	 *
	 * @return int How many went.
	 */
	private static function remove_terms(): int {
		$gone = 0;

		foreach ( self::owned_terms() as $term ) {
			if ( (int) $term->count > 0 ) {
				continue;
			}

			wp_delete_term( (int) $term->term_id, 'product_cat' );
			++$gone;
		}

		return $gone;
	}

	/**
	 * Posts this importer created.
	 *
	 * @param array<int, string>|null $types Post types to include; every owned type when null.
	 * @return array<int, \WP_Post>
	 */
	private static function owned_posts( ?array $types = null ): array {
		/*
		 * The record types an import invented count as its own. They are made
		 * by the build, they carry its meta, and leaving them behind would mean
		 * "delete everything this import added" quietly kept the reports.
		 */
		$default = array_merge(
			array( 'page', 'wp_template_part', 'wp_navigation', 'wp_block' ),
			array_keys( DesignType::all() )
		);

		/*
		 * And the products, which are not an invented type but are just as
		 * much the build's doing: CatalogImport stamps every one of them with
		 * the same meta. They were missing from this list, so "delete
		 * everything this import added" deleted the pages, the menus and the
		 * pictures and left three hundred products behind — the one kind of
		 * leftover that is hardest to clear by hand.
		 *
		 * Asked for only when the type is registered. A query for a post type
		 * WordPress does not know returns nothing, so with WooCommerce
		 * deactivated the products wait for a cleanup run with it back on
		 * rather than being reported as gone.
		 */
		if ( post_type_exists( 'product' ) ) {
			$default[] = 'product';
		}

		return (array) get_posts(
			array(
				'post_type'      => $types ?? $default,
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
					'meta_key'       => '_qwerty_soft_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping.
				)
			)
		);
	}
}
