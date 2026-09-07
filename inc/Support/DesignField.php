<?php
/**
 * Reads a field value whatever shape it arrives in.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The small readings a generated block needs.
 *
 * ACF hands the same field back in three different shapes depending on how the
 * group was configured and whether a value was ever set: an image is an array,
 * or an id, or an empty string. A generated template cannot branch on that
 * without becoming unreadable, and a template that assumes one shape is a
 * fatal error on the day somebody changes the return format in the field
 * group. So the branching lives here and the template stays a line long.
 *
 * Every reading returns a string. Nothing here escapes — the template does
 * that, at the point of echo, where it is visible in review.
 */
final class DesignField {

	/**
	 * Whether to read the block's own data and ignore ACF for the moment.
	 *
	 * A build measures the page it just wrote in the same request that wrote
	 * it. ACF registered the blocks' field groups at init, from the files
	 * that were on disk then, so `get_field()` in that request answers from
	 * a group that may no longer match — a repeater read through last
	 * version's sub-fields came back with every new field empty, and a good
	 * page measured 74%. The block carries its own values, and while this is
	 * on they are the only ones read. {@see Fidelity::of()} turns it on.
	 *
	 * @var bool
	 */
	public static $raw = false;

	/**
	 * A field's value, with or without ACF.
	 *
	 * This exists because of a failure worth naming. A generated block pointed
	 * only at `acf.renderTemplate`, so on a site without ACF Pro WordPress had
	 * no way to render it and produced nothing at all — pages that were
	 * structurally correct and visually empty, with no error and nothing in
	 * the markup to say why.
	 *
	 * The design's own words are already in the block, put there when the page
	 * was built. So the block can always draw itself: ACF, when present, makes
	 * the words editable; when absent they are read straight back out of the
	 * block. A missing plugin costs the editing, never the page.
	 *
	 * @param string $name     Field name.
	 * @param mixed  $block    The block, as core or ACF passes it.
	 * @param mixed  $fallback What the design itself said, used when nothing is stored.
	 * @return mixed
	 */
	public static function value( string $name, $block = null, $fallback = null ) {
		if ( ! self::$raw && function_exists( 'get_field' ) ) {
			$found = get_field( $name );

			if ( null !== $found && '' !== $found && array() !== $found ) {
				return $found;
			}
		}

		$stored = self::stored( $name, $block );

		return self::given( $stored ) ? $stored : $fallback;
	}

	/**
	 * Whether a stored value is worth preferring over the design's own.
	 *
	 * A field somebody cleared on purpose is a value; a field that was never
	 * written is not. The two look alike from here, and the design's own words
	 * are the better guess for both — a section that comes up blank tells an
	 * editor nothing about what belongs in it.
	 *
	 * @param mixed $value What was found.
	 * @return bool
	 */
	private static function given( $value ): bool {
		if ( null === $value || '' === $value || array() === $value ) {
			return false;
		}

		return true;
	}

	/**
	 * A site-wide field's value, with or without ACF.
	 *
	 * @param string $name     Field name.
	 * @param mixed  $fallback What the design itself said, used when nothing is stored.
	 * @return mixed
	 */
	public static function site( string $name, $fallback = null ) {
		if ( ! self::$raw && function_exists( 'get_field' ) ) {
			$found = get_field( $name, 'option' );

			if ( null !== $found && '' !== $found && array() !== $found ) {
				return $found;
			}
		}

		/*
		 * ACF's own storage, read directly. The importer wrote these rows
		 * itself for the same reason it is reading them here: neither moment
		 * can depend on the plugin being loaded.
		 */
		$stored = get_option( 'options_' . $name, null );

		return self::given( $stored ) ? $stored : $fallback;
	}

	/**
	 * What the block itself carries, whichever shape it arrived in.
	 *
	 * @param string $name  Field name.
	 * @param mixed  $block The block, as core or ACF passes it.
	 * @return mixed
	 */
	private static function stored( string $name, $block ) {
		$data = array();

		if ( is_array( $block ) && isset( $block['data'] ) && is_array( $block['data'] ) ) {
			$data = $block['data'];
		} elseif ( $block instanceof \WP_Block && isset( $block->attributes['data'] ) && is_array( $block->attributes['data'] ) ) {
			$data = $block->attributes['data'];
		} elseif ( is_array( $block ) && isset( $block['attrs']['data'] ) && is_array( $block['attrs']['data'] ) ) {
			$data = $block['attrs']['data'];
		}

		return $data[ $name ] ?? '';
	}

	/**
	 * The rows of a repeat, with or without ACF.
	 *
	 * @param string $name  Repeater name.
	 * @param mixed  $block The block, as core or ACF passes it.
	 * @param bool   $site  Whether the repeat lives on the options page.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( string $name, $block = null, bool $site = false ): array {
		if ( ! self::$raw && function_exists( 'get_field' ) ) {
			$found = $site ? get_field( $name, 'option' ) : get_field( $name );

			if ( is_array( $found ) && array() !== $found ) {
				return $found;
			}
		}

		/*
		 * Flat storage, rebuilt into rows. ACF keeps a repeater as a count and
		 * one entry per field per index — `items`, `items_0_heading`,
		 * `items_1_heading` — and that is the shape both the block and the
		 * options page hold when the plugin is not there to read it.
		 */
		$flat  = $site ? null : self::stored( $name, $block );
		$count = 0;

		if ( $site ) {
			$count = (int) get_option( 'options_' . $name, 0 );
		} elseif ( is_numeric( $flat ) ) {
			$count = (int) $flat;
		} elseif ( is_array( $flat ) ) {
			return $flat;
		}

		$rows = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$row    = array();
			$prefix = $name . '_' . $index . '_';

			if ( $site ) {
				foreach ( self::site_keys( $prefix ) as $key => $value ) {
					$row[ $key ] = $value;
				}
			} else {
				foreach ( self::block_keys( $prefix, $block ) as $key => $value ) {
					$row[ $key ] = $value;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Every stored value under one repeater-row prefix, on the options page.
	 *
	 * @param string $prefix Row prefix, such as `items_0_`.
	 * @return array<string, mixed>
	 */
	private static function site_keys( string $prefix ): array {
		$found = array();

		foreach ( (array) get_option( SiteOptions::REGISTER, array() ) as $name ) {
			if ( ! is_string( $name ) || ! str_starts_with( $name, 'options_' . $prefix ) ) {
				continue;
			}

			$found[ substr( $name, strlen( 'options_' . $prefix ) ) ] = get_option( $name, '' );
		}

		return $found;
	}

	/**
	 * Every value the block carries under one repeater-row prefix.
	 *
	 * @param string $prefix Row prefix, such as `items_0_`.
	 * @param mixed  $block  The block, as core or ACF passes it.
	 * @return array<string, mixed>
	 */
	private static function block_keys( string $prefix, $block ): array {
		$data = array();

		if ( is_array( $block ) && isset( $block['data'] ) && is_array( $block['data'] ) ) {
			$data = $block['data'];
		} elseif ( $block instanceof \WP_Block && isset( $block->attributes['data'] ) && is_array( $block->attributes['data'] ) ) {
			$data = $block->attributes['data'];
		}

		$found = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, $prefix ) ) {
				$found[ substr( $key, strlen( $prefix ) ) ] = $value;
			}
		}

		return $found;
	}

	/**
	 * The URL of an image field, whatever ACF returned.
	 *
	 * @param mixed $value Field value.
	 * @return string URL, or empty.
	 */
	public static function image_url( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['url'] ?? '' );
		}

		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_image_url( (int) $value, 'full' );

			return is_string( $url ) ? $url : '';
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The alt text of an image field.
	 *
	 * @param mixed $value Field value.
	 * @return string Alt text, or empty.
	 */
	public static function image_alt( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['alt'] ?? '' );
		}

		if ( is_numeric( $value ) ) {
			return (string) get_post_meta( (int) $value, '_wp_attachment_image_alt', true );
		}

		return '';
	}

	/**
	 * The address of a link field.
	 *
	 * @param mixed $value Field value.
	 * @return string URL, or empty.
	 */
	public static function link_url( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['url'] ?? '' );
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * A link value, escaped without a host being invented for it.
	 *
	 * `esc_url()` reads anything without a scheme and without a leading slash
	 * as a host name, so a design's own `../en/` comes out as `http://../en/` —
	 * a link to a machine that does not exist, on every page that draws the
	 * block. The importer rewrites every relative link it can match to a page;
	 * what is left is a link into a part of the design that was never imported,
	 * and it is better left relative and reported than dressed up as absolute.
	 *
	 * @param mixed $value A link field, or a plain address.
	 * @return string Ready to place inside an href attribute.
	 */
	public static function url( $value ): string {
		$url = trim( self::link_url( $value ) );

		if ( '' === $url ) {
			return '';
		}

		if ( 1 === preg_match( '~^(https?:)?//|^(mailto|tel):|^[#/?]~i', $url ) ) {
			return esc_url( $url );
		}

		/*
		 * Relative, so it stays relative. Anything that could carry script is
		 * not a relative path in the first place — a colon before the first
		 * slash is a scheme, and none of the ones left here are wanted.
		 */
		if ( 1 === preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) ) {
			return '';
		}

		return esc_attr( $url );
	}
	/**
	 * The site's navigation, as block markup a template can render.
	 *
	 * Looked up rather than baked in, and that is the whole point. A generated
	 * header is written once and never rewritten, while every rebuild made a
	 * fresh navigation post — so a header built on Monday held the id of a menu
	 * deleted on Tuesday, and the top of the site rendered an empty list. The
	 * id is read at render time instead, so the header follows whatever menu
	 * the site currently has.
	 *
	 * @return string Block markup, or empty when there is no menu to draw.
	 */
	public static function menu(): string {
		$id = (int) get_option( SiteOptions::MENU, 0 );

		/*
		 * The page's language picks its menu. The design ships a navigation
		 * per language — different words, sometimes different pages — and the
		 * build keeps each one; a Russian page drawing the English menu was
		 * the design mistranslated by the theme.
		 */
		$language = (string) get_post_meta( get_the_ID(), SiteAssembler::LANG_META, true );

		if ( '' !== $language ) {
			$menus = get_option( SiteOptions::MENUS, array() );
			$own   = is_array( $menus ) ? (int) ( $menus[ $language ] ?? 0 ) : 0;

			if ( $own > 0 ) {
				$id = $own;
			}
		}

		if ( $id <= 0 || 'wp_navigation' !== get_post_type( $id ) ) {
			return '';
		}

		return '<!-- wp:navigation {"ref":' . $id . ',"overlayMenu":"mobile"} /-->';
	}

	/**
	 * A copyright line with this year in it rather than the design's.
	 *
	 * The archive says "© 2026" as literal text. Kept as written, every site
	 * built from it is wrong from the next New Year, and it is the kind of
	 * wrong nobody notices for eleven months. The words stay the editor's; only
	 * the year is read from the clock.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	public static function dated( $value ): string {
		$text = is_string( $value ) ? $value : '';

		if ( '' === $text ) {
			return '';
		}

		if ( 1 === preg_match( '/\b(19|20)\d{2}\b/', $text ) ) {
			return (string) preg_replace_callback(
				'/\b(19|20)\d{2}\b/',
				static function (): string {
					return (string) gmdate( 'Y' );
				},
				$text,
				1
			);
		}

		/*
		 * No year to replace, which is not the same as a line that does not
		 * want one. A design that fills its own year in the browser writes
		 * `© <span id="year"></span> Name`; wrapping keeps the words and drops
		 * the empty span, and what reaches the page is "©  Name" — a copyright
		 * line with a hole in it, on every page, for good. The hole is where
		 * the year goes.
		 */
		return (string) preg_replace(
			'/(©|&copy;|\(c\))\s*/iu',
			'$1 ' . gmdate( 'Y' ) . ' ',
			$text,
			1
		);
	}

	/**
	 * The words on a link.
	 *
	 * @param mixed $value Field value.
	 * @return string Label, or empty.
	 */
	public static function link_text( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['title'] ?? '' );
		}

		return '';
	}

	/**
	 * A line of copy that may hold the design's own inline markup, made safe.
	 *
	 * The tags are {@see SectionPlan::RICH_TAGS}; the attributes are the few
	 * a stylesheet or a link needs. Everything else is stripped, whether it
	 * came from the archive or was typed into the field later. Run at build
	 * time on what the design said and again at render time on what is
	 * stored, so the two can never disagree about what a field may hold.
	 *
	 * @param mixed $value Field value.
	 * @return string Markup safe to echo inside the element that holds it.
	 */
	public static function inline( $value ): string {
		$html = is_string( $value ) ? $value : '';

		if ( '' === $html ) {
			return '';
		}

		if ( function_exists( 'wp_kses' ) ) {
			return wp_kses( $html, self::inline_tags() );
		}

		/*
		 * The same allowance without WordPress. `strip_tags()` keeps the
		 * tags it is told to and every attribute on them, so the attributes
		 * are then cut back by hand to the list `inline_tags()` allows.
		 */
		$kept = '<' . implode( '><', SectionPlan::RICH_TAGS ) . '>';
		$html = strip_tags( $html, $kept );

		return (string) preg_replace_callback(
			'/<([a-z][a-z0-9]*)\b([^>]*)>/i',
			static function ( array $found ): string {
				$allowed = self::inline_tags()[ strtolower( $found[1] ) ] ?? array();
				$kept    = '';

				preg_match_all( '/\s([a-z-]+)\s*=\s*("[^"]*"|\'[^\']*\')/i', $found[2], $pairs, PREG_SET_ORDER );

				foreach ( (array) $pairs as $pair ) {
					$name = strtolower( $pair[1] );

					if ( isset( $allowed[ $name ] ) && 1 !== preg_match( '/^["\']\s*javascript:/i', $pair[2] ) ) {
						$kept .= ' ' . $name . '=' . $pair[2];
					}
				}

				return '<' . strtolower( $found[1] ) . $kept . '>';
			},
			$html
		);
	}

	/**
	 * What `wp_kses()` may keep in a rich text field.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function inline_tags(): array {
		$tags = array();

		foreach ( SectionPlan::RICH_TAGS as $tag ) {
			$tags[ $tag ] = array(
				'class' => true,
				'id'    => true,
				'title' => true,
				'lang'  => true,
				'dir'   => true,
			);
		}

		$tags['a']['href']        = true;
		$tags['a']['target']      = true;
		$tags['a']['rel']         = true;
		$tags['time']['datetime'] = true;
		$tags['abbr']['title']    = true;

		return $tags;
	}

	/**
	 * Whether the block is being drawn for somebody who may edit it.
	 *
	 * The generated markup carries `data-qs-*` marks saying which element is
	 * which field, so the editor can offer them for editing on the canvas.
	 * A visitor has no use for them, so on the front end they are stripped
	 * — see {@see self::unmarked()}.
	 *
	 * @return bool
	 */
	public static function editing(): bool {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}

		$editor = ( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		return $editor && current_user_can( 'edit_posts' );
	}

	/**
	 * Rendered markup with the editor's marks taken out.
	 *
	 * @param string $html Rendered block.
	 * @return string
	 */
	public static function unmarked( string $html ): string {
		return (string) preg_replace( '/\s+data-qs-(?:field|type|row|index|rows)="[^"]*"/', '', $html );
	}
}
