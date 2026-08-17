<?php
/**
 * Turns converted sections into an assembled site.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * The deterministic half of an import.
 *
 * Converting a design's markup needs a model. Everything after that does not:
 * photographs are files that belong in the Media Library, links between pages
 * are a lookup, a navigation menu is the header's list of links. Doing that
 * work here rather than asking a model to imagine it makes it exact, free and
 * repeatable — and it is the difference between an import that leaves someone
 * with a page of grey boxes and one that leaves them with their site.
 */
final class SiteBuilder {

	/**
	 * Meta key recording which design file an attachment came from.
	 */
	private const SOURCE_META = '_wow_signal_source';

	/**
	 * Extensions worth importing, in order of preference for the master file.
	 *
	 * A design usually ships the same photograph twice, as .webp beside .jpg.
	 * WordPress makes its own modern formats from whichever original it is
	 * given, so importing both would put a duplicate in the library for no
	 * gain. The widest-support format wins and its siblings point at it.
	 *
	 * @var array<int, string>
	 */
	private const IMAGE_TYPES = array( 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif' );

	/**
	 * Largest image accepted, in bytes.
	 */
	private const MAX_IMAGE_BYTES = 12582912;

	/**
	 * Copy a design's images into the Media Library.
	 *
	 * Re-running is safe: an image already imported from the same design file
	 * is found by its meta and reused rather than duplicated.
	 *
	 * @param string                $root Design root directory.
	 * @param array<string, string> $alts Alt text keyed by relative path.
	 * @return array<string, array{id:int,url:string}>|WP_Error
	 */
	public static function import_media( string $root, array $alts = array() ) {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		if ( ! is_dir( $root ) ) {
			return new WP_Error( 'wow_signal_no_design', __( 'That design is not on this site.', 'wow-signal' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$found = self::collect_images( $root );
		$map   = array();

		foreach ( self::group_by_stem( $found ) as $siblings ) {
			$master = $siblings[0];
			$id     = self::existing_attachment( $master['rel'] );

			if ( 0 === $id ) {
				$id = self::sideload( $root . '/' . $master['rel'], $master['rel'], $alts[ $master['rel'] ] ?? '' );
			}

			if ( is_wp_error( $id ) || 0 === $id ) {
				continue;
			}

			$url = (string) wp_get_attachment_url( $id );

			// Every sibling resolves to the one attachment.
			foreach ( $siblings as $sibling ) {
				$map[ $sibling['rel'] ] = array(
					'id'  => $id,
					'url' => $url,
				);
			}
		}

		return $map;
	}

	/**
	 * Every importable image under the design root, as relative paths.
	 *
	 * @param string $root Design root.
	 * @return array<int, array{rel:string,stem:string,ext:string,bytes:int}>
	 */
	private static function collect_images( string $root ): array {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		$found = array();

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = str_replace( '\\', '/', $file->getPathname() );
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, self::IMAGE_TYPES, true ) ) {
				continue;
			}

			if ( $file->getSize() > self::MAX_IMAGE_BYTES ) {
				continue;
			}

			$rel = ltrim( substr( $path, strlen( $root ) ), '/' );

			$found[] = array(
				'rel'   => $rel,
				'stem'  => preg_replace( '/\.[^.]+$/', '', $rel ) ?? $rel,
				'ext'   => $ext,
				'bytes' => (int) $file->getSize(),
			);
		}

		return $found;
	}

	/**
	 * Group the same photograph's formats together, best format first.
	 *
	 * @param array<int, array{rel:string,stem:string,ext:string,bytes:int}> $found Images.
	 * @return array<string, array<int, array{rel:string,stem:string,ext:string,bytes:int}>>
	 */
	private static function group_by_stem( array $found ): array {
		$groups = array();

		foreach ( $found as $image ) {
			$groups[ $image['stem'] ][] = $image;
		}

		foreach ( $groups as &$group ) {
			usort(
				$group,
				static function ( array $a, array $b ): int {
					$rank = array_flip( self::IMAGE_TYPES );

					return ( $rank[ $a['ext'] ] ?? 99 ) <=> ( $rank[ $b['ext'] ] ?? 99 );
				}
			);
		}

		unset( $group );

		return $groups;
	}

	/**
	 * Find an attachment already imported from this design file.
	 *
	 * @param string $rel Relative path inside the design.
	 * @return int Attachment ID, or 0.
	 */
	private static function existing_attachment( string $rel ): int {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded to one row, runs only during an import.
					array(
						'key'   => self::SOURCE_META,
						'value' => $rel,
					),
				),
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Copy one file into the Media Library.
	 *
	 * @param string $path Absolute source path.
	 * @param string $rel  Relative path, kept as provenance.
	 * @param string $alt  Alt text from the design.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function sideload( string $path, string $rel, string $alt ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'wow_signal_unreadable', $rel );
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'wow_signal_uploads', (string) $uploads['error'] );
		}

		$name = wp_unique_filename( $uploads['path'], basename( $rel ) );
		$dest = trailingslashit( $uploads['path'] ) . $name;

		if ( ! copy( $path, $dest ) ) {
			return new WP_Error( 'wow_signal_copy', $rel );
		}

		$type = wp_check_filetype( $dest );

		if ( empty( $type['type'] ) ) {
			wp_delete_file( $dest );

			return new WP_Error( 'wow_signal_filetype', $rel );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $type['type'],
				'post_title'     => self::title_from( $rel ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$dest,
			0,
			true
		);

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $dest );

			return $id;
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
		update_post_meta( $id, self::SOURCE_META, $rel );

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return (int) $id;
	}

	/**
	 * A readable media title from a file name.
	 *
	 * @param string $rel Relative path.
	 * @return string
	 */
	private static function title_from( string $rel ): string {
		$stem = preg_replace( '/\.[^.]+$/', '', basename( $rel ) ) ?? $rel;

		return ucfirst( trim( str_replace( array( '-', '_' ), ' ', $stem ) ) );
	}

	/**
	 * Point a section's images at the imported attachments.
	 *
	 * The converter keeps each image's original path so this step can find it.
	 * An image whose file was not in the archive is left alone rather than
	 * silently dropped, so it shows up in review instead of disappearing.
	 *
	 * @param string                                  $markup   Block markup.
	 * @param array<string, array{id:int,url:string}> $map      Path to attachment.
	 * @param string                                  $page_dir Directory of the page, for relative paths.
	 * @return string
	 */
	public static function relink_media( string $markup, array $map, string $page_dir = '' ): string {
		if ( array() === $map ) {
			return $markup;
		}

		return (string) preg_replace_callback(
			'#(src|srcset)="([^"]+)"#i',
			static function ( array $found ) use ( $map, $page_dir ): string {
				$resolved = self::resolve( $found[2], $map, $page_dir );

				return null === $resolved ? $found[0] : $found[1] . '="' . esc_url( $resolved['url'] ) . '"';
			},
			$markup
		);
	}

	/**
	 * Resolve one src against the imported map.
	 *
	 * @param string                                  $src      Original attribute value.
	 * @param array<string, array{id:int,url:string}> $map      Path to attachment.
	 * @param string                                  $page_dir Directory of the page.
	 * @return array{id:int,url:string}|null
	 */
	private static function resolve( string $src, array $map, string $page_dir ) {
		$src = trim( explode( ' ', trim( $src ) )[0] );

		if ( '' === $src || preg_match( '#^(https?:)?//#i', $src ) || str_starts_with( $src, 'data:' ) ) {
			return null;
		}

		$candidates = array( ltrim( $src, '/' ) );

		if ( '' !== $page_dir ) {
			$candidates[] = self::normalise( $page_dir . '/' . $src );
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $map[ $candidate ] ) ) {
				return $map[ $candidate ];
			}
		}

		// Last resort: match on file name alone.
		$name = basename( $src );

		foreach ( $map as $rel => $attachment ) {
			if ( basename( $rel ) === $name ) {
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
}
