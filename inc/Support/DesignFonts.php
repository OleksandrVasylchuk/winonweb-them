<?php
/**
 * Brings a design's typefaces along with its colours.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use WP_Font_Utils;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * The step that makes an imported site set in the design's own type.
 *
 * A design exported from a design tool loads its faces from Google Fonts at
 * run time. The theme ships one family of its own, so without this step every
 * heading that asked for Sora falls back to whatever the browser has. The
 * families are fetched once, stored in the site's own uploads and registered
 * through the Font Library, so they appear in the Site Editor like any face
 * the owner installed by hand and are printed as @font-face on the front end.
 *
 * Nothing here is fatal: a family that cannot be fetched is reported and the
 * rest of the import carries on.
 */
final class DesignFonts {

	/**
	 * Value stored under SiteAssembler::OWNED_META on every post created here.
	 */
	private const OWNED_VALUE = 'font';

	/**
	 * Hosts the class is willing to download from.
	 *
	 * @var array<int, string>
	 */
	private const HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com' );

	/**
	 * Character subsets kept from Google's stylesheet.
	 *
	 * @var array<int, string>
	 */
	private const SUBSETS = array( 'latin', 'latin-ext', 'cyrillic' );

	/**
	 * Font file extensions accepted from inside a design archive.
	 *
	 * @var array<int, string>
	 */
	private const LOCAL_TYPES = array( 'woff2', 'woff', 'ttf', 'otf' );

	/**
	 * Largest stylesheet or markup file worth scanning, in bytes.
	 */
	private const MAX_SCAN_BYTES = 2097152;

	/**
	 * Largest single font file accepted, in bytes.
	 */
	private const MAX_FONT_BYTES = 2097152;

	/**
	 * Most font files one import will write.
	 */
	private const MAX_FILES = 40;

	/**
	 * Seconds to wait on Google before giving up on a request.
	 */
	private const TIMEOUT = 15;

	/**
	 * Browser identity sent to Google, which serves woff2 only to browsers it knows.
	 */
	private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

	/**
	 * Files written during the current import.
	 *
	 * @var int
	 */
	private static int $written = 0;

	/**
	 * Fetch, store, register and activate every typeface a design uses.
	 *
	 * Re-running is safe: families already registered by a previous run are
	 * reused, and files already on disk are not downloaded again.
	 *
	 * @param string $root Design root directory.
	 * @return array{families: array<int,string>, faces:int, skipped: array<int,string>}
	 */
	public static function import( string $root ): array {
		$root          = rtrim( str_replace( '\\', '/', $root ), '/' );
		self::$written = 0;

		$result = array(
			'families' => array(),
			'faces'    => 0,
			'skipped'  => array(),
		);

		if ( ! is_dir( $root ) ) {
			$result['skipped'][] = __( 'That design is not on this site.', 'wow-signal' );

			return $result;
		}

		$sources = self::gather_sources( $root );
		$wanted  = self::google_families( $sources );
		$local   = self::local_families( $sources, $root );
		$base    = self::font_dir();

		if ( null === $base ) {
			$result['skipped'][] = __( 'The uploads folder is not writable, so no fonts were imported.', 'wow-signal' );

			return $result;
		}

		$activate = array();

		foreach ( $wanted as $name => $variants ) {
			$faces = self::fetch_google_family( $name, $variants, $base, $result['skipped'] );

			if ( array() === $faces ) {
				/* translators: %s: font family name. */
				$result['skipped'][] = sprintf( __( '%s could not be fetched from Google Fonts.', 'wow-signal' ), $name );
				continue;
			}

			$activate[ $name ] = $faces;
		}

		foreach ( $local as $name => $faces ) {
			if ( isset( $activate[ $name ] ) ) {
				continue;
			}

			$copied = self::copy_local_faces( $name, $faces, $base, $result['skipped'] );

			if ( array() !== $copied ) {
				$activate[ $name ] = $copied;
			}
		}

		$generics = self::generics( $sources );
		$entries  = array();

		foreach ( $activate as $name => $faces ) {
			$entry = self::register_family( $name, $faces, $generics[ strtolower( $name ) ] ?? self::guess_generic( $name ) );

			if ( null === $entry ) {
				/* translators: %s: font family name. */
				$result['skipped'][] = sprintf( __( '%s could not be registered in the Font Library.', 'wow-signal' ), $name );
				continue;
			}

			$entries[]            = $entry;
			$result['families'][] = $name;
			$result['faces']     += count( $faces );
		}

		if ( array() !== $entries ) {
			self::activate( $entries );
		}

		return $result;
	}

	/**
	 * Remove every family a previous import installed.
	 *
	 * @return int Number of families removed.
	 */
	public static function reset(): int {
		$families = self::owned_families();
		$slugs    = array();

		foreach ( $families as $family ) {
			$slugs[] = (string) $family->post_name;

			$faces = get_posts(
				array(
					'post_type'      => 'wp_font_face',
					'post_status'    => 'any',
					'post_parent'    => $family->ID,
					'posts_per_page' => -1,
				)
			);

			foreach ( $faces as $face ) {
				wp_delete_post( $face->ID, true );
			}

			wp_delete_post( $family->ID, true );
		}

		// Faces whose family went missing some other way are still ours to tidy.
		$orphans = get_posts(
			array(
				'post_type'      => 'wp_font_face',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => SiteAssembler::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping, not a front-end query.
				'meta_value'     => self::OWNED_VALUE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Import bookkeeping, not a front-end query.
			)
		);

		foreach ( $orphans as $orphan ) {
			wp_delete_post( $orphan->ID, true );
		}

		$base = self::font_dir( false );

		foreach ( $slugs as $slug ) {
			if ( null !== $base && '' !== $slug ) {
				self::remove_dir( $base . '/' . $slug );
			}
		}

		if ( array() !== $slugs ) {
			self::deactivate( $slugs );
		}

		return count( $families );
	}

	/**
	 * How many families an import has installed.
	 *
	 * @return int
	 */
	public static function count(): int {
		return count( self::owned_families() );
	}

	/**
	 * Family posts this class created.
	 *
	 * @return array<int, \WP_Post>
	 */
	private static function owned_families(): array {
		return get_posts(
			array(
				'post_type'      => 'wp_font_family',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => SiteAssembler::OWNED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import bookkeeping, not a front-end query.
				'meta_value'     => self::OWNED_VALUE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Import bookkeeping, not a front-end query.
			)
		);
	}

	/**
	 * Every stylesheet and page in the design, with where it came from.
	 *
	 * Inline <style> blocks and style attributes count as well; some exports
	 * ship no .css at all and put the Google link straight in the head.
	 *
	 * @param string $root Design root.
	 * @return array<int, array{dir:string,text:string}>
	 */
	private static function gather_sources( string $root ): array {
		$sources  = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getSize() > self::MAX_SCAN_BYTES ) {
				continue;
			}

			if ( 1 !== preg_match( '/\.(css|html?)$/i', $file->getFilename() ) ) {
				continue;
			}

			$sources[] = array(
				'dir'  => str_replace( '\\', '/', $file->getPath() ),
				'text' => (string) file_get_contents( $file->getPathname() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
			);
		}

		return $sources;
	}

	/**
	 * Google families the design asks for, with the variants it uses.
	 *
	 * @param array<int, array{dir:string,text:string}> $sources Design files.
	 * @return array<string, array<int, array{style:string,weight:int}>> Family name to variants.
	 */
	private static function google_families( array $sources ): array {
		$families = array();

		foreach ( $sources as $source ) {
			$text = html_entity_decode( $source['text'], ENT_QUOTES | ENT_HTML5 );

			if ( ! preg_match_all( '#https?://fonts\.googleapis\.com/(css2?)\?([^"\'\s)<>]+)#i', $text, $urls, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $urls as $url ) {
				$query = str_replace( '&amp;', '&', $url[2] );

				foreach ( explode( '&', $query ) as $pair ) {
					if ( ! str_starts_with( $pair, 'family=' ) ) {
						continue;
					}

					$value = rawurldecode( str_replace( '+', ' ', substr( $pair, 7 ) ) );

					// The legacy endpoint lists several families in one parameter.
					foreach ( explode( '|', $value ) as $spec ) {
						$parsed = 'css2' === strtolower( $url[1] ) ? self::parse_css2_spec( $spec ) : self::parse_legacy_spec( $spec );

						if ( null === $parsed ) {
							continue;
						}

						list( $name, $variants ) = $parsed;

						$families[ $name ] = self::merge_variants( $families[ $name ] ?? array(), $variants );
					}
				}
			}
		}

		return $families;
	}

	/**
	 * Read one css2 family spec such as "Sora:wght@600;700" or "Lora:ital,wght@0,400;1,400".
	 *
	 * @param string $spec Family spec.
	 * @return array{0:string,1:array<int, array{style:string,weight:int}>}|null
	 */
	private static function parse_css2_spec( string $spec ): ?array {
		$parts = explode( ':', trim( $spec ), 2 );
		$name  = self::clean_name( $parts[0] );

		if ( '' === $name ) {
			return null;
		}

		$variants = array();

		if ( isset( $parts[1] ) && str_contains( $parts[1], '@' ) ) {
			list( $axes, $tuples ) = explode( '@', $parts[1], 2 );

			$axes = explode( ',', strtolower( $axes ) );

			foreach ( explode( ';', $tuples ) as $tuple ) {
				$values = explode( ',', $tuple );
				$style  = 'normal';
				$weight = 400;

				foreach ( $axes as $index => $axis ) {
					$value = trim( $values[ $index ] ?? '' );

					if ( 'ital' === $axis && '1' === $value ) {
						$style = 'italic';
					}

					if ( 'wght' === $axis && '' !== $value ) {
						// A range such as 200..800 is a variable font; ask for its ends.
						if ( str_contains( $value, '..' ) ) {
							list( $low, $high ) = explode( '..', $value, 2 );

							$variants[] = array(
								'style'  => $style,
								'weight' => (int) $low,
							);

							$weight = (int) $high;
						} else {
							$weight = (int) $value;
						}
					}
				}

				$variants[] = array(
					'style'  => $style,
					'weight' => $weight,
				);
			}
		}

		if ( array() === $variants ) {
			$variants[] = array(
				'style'  => 'normal',
				'weight' => 400,
			);
		}

		return array( $name, $variants );
	}

	/**
	 * Read one legacy family spec such as "Sora:400,700,400i,700italic".
	 *
	 * @param string $spec Family spec.
	 * @return array{0:string,1:array<int, array{style:string,weight:int}>}|null
	 */
	private static function parse_legacy_spec( string $spec ): ?array {
		$parts = explode( ':', trim( $spec ), 2 );
		$name  = self::clean_name( $parts[0] );

		if ( '' === $name ) {
			return null;
		}

		$variants = array();

		foreach ( explode( ',', $parts[1] ?? '' ) as $token ) {
			$token = strtolower( trim( $token ) );

			if ( '' === $token ) {
				continue;
			}

			if ( 1 === preg_match( '/^(\d{3})?(i|italic)?$/', $token, $found ) ) {
				$variants[] = array(
					'style'  => empty( $found[2] ) ? 'normal' : 'italic',
					'weight' => empty( $found[1] ) ? 400 : (int) $found[1],
				);
			} elseif ( 'bold' === $token || 'b' === $token ) {
				$variants[] = array(
					'style'  => 'normal',
					'weight' => 700,
				);
			} elseif ( 'bolditalic' === $token || 'bi' === $token ) {
				$variants[] = array(
					'style'  => 'italic',
					'weight' => 700,
				);
			}
		}

		if ( array() === $variants ) {
			$variants[] = array(
				'style'  => 'normal',
				'weight' => 400,
			);
		}

		return array( $name, $variants );
	}

	/**
	 * A family name fit to ask Google for.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function clean_name( string $name ): string {
		$name = trim( $name, " \t\n\r\0\x0B\"'" );

		return 1 === preg_match( '/^[A-Za-z0-9 ]{1,60}$/', $name ) ? $name : '';
	}

	/**
	 * Combine two variant lists without duplicates, sorted as Google wants them.
	 *
	 * @param array<int, array{style:string,weight:int}> $one First list.
	 * @param array<int, array{style:string,weight:int}> $two Second list.
	 * @return array<int, array{style:string,weight:int}>
	 */
	private static function merge_variants( array $one, array $two ): array {
		$seen = array();

		foreach ( array_merge( $one, $two ) as $variant ) {
			$weight = max( 100, min( 900, $variant['weight'] ) );

			$seen[ $variant['style'] . $weight ] = array(
				'style'  => $variant['style'],
				'weight' => $weight,
			);
		}

		$merged = array_values( $seen );

		usort(
			$merged,
			static function ( array $a, array $b ): int {
				return array( 'italic' === $a['style'], $a['weight'] ) <=> array( 'italic' === $b['style'], $b['weight'] );
			}
		);

		return $merged;
	}

	/**
	 * Fetch one family from Google and store its files.
	 *
	 * @param string                                     $name     Family name.
	 * @param array<int, array{style:string,weight:int}> $variants Variants wanted.
	 * @param string                                     $base     Fonts directory.
	 * @param array<int, string>                         $skipped  Problems, appended to.
	 * @return array<int, array<string, mixed>> Font face definitions.
	 */
	private static function fetch_google_family( string $name, array $variants, string $base, array &$skipped ): array {
		$css = self::fetch_google_css( $name, $variants );

		if ( '' === $css ) {
			return array();
		}

		$slug  = sanitize_title( $name );
		$dir   = $base . '/' . $slug;
		$faces = array();

		if ( ! self::ensure_dir( $dir ) ) {
			/* translators: %s: directory path. */
			$skipped[] = sprintf( __( 'Could not create %s.', 'wow-signal' ), $dir );

			return array();
		}

		foreach ( self::parse_font_faces( $css ) as $face ) {
			if ( null !== $face['subset'] && ! in_array( $face['subset'], self::SUBSETS, true ) ) {
				continue;
			}

			if ( null === $face['subset'] && ! self::range_is_wanted( $face['unicodeRange'] ) ) {
				continue;
			}

			$file = sprintf( '%s-%s-%s-%s.woff2', $slug, $face['fontWeight'], $face['fontStyle'], $face['subset'] ?? 'all' );
			$file = sanitize_file_name( $file );

			if ( ! self::download( $face['src'], $dir . '/' . $file, $skipped ) ) {
				continue;
			}

			$faces[] = self::face_definition( $name, $face['fontStyle'], $face['fontWeight'], self::font_url( $slug, $file ), $face['unicodeRange'] );
		}

		return $faces;
	}

	/**
	 * Google's stylesheet for a set of variants, or an empty string.
	 *
	 * @param string                                     $name     Family name.
	 * @param array<int, array{style:string,weight:int}> $variants Variants wanted.
	 * @return string
	 */
	private static function fetch_google_css( string $name, array $variants ): string {
		$italic = false;

		foreach ( $variants as $variant ) {
			$italic = $italic || 'italic' === $variant['style'];
		}

		$tuples = array();

		foreach ( $variants as $variant ) {
			$tuples[] = $italic
				? ( 'italic' === $variant['style'] ? '1,' : '0,' ) . $variant['weight']
				: (string) $variant['weight'];
		}

		$spec = str_replace( ' ', '+', $name ) . ':' . ( $italic ? 'ital,wght' : 'wght' ) . '@' . implode( ';', $tuples );
		$url  = 'https://fonts.googleapis.com/css2?family=' . $spec . '&display=swap';

		$response = self::get( $url );

		if ( null === $response || ! str_contains( $response, '@font-face' ) ) {
			// Google refuses unknown variants outright; a plain request still works.
			$response = self::get( 'https://fonts.googleapis.com/css2?family=' . str_replace( ' ', '+', $name ) . '&display=swap' );
		}

		return $response ?? '';
	}

	/**
	 * A GET against one of the two allowed hosts.
	 *
	 * @param string $url URL.
	 * @return string|null Body, or null on any failure.
	 */
	private static function get( string $url ): ?string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! in_array( strtolower( $host ), self::HOSTS, true ) ) {
			return null;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::USER_AGENT,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * The @font-face blocks in a Google stylesheet.
	 *
	 * @param string $css Stylesheet text.
	 * @return array<int, array{subset:?string,fontStyle:string,fontWeight:string,unicodeRange:string,src:string}>
	 */
	private static function parse_font_faces( string $css ): array {
		$faces = array();

		if ( ! preg_match_all( '#(?:/\*\s*([a-z0-9-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#i', $css, $blocks, PREG_SET_ORDER ) ) {
			return $faces;
		}

		foreach ( $blocks as $block ) {
			$body = $block[2];

			if ( 1 !== preg_match( '/src\s*:[^;]*?url\(\s*["\']?([^"\')\s]+)["\']?\s*\)\s*format\(\s*["\']?woff2["\']?\s*\)/i', $body, $src ) ) {
				continue;
			}

			$faces[] = array(
				'subset'       => '' === ( $block[1] ?? '' ) ? null : strtolower( $block[1] ),
				'fontStyle'    => self::property( $body, 'font-style', 'normal' ),
				'fontWeight'   => self::property( $body, 'font-weight', '400' ),
				'unicodeRange' => self::property( $body, 'unicode-range', '' ),
				'src'          => $src[1],
			);
		}

		return $faces;
	}

	/**
	 * One declaration's value inside a rule body.
	 *
	 * @param string $body     Rule body.
	 * @param string $name     Property name.
	 * @param string $fallback Value when absent.
	 * @return string
	 */
	private static function property( string $body, string $name, string $fallback ): string {
		if ( 1 === preg_match( '/(?:^|;)\s*' . preg_quote( $name, '/' ) . '\s*:\s*([^;]+)/i', $body, $found ) ) {
			return trim( $found[1] );
		}

		return $fallback;
	}

	/**
	 * Whether a unicode-range covers Latin or Cyrillic text.
	 *
	 * Used when Google's stylesheet carries no subset comment.
	 *
	 * @param string $range unicode-range value.
	 * @return bool
	 */
	private static function range_is_wanted( string $range ): bool {
		if ( '' === $range ) {
			return true;
		}

		return 1 === preg_match( '/U\+(0000-00FF|0100-02BA|0400-045F|0-10FFFF)/i', $range );
	}

	/**
	 * Download one font file, verifying it really is woff2.
	 *
	 * @param string             $url     Remote file.
	 * @param string             $dest    Local path.
	 * @param array<int, string> $skipped Problems, appended to.
	 * @return bool
	 */
	private static function download( string $url, string $dest, array &$skipped ): bool {
		if ( file_exists( $dest ) ) {
			return true;
		}

		if ( self::$written >= self::MAX_FILES ) {
			/* translators: %d: number of files. */
			$skipped[] = sprintf( __( 'Stopped after %d font files.', 'wow-signal' ), self::MAX_FILES );

			return false;
		}

		$body = self::get( $url );

		if ( null === $body || strlen( $body ) > self::MAX_FONT_BYTES || ! str_starts_with( $body, 'wOF2' ) ) {
			/* translators: %s: file name. */
			$skipped[] = sprintf( __( '%s was not a usable font file.', 'wow-signal' ), basename( $dest ) );

			return false;
		}

		if ( false === file_put_contents( $dest, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a verified font file into the site's own uploads folder.
			/* translators: %s: file name. */
			$skipped[] = sprintf( __( '%s could not be written.', 'wow-signal' ), basename( $dest ) );

			return false;
		}

		++self::$written;

		return true;
	}

	/**
	 * Families the design ships as files of its own, declared with @font-face.
	 *
	 * @param array<int, array{dir:string,text:string}> $sources Design files.
	 * @param string                                    $root    Design root.
	 * @return array<string, array<int, array{style:string,weight:string,range:string,path:string}>>
	 */
	private static function local_families( array $sources, string $root ): array {
		$families = array();
		$real     = realpath( $root );

		if ( false === $real ) {
			return $families;
		}

		$real = str_replace( '\\', '/', $real );

		foreach ( $sources as $source ) {
			if ( ! preg_match_all( '/@font-face\s*\{([^}]*)\}/i', $source['text'], $blocks ) ) {
				continue;
			}

			foreach ( $blocks[1] as $body ) {
				$name = self::clean_name( self::property( $body, 'font-family', '' ) );

				if ( '' === $name || ! preg_match_all( '/url\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $body, $urls ) ) {
					continue;
				}

				foreach ( $urls[1] as $url ) {
					if ( preg_match( '#^(https?:)?//#i', $url ) || str_starts_with( $url, 'data:' ) ) {
						continue;
					}

					$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

					if ( ! in_array( $ext, self::LOCAL_TYPES, true ) ) {
						continue;
					}

					$path = realpath( $source['dir'] . '/' . (string) wp_parse_url( $url, PHP_URL_PATH ) );

					if ( false === $path ) {
						$path = realpath( $root . '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
					}

					if ( false === $path ) {
						continue;
					}

					$path = str_replace( '\\', '/', $path );

					// Only files inside the archive; never follow a link out of it.
					if ( ! str_starts_with( $path, $real . '/' ) || filesize( $path ) > self::MAX_FONT_BYTES ) {
						continue;
					}

					$families[ $name ][] = array(
						'style'  => self::property( $body, 'font-style', 'normal' ),
						'weight' => self::property( $body, 'font-weight', '400' ),
						'range'  => self::property( $body, 'unicode-range', '' ),
						'path'   => $path,
					);

					// One file per face is enough; the first is the author's preference.
					break;
				}
			}
		}

		return $families;
	}

	/**
	 * Copy a design's own font files into the fonts folder.
	 *
	 * @param string                                                                 $name    Family name.
	 * @param array<int, array{style:string,weight:string,range:string,path:string}> $faces   Faces with local files.
	 * @param string                                                                 $base    Fonts directory.
	 * @param array<int, string>                                                     $skipped Problems, appended to.
	 * @return array<int, array<string, mixed>> Font face definitions.
	 */
	private static function copy_local_faces( string $name, array $faces, string $base, array &$skipped ): array {
		$slug = sanitize_title( $name );
		$dir  = $base . '/' . $slug;
		$out  = array();

		if ( ! self::ensure_dir( $dir ) ) {
			/* translators: %s: directory path. */
			$skipped[] = sprintf( __( 'Could not create %s.', 'wow-signal' ), $dir );

			return $out;
		}

		foreach ( $faces as $face ) {
			if ( self::$written >= self::MAX_FILES ) {
				break;
			}

			$ext  = strtolower( pathinfo( $face['path'], PATHINFO_EXTENSION ) );
			$file = sanitize_file_name( sprintf( '%s-%s-%s.%s', $slug, $face['weight'], $face['style'], $ext ) );
			$dest = $dir . '/' . $file;

			if ( ! file_exists( $dest ) ) {
				if ( ! copy( $face['path'], $dest ) ) {
					/* translators: %s: file name. */
					$skipped[] = sprintf( __( '%s could not be written.', 'wow-signal' ), $file );
					continue;
				}

				++self::$written;
			}

			$out[] = self::face_definition( $name, $face['style'], $face['weight'], self::font_url( $slug, $file ), $face['range'] );
		}

		return $out;
	}

	/**
	 * A font face in the shape the Font Library stores.
	 *
	 * @param string $family Family name.
	 * @param string $style  font-style.
	 * @param string $weight font-weight.
	 * @param string $url    File URL.
	 * @param string $range  unicode-range, possibly empty.
	 * @return array<string, mixed>
	 */
	private static function face_definition( string $family, string $style, string $weight, string $url, string $range ): array {
		$face = array(
			'fontFamily' => $family,
			'fontStyle'  => sanitize_text_field( $style ),
			'fontWeight' => sanitize_text_field( $weight ),
			'src'        => array( $url ),
		);

		if ( '' !== $range ) {
			$face['unicodeRange'] = sanitize_text_field( $range );
		}

		return $face;
	}

	/**
	 * Create or reuse the Font Library posts for one family.
	 *
	 * @param string                           $name    Family name.
	 * @param array<int, array<string, mixed>> $faces   Face definitions.
	 * @param string                           $generic Generic fallback.
	 * @return array<string, mixed>|null The fontFamilies entry for global styles.
	 */
	private static function register_family( string $name, array $faces, string $generic ): ?array {
		$slug     = sanitize_title( $name );
		$settings = array(
			'name'       => $name,
			'slug'       => $slug,
			'fontFamily' => WP_Font_Utils::sanitize_font_family( "'" . $name . "', " . $generic ),
		);

		$existing = get_posts(
			array(
				'post_type'      => 'wp_font_family',
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => 1,
			)
		);

		if ( array() !== $existing ) {
			$family_id = (int) $existing[0]->ID;
		} else {
			$family_id = wp_insert_post(
				array(
					'post_type'    => 'wp_font_family',
					'post_status'  => 'publish',
					'post_title'   => $name,
					'post_name'    => $slug,
					'post_content' => wp_slash( (string) wp_json_encode( $settings ) ),
					'meta_input'   => array( SiteAssembler::OWNED_META => self::OWNED_VALUE ),
				),
				true
			);

			if ( is_wp_error( $family_id ) || 0 === $family_id ) {
				return null;
			}
		}

		$known = array();

		foreach (
			get_posts(
				array(
					'post_type'      => 'wp_font_face',
					'post_status'    => 'any',
					'post_parent'    => $family_id,
					'posts_per_page' => -1,
				)
			) as $post
		) {
			$known[ $post->post_title ] = true;
		}

		foreach ( $faces as $face ) {
			$title = WP_Font_Utils::get_font_face_slug( $face );

			if ( isset( $known[ $title ] ) ) {
				continue;
			}

			$meta = array( SiteAssembler::OWNED_META => self::OWNED_VALUE );

			/*
			 * The Font Library records each face's file relative to the fonts
			 * directory so that deleting the face from the Site Editor also
			 * deletes the file. Mirror that, or the editor's delete leaves
			 * orphaned woff2 files behind.
			 */
			$src      = (string) ( $face['src'][0] ?? '' );
			$position = strpos( $src, '/fonts/' );

			if ( false !== $position ) {
				$meta['_wp_font_face_file'] = substr( $src, $position + strlen( '/fonts/' ) );
			}

			wp_insert_post(
				array(
					'post_type'    => 'wp_font_face',
					'post_status'  => 'publish',
					'post_parent'  => $family_id,
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
					'post_content' => wp_slash( (string) wp_json_encode( $face ) ),
					'meta_input'   => $meta,
				)
			);
		}

		$settings['fontFace'] = $faces;

		return $settings;
	}

	/**
	 * Write families into the user's global styles, which is what the editor
	 * does when a font is installed and what makes the front end print them.
	 *
	 * @param array<int, array<string, mixed>> $entries fontFamilies entries.
	 * @return void
	 */
	private static function activate( array $entries ): void {
		$data    = self::user_data();
		$current = $data['settings']['typography']['fontFamilies']['custom'] ?? array();
		$current = is_array( $current ) ? $current : array();
		$slugs   = array_column( $entries, 'slug' );

		$kept = array_values(
			array_filter(
				$current,
				static fn( $entry ): bool => is_array( $entry ) && ! in_array( $entry['slug'] ?? '', $slugs, true )
			)
		);

		$data['version']                     = 3;
		$data['isGlobalStylesUserThemeJSON'] = true;
		$data['settings']['typography']['fontFamilies']['custom'] = array_merge( $kept, $entries );

		self::save( $data );
	}

	/**
	 * Take families out of the user's global styles.
	 *
	 * @param array<int, string> $slugs Family slugs.
	 * @return void
	 */
	private static function deactivate( array $slugs ): void {
		$data    = self::user_data();
		$current = $data['settings']['typography']['fontFamilies']['custom'] ?? null;

		if ( ! is_array( $current ) ) {
			return;
		}

		$kept = array_values(
			array_filter(
				$current,
				static fn( $entry ): bool => is_array( $entry ) && ! in_array( $entry['slug'] ?? '', $slugs, true )
			)
		);

		if ( array() === $kept ) {
			unset( $data['settings']['typography']['fontFamilies']['custom'] );

			if ( array() === $data['settings']['typography']['fontFamilies'] ) {
				unset( $data['settings']['typography']['fontFamilies'] );
			}

			if ( array() === $data['settings']['typography'] ) {
				unset( $data['settings']['typography'] );
			}
		} else {
			$data['settings']['typography']['fontFamilies']['custom'] = $kept;
		}

		self::save( $data );
	}

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

		$data['settings'] = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
		$data['styles']   = isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array();

		return $data;
	}

	/**
	 * Store global styles and drop every cached copy.
	 *
	 * The front end's wp_print_font_faces() reads wp_get_global_settings(), which
	 * keeps a cache of its own beside the resolver's, so both are cleared — or
	 * the request that ran reset() would go on printing faces that no longer exist.
	 *
	 * @param array<string, mixed> $data Global styles.
	 * @return void
	 */
	private static function save( array $data ): void {
		wp_update_post(
			array(
				'ID'           => WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);

		wp_clean_theme_json_cache();
	}

	/**
	 * The generic family each named face falls back to, as the design wrote it.
	 *
	 * @param array<int, array{dir:string,text:string}> $sources Design files.
	 * @return array<string, string> Lower-case family name to generic.
	 */
	private static function generics( array $sources ): array {
		$out = array();

		foreach ( $sources as $source ) {
			if ( ! preg_match_all( '/font-family\s*:\s*["\']([^"\']+)["\']\s*,[^;}]*?\b(sans-serif|serif|monospace|cursive|system-ui)\b/i', $source['text'], $found, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $found as $match ) {
				$out[ strtolower( trim( $match[1] ) ) ] = strtolower( $match[2] );
			}
		}

		return $out;
	}

	/**
	 * A sensible generic fallback from the family's name alone.
	 *
	 * @param string $name Family name.
	 * @return string
	 */
	private static function guess_generic( string $name ): string {
		$lower = strtolower( $name );

		if ( str_contains( $lower, 'mono' ) || str_contains( $lower, 'code' ) ) {
			return 'monospace';
		}

		if ( str_contains( $lower, 'serif' ) && ! str_contains( $lower, 'sans' ) ) {
			return 'serif';
		}

		return 'sans-serif';
	}

	/**
	 * The fonts folder inside uploads, created on demand.
	 *
	 * @param bool $create Whether to create it.
	 * @return string|null Absolute path, or null when unavailable.
	 */
	private static function font_dir( bool $create = true ): ?string {
		$uploads = wp_upload_dir( null, $create );

		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		$dir = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) . '/fonts';

		if ( $create && ! self::ensure_dir( $dir ) ) {
			return null;
		}

		return $dir;
	}

	/**
	 * The public URL of a stored font file.
	 *
	 * @param string $slug Family slug.
	 * @param string $file File name.
	 * @return string
	 */
	private static function font_url( string $slug, string $file ): string {
		$uploads = wp_upload_dir( null, false );

		return rtrim( (string) $uploads['baseurl'], '/' ) . '/fonts/' . $slug . '/' . $file;
	}

	/**
	 * Make sure a directory exists, with an index guard in it.
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	private static function ensure_dir( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Directory listing guard in the site's own uploads folder.
		}

		return is_writable( $dir );
	}

	/**
	 * Delete a family's folder and everything in it.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				self::remove_dir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing an emptied folder this class created under uploads.
	}
}
