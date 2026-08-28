<?php
/**
 * Turns converted sections into an assembled site.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

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
	private const SOURCE_META = '_qwerty_soft_source';

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
	private const IMAGE_TYPES = array( 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg' );

	/**
	 * Largest image accepted, in bytes.
	 */
	private const MAX_IMAGE_BYTES = 12582912;

	/**
	 * Directory, under the design root, where inline images are written out.
	 */
	private const INLINE_DIR = '_inline';

	/**
	 * SVG elements allowed through the sanitiser. Anything else is removed.
	 *
	 * @var array<int, string>
	 */
	private const SVG_ELEMENTS = array(
		'svg',
		'g',
		'path',
		'rect',
		'circle',
		'ellipse',
		'line',
		'polyline',
		'polygon',
		'text',
		'tspan',
		'textpath',
		'defs',
		'lineargradient',
		'radialgradient',
		'stop',
		'clippath',
		'mask',
		'use',
		'symbol',
		'title',
		'desc',
		'pattern',
		'marker',
		'image',
		'filter',
		'feblend',
		'fecolormatrix',
		'fecomponenttransfer',
		'fecomposite',
		'feconvolvematrix',
		'fediffuselighting',
		'fedisplacementmap',
		'fedistantlight',
		'fedropshadow',
		'feflood',
		'fefunca',
		'fefuncb',
		'fefuncg',
		'fefuncr',
		'fegaussianblur',
		'feimage',
		'femerge',
		'femergenode',
		'femorphology',
		'feoffset',
		'fepointlight',
		'fespecularlighting',
		'fespotlight',
		'fetile',
		'feturbulence',
	);

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
			return new WP_Error( 'qwerty_soft_no_design', __( 'That design is not on this site.', 'qwerty-soft-signal' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Pictures pasted straight into the markup become files first.
		self::materialise_data_uris( $root );

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
	 * Write images embedded as data: URIs out to files the importer can see.
	 *
	 * A design tool that lets someone paste a screenshot stores it inside the
	 * page as base64. The converter and the Media Library both work in files,
	 * so each such picture is decoded once into `_inline/` under the design
	 * root and the markup is rewritten to point at it. Re-running is a no-op:
	 * the file is named by its content, and a page with no data: URIs left is
	 * not touched.
	 *
	 * @param string $root Design root directory.
	 * @return int How many images were written out.
	 */
	public static function materialise_data_uris( string $root ): int {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		$written = 0;

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 1 !== preg_match( '/\.html?$/i', $file->getFilename() ) ) {
				continue;
			}

			$path = str_replace( '\\', '/', $file->getPathname() );
			$html = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			if ( ! str_contains( $html, 'data:image/' ) ) {
				continue;
			}

			$rewritten = (string) preg_replace_callback(
				'#(\s(?:src|data-src|href|poster)=")data:image/(png|jpeg|jpg|webp|gif);base64,([A-Za-z0-9+/=\s]+)(")#i',
				static function ( array $found ) use ( $root, &$written ): string {
					$ext   = 'jpg' === strtolower( $found[2] ) ? 'jpeg' : strtolower( $found[2] );
					$bytes = base64_decode( (string) preg_replace( '/\s+/', '', $found[3] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an inline image a design tool embedded; the bytes are written to a file, never executed.

					if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_IMAGE_BYTES ) {
						return $found[0];
					}

					$rel  = self::INLINE_DIR . '/' . sha1( $bytes ) . '.' . $ext;
					$dest = $root . '/' . $rel;

					if ( ! is_dir( dirname( $dest ) ) && ! wp_mkdir_p( dirname( $dest ) ) ) {
						return $found[0];
					}

					if ( ! is_file( $dest ) ) {
						if ( false === file_put_contents( $dest, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing into the importer's own unpacked design.
							return $found[0];
						}

						++$written;
					}

					// The page's own directory may be below the root.
					return $found[1] . $rel . $found[4];
				},
				$html
			);

			if ( $rewritten !== $html ) {
				file_put_contents( $path, $rewritten ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewriting the importer's own unpacked design.
			}
		}

		return $written;
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
			return new WP_Error( 'qwerty_soft_unreadable', $rel );
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'qwerty_soft_uploads', (string) $uploads['error'] );
		}

		$name = wp_unique_filename( $uploads['path'], basename( $rel ) );
		$dest = trailingslashit( $uploads['path'] ) . $name;
		$svg  = 'svg' === strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );

		if ( $svg ) {
			/*
			 * Vector files are the one format that can carry a script, so
			 * they never go in as they came: the file written to the library
			 * is the sanitised copy, and one that fails sanitising is refused.
			 */
			$clean = self::sanitise_svg( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			if ( null === $clean ) {
				return new WP_Error( 'qwerty_soft_svg_rejected', $rel );
			}

			if ( false === file_put_contents( $dest, $clean ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing the sanitised copy into the uploads directory.
				return new WP_Error( 'qwerty_soft_copy', $rel );
			}
		} elseif ( ! copy( $path, $dest ) ) {
			return new WP_Error( 'qwerty_soft_copy', $rel );
		}

		$allow_svg = static function ( $mimes ): array {
			$mimes        = is_array( $mimes ) ? $mimes : array();
			$mimes['svg'] = 'image/svg+xml';

			return $mimes;
		};

		// Scoped to this one call: the site's own upload policy is unchanged.
		if ( $svg ) {
			add_filter( 'upload_mimes', $allow_svg );
		}

		$type = wp_check_filetype( $dest );

		if ( $svg ) {
			remove_filter( 'upload_mimes', $allow_svg );
		}

		$mime = (string) ( $type['type'] ?? '' );

		if ( '' === $mime && $svg ) {
			$mime = 'image/svg+xml';
		}

		if ( '' === $mime ) {
			wp_delete_file( $dest );

			return new WP_Error( 'qwerty_soft_filetype', $rel );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
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

		$metadata = $svg
			? self::svg_metadata( $dest, $uploads )
			: wp_generate_attachment_metadata( $id, $dest );

		wp_update_attachment_metadata( $id, $metadata );
		update_post_meta( $id, self::SOURCE_META, $rel );

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return (int) $id;
	}

	/**
	 * Attachment metadata for a vector file, so the editor knows its size.
	 *
	 * WordPress's own generator reads pixels and finds none in an SVG. The
	 * viewBox, or the width and height attributes, say what the editor needs.
	 *
	 * @param string               $dest    Absolute path in the uploads directory.
	 * @param array<string, mixed> $uploads wp_upload_dir() result.
	 * @return array<string, mixed>
	 */
	private static function svg_metadata( string $dest, array $uploads ): array {
		$size = self::svg_size( (string) file_get_contents( $dest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading the file just written.

		$base = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' );
		$file = ltrim( substr( str_replace( '\\', '/', $dest ), strlen( $base ) ), '/' );

		return array(
			'width'    => $size[0],
			'height'   => $size[1],
			'file'     => $file,
			'filesize' => (int) filesize( $dest ),
			'sizes'    => array(),
		);
	}

	/**
	 * The intrinsic size declared by an SVG, in whole pixels.
	 *
	 * @param string $svg File contents.
	 * @return array{0:int,1:int}
	 */
	private static function svg_size( string $svg ): array {
		if ( 1 !== preg_match( '/<svg\b[^>]*>/i', $svg, $open ) ) {
			return array( 0, 0 );
		}

		$tag    = $open[0];
		$width  = 0;
		$height = 0;

		if ( 1 === preg_match( '/\swidth="([\d.]+)(?:px)?"/i', $tag, $w ) && 1 === preg_match( '/\sheight="([\d.]+)(?:px)?"/i', $tag, $h ) ) {
			$width  = (int) round( (float) $w[1] );
			$height = (int) round( (float) $h[1] );
		}

		if ( ( 0 === $width || 0 === $height ) && 1 === preg_match( '/\sviewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*"/i', $tag, $box ) ) {
			$width  = (int) round( (float) $box[1] );
			$height = (int) round( (float) $box[2] );
		}

		return array( max( 0, $width ), max( 0, $height ) );
	}

	/**
	 * Reduce an SVG to the drawing instructions and nothing else.
	 *
	 * An SVG is a document, and a document can run code: in a script
	 * element, in an event attribute, in a foreignObject holding HTML, in a
	 * reference to something on another server. None of those draw anything,
	 * so the sanitiser keeps an allowlist of drawing elements, drops every
	 * attribute that starts with "on", and only follows references to the
	 * file itself or to an embedded raster. A file that is not an SVG at the
	 * root, or that declares a DOCTYPE (the entity-expansion route), is
	 * refused outright rather than repaired.
	 *
	 * @param string $svg Raw file contents.
	 * @return string|null Cleaned markup, or null when the file is refused.
	 */
	public static function sanitise_svg( string $svg ): ?string {
		$svg = trim( $svg );

		// Strip a UTF-8 byte-order mark, which libxml treats as content.
		if ( str_starts_with( $svg, "\xEF\xBB\xBF" ) ) {
			$svg = substr( $svg, 3 );
		}

		if ( '' === $svg || 1 === preg_match( '/<!DOCTYPE/i', $svg ) || 1 === preg_match( '/<!ENTITY/i', $svg ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();

		$loaded = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOCDATA );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $dom->documentElement;

		if ( ! $loaded || ! $root instanceof \DOMElement || 'svg' !== strtolower( $root->localName ) ) {
			return null;
		}

		// Walk a snapshot: removing while iterating a live list skips nodes.
		$elements = iterator_to_array( $dom->getElementsByTagName( '*' ) );

		foreach ( $elements as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			if ( ! in_array( strtolower( $element->localName ), self::SVG_ELEMENTS, true ) ) {
				if ( $element->parentNode instanceof \DOMNode ) {
					$element->parentNode->removeChild( $element );
				}

				continue;
			}

			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				if ( ! $attribute instanceof \DOMAttr ) {
					continue;
				}

				$name  = strtolower( $attribute->name );
				$value = trim( $attribute->value );

				if ( str_starts_with( $name, 'on' ) || ! self::svg_attribute_is_safe( $name, $value ) ) {
					$element->removeAttributeNode( $attribute );
				}
			}
		}

		// Processing instructions can load a stylesheet from anywhere.
		foreach ( iterator_to_array( $dom->childNodes ) as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$dom->removeChild( $node );
			}
		}

		$out = $dom->saveXML( $root );

		return is_string( $out ) && '' !== $out ? $out : null;
	}

	/**
	 * Whether one SVG attribute may stay.
	 *
	 * @param string $name  Attribute name, lower-cased.
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function svg_attribute_is_safe( string $name, string $value ): bool {
		$is_reference = 'href' === $name || 'xlink:href' === $name || str_ends_with( $name, ':href' );

		if ( $is_reference ) {
			if ( str_starts_with( $value, '#' ) ) {
				return true;
			}

			return 1 === preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,#i', $value );
		}

		// url(...) inside a style or a paint must point at the file itself.
		$lowered = strtolower( $value );

		if ( str_contains( $lowered, 'url(' ) && 1 !== preg_match( '/url\(\s*["\']?#/', $lowered ) ) {
			return false;
		}

		return ! str_contains( $lowered, 'javascript:' ) && ! str_contains( $lowered, '&#' ) && ! str_contains( $lowered, '@import' );
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

		$markup = (string) preg_replace_callback(
			'#(src|srcset)="([^"]+)"#i',
			static function ( array $found ) use ( $map, $page_dir ): string {
				$resolved = self::resolve( $found[2], $map, $page_dir );

				return null === $resolved ? $found[0] : $found[1] . '="' . esc_url( $resolved['url'] ) . '"';
			},
			$markup
		);

		/*
		 * A cover block carries its picture twice: once as the <img> just
		 * handled, and once as a JSON attribute in the block comment, which
		 * the editor reads. Both have to agree or the editor shows a broken
		 * image over a perfectly good one.
		 */
		return (string) preg_replace_callback(
			'#(<!-- wp:cover \{[^\n]*?"url":")([^"]+)(")#',
			static function ( array $found ) use ( $map, $page_dir ): string {
				$resolved = self::resolve( str_replace( '\/', '/', $found[2] ), $map, $page_dir );

				if ( null === $resolved ) {
					return $found[0];
				}

				$encoded = wp_json_encode( esc_url( $resolved['url'] ) );
				$encoded = is_string( $encoded ) ? trim( $encoded, '"' ) : '';

				return '' === $encoded ? $found[0] : $found[1] . $encoded . $found[3];
			},
			$markup
		);
	}

	/**
	 * Point every `url(...)` in a stylesheet at the media that was imported.
	 *
	 * A wrapped section keeps the design's own CSS, and that CSS names its
	 * pictures the way the archive did — `url(img/band.jpg)`, relative to
	 * where the stylesheet used to sit. Copied into a block's own directory
	 * those addresses resolve to nothing, so a hero with a background image
	 * renders as a coloured rectangle and nothing says why.
	 *
	 * @param string                                  $css      Stylesheet text.
	 * @param array<string, array{id:int,url:string}> $map      Path to attachment.
	 * @param string                                  $page_dir Directory of the page.
	 * @return string
	 */
	public static function relink_css_urls( string $css, array $map, string $page_dir = '' ): string {
		if ( array() === $map || '' === trim( $css ) ) {
			return $css;
		}

		return (string) preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			static function ( array $found ) use ( $map, $page_dir ): string {
				$resolved = self::resolve( $found[2], $map, $page_dir );

				return null === $resolved ? $found[0] : 'url("' . $resolved['url'] . '")';
			},
			$css
		);
	}

	/**
	 * The attachment one of the design's own image paths was imported as.
	 *
	 * The same resolution the markup rewrite uses, exposed because a wrapped
	 * section needs the attachment's id rather than its address: an ACF image
	 * field holding a bare URL renders but cannot be changed from the library.
	 *
	 * @param string                                  $src      Original attribute value.
	 * @param array<string, array{id:int,url:string}> $map      Path to attachment.
	 * @param string                                  $page_dir Directory of the page.
	 * @return array{id:int,url:string}|null
	 */
	public static function attachment_for( string $src, array $map, string $page_dir = '' ) {
		return self::resolve( $src, $map, $page_dir );
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
