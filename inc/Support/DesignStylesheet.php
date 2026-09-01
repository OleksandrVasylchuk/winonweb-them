<?php
/**
 * The design's own stylesheet, carried onto the site as Additional CSS.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the archive's CSS as the site's custom CSS, rewritten to survive the move.
 *
 * The converter rebuilds a page out of core blocks and keeps the design's
 * class names on them. That is only half of the job: the rules those classes
 * pointed at are still in the archive. This class reads them, drops what
 * would fight WordPress — the reset, the sticky header, a second `body` —
 * repoints every `url()` at the Media Library, and appends the rest to the
 * global styles' Additional CSS between markers, so reset() can take exactly
 * that slice out again.
 *
 * Nothing is prefixed or layered. Layered CSS loses to the theme's own
 * unlayered rules and would be inert; prefixed selectors would no longer
 * match. The rules land as written and win by source order, which is the
 * point: the site is meant to look like the archive.
 */
final class DesignStylesheet {

	/**
	 * Opens the slice this class owns inside Additional CSS.
	 *
	 * @var string
	 */
	public const START = '/* qwerty-soft-signal:design-css:start */';

	/**
	 * Closes the slice.
	 *
	 * @var string
	 */
	public const END = '/* qwerty-soft-signal:design-css:end */';

	/**
	 * Most CSS that is installed, in bytes.
	 *
	 * Three hundred kilobytes was chosen when a design meant a hand-written
	 * stylesheet. A Tailwind build ships every utility the site uses in one
	 * file — the smaller of the two handoffs here is 146 KB and a larger site
	 * runs past half a megabyte — and a stylesheet cut off in the middle is
	 * not a smaller design, it is a broken one: the rules that happened to be
	 * last are exactly the ones the last sections needed.
	 *
	 * @var int
	 */
	private const CAP = 2097152;

	/**
	 * Largest single file that is read.
	 *
	 * @var int
	 */
	private const MAX_FILE = 2097152;

	/**
	 * At-rules whose block holds further rules rather than declarations.
	 *
	 * @var array<int, string>
	 */
	private const GROUP_RULES = array( 'media', 'supports', 'container', 'layer', 'document', 'scope' );

	/**
	 * At-rules that never come across.
	 *
	 * Fonts are DesignFonts' job; the others either pull remote resources or
	 * are not CSS the site should be running.
	 *
	 * @var array<int, string>
	 */
	private const DROPPED_RULES = array( 'font-face', 'import', 'charset', 'namespace' );

	/**
	 * Declarations a container-like selector is not allowed to set.
	 *
	 * When the theme is doing the styling, the block layout owns the content
	 * width and a design's `.container` narrowing or centring a block wrapper
	 * fights it. When the design is doing the styling there is no block
	 * container at all — that was taken out on purpose — and this rule is the
	 * only thing centring the page. Stripped anyway, every imported page came
	 * out flush against the left edge of the window.
	 *
	 * @var array<int, string>
	 */
	private const LAYOUT_PROPERTIES = array( 'max-width', 'margin-left', 'margin-right', 'margin-inline', 'margin-inline-start', 'margin-inline-end' );

	/**
	 * Counters for the current import.
	 *
	 * @var array{rules:int,dropped:int,unmapped:int}
	 */
	private static array $counts = array(
		'rules'    => 0,
		'dropped'  => 0,
		'unmapped' => 0,
	);

	/**
	 * Whether the compilation is for a preview frame rather than for the site.
	 *
	 * On the site the theme owns html and body, so a design's rules for them
	 * are dropped and only its variables survive. In a preview frame the body
	 * *is* the design's — and a section whose background lived on the body
	 * previewed as white text on white until this told the two apart.
	 *
	 * @var bool
	 */
	private static bool $preview = false;

	/**
	 * The design's stylesheets, gathered and rewritten, without installing them.
	 *
	 * Split out from import() for the preview, which has to show the design as
	 * the archive renders it — markup with no stylesheet behind it is not a
	 * preview of anything, and every Tailwind-shaped design previewed as a
	 * column of unstyled text until this existed.
	 *
	 * @param string                                  $root         Design root directory.
	 * @param array<string, array{id:int,url:string}> $media_map    Archive-relative path to imported attachment.
	 * @param bool                                    $for_preview  Keep the design's html and body rules, for a frame that is the design's own page.
	 * @param array<int, string>                      $pages        Pages whose styling is wanted, absolute paths; every stylesheet in the design when empty.
	 * @return string
	 */
	public static function compile( string $root, array $media_map = array(), bool $for_preview = false, array $pages = array() ): string {
		self::$preview = $for_preview;
		self::$counts  = array(
			'rules'    => 0,
			'dropped'  => 0,
			'unmapped' => 0,
		);

		$root   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$pieces = array();

		foreach ( self::gather( $root, $pages ) as $sheet ) {
			$pieces[] = self::rewrite( (string) $sheet['css'], (string) $sheet['dir'], $root, $media_map );
		}

		$css = trim( implode( "\n", array_filter( $pieces ) ) );

		if ( strlen( $css ) > self::CAP ) {
			$css = self::prune( $css, self::html_classes( $root ) );
		}

		if ( strlen( $css ) > self::CAP ) {
			$css = self::truncate( $css, self::CAP );
		}

		return $css;
	}

	/**
	 * Read the design's CSS, rewrite it, and append it to Additional CSS.
	 *
	 * @param string                                  $root      Design root directory.
	 * @param array<string, array{id:int,url:string}> $media_map Archive-relative path to imported attachment.
	 * @param array<int, string>                      $pages     Pages being built, absolute paths; every stylesheet in the design when empty.
	 * @return array{bytes:int,rules:int,dropped:int,unmapped:int,installed:bool}
	 */
	public static function import( string $root, array $media_map = array(), array $pages = array() ): array {
		$css = self::compile( $root, $media_map, false, $pages );

		self::reset();

		$installed = false;

		if ( '' !== $css ) {
			$data     = self::user_data();
			$existing = isset( $data['styles']['css'] ) ? (string) $data['styles']['css'] : '';

			$data['styles']['css'] = trim( $existing . "\n" . self::START . "\n" . $css . "\n" . self::END );

			self::save( $data );

			/*
			 * WordPress filters the global styles post on save and strips
			 * Additional CSS from anyone without `edit_css`. Say so rather
			 * than report bytes that never reached the site.
			 */
			$installed = self::installed();
		}

		return array(
			'bytes'     => $installed ? strlen( $css ) : 0,
			'rules'     => self::$counts['rules'],
			'dropped'   => self::$counts['dropped'],
			'unmapped'  => self::$counts['unmapped'],
			'installed' => $installed,
		);
	}

	/**
	 * Take the design's slice out of Additional CSS, leaving the rest as it was.
	 *
	 * @return bool Whether there was a slice to remove.
	 */
	public static function reset(): bool {
		$data     = self::user_data();
		$existing = isset( $data['styles']['css'] ) ? (string) $data['styles']['css'] : '';

		if ( ! str_contains( $existing, self::START ) ) {
			return false;
		}

		$pattern = '/\s*' . preg_quote( self::START, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\s*/s';
		$kept    = trim( (string) preg_replace( $pattern, "\n", $existing ) );

		if ( '' === $kept ) {
			unset( $data['styles']['css'] );
		} else {
			$data['styles']['css'] = $kept;
		}

		self::save( $data );

		return true;
	}

	/**
	 * Whether a design stylesheet is currently installed.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		$data = self::user_data();

		return isset( $data['styles']['css'] ) && str_contains( (string) $data['styles']['css'], self::START );
	}

	// ----------------------------------------------------------------- gather

	/**
	 * Every stylesheet in the design, each with the directory it resolves URLs from.
	 *
	 * Mirrors DesignTokens::gather_css: all .css files, then the inline
	 * `<style>` blocks of every page, because some exports ship no .css at all.
	 *
	 * @param string             $root  Design root.
	 * @param array<int, string> $pages Pages whose styling is wanted, absolute paths; the whole design when empty.
	 * @return array<int, array{css:string,dir:string}>
	 */
	private static function gather( string $root, array $pages = array() ): array {
		$sheets = array();

		if ( ! is_dir( $root ) ) {
			return $sheets;
		}

		/*
		 * Named pages are styled by what they link, and by nothing else.
		 *
		 * A developer handoff is not one design. The last one held five
		 * projects side by side — a marketing site, an admin console, two
		 * prototypes — each with a complete stylesheet of its own, each
		 * defining `:root`, `body`, `.card`, `.btn` and `.table`. Swept up
		 * together they overwrite one another in directory order, and the
		 * page comes out wearing whichever project sorted last. Following the
		 * page's own `<link>` tags gives it the stylesheet it was written
		 * against, and only that one.
		 */
		if ( array() !== $pages ) {
			return self::for_pages( $root, $pages );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		$files = array();
		$pages = array();

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getSize() >= self::MAX_FILE ) {
				continue;
			}

			$name = $file->getFilename();

			if ( 'css' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			} elseif ( 1 === preg_match( '/\.html?$/i', $name ) ) {
				$pages[] = $file->getPathname();
			}
		}

		sort( $files );
		sort( $pages );

		foreach ( $files as $path ) {
			$sheets[] = array(
				'css' => (string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
				'dir' => self::relative_dir( $path, $root ),
			);
		}

		foreach ( $pages as $path ) {
			$html = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			if ( preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $blocks ) ) {
				$sheets[] = array(
					'css' => implode( "\n", $blocks[1] ),
					'dir' => self::relative_dir( $path, $root ),
				);
			}
		}

		return $sheets;
	}

	/**
	 * The stylesheets a set of pages links, plus the CSS written inside them.
	 *
	 * @param string             $root  Design root.
	 * @param array<int, string> $pages Absolute page paths.
	 * @return array<int, array{css:string,dir:string}>
	 */
	private static function for_pages( string $root, array $pages ): array {
		$sheets = array();
		$seen   = array();

		foreach ( $pages as $page ) {
			$page = str_replace( '\\', '/', (string) $page );

			if ( ! is_file( $page ) ) {
				continue;
			}

			foreach ( self::linked_sheets( $root, $page ) as $path ) {
				if ( isset( $seen[ $path ] ) ) {
					continue;
				}

				$seen[ $path ] = true;

				$sheets[] = array(
					'css' => (string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
					'dir' => self::relative_dir( $path, $root ),
				);
			}

			$inline = self::inline_css( $page );

			if ( '' !== $inline ) {
				$sheets[] = array(
					'css' => $inline,
					'dir' => self::relative_dir( $page, $root ),
				);
			}
		}

		return $sheets;
	}

	/**
	 * The stylesheets one page links, resolved to files inside the archive.
	 *
	 * @param string $root Design root.
	 * @param string $page Absolute page path, forward slashes.
	 * @return array<int, string> Absolute stylesheet paths, in link order, each once.
	 */
	private static function linked_sheets( string $root, string $page ): array {
		$html   = (string) file_get_contents( $page ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
		$dir    = dirname( $page );
		$sheets = array();

		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $links ) ) {
			return $sheets;
		}

		foreach ( $links[0] as $tag ) {
			if ( 1 !== preg_match( '#\brel\s*=\s*["\']?stylesheet#i', $tag ) ) {
				continue;
			}

			if ( 1 !== preg_match( '#\bhref\s*=\s*["\']([^"\']+)["\']#i', $tag, $found ) ) {
				continue;
			}

			$href = trim( html_entity_decode( $found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			// A stylesheet from somewhere else on the internet is not the design's to install.
			if ( '' === $href || 1 === preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $href ) ) {
				continue;
			}

			$target = (string) strtok( $href, '?#' );
			$path   = self::beside( $dir, $target );

			/*
			 * A link that resolves to nothing is not a mistake to skip
			 * over. A handoff splits one site across several folders
			 * and zips them separately, so a page in the AI blueprint
			 * asks for `../assets/styles.css` and the file is over in
			 * the website baseline — the same stylesheet, one folder
			 * further out than the export knew about. Looked up by
			 * name, nearest copy first, the page gets the sheet it was
			 * written against instead of nothing at all.
			 */
			if ( null === $path || ! str_starts_with( $path, $root . '/' ) ) {
				$path = self::nearest_named( $root, basename( $target ), $page );
			}

			if ( null === $path || in_array( $path, $sheets, true ) ) {
				continue;
			}

			if ( ! is_file( $path ) || filesize( $path ) >= self::MAX_FILE ) {
				continue;
			}

			$sheets[] = $path;
		}

		return $sheets;
	}

	/**
	 * The CSS written inside one page's own `<style>` elements.
	 *
	 * @param string $page Absolute page path.
	 * @return string
	 */
	private static function inline_css( string $page ): string {
		$html = (string) file_get_contents( $page ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

		if ( ! preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $blocks ) ) {
			return '';
		}

		return trim( implode( "\n", $blocks[1] ) );
	}

	/**
	 * Which stylesheet source each page belongs to.
	 *
	 * A handoff is not always one site. This one carries the website baseline
	 * and an older AI-roadmap prototype side by side, each with a complete
	 * stylesheet redefining `:root`, `.brand-mark` and `.site-header`.
	 * Concatenated, whichever sorts last clobbers the other on every page —
	 * the logo came out wearing the prototype's gold outline site-wide. So
	 * pages are grouped by the set of stylesheets they actually link, and each
	 * group gets a canonical file of its own, loaded only by that group's
	 * blocks.
	 *
	 * @param string             $root  Design root.
	 * @param array<int, string> $pages Absolute page paths.
	 * @return array<string, string> Page path (normalized) to source key; '' names the primary source.
	 */
	public static function routes( string $root, array $pages ): array {
		$root   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$groups = array();

		foreach ( $pages as $page ) {
			$page = str_replace( '\\', '/', (string) $page );

			if ( ! is_file( $page ) ) {
				continue;
			}

			$signature = implode( '|', self::linked_sheets( $root, $page ) );

			$groups[ $signature ][] = $page;
		}

		/*
		 * The primary source is the one most pages wear; a tie goes to the
		 * earlier page, which a build lists front page first.
		 */
		$primary = '';
		$best    = 0;

		foreach ( $groups as $signature => $members ) {
			if ( count( $members ) > $best ) {
				$best    = count( $members );
				$primary = $signature;
			}
		}

		$routes = array();

		foreach ( $groups as $signature => $members ) {
			$key = $signature === $primary ? '' : substr( md5( (string) $signature ), 0, 8 );

			foreach ( $members as $page ) {
				$routes[ $page ] = $key;
			}
		}

		return $routes;
	}

	/**
	 * The design's CSS and scripts, compiled per stylesheet source.
	 *
	 * `compile()` concatenated everything the built pages linked, which put
	 * every page in whichever of the handoff's sites sorted last — see
	 * `routes()`. This keeps each source whole but separate, so a page loads
	 * the stylesheet it was written against and no other.
	 *
	 * @param string                                  $root      Design root directory.
	 * @param array<string, array{id:int,url:string}> $media_map Archive-relative path to imported attachment.
	 * @param array<int, string>                      $pages     Pages being built, absolute paths.
	 * @return array{sources: array<int, array{key:string, css:string, js:string}>, routes: array<string, string>}
	 */
	public static function compile_sources( string $root, array $media_map, array $pages ): array {
		self::$preview = false;
		self::$counts  = array(
			'rules'    => 0,
			'dropped'  => 0,
			'unmapped' => 0,
		);

		$root   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$routes = self::routes( $root, $pages );

		$members = array();

		foreach ( $routes as $page => $key ) {
			$members[ $key ][] = $page;
		}

		$sources = array();

		foreach ( $members as $key => $group ) {
			$pieces = array();
			$seen   = array();

			foreach ( $group as $page ) {
				foreach ( self::linked_sheets( $root, $page ) as $path ) {
					if ( isset( $seen[ $path ] ) ) {
						continue;
					}

					$seen[ $path ] = true;

					$pieces[] = self::rewrite(
						(string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
						self::relative_dir( $path, $root ),
						$root,
						$media_map
					);
				}

				$inline = self::inline_css( $page );

				if ( '' !== $inline ) {
					$pieces[] = self::rewrite( $inline, self::relative_dir( $page, $root ), $root, $media_map );
				}
			}

			$css = trim( implode( "\n", array_filter( $pieces ) ) );

			if ( strlen( $css ) > self::CAP ) {
				$css = self::prune( $css, self::html_classes( $root ) );
			}

			if ( strlen( $css ) > self::CAP ) {
				$css = self::truncate( $css, self::CAP );
			}

			$sources[] = array(
				'key' => (string) $key,
				'css' => $css,
				'js'  => self::scripts( $root, $group ),
			);
		}

		return array(
			'sources' => $sources,
			'routes'  => $routes,
		);
	}

	/**
	 * The design's own scripts, in the order its pages ask for them.
	 *
	 * The stylesheet is not the only file a page links. A design's behaviour —
	 * the button that opens the navigation on a phone, the tab strip, the
	 * accordion — lives in a script, and a wrapped section that arrives
	 * without it is a design that looks right and does nothing. This gathers
	 * those files the same way `for_pages()` gathers stylesheets: only what
	 * the pages being built actually link, each file once, resolved against
	 * the archive and never off it.
	 *
	 * Scripts from elsewhere on the internet are skipped — a CDN address is
	 * not the design's to vendor, and a site that fetches one has a
	 * third-party request the studio did not choose. Inline `<script>` is
	 * skipped too: it is usually analytics or a JSON-LD block, and the ones
	 * that are neither are page-specific in a way a shared file must not be.
	 *
	 * @param string             $root  Design root directory.
	 * @param array<int, string> $pages Pages being built, absolute paths.
	 * @return string The scripts, concatenated, each preceded by its own name.
	 */
	public static function scripts( string $root, array $pages ): string {
		$root  = rtrim( str_replace( '\\', '/', $root ), '/' );
		$seen  = array();
		$parts = array();

		foreach ( $pages as $page ) {
			$page = str_replace( '\\', '/', (string) $page );

			if ( ! is_file( $page ) ) {
				continue;
			}

			$html = (string) file_get_contents( $page ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
			$dir  = dirname( $page );

			if ( 1 !== preg_match_all( '#<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>#i', $html, $found ) && array() === ( $found[1] ?? array() ) ) {
				continue;
			}

			foreach ( (array) $found[1] as $src ) {
				$src = trim( html_entity_decode( (string) $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

				if ( '' === $src || 1 === preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $src ) ) {
					continue;
				}

				$target = (string) strtok( $src, '?#' );
				$path   = self::beside( $dir, $target );

				if ( null === $path || ! str_starts_with( $path, $root . '/' ) ) {
					$path = self::nearest_named( $root, basename( $target ), $page );
				}

				if ( null === $path || isset( $seen[ $path ] ) ) {
					continue;
				}

				$seen[ $path ] = true;

				if ( ! is_file( $path ) || filesize( $path ) >= self::MAX_FILE ) {
					continue;
				}

				$js = trim( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

				if ( '' === $js ) {
					continue;
				}

				/*
				 * Each file is wrapped, so one that ends mid-statement or
				 * declares a `const` the next one also declares cannot take
				 * the rest of the design's behaviour down with it.
				 */
				$parts[] = '/* ' . str_replace( '*/', '', self::relative_dir( $path, $root ) . '/' . basename( $path ) ) . " */\n"
					. "( function () {\n" . $js . "\n}() );";
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * The copy of a named stylesheet that sits closest to a page.
	 *
	 * Closest by shared path: a `styles.css` inside the same project beats one
	 * in a sibling project, which beats one at the far end of the archive.
	 *
	 * @param string $root Design root.
	 * @param string $name File name, no directory.
	 * @param string $page Absolute path of the page that asked for it.
	 * @return string|null
	 */
	private static function nearest_named( string $root, string $name, string $page ): ?string {
		if ( '' === $name || 'css' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return null;
		}

		$best  = null;
		$score = -1;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || $file->getFilename() !== $name || $file->getSize() >= self::MAX_FILE ) {
					continue;
				}

				$path  = str_replace( '\\', '/', $file->getPathname() );
				$share = strspn( $path ^ $page, "\0" );

				if ( $share > $score ) {
					$score = $share;
					$best  = $path;
				}
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		return $best;
	}

	/**
	 * A stylesheet href resolved against the directory it was written in.
	 *
	 * @param string $dir  Directory of the page holding the link.
	 * @param string $href Relative path.
	 * @return string|null Absolute path, or null when it cannot be resolved.
	 */
	private static function beside( string $dir, string $href ): ?string {
		$href = ltrim( str_replace( '\\', '/', $href ), '/' );
		$path = realpath( $dir . '/' . $href );

		return false === $path ? null : str_replace( '\\', '/', $path );
	}

	/**
	 * The archive-relative directory of a file, forward-slashed, no edges.
	 *
	 * @param string $path Absolute path.
	 * @param string $root Design root.
	 * @return string
	 */
	private static function relative_dir( string $path, string $root ): string {
		$path = str_replace( '\\', '/', $path );
		$rel  = str_starts_with( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : $path;
		$dir  = dirname( $rel );

		return '.' === $dir ? '' : trim( $dir, '/' );
	}

	/**
	 * Every class name used in the design's pages.
	 *
	 * Stands in for the converter's carried set when the stylesheet is too
	 * big: the converter runs page by page after the stylesheet is installed,
	 * so what it will carry is not known yet, but it cannot carry a class the
	 * pages do not use.
	 *
	 * @param string $root Design root.
	 * @return array<string, true>
	 */
	private static function html_classes( string $root ): array {
		$classes  = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 1 !== preg_match( '/\.html?$/i', $file->getFilename() ) || $file->getSize() >= self::MAX_FILE ) {
				continue;
			}

			$html = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			if ( preg_match_all( '/\sclass\s*=\s*["\']([^"\']+)["\']/i', $html, $found ) ) {
				foreach ( $found[1] as $list ) {
					$names = preg_split( '/\s+/', $list );

					foreach ( is_array( $names ) ? $names : array() as $class ) {
						if ( '' !== $class ) {
							$classes[ $class ] = true;
						}
					}
				}
			}
		}

		return $classes;
	}

	// ---------------------------------------------------------------- rewrite

	/**
	 * Rewrite one stylesheet.
	 *
	 * @param string                                  $css  Stylesheet text.
	 * @param string                                  $dir  Its archive-relative directory.
	 * @param string                                  $root Design root.
	 * @param array<string, array{id:int,url:string}> $map  Media map.
	 * @return string
	 */
	private static function rewrite( string $css, string $dir, string $root, array $map ): string {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = str_replace( array( "\r\n", "\r" ), "\n", $css );

		return self::emit( self::parse( $css ), $dir, $root, $map );
	}

	/**
	 * Serialise a parsed tree, rewriting as it goes.
	 *
	 * @param array<int, array<string, mixed>>        $nodes Parsed nodes.
	 * @param string                                  $dir   Archive-relative directory.
	 * @param string                                  $root  Design root.
	 * @param array<string, array{id:int,url:string}> $map   Media map.
	 * @return string
	 */
	private static function emit( array $nodes, string $dir, string $root, array $map ): string {
		$out = array();

		foreach ( $nodes as $node ) {
			switch ( $node['type'] ) {
				case 'statement':
					// @import, @charset, @namespace, @layer names: none come across.
					++self::$counts['dropped'];
					break;

				case 'group':
					$inner = self::emit( (array) $node['children'], $dir, $root, $map );

					if ( '' !== trim( $inner ) ) {
						$out[] = self::scrub( (string) $node['prelude'] ) . '{' . $inner . '}';
					}
					break;

				case 'at':
					if ( in_array( $node['name'], self::DROPPED_RULES, true ) ) {
						++self::$counts['dropped'];
						break;
					}

					// @keyframes, @page, @property and friends: kept whole, pictures repointed.
					$body = self::rewrite_urls( self::scrub( (string) $node['body'] ), $dir, $root, $map, $lost );

					if ( ! $lost ) {
						++self::$counts['rules'];
						$out[] = self::scrub( (string) $node['prelude'] ) . '{' . $body . '}';
					} else {
						++self::$counts['dropped'];
					}
					break;

				case 'rule':
					$rule = self::rule( (string) $node['selector'], (string) $node['body'], $dir, $root, $map );

					if ( '' !== $rule ) {
						$out[] = $rule;
					}
					break;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Rewrite one style rule, or drop it.
	 *
	 * @param string                                  $selector Selector list.
	 * @param string                                  $body     Declaration text.
	 * @param string                                  $dir      Archive-relative directory.
	 * @param string                                  $root     Design root.
	 * @param array<string, array{id:int,url:string}> $map      Media map.
	 * @return string Empty when the rule is dropped.
	 */
	private static function rule( string $selector, string $body, string $dir, string $root, array $map ): string {
		$selector = self::scrub( trim( (string) preg_replace( '/\s+/', ' ', $selector ) ) );

		if ( '' === $selector || str_contains( $selector, '<' ) ) {
			++self::$counts['dropped'];

			return '';
		}

		$root_only = self::is_root_selector( $selector );
		$chrome    = 1 === preg_match( '/(nav|header|topbar|masthead|menu)/i', $selector );
		$container = ! BlockConverter::faithful()
			&& 1 === preg_match( '/\.(container|wrap|wrapper|inner|content)(?![\w-])/i', $selector );
		$kept      = array();

		foreach ( self::declarations( $body ) as $declaration ) {
			list( $property, $value ) = $declaration;

			$lower = strtolower( $property );
			$check = strtolower( $value );

			// Variables are kept wherever they are declared; on html/body/:root they are all that is kept.
			if ( str_starts_with( $lower, '--' ) ) {
				if ( ! self::is_safe( $check ) ) {
					continue;
				}

				$kept[] = $property . ':' . self::rewrite_urls( $value, $dir, $root, $map, $lost );
				continue;
			}

			if ( ( $root_only && ! self::$preview ) || ! self::is_safe( $check ) ) {
				continue;
			}

			if ( $chrome && 'position' === $lower && 1 === preg_match( '/\b(fixed|sticky)\b/', $check ) ) {
				continue;
			}

			if ( $container && in_array( $lower, self::LAYOUT_PROPERTIES, true ) ) {
				continue;
			}

			if ( $container && 'margin' === $lower && str_contains( $check, 'auto' ) ) {
				continue;
			}

			$lost  = false;
			$value = self::rewrite_urls( self::scrub( $value ), $dir, $root, $map, $lost );

			if ( $lost ) {
				// The picture is not in the archive; a broken url() is worse than none.
				continue;
			}

			$kept[] = $property . ':' . $value;
		}

		if ( array() === $kept ) {
			++self::$counts['dropped'];

			return '';
		}

		// A helper that only hides a bare tag or [hidden] is the browser's own job.
		if ( 1 === count( $kept ) && 1 === preg_match( '/^display\s*:\s*none/i', $kept[0] ) && 1 === preg_match( '/^(?:[a-z][a-z0-9-]*|\[hidden\]|\.no-js(?:\s.*)?)$/i', $selector ) ) {
			++self::$counts['dropped'];

			return '';
		}

		++self::$counts['rules'];

		return $selector . '{' . implode( ';', $kept ) . '}';
	}

	/**
	 * Whether a selector list is nothing but html, body, the universal selector or :root.
	 *
	 * @param string $selector Selector list.
	 * @return bool
	 */
	private static function is_root_selector( string $selector ): bool {
		foreach ( self::split_top( $selector, ',' ) as $part ) {
			$part = trim( (string) preg_replace( '/::?(before|after)\b/i', '', $part ) );

			if ( 1 !== preg_match( '/^(?:html|body|\*|:root)(?:\s+(?:html|body|\*|:root))*$/i', $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is CSS and only CSS.
	 *
	 * @param string $value Lower-cased declaration value.
	 * @return bool
	 */
	private static function is_safe( string $value ): bool {
		return 1 !== preg_match( '/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import|<\/?\s*script/i', $value );
	}

	/**
	 * Strip what can never be CSS, and the one character that can end a <style> element.
	 *
	 * @param string $text Any CSS text.
	 * @return string
	 */
	private static function scrub( string $text ): string {
		$text = (string) preg_replace( '/expression\s*\([^)]*\)|-moz-binding\s*:[^;]*;?|behavior\s*:[^;]*;?/i', '', $text );

		// As an escape it is still the same character to the parser, and harmless to the page.
		return str_replace( '<', '\\3c ', $text );
	}

	/**
	 * Repoint every relative url() at the Media Library.
	 *
	 * @param string                                  $value Declaration value.
	 * @param string                                  $dir   Archive-relative directory the value resolves from.
	 * @param string                                  $root  Design root.
	 * @param array<string, array{id:int,url:string}> $map   Media map.
	 * @param bool|null                               $lost  Set when a url() points at a file the archive does not have.
	 * @return string
	 */
	private static function rewrite_urls( string $value, string $dir, string $root, array $map, ?bool &$lost = null ): string {
		$lost = false;

		if ( ! str_contains( strtolower( $value ), 'url(' ) ) {
			return $value;
		}

		return (string) preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]*)\1\s*\)/i',
			static function ( array $found ) use ( $dir, $root, $map, &$lost ): string {
				$target = trim( $found[2] );

				if ( '' === $target || str_starts_with( $target, '#' ) || 1 === preg_match( '#^(?:https?:)?//|^data:|^blob:#i', $target ) ) {
					return $found[0];
				}

				if ( 1 === preg_match( '#^(?:javascript|vbscript):#i', $target ) ) {
					$lost = true;

					return 'url()';
				}

				$suffix = '';

				if ( 1 === preg_match( '/^([^?#]*)([?#].*)$/', $target, $parts ) ) {
					$target = $parts[1];
					$suffix = $parts[2];
				}

				$resolved = self::resolve( $target, $dir, $map );

				if ( null !== $resolved ) {
					return 'url("' . esc_url_raw( $resolved['url'] ) . '")';
				}

				$relative = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : self::normalise( $dir . '/' . $target );

				if ( '' !== $relative && is_file( $root . '/' . $relative ) ) {
					// In the archive but not in the Media Library (a font, an SVG sprite): left as written.
					++self::$counts['unmapped'];

					return $found[0];
				}

				$lost = true;

				return 'url(' . $found[1] . $target . $suffix . $found[1] . ')';
			},
			$value
		);
	}

	/**
	 * Find a url() target in the media map.
	 *
	 * Same candidates as SiteBuilder::relink_media: the path as written, the
	 * path against the stylesheet's directory, and finally the bare file name.
	 *
	 * @param string                                  $target Path from the url().
	 * @param string                                  $dir    Archive-relative directory.
	 * @param array<string, array{id:int,url:string}> $map    Media map.
	 * @return array{id:int,url:string}|null
	 */
	private static function resolve( string $target, string $dir, array $map ) {
		if ( array() === $map ) {
			return null;
		}

		$candidates = array( ltrim( $target, '/' ) );

		if ( '' !== $dir && ! str_starts_with( $target, '/' ) ) {
			$candidates[] = self::normalise( $dir . '/' . $target );
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $map[ $candidate ] ) ) {
				return $map[ $candidate ];
			}
		}

		$name = basename( $target );

		foreach ( $map as $rel => $attachment ) {
			if ( basename( (string) $rel ) === $name ) {
				return $attachment;
			}
		}

		return null;
	}

	/**
	 * Collapse . and .. inside a relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalise( string $path ): string {
		$out = array();

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop( $out );
				continue;
			}

			$out[] = $part;
		}

		return implode( '/', $out );
	}

	// ------------------------------------------------------------------ parse

	/**
	 * Parse CSS into rules, at-rules and groups.
	 *
	 * A small scanner rather than a grammar: it only has to find the braces
	 * that matter while ignoring the ones inside strings and parentheses.
	 *
	 * @param string $css Comment-free CSS.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse( string $css ): array {
		$nodes  = array();
		$length = strlen( $css );
		$start  = 0;
		$depth  = 0;
		$quote  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
				continue;
			}

			if ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}

			if ( $depth > 0 ) {
				continue;
			}

			if ( ';' === $char ) {
				$text = trim( substr( $css, $start, $i - $start ) );

				if ( '' !== $text ) {
					$nodes[] = array(
						'type' => 'statement',
						'text' => $text,
					);
				}

				$start = $i + 1;
				continue;
			}

			if ( '}' === $char ) {
				// A stray close; whatever preceded it was not a rule.
				$start = $i + 1;
				continue;
			}

			if ( '{' !== $char ) {
				continue;
			}

			$prelude = trim( substr( $css, $start, $i - $start ) );
			$end     = self::matching_brace( $css, $i );
			$body    = substr( $css, $i + 1, max( 0, $end - $i - 1 ) );

			if ( str_starts_with( $prelude, '@' ) ) {
				$name = strtolower( (string) preg_replace( '/^@([a-z-]+).*$/is', '$1', $prelude ) );

				if ( in_array( $name, self::GROUP_RULES, true ) ) {
					$nodes[] = array(
						'type'     => 'group',
						'name'     => $name,
						'prelude'  => $prelude,
						'children' => self::parse( $body ),
					);
				} else {
					$nodes[] = array(
						'type'    => 'at',
						'name'    => $name,
						'prelude' => $prelude,
						'body'    => $body,
					);
				}
			} elseif ( '' !== $prelude ) {
				$nodes[] = array(
					'type'     => 'rule',
					'selector' => $prelude,
					'body'     => $body,
				);
			}

			$i     = $end;
			$start = $end + 1;
		}

		return $nodes;
	}

	/**
	 * Index of the brace closing the one at $open.
	 *
	 * @param string $css  CSS text.
	 * @param int    $open Index of an opening brace.
	 * @return int Index of the close, or the last index when unbalanced.
	 */
	private static function matching_brace( string $css, int $open ): int {
		$length = strlen( $css );
		$depth  = 0;
		$quote  = '';

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return $length - 1;
	}

	/**
	 * Split a declaration block into property/value pairs.
	 *
	 * @param string $body Declaration text.
	 * @return array<int, array{0:string,1:string}>
	 */
	private static function declarations( string $body ): array {
		$out = array();

		foreach ( self::split_top( $body, ';' ) as $declaration ) {
			$declaration = trim( $declaration );

			if ( '' === $declaration || ! str_contains( $declaration, ':' ) ) {
				continue;
			}

			list( $property, $value ) = explode( ':', $declaration, 2 );

			$property = trim( $property );
			$value    = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

			if ( '' === $property || '' === $value || 1 !== preg_match( '/^-{0,2}[a-z][a-z0-9_-]*$/i', $property ) ) {
				continue;
			}

			$out[] = array( $property, $value );
		}

		return $out;
	}

	/**
	 * Split on a separator, ignoring ones inside strings and parentheses.
	 *
	 * @param string $text      Text.
	 * @param string $separator One character.
	 * @return array<int, string>
	 */
	private static function split_top( string $text, string $separator ): array {
		$parts  = array();
		$length = strlen( $text );
		$start  = 0;
		$depth  = 0;
		$quote  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $char === $separator && 0 === $depth ) {
				$parts[] = substr( $text, $start, $i - $start );
				$start   = $i + 1;
			}
		}

		$parts[] = substr( $text, $start );

		return $parts;
	}

	// ------------------------------------------------------------------- size

	/**
	 * Keep only the rules whose selectors name a class the pages use.
	 *
	 * @param string              $css     Rewritten CSS.
	 * @param array<string, true> $classes Class names in use.
	 * @return string
	 */
	private static function prune( string $css, array $classes ): string {
		if ( array() === $classes ) {
			return $css;
		}

		$keep = static function ( string $selector ) use ( $classes ): bool {
			if ( preg_match_all( '/\.(-?[_a-zA-Z][\w-]*)/', $selector, $found ) ) {
				foreach ( $found[1] as $class ) {
					if ( isset( $classes[ $class ] ) ) {
						return true;
					}
				}
			}

			return false;
		};

		$walk = static function ( array $nodes ) use ( &$walk, $keep ): string {
			$out = array();

			foreach ( $nodes as $node ) {
				if ( 'group' === $node['type'] ) {
					$inner = $walk( (array) $node['children'] );

					if ( '' !== $inner ) {
						$out[] = $node['prelude'] . '{' . $inner . '}';
					}
				} elseif ( 'at' === $node['type'] ) {
					$out[] = $node['prelude'] . '{' . $node['body'] . '}';
				} elseif ( 'rule' === $node['type'] && $keep( (string) $node['selector'] ) ) {
					$out[] = $node['selector'] . '{' . $node['body'] . '}';
				}
			}

			return implode( "\n", $out );
		};

		return $walk( self::parse( $css ) );
	}

	/**
	 * Cut CSS at a rule boundary under a byte limit.
	 *
	 * @param string $css   CSS.
	 * @param int    $limit Byte limit.
	 * @return string
	 */
	private static function truncate( string $css, int $limit ): string {
		$out = '';

		foreach ( self::parse( $css ) as $node ) {
			if ( 'group' === $node['type'] ) {
				$inner = self::truncate( (string) self::flatten( (array) $node['children'] ), $limit - strlen( $out ) - strlen( (string) $node['prelude'] ) - 2 );
				$piece = '' === $inner ? '' : $node['prelude'] . '{' . $inner . '}';
			} elseif ( 'statement' === $node['type'] ) {
				$piece = '';
			} else {
				$piece = ( $node['prelude'] ?? $node['selector'] ) . '{' . $node['body'] . '}';
			}

			if ( '' === $piece ) {
				continue;
			}

			if ( strlen( $out ) + strlen( $piece ) + 1 > $limit ) {
				break;
			}

			$out .= ( '' === $out ? '' : "\n" ) . $piece;
		}

		return $out;
	}

	/**
	 * Serialise parsed nodes back to text without changing them.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodes.
	 * @return string
	 */
	private static function flatten( array $nodes ): string {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( 'group' === $node['type'] ) {
				$out[] = $node['prelude'] . '{' . self::flatten( (array) $node['children'] ) . '}';
			} elseif ( 'statement' !== $node['type'] ) {
				$out[] = ( $node['prelude'] ?? $node['selector'] ) . '{' . $node['body'] . '}';
			}
		}

		return implode( "\n", $out );
	}

	// ---------------------------------------------------------------- storage

	/**
	 * The site's current user global styles, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private static function user_data(): array {
		$id   = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post = get_post( $id );
		$data = null === $post ? array() : json_decode( (string) $post->post_content, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['styles'] = isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array();

		return $data;
	}

	/**
	 * Store global styles and drop every cached copy.
	 *
	 * @param array<string, mixed> $data Global styles.
	 * @return void
	 */
	private static function save( array $data ): void {
		if ( array() === $data['styles'] ) {
			unset( $data['styles'] );
		}

		wp_update_post(
			array(
				'ID'           => WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);

		wp_clean_theme_json_cache();
	}
}
