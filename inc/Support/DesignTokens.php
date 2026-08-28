<?php
/**
 * Reads a design's own colours and type, and makes the site wear them.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * The step that stops every imported site looking like this theme.
 *
 * The theme supplies the vocabulary — base, surface, contrast, accent — and
 * the design supplies what those words mean. Roles are worked out from how a
 * stylesheet is used rather than what its authors named things, because one
 * design calls its brand colour "gold" and the next calls it "accent", and no
 * list of names would survive the third design.
 *
 * Values are written to the site's own global styles, which is where the Site
 * Editor writes too: the import is a starting point the owner can then change
 * by hand, not a fact baked into the theme.
 */
final class DesignTokens {

	/**
	 * Every palette slug the theme's markup refers to.
	 *
	 * The whole set has to be written at once. WordPress replaces the theme
	 * palette wholesale when user styles define one, so a partial palette
	 * silently deletes the slugs it leaves out.
	 *
	 * @var array<int, string>
	 */
	private const SLUGS = array(
		'base',
		'surface',
		'surface-2',
		'border',
		'border-strong',
		'contrast',
		'muted',
		'accent',
		'accent-ink',
		'accent-2',
		'accent-3',
		'success',
		'warning',
	);

	/**
	 * Read a design's tokens.
	 *
	 * @param string $root Design root directory.
	 * @return array{colors:array<string,string>,fonts:array<string,string>,radius:string,source:array<string,string>}
	 */
	public static function extract( string $root ): array {
		$css  = self::gather_css( $root );
		$vars = self::custom_properties( $css );

		$base     = self::declaration( $css, array( 'body', 'html', ':root' ), 'background-color', $vars )
			?? self::declaration( $css, array( 'body', 'html' ), 'background', $vars );
		$contrast = self::declaration( $css, array( 'body', 'html' ), 'color', $vars );
		$accent   = self::accent( $css, $vars );
		$surface  = self::declaration( $css, array( '.card', '.panel', '.box', '.tile' ), 'background-color', $vars )
			?? self::declaration( $css, array( '.card', '.panel' ), 'background', $vars );
		$border   = self::declaration( $css, array( '.card', '.panel', 'hr', 'table', 'td', 'th' ), 'border-color', $vars );
		$muted    = self::named( $vars, array( 'muted', 'secondary', 'faint', 'subtle', 'dim' ) );

		// Anything still unknown is derived from what is known.
		$base     = $base ?? self::named( $vars, array( 'bg', 'background', 'white', 'base', 'page' ) ) ?? '#ffffff';
		$contrast = $contrast ?? self::named( $vars, array( 'ink', 'text', 'foreground', 'body', 'charcoal', 'navy' ) ) ?? '#111111';
		$accent   = $accent ?? self::named( $vars, array( 'accent', 'primary', 'brand', 'gold', 'cta' ) ) ?? '#2b54e6';

		$dark    = self::luminance( $base ) < 0.4;
		$surface = $surface ?? self::named( $vars, array( 'soft', 'surface', 'panel', 'muted-bg' ) );

		/*
		 * A card sits on the page, so its colour is a step away from the page
		 * colour, not the opposite of it. Designs reuse ".panel" for inverted
		 * bands too, and taking that would flip the whole site — so a surface
		 * that is nowhere near the base is discarded and derived instead.
		 */
		if ( null !== $surface && abs( self::luminance( $surface ) - self::luminance( $base ) ) > 0.3 ) {
			$surface = null;
		}

		$surface = $surface ?? self::shift( $base, $contrast, 0.05 );
		$border  = $border ?? self::named( $vars, array( 'line', 'border', 'rule', 'divider' ) ) ?? self::shift( $base, $contrast, 0.14 );
		$muted   = $muted ?? self::shift( $contrast, $base, 0.38 );

		$colors = array(
			'base'          => $base,
			'surface'       => $surface,
			'surface-2'     => self::shift( $base, $contrast, $dark ? 0.14 : 0.09 ),
			'border'        => $border,
			'border-strong' => self::shift( $border, $contrast, 0.45 ),
			'contrast'      => $contrast,
			'muted'         => $muted,
			'accent'        => $accent,
			'accent-ink'    => self::readable( $accent, $base, $contrast ),
			'accent-2'      => self::named( $vars, array( 'accent-2', 'secondary-accent', 'gold-2', 'accent2' ) ) ?? self::rotate( $accent, 40 ),
			'accent-3'      => self::named( $vars, array( 'accent-3', 'tertiary', 'accent3' ) ) ?? self::rotate( $accent, -40 ),
			'success'       => self::named( $vars, array( 'success', 'ok', 'positive', 'green' ) ) ?? '#1a7f4b',
			'warning'       => self::named( $vars, array( 'warning', 'warn', 'caution', 'amber' ) ) ?? '#b96c10',
		);

		$tokens = array(
			'colors' => $colors,
			'fonts'  => self::fonts( $css, $vars ),
			'radius' => self::named_raw( $vars, array( 'radius', 'border-radius', 'rounded' ) ) ?? '',
			'source' => $vars,
		);

		return $tokens + self::takeover( $root, $css, $vars, $colors, $tokens['fonts'] );
	}

	/**
	 * What colour the design paints each class it uses on a section.
	 *
	 * Lets the converter keep the original's rhythm — a band the design made
	 * grey stays a shade apart from one it left white — while still emitting
	 * palette slugs rather than literal colours.
	 *
	 * @param string $root Design root directory.
	 * @return array<string, string> Class name to hex.
	 */
	public static function section_backgrounds( string $root ): array {
		$css  = self::gather_css( $root );
		$vars = self::custom_properties( $css );
		$out  = array();

		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $rules as $rule ) {
			if ( 1 !== preg_match( '/(?:^|;)\s*background(?:-color|-image)?\s*:\s*([^;]+)/i', $rule[2], $found ) ) {
				continue;
			}

			$value = trim( self::resolve_var( trim( $found[1] ), $vars ) );

			/*
			 * A gradient is kept whole rather than flattened to one colour.
			 * A design's hero is very often a gradient, and reducing it to the
			 * nearest flat swatch is the difference between a page that looks
			 * like the archive and one that does not.
			 *
			 * A picture is kept as its url() so the converter can make the
			 * band a Cover block. It is recorded only for the image itself:
			 * the colour under it, if any, is not what the section shows.
			 */
			if ( 1 === preg_match( '/url\(\s*["\']?[^"\')]+["\']?\s*\)/i', $value, $picture ) && ! str_contains( strtolower( $picture[0] ), 'data:' ) ) {
				$color = $picture[0];
			} else {
				$color = str_contains( strtolower( $value ), 'gradient' )
					? self::clean_gradient( $value, $vars )
					: self::to_hex( $value, $vars );
			}

			if ( null === $color ) {
				continue;
			}

			foreach ( explode( ',', $rule[1] ) as $selector ) {
				$selector = trim( strtolower( $selector ) );

				// Only plain single-class selectors: anything else is context.
				if ( 1 !== preg_match( '/^\.([a-z0-9_-]+)$/', $selector, $class ) ) {
					continue;
				}

				$out[ $class[1] ] = $color;
			}
		}

		return $out;
	}

	/**
	 * A gradient declaration reduced to something a block can carry.
	 *
	 * Only the last layer is kept: designs stack a decorative radial highlight
	 * over the real background, and a block's gradient attribute holds one
	 * image. Any var() inside is resolved first, because the design's custom
	 * properties do not exist on the WordPress side.
	 *
	 * @param string                $value Declaration value.
	 * @param array<string, string> $vars  Custom properties.
	 * @return string|null
	 */
	public static function clean_gradient( string $value, array $vars ): ?string {
		$value = (string) preg_replace_callback(
			'/var\(\s*(--[a-z0-9\-_]+)\s*(?:,([^)]*))?\)/i',
			static function ( array $ref ) use ( $vars ): string {
				$name = strtolower( $ref[1] );

				return $vars[ $name ] ?? trim( $ref[2] ?? '' );
			},
			$value
		);

		// Split top-level commas so a stacked background can be taken apart.
		$layers = array();
		$depth  = 0;
		$buffer = '';

		foreach ( str_split( $value ) as $character ) {
			if ( '(' === $character ) {
				++$depth;
			}

			if ( ')' === $character ) {
				--$depth;
			}

			if ( ',' === $character && 0 === $depth ) {
				$layers[] = trim( $buffer );
				$buffer   = '';
				continue;
			}

			$buffer .= $character;
		}

		$layers[] = trim( $buffer );

		foreach ( array_reverse( $layers ) as $layer ) {
			if ( 1 === preg_match( '/^(linear|radial|conic)-gradient\(.+\)$/i', $layer ) ) {
				return $layer;
			}
		}

		return null;
	}

	/**
	 * Human-readable, translatable name for a palette slug.
	 *
	 * These are the labels the Site Editor shows in the colour picker, so
	 * they go through the text domain like any other UI string. An unknown
	 * slug falls back to the slug itself, title-cased.
	 *
	 * @param string $slug Palette slug.
	 * @return string
	 */
	private static function palette_name( string $slug ): string {
		$names = array(
			'base'          => _x( 'Base', 'Palette colour name', 'qwerty-soft-signal' ),
			'surface'       => _x( 'Surface', 'Palette colour name', 'qwerty-soft-signal' ),
			'surface-2'     => _x( 'Surface raised', 'Palette colour name', 'qwerty-soft-signal' ),
			'border'        => _x( 'Border', 'Palette colour name', 'qwerty-soft-signal' ),
			'border-strong' => _x( 'Border strong', 'Palette colour name', 'qwerty-soft-signal' ),
			'contrast'      => _x( 'Contrast', 'Palette colour name', 'qwerty-soft-signal' ),
			'muted'         => _x( 'Muted', 'Palette colour name', 'qwerty-soft-signal' ),
			'accent'        => _x( 'Accent', 'Palette colour name', 'qwerty-soft-signal' ),
			'accent-ink'    => _x( 'Accent ink', 'Palette colour name', 'qwerty-soft-signal' ),
			'accent-2'      => _x( 'Accent 2', 'Palette colour name', 'qwerty-soft-signal' ),
			'accent-3'      => _x( 'Accent 3', 'Palette colour name', 'qwerty-soft-signal' ),
			'success'       => _x( 'Success', 'Palette colour name', 'qwerty-soft-signal' ),
			'warning'       => _x( 'Warning', 'Palette colour name', 'qwerty-soft-signal' ),
		);

		return $names[ $slug ] ?? ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * Write the tokens into the site's global styles.
	 *
	 * @param array<string, mixed> $tokens extract() result.
	 * @return void
	 */
	public static function apply( array $tokens ): void {
		$palette = array();

		foreach ( self::SLUGS as $slug ) {
			$palette[] = array(
				'slug'  => $slug,
				'name'  => self::palette_name( $slug ),
				'color' => (string) ( $tokens['colors'][ $slug ] ?? '#000000' ),
			);
		}

		/*
		 * Whatever an earlier import wrote goes first, so a design that says
		 * less than the previous one does not inherit the remainder of it.
		 * That is also what makes applying the same design twice a no-op.
		 */
		$data = self::without_takeover( self::user_data() );

		$data['version']                     = 3;
		$data['isGlobalStylesUserThemeJSON'] = true;

		$written = array();

		self::put( $data, $written, 'settings.color.palette.theme', $palette );

		$body    = (string) ( $tokens['fonts']['body'] ?? '' );
		$heading = (string) ( $tokens['fonts']['heading'] ?? '' );

		if ( '' !== $body ) {
			self::put( $data, $written, 'styles.typography.fontFamily', $body );
		}

		if ( '' !== $heading ) {
			self::put( $data, $written, 'styles.elements.heading.typography.fontFamily', $heading );
		}

		/*
		 * The theme's default is dark text on its own bright accent. A design
		 * whose accent is pale needs the opposite, so the button label is
		 * whichever of the two page colours can actually be read on the fill.
		 * A design that names its own label colour keeps it when it is legible.
		 */
		$colors = $tokens['colors'];
		$label  = self::legible_on( $colors['accent'], $colors['base'], $colors['contrast'] );
		$slug   = $label === $colors['base'] ? 'base' : 'contrast';
		$text   = 'var(--wp--preset--color--' . $slug . ')';
		$own    = (string) ( $tokens['typography']['button']['color']['text'] ?? '' );

		if ( '' !== $own && ( str_starts_with( $own, 'var(' ) || self::contrast( $own, $colors['accent'] ) >= 4.5 ) ) {
			$text = $own;
		}

		self::put( $data, $written, 'styles.elements.button.color.text', $text );

		self::write_takeover( $data, $written, $tokens );

		self::save( $data );

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, array_values( array_unique( $written ) ), false );
		}
	}

	/**
	 * Put the theme's own palette, type, spacing and shape back.
	 *
	 * Removes exactly the paths apply() recorded, plus the handful an older
	 * version wrote before it kept a record, and nothing else the owner may
	 * have set in the Site Editor meanwhile.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::save( self::without_takeover( self::user_data() ) );

		delete_option( self::OPTION );
	}

	/**
	 * The option that lists every global-styles path apply() wrote.
	 *
	 * @var string
	 */
	public const OPTION = 'qwerty_soft_design_takeover';

	/**
	 * The paths apply() always wrote before it started recording them.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_PATHS = array(
		'settings.color.palette',
		'styles.typography.fontFamily',
		'styles.elements.button.color.text',
		'styles.elements.heading.typography.fontFamily',
	);

	/**
	 * User global styles with every path a design import wrote removed.
	 *
	 * @param array<string, mixed> $data Decoded user global styles.
	 * @return array<string, mixed>
	 */
	private static function without_takeover( array $data ): array {
		$recorded = get_option( self::OPTION, array() );
		$paths    = array_merge( self::LEGACY_PATHS, is_array( $recorded ) ? array_filter( $recorded, 'is_string' ) : array() );

		foreach ( $paths as $path ) {
			self::forget( $data, explode( '.', $path ) );
		}

		$data['settings'] = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
		$data['styles']   = isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array();

		return $data;
	}

	/**
	 * Set one dotted path and record that it was written.
	 *
	 * @param array<string, mixed> $data    Global styles, by reference.
	 * @param array<int, string>   $written Paths written so far, by reference.
	 * @param string               $path    Dotted path; block names keep their slash.
	 * @param mixed                $value   Value.
	 * @return void
	 */
	private static function put( array &$data, array &$written, string $path, $value ): void {
		$cursor = &$data;

		foreach ( explode( '.', $path ) as $key ) {
			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = array();
			}

			$cursor = &$cursor[ $key ];
		}

		$cursor    = $value;
		$written[] = $path;
	}

	/**
	 * Remove a path, then any parent it leaves empty.
	 *
	 * @param array<string, mixed> $data Global styles, by reference.
	 * @param array<int, string>   $keys Path segments.
	 * @return void
	 */
	private static function forget( array &$data, array $keys ): void {
		$key = array_shift( $keys );

		if ( null === $key || ! array_key_exists( $key, $data ) ) {
			return;
		}

		if ( array() === $keys ) {
			unset( $data[ $key ] );

			return;
		}

		if ( ! is_array( $data[ $key ] ) ) {
			return;
		}

		self::forget( $data[ $key ], $keys );

		if ( array() === $data[ $key ] ) {
			unset( $data[ $key ] );
		}
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
	 * Store global styles and drop the cached copy.
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

		// Both caches: the resolver's own and the stylesheet WordPress keeps beside it.
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
	}

	// ------------------------------------------------------------ takeover

	/**
	 * Everything beyond colour that makes the imported site look like the design.
	 *
	 * Reads the type scale, the spacing rhythm, the radii, shadows and
	 * gradients out of the design's stylesheets and expresses them in the
	 * theme's own vocabulary — same preset slugs, same custom keys — so every
	 * pattern and block style keeps resolving, but to the design's values.
	 *
	 * Only desktop rules count: `max-width` media blocks are the narrow-screen
	 * adjustments and would otherwise pull a 96px hero heading down to 48px.
	 *
	 * @param string                $root   Design root.
	 * @param string                $css    Every stylesheet, concatenated.
	 * @param array<string, string> $vars   Custom properties.
	 * @param array<string, string> $colors Palette extract() settled on.
	 * @param array<string, string> $fonts  Body and heading stacks.
	 * @return array<string, mixed>
	 */
	private static function takeover( string $root, string $css, array $vars, array $colors, array $fonts ): array {
		$theme = self::theme_json();
		$rules = self::rules( self::desktop_css( $css ), $vars );
		$probe = self::probe( $root, $css );

		$type    = self::type_scale( $rules, $probe, $theme );
		$spacing = self::spacing_scale( $rules, $probe, $theme );
		$shape   = self::shape( $rules, $vars, $colors, $theme );

		return array(
			'font_sizes'    => $type['sizes'],
			'typography'    => self::typography( $rules, $probe, $type, $colors, $fonts ),
			'spacing_sizes' => $spacing['sizes'],
			'layout'        => $spacing['layout'],
			'radii'         => $shape['radii'],
			'shadows'       => $shape['shadows'],
			'gradients'     => $shape['gradients'],
			'blocks'        => self::block_defaults( $probe, $colors ),
			'css'           => self::css_overrides( $rules, $theme ),
		);
	}

	/**
	 * Write the takeover tokens into user global styles.
	 *
	 * Every value passes through safecss_filter_attr() first; one that does
	 * not survive is left out rather than written half-cleaned.
	 *
	 * @param array<string, mixed> $data    Global styles, by reference.
	 * @param array<int, string>   $written Paths written, by reference.
	 * @param array<string, mixed> $tokens  extract() result.
	 * @return void
	 */
	private static function write_takeover( array &$data, array &$written, array $tokens ): void {
		$theme = self::theme_json();

		// 1. Type scale, same slugs and names as the theme's.
		$sizes = is_array( $tokens['font_sizes'] ?? null ) ? $tokens['font_sizes'] : array();

		if ( array() !== $sizes ) {
			$list = array();

			foreach ( self::theme_presets( $theme, array( 'settings', 'typography', 'fontSizes' ) ) as $preset ) {
				$slug = (string) $preset['slug'];

				if ( ! isset( $sizes[ $slug ] ) || ! self::safe( 'font-size', (string) $sizes[ $slug ]['size'] ) ) {
					continue;
				}

				$entry = array(
					'slug'  => $slug,
					'name'  => (string) ( $preset['name'] ?? $slug ),
					'size'  => (string) $sizes[ $slug ]['size'],
					'fluid' => false,
				);

				$fluid = $sizes[ $slug ]['fluid'] ?? false;

				if ( is_array( $fluid ) && self::safe( 'font-size', (string) $fluid['min'] ) && self::safe( 'font-size', (string) $fluid['max'] ) ) {
					$entry['fluid'] = array(
						'min' => (string) $fluid['min'],
						'max' => (string) $fluid['max'],
					);
				}

				$list[] = $entry;
			}

			if ( array() !== $list ) {
				self::put( $data, $written, 'settings.typography.fontSizes.theme', $list );
			}
		}

		// 2. Typography on body, headings, links, buttons, captions.
		$typography = is_array( $tokens['typography'] ?? null ) ? $tokens['typography'] : array();

		foreach ( $typography as $element => $groups ) {
			$base = 'body' === $element ? 'styles' : 'styles.elements.' . $element;

			foreach ( self::flatten( (array) $groups ) as $leaf => $value ) {
				$property = self::css_property( $leaf );

				if ( ! is_string( $value ) || '' === $value || ! self::safe( $property, $value ) ) {
					continue;
				}

				self::put( $data, $written, $base . '.' . $leaf, $value );
			}
		}

		// 3. Spacing scale and layout.
		$spacing = is_array( $tokens['spacing_sizes'] ?? null ) ? $tokens['spacing_sizes'] : array();

		if ( array() !== $spacing ) {
			$list = array();

			foreach ( self::theme_presets( $theme, array( 'settings', 'spacing', 'spacingSizes' ) ) as $preset ) {
				$slug = (string) $preset['slug'];

				if ( isset( $spacing[ $slug ] ) && self::safe( 'padding', (string) $spacing[ $slug ] ) ) {
					$list[] = array(
						'slug' => $slug,
						'name' => (string) ( $preset['name'] ?? $slug ),
						'size' => (string) $spacing[ $slug ],
					);
				}
			}

			if ( array() !== $list ) {
				self::put( $data, $written, 'settings.spacing.spacingSizes.theme', $list );
			}
		}

		$layout = is_array( $tokens['layout'] ?? null ) ? $tokens['layout'] : array();

		foreach ( array( 'contentSize', 'wideSize' ) as $key ) {
			if ( isset( $layout[ $key ] ) && self::safe( 'max-width', (string) $layout[ $key ] ) ) {
				self::put( $data, $written, 'settings.layout.' . $key, (string) $layout[ $key ] );
			}
		}

		if ( isset( $layout['blockGap'] ) && self::safe( 'gap', (string) $layout['blockGap'] ) ) {
			self::put( $data, $written, 'styles.spacing.blockGap', (string) $layout['blockGap'] );
		}

		// 4. Shape: radii, shadows, gradients.
		foreach ( (array) ( $tokens['radii'] ?? array() ) as $key => $value ) {
			if ( is_string( $value ) && self::safe( 'border-radius', $value ) ) {
				self::put( $data, $written, 'settings.custom.radius.' . $key, $value );
			}
		}

		$shadows = is_array( $tokens['shadows'] ?? null ) ? $tokens['shadows'] : array();

		if ( array() !== $shadows ) {
			$list = array();

			foreach ( self::theme_presets( $theme, array( 'settings', 'shadow', 'presets' ) ) as $preset ) {
				$slug = (string) $preset['slug'];

				if ( isset( $shadows[ $slug ] ) && self::safe( 'box-shadow', (string) $shadows[ $slug ] ) ) {
					$list[] = array(
						'slug'   => $slug,
						'name'   => (string) ( $preset['name'] ?? $slug ),
						'shadow' => (string) $shadows[ $slug ],
					);
				}
			}

			if ( array() !== $list ) {
				self::put( $data, $written, 'settings.shadow.presets.theme', $list );
			}
		}

		$gradients = is_array( $tokens['gradients'] ?? null ) ? $tokens['gradients'] : array();

		if ( array() !== $gradients ) {
			$list = array();

			foreach ( self::theme_presets( $theme, array( 'settings', 'color', 'gradients' ) ) as $preset ) {
				$slug = (string) $preset['slug'];

				if ( isset( $gradients[ $slug ] ) && self::safe( 'background-image', (string) $gradients[ $slug ] ) ) {
					$list[] = array(
						'slug'     => $slug,
						'name'     => (string) ( $preset['name'] ?? $slug ),
						'gradient' => (string) $gradients[ $slug ],
					);
				}
			}

			if ( array() !== $list ) {
				self::put( $data, $written, 'settings.color.gradients.theme', $list );
			}
		}

		// 5. Block defaults.
		foreach ( (array) ( $tokens['blocks'] ?? array() ) as $block => $groups ) {
			foreach ( self::flatten( (array) $groups ) as $leaf => $value ) {
				if ( is_string( $value ) && self::safe( self::css_property( $leaf ), $value ) ) {
					self::put( $data, $written, 'styles.blocks.' . $block . '.' . $leaf, $value );
				}
			}
		}

		/*
		 * 6. The theme's critical CSS. WordPress replaces, rather than appends,
		 * a user-level styles.css, so the theme's own sheet has to travel with
		 * the overrides or the focus ring and skip link would go with it.
		 */
		$overrides = (string) ( $tokens['css'] ?? '' );

		if ( '' !== $overrides ) {
			self::put( $data, $written, 'styles.css', (string) ( $theme['styles']['css'] ?? '' ) . $overrides );
		}
	}

	/**
	 * The theme's own theme.json, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private static function theme_json(): array {
		static $cache = null;

		if ( null === $cache ) {
			$dir  = defined( 'QSOFT_DIR' ) ? (string) QSOFT_DIR : dirname( __DIR__, 2 );
			$json = is_readable( $dir . '/theme.json' ) ? (string) file_get_contents( $dir . '/theme.json' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- The theme's own file.
			$data = json_decode( $json, true );

			$cache = is_array( $data ) ? $data : array();
		}

		return $cache;
	}

	/**
	 * A preset list from theme.json, as slug/name rows.
	 *
	 * @param array<string, mixed> $theme theme.json.
	 * @param array<int, string>   $path  Path to the list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function theme_presets( array $theme, array $path ): array {
		$node = $theme;

		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
				return array();
			}

			$node = $node[ $key ];
		}

		$out = array();

		foreach ( is_array( $node ) ? $node : array() as $preset ) {
			if ( is_array( $preset ) && isset( $preset['slug'] ) ) {
				$out[] = $preset;
			}
		}

		return $out;
	}

	/**
	 * Does a value survive WordPress's inline-style filter unchanged?
	 *
	 * Outside WordPress (the unit runner) every value is trusted, since the
	 * write path that needs the check never runs there.
	 *
	 * @param string $property CSS property the value is for.
	 * @param string $value    Value.
	 * @return bool
	 */
	private static function safe( string $property, string $value ): bool {
		if ( '' === $value || 1 === preg_match( '/[<>{};]|url\(|expression|javascript:/i', $value ) ) {
			return false;
		}

		/*
		 * Shadows are not on the inline-style allow list at all, so they get
		 * their own grammar: two to four lengths and one colour per layer.
		 */
		if ( 'box-shadow' === $property ) {
			$color = '(?:#[0-9a-f]{3,8}|rgba?\([0-9\s.,%\/]+\)|hsla?\([0-9\s.,%\/]+\))';
			$layer = '(?:inset\s+)?(?:' . $color . '\s+)?(?:-?\d*\.?\d+(?:px|rem|em)?(?:\s+|$)){2,4}(?:' . $color . ')?';

			foreach ( self::split_commas( $value ) as $part ) {
				if ( 1 !== preg_match( '/^' . $layer . '$/i', trim( $part ) ) ) {
					return false;
				}
			}

			return true;
		}

		if ( ! function_exists( 'safecss_filter_attr' ) ) {
			return true;
		}

		$filtered = safecss_filter_attr( $property . ': ' . $value );

		return '' !== $filtered && strtolower( trim( substr( $filtered, strlen( $property ) + 1 ) ) ) === strtolower( $value );
	}

	/**
	 * Nested style groups to dotted leaves: typography.fontSize => value.
	 *
	 * @param array<string, mixed> $groups Style node.
	 * @param string               $prefix Path so far.
	 * @return array<string, mixed>
	 */
	private static function flatten( array $groups, string $prefix = '' ): array {
		$out = array();

		foreach ( $groups as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				$out += self::flatten( $value, $path );
			} else {
				$out[ $path ] = $value;
			}
		}

		return $out;
	}

	/**
	 * The CSS property a theme.json leaf stands for, for the safety filter.
	 *
	 * @param string $leaf Dotted leaf such as `typography.fontSize`.
	 * @return string
	 */
	private static function css_property( string $leaf ): string {
		$last = (string) substr( (string) strrchr( '.' . $leaf, '.' ), 1 );
		$map  = array(
			'text'       => 'color',
			'background' => 'background-color',
			'radius'     => 'border-radius',
			'width'      => 'border-width',
			'style'      => 'border-style',
			'color'      => 'border-color',
			'top'        => 'padding-top',
			'right'      => 'padding-right',
			'bottom'     => 'padding-bottom',
			'left'       => 'padding-left',
			'blockGap'   => 'gap',
		);

		if ( isset( $map[ $last ] ) ) {
			return $map[ $last ];
		}

		return strtolower( (string) preg_replace( '/([A-Z])/', '-$1', $last ) );
	}

	// ------------------------------------------------------------- reading

	/**
	 * The stylesheet with narrow-screen, print and dark-scheme blocks removed.
	 *
	 * Other conditional groups (`min-width` up to 1024px, @supports, @layer)
	 * are unwrapped so their rules read as ordinary ones; descriptor at-rules
	 * such as @font-face and @keyframes are dropped.
	 *
	 * @param string $css Stylesheet text.
	 * @return string
	 */
	private static function desktop_css( string $css ): string {
		$css    = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$out    = '';
		$buffer = '';
		$length = strlen( $css );
		$i      = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '{' !== $char ) {
				$buffer .= $char;
				++$i;
				continue;
			}

			$prelude = trim( $buffer );
			$buffer  = '';
			$depth   = 0;
			$start   = $i;

			for ( ; $i < $length; $i++ ) {
				if ( '{' === $css[ $i ] ) {
					++$depth;
				} elseif ( '}' === $css[ $i ] ) {
					--$depth;

					if ( 0 === $depth ) {
						break;
					}
				}
			}

			$body = substr( $css, $start + 1, max( 0, $i - $start - 1 ) );
			++$i;

			if ( ! str_starts_with( $prelude, '@' ) ) {
				$out .= $prelude . '{' . $body . "}\n";
				continue;
			}

			if ( 1 !== preg_match( '/^@(media|supports|layer|container)\b/i', $prelude ) ) {
				continue;
			}

			$at = strtolower( $prelude );

			if ( str_starts_with( $at, '@container' ) || 1 === preg_match( '/max-width|print|prefers-color-scheme|orientation/', $at ) ) {
				continue;
			}

			if ( 1 === preg_match( '/min-width\s*:\s*([\d.]+)\s*(px|em|rem)/', $at, $width )
				&& (float) $width[1] * ( 'px' === $width[2] ? 1 : 16 ) > 1024 ) {
				continue;
			}

			$out .= self::desktop_css( $body );
		}

		return $out;
	}

	/**
	 * Every flat rule as a selector list plus resolved declarations.
	 *
	 * @param string                $css  Flattened CSS.
	 * @param array<string, string> $vars Custom properties.
	 * @return array<int, array{selectors:array<int,string>, declarations:array<string,string>}>
	 */
	private static function rules( string $css, array $vars ): array {
		$out = array();

		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $found, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $found as $rule ) {
			$declarations = array();

			foreach ( explode( ';', $rule[2] ) as $declaration ) {
				$colon = strpos( $declaration, ':' );

				if ( false === $colon ) {
					continue;
				}

				$name  = strtolower( trim( substr( $declaration, 0, $colon ) ) );
				$value = trim( (string) preg_replace( '/\s*!\s*important\s*$/i', '', trim( substr( $declaration, $colon + 1 ) ) ) );

				if ( '' === $name || '' === $value || str_starts_with( $name, '--' ) ) {
					continue;
				}

				$value = self::substitute_vars( $value, $vars );

				if ( str_contains( $value, 'var(' ) ) {
					continue;
				}

				$declarations[ $name ] = $value;
			}

			if ( array() === $declarations ) {
				continue;
			}

			$selectors = array();

			foreach ( explode( ',', $rule[1] ) as $selector ) {
				$selector = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $selector ) ) );

				if ( '' !== $selector ) {
					$selectors[] = $selector;
				}
			}

			$out[] = array(
				'selectors'    => $selectors,
				'declarations' => $declarations,
			);
		}

		return $out;
	}

	/**
	 * What the design's cascade gives a handful of representative elements.
	 *
	 * A tiny document holding one of each thing the takeover asks about —
	 * a paragraph, a `small`, a lede, an eyebrow, a link, a primary button,
	 * a rule, a quote, a container — is run through CssIndex, which honours
	 * specificity and source order the way a browser would.
	 *
	 * @param string $root Design root.
	 * @param string $css  Every stylesheet (for inline blocks already gathered).
	 * @return array<string, array<string, string>> Probe name to resolved longhands.
	 */
	private static function probe( string $root, string $css ): array {
		unset( $root ); // The concatenated text already holds every file and inline block.

		$index = new CssIndex( array( $css ) );
		$html  = '<body>'
			. '<p id="qs-p"></p>'
			. '<small id="qs-small"></small>'
			. '<p id="qs-lead" class="lead lede hero-lede intro subtitle standfirst"></p>'
			. '<span id="qs-eyebrow" class="eyebrow kicker section-kicker overline"></span>'
			. '<a id="qs-link" href="#"></a>'
			. '<a id="qs-button" class="btn btn-primary button button-primary cta" href="#"></a>'
			. '<button id="qs-native"></button>'
			. '<hr id="qs-hr">'
			. '<blockquote id="qs-quote"><cite id="qs-cite"></cite></blockquote>'
			. '<figcaption id="qs-caption"></figcaption>'
			. '<div id="qs-container" class="container wrap wrapper inner shell"></div>'
			. '</body>';

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!doctype html><html>' . $html . '</html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$out  = array();
		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( $body instanceof \DOMElement ) {
			$out['body'] = $index->declared_for( $body );
		}

		foreach ( array( 'p', 'small', 'lead', 'eyebrow', 'link', 'button', 'native', 'hr', 'quote', 'cite', 'caption', 'container' ) as $name ) {
			$node = $document->getElementById( 'qs-' . $name );

			$out[ $name ] = $node instanceof \DOMElement ? $index->declared_for( $node ) : array();
		}

		// A design that styles <button> but no class still has a button.
		if ( ! isset( $out['button']['background-color'] ) && isset( $out['native']['background-color'] ) ) {
			$out['button'] = $out['native'] + $out['button'];
		}

		return $out;
	}

	/**
	 * A length in CSS pixels; clamp() and min()/max() read at their largest.
	 *
	 * @param string $value Length.
	 * @return float|null
	 */
	private static function px( string $value ): ?float {
		$value = strtolower( trim( $value ) );

		if ( 1 === preg_match( '/^clamp\((.+)\)$/s', $value, $parts ) ) {
			$range = self::clamp_range( $value );

			return null === $range ? null : $range['max'];
		}

		if ( 1 === preg_match( '/^(?:min|max)\((.+)\)$/s', $value, $inner ) ) {
			$best = null;

			foreach ( self::split_commas( $inner[1] ) as $part ) {
				$px = self::px( $part );

				if ( null !== $px && ( null === $best || $px > $best ) ) {
					$best = $px;
				}
			}

			return $best;
		}

		return CssIndex::px( $value );
	}

	/**
	 * The fixed ends of a clamp(), in pixels.
	 *
	 * @param string $value clamp(min, preferred, max).
	 * @return array{min:float,max:float}|null
	 */
	private static function clamp_range( string $value ): ?array {
		if ( 1 !== preg_match( '/^clamp\((.+)\)$/is', trim( $value ), $inner ) ) {
			return null;
		}

		$parts = self::split_commas( $inner[1] );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		$min = CssIndex::px( $parts[0] );
		$max = CssIndex::px( $parts[2] );

		if ( null === $min || null === $max || $min <= 0 || $max < $min ) {
			return null;
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * Split on top-level commas.
	 *
	 * @param string $text Text.
	 * @return array<int, string>
	 */
	private static function split_commas( string $text ): array {
		$parts  = array();
		$buffer = '';
		$depth  = 0;

		foreach ( str_split( $text ) as $char ) {
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			}

			if ( ',' === $char && 0 === $depth ) {
				$parts[] = trim( $buffer );
				$buffer  = '';
				continue;
			}

			$buffer .= $char;
		}

		$parts[] = trim( $buffer );

		return $parts;
	}

	/**
	 * Pixels as a rem string, trimmed.
	 *
	 * @param float $px Pixels.
	 * @return string
	 */
	private static function rem( float $px ): string {
		$text = rtrim( rtrim( number_format( $px / 16, 4, '.', '' ), '0' ), '.' );

		return ( '' === $text || '-0' === $text ? '0' : $text ) . 'rem';
	}

	/**
	 * The most common key of a histogram, ties going to the first seen.
	 *
	 * @param array<int|string, int> $counts Value to count.
	 * @return int|string|null
	 */
	private static function commonest( array $counts ) {
		if ( array() === $counts ) {
			return null;
		}

		arsort( $counts );

		return array_key_first( $counts );
	}

	/**
	 * Does a selector end on a given element, optionally qualified by a class?
	 *
	 * @param string $selector One selector.
	 * @param string $tag      Element name.
	 * @return bool
	 */
	private static function ends_with_tag( string $selector, string $tag ): bool {
		return 1 === preg_match( '/(?:^|[\s>+~])' . preg_quote( $tag, '/' ) . '(?:[.#][\w-]+)*$/', $selector );
	}

	/**
	 * Does a selector end on one of the given classes, in its resting state?
	 *
	 * @param string             $selector One selector.
	 * @param array<int, string> $classes  Class names without the dot.
	 * @return bool
	 */
	private static function ends_with_class( string $selector, array $classes ): bool {
		foreach ( $classes as $class ) {
			if ( 1 === preg_match( '/(?:^|[\s>+~\w])\.' . preg_quote( $class, '/' ) . '(?:\.[\w-]+)*$/', $selector ) ) {
				return true;
			}
		}

		return false;
	}

	// ---------------------------------------------------------- type scale

	/**
	 * The design's type sizes on the theme's font-size slugs.
	 *
	 * Body is `medium`. `small` and `x-small` come from `small` text and
	 * eyebrows; `large` from a lede. The heading slots are the design's
	 * distinct heading sizes in rank order, largest first into the largest
	 * slug, and whatever the design leaves unsaid is filled by stepping the
	 * nearest known size by a fixed ratio so the scale stays monotonic.
	 *
	 * @param array<int, array{selectors:array<int,string>, declarations:array<string,string>}> $rules Desktop rules.
	 * @param array<string, array<string, string>>                                              $probe Resolved probes.
	 * @param array<string, mixed>                                                              $theme theme.json.
	 * @return array{sizes:array<string,array{size:string,fluid:false|array{min:string,max:string}}>, levels:array<int,array<string,mixed>>, body:float}
	 */
	private static function type_scale( array $rules, array $probe, array $theme ): array {
		$slugs = array();

		foreach ( self::theme_presets( $theme, array( 'settings', 'typography', 'fontSizes' ) ) as $preset ) {
			$px = self::px( (string) ( $preset['size'] ?? '' ) );

			if ( null !== $px ) {
				$slugs[ (string) $preset['slug'] ] = $px;
			}
		}

		// Smallest first: the theme's own sizes give the slot order.
		asort( $slugs );
		$order = array_keys( $slugs );

		$body = self::px( (string) ( $probe['body']['font-size'] ?? '' ) ) ?? self::px( (string) ( $probe['p']['font-size'] ?? '' ) ) ?? 16.0;

		if ( $body < 10 || $body > 28 ) {
			$body = 16.0;
		}

		// Every heading level's sizes, largest kept, with the rule that set it.
		$levels    = array();
		$all_sizes = array();

		foreach ( $rules as $rule ) {
			if ( isset( $rule['declarations']['font-size'] ) ) {
				$px = self::px( $rule['declarations']['font-size'] );

				if ( null !== $px && $px >= 8 && $px <= 200 ) {
					$all_sizes[ (string) $px ] = ( $all_sizes[ (string) $px ] ?? 0 ) + 1;
				}
			}

			foreach ( $rule['selectors'] as $selector ) {
				if ( 1 !== preg_match( '/(?:^|[\s>+~])h([1-6])(?:[.#][\w-]+)*$/', $selector, $found ) ) {
					continue;
				}

				$level = (int) $found[1];
				$size  = isset( $rule['declarations']['font-size'] ) ? self::px( $rule['declarations']['font-size'] ) : null;

				$levels[ $level ] = $levels[ $level ] ?? array(
					'size'  => null,
					'fluid' => false,
					'all'   => array(),
					'props' => array(),
				);

				if ( null !== $size && $size >= 10 && $size <= 200 ) {
					$levels[ $level ]['all'][ (string) $size ] = self::clamp_range( $rule['declarations']['font-size'] );
				}

				foreach ( array( 'font-weight', 'line-height', 'letter-spacing', 'font-family', 'text-transform' ) as $property ) {
					if ( isset( $rule['declarations'][ $property ] ) ) {
						$levels[ $level ]['props'][ $property ][ $rule['declarations'][ $property ] ] = ( $levels[ $level ]['props'][ $property ][ $rule['declarations'][ $property ] ] ?? 0 ) + 1;
					}
				}

				if ( null !== $size && $size >= 10 && $size <= 200 && ( null === $levels[ $level ]['size'] || $size > $levels[ $level ]['size'] ) ) {
					$levels[ $level ]['size']  = $size;
					$levels[ $level ]['fluid'] = self::clamp_range( $rule['declarations']['font-size'] );
				}
			}
		}

		$sizes = array();

		$sizes['medium'] = array( $body, false );

		$small = self::px( (string) ( $probe['small']['font-size'] ?? '' ) );

		if ( null === $small || $small >= $body || $small < $body * 0.6 ) {
			$small = self::commonest_between( $all_sizes, $body * 0.7, $body - 0.5 ) ?? round( $body * 0.875, 2 );
		}

		$sizes['small'] = array( (float) $small, false );

		$x_small = self::px( (string) ( $probe['eyebrow']['font-size'] ?? '' ) );

		if ( null === $x_small || $x_small >= $small || $x_small < $body * 0.5 ) {
			$x_small = self::commonest_between( $all_sizes, $body * 0.55, $small - 0.5 ) ?? round( $small * 0.875, 2 );
		}

		$sizes['x-small'] = array( (float) $x_small, false );

		$lead_raw = (string) ( $probe['lead']['font-size'] ?? '' );
		$large    = self::px( $lead_raw );

		if ( null === $large || $large <= $body || $large > $body * 1.75 ) {
			$large    = self::commonest_between( $all_sizes, $body + 0.5, $body * 1.5 ) ?? round( $body * 1.2, 2 );
			$lead_raw = '';
		}

		$sizes['large'] = array( (float) $large, self::clamp_range( $lead_raw ) );

		/*
		 * Heading slots. The largest heading in the design is the top slug;
		 * the slots between it and `large` are spaced evenly on a log scale
		 * and each then snaps to the nearest size the design actually uses
		 * for a heading, when one is close. A design with no heading sizes
		 * gets the theme's own top-to-lede ratio applied to its lede.
		 */
		$heading_slots = array_values( array_filter( $order, static fn( string $slug ): bool => ! isset( $sizes[ $slug ] ) ) );
		$candidates    = array();
		$top           = null;
		$top_fluid     = false;

		foreach ( $levels as $level ) {
			foreach ( $level['all'] as $size => $fluid ) {
				if ( (float) $size > $large ) {
					$candidates[ (string) $size ] = $fluid;
				}
			}

			if ( null !== $level['size'] && $level['size'] > $large && ( null === $top || $level['size'] > $top ) ) {
				$top       = $level['size'];
				$top_fluid = $level['fluid'];
			}
		}

		if ( null === $top ) {
			$first = $slugs[ $order[ count( $order ) - 1 ] ] ?? 80.0;
			$last  = $slugs['large'] ?? 20.0;
			$top   = round( $large * ( $first / max( 1.0, $last ) ), 2 );
		}

		$steps = count( $heading_slots );

		foreach ( $heading_slots as $index => $slug ) {
			if ( $index === $steps - 1 ) {
				$sizes[ $slug ] = array( $top, $top_fluid );
				continue;
			}

			$target = $large * pow( $top / $large, ( $index + 1 ) / $steps );
			$chosen = array( round( $target, 2 ), false );
			$gap    = 0.18;

			foreach ( $candidates as $size => $fluid ) {
				$distance = abs( log( (float) $size / $target ) );

				if ( $distance < $gap ) {
					$gap    = $distance;
					$chosen = array( (float) $size, $fluid );
				}
			}

			$sizes[ $slug ] = $chosen;
		}

		// Monotonic, in the theme's order, whatever the design said.
		$previous = 0.0;

		foreach ( $order as $slug ) {
			if ( ! isset( $sizes[ $slug ] ) ) {
				continue;
			}

			if ( $sizes[ $slug ][0] <= $previous ) {
				$sizes[ $slug ] = array( round( $previous * 1.125, 2 ), false );
			}

			$previous = $sizes[ $slug ][0];
		}

		$out = array();

		foreach ( $order as $slug ) {
			if ( ! isset( $sizes[ $slug ] ) ) {
				continue;
			}

			list( $px, $fluid ) = $sizes[ $slug ];

			$out[ $slug ] = array(
				'size'  => self::rem( $px ),
				'fluid' => is_array( $fluid ) && $fluid['max'] >= $px - 0.01 ? array(
					'min' => self::rem( $fluid['min'] ),
					'max' => self::rem( $px ),
				) : false,
			);
		}

		return array(
			'sizes'  => $out,
			'levels' => $levels,
			'body'   => $body,
		);
	}

	/**
	 * The most used size inside a range, or null.
	 *
	 * @param array<string, int> $counts Size in px to count.
	 * @param float              $min    Lower bound, inclusive.
	 * @param float              $max    Upper bound, inclusive.
	 * @return float|null
	 */
	private static function commonest_between( array $counts, float $min, float $max ): ?float {
		$inside = array();

		foreach ( $counts as $size => $count ) {
			if ( (float) $size >= $min && (float) $size <= $max ) {
				$inside[ $size ] = $count;
			}
		}

		$found = self::commonest( $inside );

		return null === $found ? null : (float) $found;
	}

	/**
	 * The font-size preset whose value is nearest to a size.
	 *
	 * @param array<string, array{size:string}> $sizes Preset slug to size.
	 * @param float                             $px    Size.
	 * @return string|null Slug.
	 */
	private static function nearest_size( array $sizes, float $px ): ?string {
		$best     = null;
		$distance = INF;

		foreach ( $sizes as $slug => $entry ) {
			$value = self::px( $entry['size'] );

			if ( null === $value ) {
				continue;
			}

			$gap = abs( log( $value / $px ) );

			if ( $gap < $distance ) {
				$distance = $gap;
				$best     = (string) $slug;
			}
		}

		return $best;
	}

	/**
	 * Body, heading, link, button and caption styles in theme.json shape.
	 *
	 * @param array<int, array{selectors:array<int,string>, declarations:array<string,string>}>                $rules  Desktop rules.
	 * @param array<string, array<string, string>>                                                             $probe  Resolved probes.
	 * @param array{sizes:array<string,array{size:string}>, levels:array<int,array<string,mixed>>, body:float} $type Type scale.
	 * @param array<string, string>                                                                            $colors Palette.
	 * @param array<string, string>                                                                            $fonts  Font stacks.
	 * @return array<string, array<string, mixed>>
	 */
	private static function typography( array $rules, array $probe, array $type, array $colors, array $fonts ): array {
		$out   = array();
		$sizes = $type['sizes'];
		$order = array_keys( $sizes );

		// Body.
		$body = array( 'typography' => array( 'fontSize' => 'var(--wp--preset--font-size--medium)' ) );

		foreach ( self::text_props( $probe['body'] ?? array() ) as $key => $value ) {
			$body['typography'][ $key ] = $value;
		}

		if ( ! isset( $body['typography']['lineHeight'] ) && isset( $probe['p']['line-height'] ) ) {
			$body['typography']['lineHeight'] = self::text_props( array( 'line-height' => $probe['p']['line-height'] ) )['lineHeight'] ?? '1.6';
		}

		unset( $body['typography']['fontFamily'] );
		$out['body'] = $body;

		// Headings: the shared defaults are what most levels agree on.
		$levels  = $type['levels'];
		$heading = array( 'typography' => array() );

		foreach ( array( 'font-weight', 'line-height', 'letter-spacing' ) as $property ) {
			$votes = array();

			foreach ( $levels as $level ) {
				foreach ( $level['props'][ $property ] ?? array() as $value => $count ) {
					$votes[ $value ] = ( $votes[ $value ] ?? 0 ) + $count;
				}
			}

			$winner = self::commonest( $votes );

			if ( null !== $winner ) {
				$heading['typography'] += self::text_props( array( $property => (string) $winner ) );
			}
		}

		if ( ! isset( $heading['typography']['fontWeight'] ) ) {
			$heading['typography']['fontWeight'] = '700';
		}

		if ( ! isset( $heading['typography']['lineHeight'] ) ) {
			$heading['typography']['lineHeight'] = '1.15';
		}

		$out['heading'] = $heading;

		// Each level: a preset reference, plus whatever that level says itself.
		$slot = null;

		foreach ( range( 1, 6 ) as $level ) {
			$known = isset( $levels[ $level ]['size'] ) ? self::nearest_size( $sizes, (float) $levels[ $level ]['size'] ) : null;

			if ( null !== $known ) {
				$slot = $known;
			} elseif ( null === $slot ) {
				// No h1 size in the design: start one slot under the top.
				$slot = $order[ max( 0, count( $order ) - 2 ) ] ?? 'large';
			} else {
				$index = array_search( $slot, $order, true );
				$slot  = $order[ max( 1, (int) $index - 1 ) ];
			}

			// A lower level never outranks the one above it.
			if ( $level > 1 && isset( $out[ 'h' . ( $level - 1 ) ] ) ) {
				$above = array_search( str_replace( array( 'var(--wp--preset--font-size--', ')' ), '', $out[ 'h' . ( $level - 1 ) ]['typography']['fontSize'] ), $order, true );
				$mine  = array_search( $slot, $order, true );

				if ( false !== $above && false !== $mine && $mine > $above ) {
					$slot = $order[ $above ];
				}
			}

			$node = array( 'typography' => array( 'fontSize' => 'var(--wp--preset--font-size--' . $slot . ')' ) );

			// No font-family per level: class-scoped faces (`.section-head h2`) are carried per block by the converter; a site-wide serif would also hit the h2s the design left in its body face.
			foreach ( array( 'font-weight', 'line-height', 'letter-spacing', 'text-transform' ) as $property ) {
				$winner = self::commonest( $levels[ $level ]['props'][ $property ] ?? array() );

				if ( null === $winner ) {
					continue;
				}

				foreach ( self::text_props( array( $property => (string) $winner ) ) as $key => $value ) {
					if ( ( $heading['typography'][ $key ] ?? null ) !== $value && ( 'fontFamily' !== $key || ( $fonts['heading'] ?? '' ) !== $value ) ) {
						$node['typography'][ $key ] = $value;
					}
				}
			}

			$out[ 'h' . $level ] = $node;
		}

		// Links.
		// CssIndex drops `inherit`, which on a link is a decision in itself.
		$link = array();
		$text = self::color_ref( (string) ( $probe['link']['color'] ?? self::probe_has( $rules, 'a', 'color' ) ?? '' ), $colors );

		if ( null !== $text ) {
			$link['color']['text'] = $text;
		}

		if ( isset( $probe['link']['text-decoration'] ) || self::probe_has( $rules, 'a', 'text-decoration' ) ) {
			$decoration = $probe['link']['text-decoration'] ?? self::probe_has( $rules, 'a', 'text-decoration' );
			$decoration = strtolower( trim( (string) $decoration ) );

			if ( in_array( $decoration, array( 'none', 'underline' ), true ) ) {
				$link['typography']['textDecoration'] = $decoration;
			}
		}

		$hover = self::state_value( $rules, array( 'a:hover', 'a:focus', 'a:hover, a:focus' ), 'color' );
		$hover = null === $hover ? null : self::color_ref( $hover, $colors );

		if ( null !== $hover ) {
			$link[':hover']['color']['text'] = $hover;
		} elseif ( null !== $text ) {
			// A design that says nothing about hover keeps the link colour.
			$link[':hover']['color']['text'] = $text;
		}

		if ( array() !== $link ) {
			$out['link'] = $link;
		}

		// Buttons.
		$button = array();
		$fill   = $probe['button'] ?? array();

		$background = self::color_ref( (string) ( $fill['background-color'] ?? '' ), $colors );

		if ( null !== $background ) {
			$button['color']['background'] = $background;
		}

		$label = self::color_ref( (string) ( $fill['color'] ?? '' ), $colors );

		if ( null !== $label ) {
			$button['color']['text'] = $label;
		}

		if ( isset( $fill['border-radius'] ) ) {
			$radius = self::px( $fill['border-radius'] );

			if ( null !== $radius ) {
				$button['border']['radius'] = $radius >= 100 ? 'var(--wp--custom--radius--pill)' : self::rem( $radius );
			} elseif ( str_ends_with( trim( $fill['border-radius'] ), '%' ) ) {
				$button['border']['radius'] = trim( $fill['border-radius'] );
			}
		}

		$padding = array();

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$px = isset( $fill[ 'padding-' . $side ] ) ? self::px( $fill[ 'padding-' . $side ] ) : null;

			if ( null !== $px && $px >= 0 ) {
				$padding[ $side ] = self::rem( $px );
			}
		}

		if ( 4 === count( $padding ) ) {
			$button['spacing']['padding'] = $padding;
		}

		foreach ( self::text_props( $fill ) as $key => $value ) {
			if ( 'fontFamily' !== $key ) {
				$button['typography'][ $key ] = $value;
			}
		}

		if ( isset( $fill['font-size'] ) ) {
			$px = self::px( $fill['font-size'] );

			if ( null !== $px ) {
				$slug = self::nearest_size( $sizes, $px );

				if ( null !== $slug ) {
					$button['typography']['fontSize'] = 'var(--wp--preset--font-size--' . $slug . ')';
				}
			}
		}

		$button['typography']['textDecoration'] = 'none';

		$hover = self::state_value( $rules, array( '.btn-primary:hover', '.btn:hover', '.button:hover', '.button-primary:hover', 'button:hover', '.cta:hover' ), 'background-color' )
			?? self::state_value( $rules, array( '.btn-primary:hover', '.btn:hover', '.button:hover', '.button-primary:hover', 'button:hover', '.cta:hover' ), 'background' );
		$hover = null === $hover ? null : self::color_ref( $hover, $colors );

		if ( null === $hover && null !== $background ) {
			$hover = self::shift( $colors['accent'], $colors['contrast'], 0.18 );
		}

		if ( null !== $hover ) {
			$button[':hover']['color']['background'] = $hover;
			$button[':hover']['color']['text']       = $button['color']['text'] ?? 'var(--wp--preset--color--base)';
		}

		$out['button'] = $button;

		// Captions and citations, only when the design has a word to say.
		foreach ( array( 'caption', 'cite' ) as $name ) {
			$node = array();

			foreach ( self::text_props( $probe[ $name ] ?? array() ) as $key => $value ) {
				if ( 'fontFamily' !== $key ) {
					$node['typography'][ $key ] = $value;
				}
			}

			if ( isset( $probe[ $name ]['font-size'] ) ) {
				$px = self::px( $probe[ $name ]['font-size'] );

				if ( null !== $px && null !== self::nearest_size( $sizes, $px ) ) {
					$node['typography']['fontSize'] = 'var(--wp--preset--font-size--' . self::nearest_size( $sizes, $px ) . ')';
				}
			}

			$color = self::color_ref( (string) ( $probe[ $name ]['color'] ?? '' ), $colors );

			if ( null !== $color ) {
				$node['color']['text'] = $color;
			}

			if ( array() !== $node ) {
				$out[ $name ] = $node;
			}
		}

		return $out;
	}

	/**
	 * Weight, line-height, letter-spacing, transform and family as theme.json keys.
	 *
	 * @param array<string, string> $styles Resolved longhands.
	 * @return array<string, string>
	 */
	private static function text_props( array $styles ): array {
		$out = array();

		if ( isset( $styles['font-weight'] ) ) {
			$weight = strtolower( trim( $styles['font-weight'] ) );
			$named  = array(
				'normal' => '400',
				'bold'   => '700',
			);
			$weight = $named[ $weight ] ?? $weight;

			if ( 1 === preg_match( '/^\d{3}$/', $weight ) ) {
				$out['fontWeight'] = (string) min( 900, max( 100, (int) round( (int) $weight / 50 ) * 50 ) );
			}
		}

		if ( isset( $styles['line-height'] ) ) {
			$height = strtolower( trim( $styles['line-height'] ) );

			if ( 1 === preg_match( '/^\d*\.?\d+$/', $height ) && (float) $height >= 0.8 && (float) $height <= 2.5 ) {
				$out['lineHeight'] = $height;
			} elseif ( 'normal' === $height ) {
				$out['lineHeight'] = '1.2';
			}
		}

		if ( isset( $styles['letter-spacing'] ) ) {
			$spacing = strtolower( trim( $styles['letter-spacing'] ) );

			if ( 1 === preg_match( '/^-?\d*\.?\d+(em|rem|px)$/', $spacing ) ) {
				$out['letterSpacing'] = $spacing;
			} elseif ( 'normal' === $spacing || '0' === $spacing ) {
				$out['letterSpacing'] = '0';
			}
		}

		if ( isset( $styles['text-transform'] ) && in_array( strtolower( trim( $styles['text-transform'] ) ), array( 'none', 'uppercase', 'lowercase', 'capitalize' ), true ) ) {
			$out['textTransform'] = strtolower( trim( $styles['text-transform'] ) );
		}

		if ( isset( $styles['font-family'] ) && '' !== trim( $styles['font-family'] ) ) {
			$out['fontFamily'] = trim( $styles['font-family'] );
		}

		return $out;
	}

	/**
	 * A colour as a palette reference when it is one of the palette's, else hex.
	 *
	 * `inherit` on a link means "the text colour", which is `contrast`.
	 *
	 * @param string                $value  Declared colour.
	 * @param array<string, string> $colors Palette.
	 * @return string|null
	 */
	private static function color_ref( string $value, array $colors ): ?string {
		$value = strtolower( trim( $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( in_array( $value, array( 'inherit', 'currentcolor' ), true ) ) {
			return 'var(--wp--preset--color--contrast)';
		}

		$hex = self::to_hex( $value, array() );

		if ( null === $hex ) {
			return null;
		}

		foreach ( $colors as $slug => $swatch ) {
			if ( ! is_string( $swatch ) ) {
				continue;
			}

			$a = self::rgb( $hex );
			$b = self::rgb( $swatch );

			if ( abs( $a[0] - $b[0] ) + abs( $a[1] - $b[1] ) + abs( $a[2] - $b[2] ) <= 18 ) {
				return 'var(--wp--preset--color--' . $slug . ')';
			}
		}

		return $hex;
	}

	/**
	 * A property set on a plain element selector, from the rule list.
	 *
	 * @param array<int, array{selectors:array<int,string>, declarations:array<string,string>}> $rules    Desktop rules.
	 * @param string                                                                            $selector Exact selector.
	 * @param string                                                                            $property Property.
	 * @return string|null
	 */
	private static function probe_has( array $rules, string $selector, string $property ): ?string {
		$found = null;

		foreach ( $rules as $rule ) {
			if ( in_array( $selector, $rule['selectors'], true ) && isset( $rule['declarations'][ $property ] ) ) {
				$found = $rule['declarations'][ $property ];
			}
		}

		return $found;
	}

	/**
	 * A property from a state selector such as `.btn-primary:hover`.
	 *
	 * @param array<int, array{selectors:array<int,string>, declarations:array<string,string>}> $rules     Desktop rules.
	 * @param array<int, string>                                                                $selectors Candidates, best first.
	 * @param string                                                                            $property  Property.
	 * @return string|null
	 */
	private static function state_value( array $rules, array $selectors, string $property ): ?string {
		foreach ( $selectors as $wanted ) {
			foreach ( $rules as $rule ) {
				foreach ( $rule['selectors'] as $selector ) {
					if ( $selector !== $wanted && ! str_ends_with( $selector, ' ' . $wanted ) ) {
						continue;
					}

					if ( isset( $rule['declarations'][ $property ] ) ) {
						$value = $rule['declarations'][ $property ];

						// A shorthand: only its colour is wanted.
						if ( 'background' === $property && 1 === preg_match( '/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)|\b(?:white|black)\b/i', $value, $color ) ) {
							$value = $color[0];
						}

						if ( null !== self::to_hex( $value, array() ) ) {
							return $value;
						}
					}
				}
			}
		}

		return null;
	}

	// ------------------------------------------------------- spacing scale

	/**
	 * The design's spacing rhythm on the theme's spacing slugs.
	 *
	 * Every padding, margin and gap length in the desktop rules is rounded
	 * to a quarter-rem and counted. The most used values — always including
	 * the largest section padding, which is what the top slot is for — are
	 * sorted and assigned in order. Too few distinct values are stretched by
	 * a fixed ratio at the top so the scale keeps its shape.
	 *
	 * @param array<int, array{selectors:array<int,string>, declarations:array<string,string>}> $rules Desktop rules.
	 * @param array<string, array<string, string>>                                              $probe Resolved probes.
	 * @param array<string, mixed>                                                              $theme theme.json.
	 * @return array{sizes:array<string,string>, layout:array<string,string>}
	 */
	private static function spacing_scale( array $rules, array $probe, array $theme ): array {
		$slugs = array();

		foreach ( self::theme_presets( $theme, array( 'settings', 'spacing', 'spacingSizes' ) ) as $preset ) {
			$slugs[] = (string) $preset['slug'];
		}

		$counts   = array();
		$gaps     = array();
		$sections = array();
		$widths   = array();

		foreach ( $rules as $rule ) {
			$is_section = false;

			foreach ( $rule['selectors'] as $selector ) {
				if ( 1 === preg_match( '/(^|[\s>.])(section|hero|band|block|strip)\b/', $selector ) ) {
					$is_section = true;
				}
			}

			foreach ( $rule['declarations'] as $property => $value ) {
				if ( 1 === preg_match( '/^(padding|margin)(-(top|right|bottom|left))?$/', $property ) ) {
					foreach ( self::lengths( $value ) as $px ) {
						if ( $px < 4 || $px > 240 ) {
							continue;
						}

						$key            = self::quarter( $px );
						$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;

						if ( $is_section && str_starts_with( $property, 'padding' ) && ! str_ends_with( $property, '-left' ) && ! str_ends_with( $property, '-right' ) ) {
							$sections[ $key ] = ( $sections[ $key ] ?? 0 ) + 1;
						}
					}
				}

				if ( in_array( $property, array( 'gap', 'grid-gap', 'row-gap', 'column-gap' ), true ) ) {
					foreach ( self::lengths( $value ) as $px ) {
						if ( $px >= 4 && $px <= 120 ) {
							$key            = self::quarter( $px );
							$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
							$gaps[ $key ]   = ( $gaps[ $key ] ?? 0 ) + 1;
						}
					}
				}

				if ( in_array( $property, array( 'max-width', 'width' ), true ) ) {
					foreach ( self::lengths( $value ) as $px ) {
						if ( $px >= 600 && $px <= 1800 ) {
							$widths[ (string) $px ] = ( $widths[ (string) $px ] ?? 0 ) + 1;
						}
					}
				}
			}
		}

		$wanted = count( $slugs );
		$chosen = array();

		if ( $wanted > 0 ) {
			/*
			 * The scale runs from the smallest recurring gap to the largest
			 * section padding, with the slots spaced evenly on a log scale
			 * and each snapped to the nearest length the design really uses.
			 * Picking purely by frequency would fill every slot with the
			 * 4–16px of chips and badges and leave nothing for a section.
			 */
			$pool = array();

			// Recurring lengths first; a small stylesheet gets every length.
			foreach ( array( 2, 1 ) as $minimum ) {
				foreach ( $counts as $key => $count ) {
					if ( $count >= $minimum && (float) $key >= 8 && ! in_array( (float) $key, $pool, true ) ) {
						$pool[] = (float) $key;
					}
				}

				if ( count( $pool ) >= 4 ) {
					break;
				}
			}

			if ( array() === $pool ) {
				$pool = array( 8.0, 16.0, 24.0, 48.0 );
			}

			sort( $pool );

			$top = (float) end( $pool );

			foreach ( $sections as $key => $count ) {
				$top = max( $top, (float) $key );
			}

			$bottom = (float) $pool[0];

			if ( $top < $bottom * 3 ) {
				$top = $bottom * 6;
			}

			for ( $index = 0; $index < $wanted; $index++ ) {
				$target = $bottom * pow( $top / $bottom, $index / max( 1, $wanted - 1 ) );
				$pick   = self::quarter( $target );
				$gap    = 0.2;

				foreach ( $pool as $px ) {
					if ( in_array( $px, $chosen, true ) ) {
						continue;
					}

					$distance = abs( log( $px / $target ) );

					if ( $distance < $gap ) {
						$gap  = $distance;
						$pick = $px;
					}
				}

				$chosen[] = $pick;
			}

			sort( $chosen );

			// Strictly rising, or two slots would collide.
			$slots = count( $chosen );

			for ( $i = 1; $i < $slots; $i++ ) {
				if ( $chosen[ $i ] <= $chosen[ $i - 1 ] ) {
					$chosen[ $i ] = $chosen[ $i - 1 ] + 4;
				}
			}
		}

		$sizes = array();

		foreach ( $slugs as $index => $slug ) {
			$sizes[ $slug ] = self::rem( $chosen[ $index ] );
		}

		$layout = array();

		$gap = self::commonest( $gaps );

		if ( null !== $gap && array() !== $sizes ) {
			$layout['blockGap'] = 'var(--wp--preset--spacing--' . self::nearest_spacing( $sizes, (float) $gap ) . ')';
		}

		// The container's width is the content width; wide is a step beyond.
		$content = null;

		foreach ( array( 'max-width', 'width' ) as $property ) {
			if ( isset( $probe['container'][ $property ] ) ) {
				foreach ( self::lengths( $probe['container'][ $property ] ) as $px ) {
					if ( $px >= 600 && $px <= 1800 && ( null === $content || $px > $content ) ) {
						$content = $px;
					}
				}
			}
		}

		if ( null === $content ) {
			foreach ( $rules as $rule ) {
				foreach ( $rule['selectors'] as $selector ) {
					if ( ! self::ends_with_class( $selector, array( 'container', 'wrap', 'wrapper', 'inner', 'shell', 'content' ) ) ) {
						continue;
					}

					foreach ( array( 'max-width', 'width' ) as $property ) {
						foreach ( self::lengths( $rule['declarations'][ $property ] ?? '' ) as $px ) {
							if ( $px >= 600 && $px <= 1800 && ( null === $content || $px > $content ) ) {
								$content = $px;
							}
						}
					}
				}
			}
		}

		if ( null !== $content ) {
			$widest = $content;

			foreach ( array_keys( $widths ) as $px ) {
				$widest = max( $widest, (float) $px );
			}

			$wide = min( 1440.0, max( $content * 1.25, $widest ) );

			$layout['contentSize'] = self::rem( $content );
			$layout['wideSize']    = self::rem( max( $wide, $content ) );
		}

		return array(
			'sizes'  => $sizes,
			'layout' => $layout,
		);
	}

	/**
	 * Every fixed length in a value, in pixels.
	 *
	 * @param string $value Declaration value.
	 * @return array<int, float>
	 */
	private static function lengths( string $value ): array {
		$out = array();

		if ( 1 === preg_match( '/^clamp\(/i', trim( $value ) ) ) {
			$px = self::px( $value );

			return null === $px ? array() : array( $px );
		}

		if ( preg_match_all( '/(?<![\w.])(-?\d*\.?\d+)(px|rem|em)\b/i', $value, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $match ) {
				$px = CssIndex::px( $match[1] . $match[2] );

				if ( null !== $px && $px > 0 ) {
					$out[] = $px;
				}
			}
		}

		return $out;
	}

	/**
	 * A length rounded to the nearest quarter rem.
	 *
	 * @param float $px Pixels.
	 * @return float
	 */
	private static function quarter( float $px ): float {
		return max( 4.0, round( $px / 4 ) * 4 );
	}

	/**
	 * The spacing slug nearest a length.
	 *
	 * @param array<string, string> $sizes Slug to rem.
	 * @param float                 $px    Pixels.
	 * @return string
	 */
	private static function nearest_spacing( array $sizes, float $px ): string {
		$best     = (string) array_key_first( $sizes );
		$distance = INF;

		foreach ( $sizes as $slug => $size ) {
			$value = self::px( $size );

			if ( null !== $value && abs( $value - $px ) < $distance ) {
				$distance = abs( $value - $px );
				$best     = (string) $slug;
			}
		}

		return $best;
	}

	// --------------------------------------------------------------- shape

	/**
	 * Radii, shadows and gradients on the theme's keys and slugs.
	 *
	 * @param array<int, array{selectors:array<int,string>, declarations:array<string,string>}> $rules  Desktop rules.
	 * @param array<string, string>                                                             $vars   Custom properties.
	 * @param array<string, string>                                                             $colors Palette.
	 * @param array<string, mixed>                                                              $theme  theme.json.
	 * @return array{radii:array<string,string>, shadows:array<string,string>, gradients:array<string,string>}
	 */
	private static function shape( array $rules, array $vars, array $colors, array $theme ): array {
		$radii     = array();
		$shadows   = array();
		$gradients = array();

		foreach ( $rules as $rule ) {
			foreach ( $rule['declarations'] as $property => $value ) {
				if ( 'border-radius' === $property ) {
					foreach ( self::lengths( $value ) as $px ) {
						if ( $px >= 2 && $px < 100 ) {
							$radii[ (string) round( $px ) ] = ( $radii[ (string) round( $px ) ] ?? 0 ) + 1;
						}
					}
				}

				if ( 'box-shadow' === $property && 'none' !== strtolower( $value ) && 1 === preg_match( '/^[\w\s,.#()%\/-]+$/', $value ) && strlen( $value ) <= 200 ) {
					$key             = preg_replace( '/\s+/', ' ', trim( $value ) );
					$shadows[ $key ] = ( $shadows[ $key ] ?? 0 ) + 1;
				}

				if ( in_array( $property, array( 'background', 'background-image' ), true ) && str_contains( strtolower( $value ), 'gradient' ) ) {
					$gradient = self::clean_gradient( $value, array() );

					if ( null !== $gradient && strlen( $gradient ) <= 300 && 1 === preg_match( '/^(?:repeating-)?(?:linear|radial|conic)-gradient\([^;{}<>]+\)$/i', $gradient ) ) {
						$key               = preg_replace( '/\s+/', ' ', $gradient );
						$gradients[ $key ] = ( $gradients[ $key ] ?? 0 ) + 1;
					}
				}
			}
		}

		// Radii: smallest recurring to sm, largest recurring to lg, the usual one to md.
		$keys = array_keys(
			$theme['settings']['custom']['radius'] ?? array(
				'sm'   => 1,
				'md'   => 1,
				'lg'   => 1,
				'pill' => 1,
			)
		);
		$used = array();

		foreach ( $radii as $px => $count ) {
			if ( $count >= 2 || count( $radii ) < 3 ) {
				$used[] = (float) $px;
			}
		}

		sort( $used );

		if ( array() === $used ) {
			$used = array( 4.0, 8.0, 16.0 );
		}

		$sm = $used[0];
		$lg = min( 48.0, (float) end( $used ) );
		$md = (float) ( self::commonest( array_filter( $radii, static fn( int $count, string $px ): bool => (float) $px > $sm && (float) $px < $lg, ARRAY_FILTER_USE_BOTH ) ) ?? round( sqrt( $sm * $lg ) ) );

		$picked_radii = array(
			'sm' => $sm,
			'md' => $md,
			'lg' => $lg,
		);

		// A design that names its radii has already made the call.
		foreach ( array(
			'sm' => array( '--radius-sm', '--radius-small', '--radius-xs', '--rounded-sm' ),
			'md' => array( '--radius-md', '--radius', '--radius-medium', '--rounded', '--border-radius' ),
			'lg' => array( '--radius-lg', '--radius-large', '--radius-xl', '--rounded-lg' ),
		) as $key => $names ) {
			foreach ( $names as $name ) {
				$px = isset( $vars[ $name ] ) ? self::px( $vars[ $name ] ) : null;

				if ( null !== $px && $px >= 1 && $px < 100 ) {
					$picked_radii[ $key ] = $px;
					break;
				}
			}
		}

		if ( $picked_radii['md'] <= $picked_radii['sm'] ) {
			$picked_radii['md'] = round( $picked_radii['sm'] * 1.5 );
		}

		if ( $picked_radii['lg'] <= $picked_radii['md'] ) {
			$picked_radii['lg'] = round( $picked_radii['md'] * 1.5 );
		}

		$values = array(
			'sm'   => (string) round( $picked_radii['sm'] ) . 'px',
			'md'   => (string) round( $picked_radii['md'] ) . 'px',
			'lg'   => (string) round( $picked_radii['lg'] ) . 'px',
			'xl'   => (string) round( $picked_radii['lg'] * 1.5 ) . 'px',
			'pill' => '999px',
		);

		$radius = array();

		foreach ( $keys as $key ) {
			if ( isset( $values[ (string) $key ] ) ) {
				$radius[ (string) $key ] = $values[ (string) $key ];
			}
		}

		// Shadows: ranked by reach, the theme's slugs from soft to glow.
		$ink    = self::rgb( $colors['contrast'] ?? '#111111' );
		$accent = self::rgb( $colors['accent'] ?? '#2b54e6' );
		$ranked = array_keys( $shadows );

		usort(
			$ranked,
			static function ( string $a, string $b ): int {
				return self::reach( $a ) <=> self::reach( $b );
			}
		);

		$derived = array(
			'soft' => sprintf( '0 1px 2px rgba(%d, %d, %d, 0.08)', $ink[0], $ink[1], $ink[2] ),
			'card' => sprintf( '0 12px 32px -12px rgba(%d, %d, %d, 0.18)', $ink[0], $ink[1], $ink[2] ),
			'glow' => sprintf( '0 0 0 1px rgba(%1$d, %2$d, %3$d, 0.35), 0 18px 48px -18px rgba(%1$d, %2$d, %3$d, 0.45)', $accent[0], $accent[1], $accent[2] ),
		);

		$shadow_slugs = array();

		foreach ( self::theme_presets( $theme, array( 'settings', 'shadow', 'presets' ) ) as $preset ) {
			$shadow_slugs[] = (string) $preset['slug'];
		}

		$picked = array();
		$count  = count( $ranked );

		foreach ( $shadow_slugs as $index => $slug ) {
			$slots = count( $shadow_slugs );

			if ( $count >= $slots ) {
				// Spread the design's shadows across the slots by rank.
				$picked[ $slug ] = $ranked[ (int) round( $index * ( $count - 1 ) / max( 1, $slots - 1 ) ) ];
			} elseif ( $count > 0 && $index < $count ) {
				$picked[ $slug ] = $ranked[ $index ];
			} else {
				$picked[ $slug ] = $derived[ $slug ] ?? $derived['card'];
			}
		}

		/*
		 * Gradients: the design's most used first. Ones that run to
		 * transparent are photo overlays and are not offered as fills; the
		 * fade preset is always derived from the design's accent instead.
		 */
		arsort( $gradients );

		$solid = array_values(
			array_filter(
				array_keys( $gradients ),
				static fn( string $gradient ): bool => 1 !== preg_match( '/transparent|rgba?\([^)]*,\s*0(?:\.0+)?\s*\)/i', $gradient )
			)
		);

		$base           = self::rgb( $colors['base'] ?? '#ffffff' );
		$gradient_slugs = array();

		foreach ( self::theme_presets( $theme, array( 'settings', 'color', 'gradients' ) ) as $preset ) {
			$gradient_slugs[] = (string) $preset['slug'];
		}

		$derived_gradients = array(
			'aurora'      => sprintf( 'linear-gradient(120deg, %s 0%%, %s 100%%)', $colors['accent'] ?? '#2b54e6', $colors['accent-2'] ?? '#2b54e6' ),
			'aurora-soft' => sprintf( 'linear-gradient(160deg, %s 0%%, %s 100%%)', $colors['surface'] ?? '#f4f4f4', $colors['surface-2'] ?? '#eeeeee' ),
			'signal-fade' => sprintf( 'linear-gradient(180deg, rgba(%d, %d, %d, 0.16) 0%%, rgba(%d, %d, %d, 0) 100%%)', $accent[0], $accent[1], $accent[2], $base[0], $base[1], $base[2] ),
		);

		$chosen = array();
		$cursor = 0;

		foreach ( $gradient_slugs as $slug ) {
			if ( 'signal-fade' === $slug || str_contains( $slug, 'fade' ) ) {
				$chosen[ $slug ] = $derived_gradients['signal-fade'];
				continue;
			}

			if ( isset( $solid[ $cursor ] ) ) {
				$chosen[ $slug ] = $solid[ $cursor ];
				++$cursor;
				continue;
			}

			$chosen[ $slug ] = $derived_gradients[ $slug ] ?? ( $solid[0] ?? $derived_gradients['aurora'] );
		}

		return array(
			'radii'     => $radius,
			'shadows'   => $picked,
			'gradients' => $chosen,
		);
	}

	/**
	 * How far a shadow reaches: its largest offset or blur, for ranking.
	 *
	 * @param string $shadow box-shadow value.
	 * @return float
	 */
	private static function reach( string $shadow ): float {
		$most = 0.0;

		foreach ( self::lengths( $shadow ) as $px ) {
			$most = max( $most, $px );
		}

		return $most;
	}

	// ------------------------------------------------------ block defaults

	/**
	 * Separator and quote defaults, when the design styles a rule or a quote.
	 *
	 * @param array<string, array<string, string>> $probe  Resolved probes.
	 * @param array<string, string>                $colors Palette.
	 * @return array<string, array<string, mixed>>
	 */
	private static function block_defaults( array $probe, array $colors ): array {
		$out = array();

		$hr = $probe['hr'] ?? array();

		foreach ( array( 'border-color', 'background-color', 'color' ) as $property ) {
			$ref = self::color_ref( (string) ( $hr[ $property ] ?? '' ), $colors );

			if ( null !== $ref ) {
				$out['core/separator']['color']['text'] = $ref;
				break;
			}
		}

		$quote = $probe['quote'] ?? array();

		if ( isset( $quote['border-color'] ) ) {
			$ref = self::color_ref( $quote['border-color'], $colors );

			if ( null !== $ref ) {
				$out['core/quote']['border']['color'] = $ref;
			}
		}

		if ( isset( $quote['border-width'] ) ) {
			$px = self::px( $quote['border-width'] );

			if ( null !== $px && $px > 0 && $px <= 16 ) {
				$out['core/quote']['border']['width'] = (string) round( $px ) . 'px';
			}
		}

		if ( isset( $quote['font-size'] ) ) {
			$px = self::px( $quote['font-size'] );

			if ( null !== $px && $px >= 12 && $px <= 48 ) {
				$out['core/quote']['typography']['fontSize'] = self::rem( $px );
			}
		}

		return $out;
	}

	// ----------------------------------------------------------- theme css

	/**
	 * Overrides for the theme's critical CSS where it imposes a look.
	 *
	 * Almost all of that sheet is behaviour — focus rings, the skip link,
	 * tap targets — and follows the design through tokens. What does not is
	 * the header: sticky, translucent and blurred. A design whose header is
	 * none of those gets each undone, and only those.
	 *
	 * @param array<int, array{selectors:array<int,string>, declarations:array<string,string>}> $rules Desktop rules.
	 * @param array<string, mixed>                                                              $theme theme.json.
	 * @return string CSS to append after the theme's own, or empty.
	 */
	private static function css_overrides( array $rules, array $theme ): string {
		$sheet = (string) ( $theme['styles']['css'] ?? '' );

		if ( ! str_contains( $sheet, '.qs-header' ) ) {
			return '';
		}

		$header = array();
		$seen   = false;

		foreach ( $rules as $rule ) {
			foreach ( $rule['selectors'] as $selector ) {
				if ( 1 === preg_match( '/^(header|\.(site-header|header|topbar|navbar|masthead|site-nav|nav-wrap))$/', $selector ) ) {
					$seen   = true;
					$header = $rule['declarations'] + $header;
				}
			}
		}

		if ( ! $seen ) {
			return '';
		}

		$undo = array();

		$position = strtolower( (string) ( $header['position'] ?? 'static' ) );

		if ( ! in_array( $position, array( 'sticky', 'fixed' ), true ) ) {
			$undo[] = 'position:static';
		}

		if ( ! isset( $header['backdrop-filter'] ) && ! isset( $header['-webkit-backdrop-filter'] ) ) {
			$undo[] = 'backdrop-filter:none';
			$undo[] = 'background:var(--wp--preset--color--base)';
		}

		$border = strtolower( (string) ( $header['border-bottom'] ?? $header['border-bottom-width'] ?? $header['border'] ?? '' ) );

		if ( '' === $border || 'none' === $border || '0' === $border ) {
			$undo[] = 'border-bottom:0';
		}

		return array() === $undo ? '' : "\n/* design takeover: header */.qs-header{" . implode( ';', $undo ) . '}';
	}

	/**
	 * Every stylesheet in the design, concatenated.
	 *
	 * @param string $root Design root.
	 * @return string
	 */
	private static function gather_css( string $root ): string {
		$css      = '';
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'css' === strtolower( $file->getExtension() ) && $file->getSize() < 2097152 ) {
				$css .= "\n" . file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
			}
		}

		// Inline <style> blocks count too; some exports ship no .css at all.
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 1 === preg_match( '/\.html?$/i', $file->getFilename() ) && $file->getSize() < 2097152 ) {
				$html = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

				if ( preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $blocks ) ) {
					$css .= "\n" . implode( "\n", $blocks[1] );
				}
			}
		}

		return $css;
	}

	/**
	 * Custom properties declared anywhere in the design.
	 *
	 * @param string $css Stylesheet text.
	 * @return array<string, string>
	 */
	public static function custom_properties( string $css ): array {
		$vars = array();

		if ( preg_match_all( '/(--[a-z0-9\-_]+)\s*:\s*([^;}]+)/i', $css, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $match ) {
				$vars[ strtolower( trim( $match[1] ) ) ] = trim( $match[2] );
			}
		}

		// Resolve one level of var() indirection, which covers real designs.
		foreach ( $vars as $name => $value ) {
			if ( 1 === preg_match( '/var\(\s*(--[a-z0-9\-_]+)/i', $value, $ref ) ) {
				$target = strtolower( $ref[1] );

				if ( isset( $vars[ $target ] ) ) {
					$vars[ $name ] = $vars[ $target ];
				}
			}
		}

		return $vars;
	}

	/**
	 * The value of a property on the first matching selector.
	 *
	 * @param string                $css      Stylesheet text.
	 * @param array<int, string>    $wanted   Selectors to look for.
	 * @param string                $property Property name.
	 * @param array<string, string> $vars     Custom properties.
	 * @return string|null
	 */
	private static function declaration( string $css, array $wanted, string $property, array $vars ): ?string {
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $rules as $rule ) {
			$selectors = array_map( 'trim', explode( ',', strtolower( $rule[1] ) ) );

			foreach ( $wanted as $target ) {
				if ( ! in_array( strtolower( $target ), $selectors, true ) ) {
					continue;
				}

				if ( 1 !== preg_match( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+)/i', $rule[2], $found ) ) {
					continue;
				}

				$color = self::to_hex( trim( $found[1] ), $vars );

				if ( null !== $color ) {
					return $color;
				}
			}
		}

		return null;
	}

	/**
	 * The brand colour, taken from whatever the design calls a primary button.
	 *
	 * @param string                $css  Stylesheet text.
	 * @param array<string, string> $vars Custom properties.
	 * @return string|null
	 */
	private static function accent( string $css, array $vars ): ?string {
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
			return null;
		}

		$candidates = array();

		foreach ( $rules as $rule ) {
			$selector = strtolower( $rule[1] );

			$is_button = ( str_contains( $selector, 'btn' ) || str_contains( $selector, 'button' ) || str_contains( $selector, 'cta' ) )
				&& ! str_contains( $selector, ':hover' )
				&& ! str_contains( $selector, 'secondary' )
				&& ! str_contains( $selector, 'outline' )
				&& ! str_contains( $selector, 'ghost' )
				&& ! str_contains( $selector, 'light' );

			if ( ! $is_button ) {
				continue;
			}

			if ( 1 !== preg_match( '/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $rule[2], $found ) ) {
				continue;
			}

			$color = self::to_hex( trim( $found[1] ), $vars );

			if ( null === $color ) {
				continue;
			}

			// A button that is white or transparent is the secondary one.
			$luminance = self::luminance( $color );

			if ( $luminance > 0.92 || $luminance < 0.02 ) {
				continue;
			}

			$candidates[ $color ] = ( $candidates[ $color ] ?? 0 ) + 1;
		}

		if ( array() === $candidates ) {
			return null;
		}

		arsort( $candidates );

		return (string) array_key_first( $candidates );
	}

	/**
	 * Body and heading font stacks.
	 *
	 * @param string                $css  Stylesheet text.
	 * @param array<string, string> $vars Custom properties.
	 * @return array<string, string>
	 */
	private static function fonts( string $css, array $vars ): array {
		$fonts = array();

		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
			foreach ( $rules as $rule ) {
				if ( 1 !== preg_match( '/(?:^|;)\s*font-family\s*:\s*([^;]+)/i', $rule[2], $found ) ) {
					continue;
				}

				$stack     = self::resolve_var( trim( $found[1] ), $vars );
				$selectors = array_map( 'trim', explode( ',', strtolower( $rule[1] ) ) );

				if ( ! isset( $fonts['body'] ) && ( in_array( 'body', $selectors, true ) || in_array( 'html', $selectors, true ) ) ) {
					$fonts['body'] = $stack;
				}

				/*
				 * Only a bare element rule (`h1, h2 {…}`) sets the site-wide
				 * heading family. A class-scoped one (`.section-head h2`) is a
				 * local choice the converter carries per block; promoting it
				 * would put a serif on every heading the design left in its
				 * body face.
				 */
				foreach ( $selectors as $selector ) {
					if ( ! isset( $fonts['heading'] ) && 1 === preg_match( '/^h[1-6]$/', $selector ) ) {
						$fonts['heading'] = $stack;
					}
				}
			}
		}

		return $fonts;
	}

	/**
	 * A custom property whose name contains one of the given words.
	 *
	 * @param array<string, string> $vars  Custom properties.
	 * @param array<int, string>    $words Name fragments, best first.
	 * @return string|null
	 */
	private static function named( array $vars, array $words ): ?string {
		foreach ( $words as $word ) {
			foreach ( $vars as $name => $value ) {
				if ( str_contains( $name, $word ) ) {
					$color = self::to_hex( $value, $vars );

					if ( null !== $color ) {
						return $color;
					}
				}
			}
		}

		return null;
	}

	/**
	 * A custom property's raw value, without requiring it to be a colour.
	 *
	 * @param array<string, string> $vars  Custom properties.
	 * @param array<int, string>    $words Name fragments.
	 * @return string|null
	 */
	private static function named_raw( array $vars, array $words ): ?string {
		foreach ( $words as $word ) {
			foreach ( $vars as $name => $value ) {
				if ( str_contains( $name, $word ) ) {
					return $value;
				}
			}
		}

		return null;
	}

	/**
	 * Replace every var() reference inside a value, in place.
	 *
	 * Unlike resolve_var(), which answers "what colour is this?" and so returns
	 * only the referenced value, this keeps the surrounding text: `0 var(--gap)`
	 * becomes `0 22px`, which is what a shorthand needs before it can be split.
	 * References are followed a few levels deep; a fallback is used when the
	 * property is unknown, and an unresolvable reference is left untouched so
	 * the caller can see it and skip the declaration.
	 *
	 * @param string                $value Declaration value.
	 * @param array<string, string> $vars  Custom properties, lower-cased names.
	 * @return string
	 */
	public static function substitute_vars( string $value, array $vars ): string {
		$depth = 0;

		while ( $depth < 4 && str_contains( $value, 'var(' ) ) {
			++$depth;
			$next = preg_replace_callback(
				'/var\(\s*(--[a-z0-9\-_]+)\s*(?:,\s*((?:[^()]|\([^()]*\))*))?\)/i',
				static function ( array $ref ) use ( $vars ): string {
					$name = strtolower( $ref[1] );

					if ( isset( $vars[ $name ] ) ) {
						return trim( $vars[ $name ] );
					}

					return isset( $ref[2] ) && '' !== trim( $ref[2] ) ? trim( $ref[2] ) : $ref[0];
				},
				$value
			);

			if ( ! is_string( $next ) || $next === $value ) {
				break;
			}

			$value = $next;
		}

		return $value;
	}

	/**
	 * Resolve a var() reference to its declared value.
	 *
	 * @param string                $value Declaration value.
	 * @param array<string, string> $vars  Custom properties.
	 * @return string
	 */
	private static function resolve_var( string $value, array $vars ): string {
		if ( 1 === preg_match( '/var\(\s*(--[a-z0-9\-_]+)\s*(?:,\s*([^)]+))?\)/i', $value, $ref ) ) {
			$name = strtolower( $ref[1] );

			if ( isset( $vars[ $name ] ) ) {
				return trim( $vars[ $name ] );
			}

			if ( isset( $ref[2] ) ) {
				return trim( $ref[2] );
			}
		}

		return $value;
	}

	/**
	 * Turn any colour notation into a plain hex value.
	 *
	 * Gradients, transparent and semi-transparent values are refused: a
	 * palette entry has to be one solid colour.
	 *
	 * @param string                $value Declaration value.
	 * @param array<string, string> $vars  Custom properties.
	 * @return string|null
	 */
	public static function to_hex( string $value, array $vars ): ?string {
		$value = strtolower( trim( self::resolve_var( $value, $vars ) ) );
		$value = trim( (string) preg_replace( '/\s*!important\s*$/', '', $value ) );

		if ( str_contains( $value, 'gradient' ) || 'transparent' === $value || 'inherit' === $value || 'currentcolor' === $value ) {
			return null;
		}

		if ( 1 === preg_match( '/^#([0-9a-f]{3})$/', $value, $short ) ) {
			return '#' . $short[1][0] . $short[1][0] . $short[1][1] . $short[1][1] . $short[1][2] . $short[1][2];
		}

		if ( 1 === preg_match( '/^#([0-9a-f]{6})$/', $value, $long ) ) {
			return '#' . $long[1];
		}

		if ( 1 === preg_match( '/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*(?:[,\/]\s*([\d.]+))?\s*\)$/', $value, $rgb ) ) {
			if ( isset( $rgb[4] ) && (float) $rgb[4] < 0.9 ) {
				return null;
			}

			return sprintf( '#%02x%02x%02x', (int) $rgb[1], (int) $rgb[2], (int) $rgb[3] );
		}

		$named = array(
			'white' => '#ffffff',
			'black' => '#000000',
		);

		return $named[ $value ] ?? null;
	}

	/**
	 * A version of the brand colour that can be read as text.
	 *
	 * A brand colour is chosen to be seen, not to be read: this design's gold
	 * is 2.3:1 on white, and the original only ever put it on navy or used it
	 * as a fill behind dark text. Links and small labels need a version that
	 * clears 4.5:1, so the hue is kept and darkened — or lightened, on a dark
	 * page — until it does.
	 *
	 * @param string $accent   Brand colour.
	 * @param string $base     Page background.
	 * @param string $contrast Body text colour.
	 * @return string
	 */
	private static function readable( string $accent, string $base, string $contrast ): string {
		if ( self::contrast( $accent, $base ) >= 4.5 ) {
			return $accent;
		}

		$toward = self::luminance( $base ) > 0.5 ? '#000000' : '#ffffff';

		for ( $step = 1; $step <= 20; $step++ ) {
			$candidate = self::shift( $accent, $toward, $step * 0.05 );

			if ( self::contrast( $candidate, $base ) >= 4.5 ) {
				return $candidate;
			}
		}

		// Nothing in that hue works; fall back to ordinary body text.
		return $contrast;
	}

	/**
	 * Whichever of two colours is easier to read on a given fill.
	 *
	 * @param string $fill Background.
	 * @param string $one  First candidate.
	 * @param string $two  Second candidate.
	 * @return string
	 */
	private static function legible_on( string $fill, string $one, string $two ): string {
		return self::contrast( $one, $fill ) >= self::contrast( $two, $fill ) ? $one : $two;
	}

	/**
	 * Move one colour a fraction of the way towards another.
	 *
	 * @param string $from   Starting colour.
	 * @param string $toward Destination colour.
	 * @param float  $amount 0 to 1.
	 * @return string
	 */
	private static function shift( string $from, string $toward, float $amount ): string {
		$a = self::rgb( $from );
		$b = self::rgb( $toward );

		return sprintf(
			'#%02x%02x%02x',
			(int) round( $a[0] + ( $b[0] - $a[0] ) * $amount ),
			(int) round( $a[1] + ( $b[1] - $a[1] ) * $amount ),
			(int) round( $a[2] + ( $b[2] - $a[2] ) * $amount )
		);
	}

	/**
	 * Turn a colour around the wheel, keeping its weight.
	 *
	 * @param string $hex     Colour.
	 * @param int    $degrees Rotation.
	 * @return string
	 */
	private static function rotate( string $hex, int $degrees ): string {
		list( $r, $g, $b ) = array_map( static fn( int $c ): float => $c / 255, self::rgb( $hex ) );

		$max   = max( $r, $g, $b );
		$min   = min( $r, $g, $b );
		$delta = $max - $min;
		$light = ( $max + $min ) / 2;

		if ( 0.0 === $delta ) {
			return $hex;
		}

		$sat = $light > 0.5 ? $delta / ( 2 - $max - $min ) : $delta / ( $max + $min );

		if ( $max === $r ) {
			$hue = ( $g - $b ) / $delta + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$hue = ( $b - $r ) / $delta + 2;
		} else {
			$hue = ( $r - $g ) / $delta + 4;
		}

		$hue = fmod( ( $hue * 60 ) + $degrees + 360, 360 ) / 360;

		$q = $light < 0.5 ? $light * ( 1 + $sat ) : $light + $sat - $light * $sat;
		$p = 2 * $light - $q;

		return sprintf(
			'#%02x%02x%02x',
			(int) round( self::channel( $p, $q, $hue + 1 / 3 ) * 255 ),
			(int) round( self::channel( $p, $q, $hue ) * 255 ),
			(int) round( self::channel( $p, $q, $hue - 1 / 3 ) * 255 )
		);
	}

	/**
	 * One channel of an HSL to RGB conversion.
	 *
	 * @param float $p Helper.
	 * @param float $q Helper.
	 * @param float $t Hue offset.
	 * @return float
	 */
	private static function channel( float $p, float $q, float $t ): float {
		if ( $t < 0 ) {
			++$t;
		}

		if ( $t > 1 ) {
			--$t;
		}

		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t;
		}

		if ( $t < 1 / 2 ) {
			return $q;
		}

		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		}

		return $p;
	}

	/**
	 * A colour's channels.
	 *
	 * @param string $hex Colour.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return array( 0, 0, 0 );
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Relative luminance, as WCAG defines it.
	 *
	 * @param string $hex Colour.
	 * @return float
	 */
	public static function luminance( string $hex ): float {
		$channels = array();

		foreach ( self::rgb( $hex ) as $value ) {
			$value      = $value / 255;
			$channels[] = $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Contrast ratio between two colours.
	 *
	 * @param string $one Colour.
	 * @param string $two Colour.
	 * @return float
	 */
	public static function contrast( string $one, string $two ): float {
		$a = self::luminance( $one );
		$b = self::luminance( $two );

		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}
}
