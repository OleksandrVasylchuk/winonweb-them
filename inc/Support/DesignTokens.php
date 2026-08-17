<?php
/**
 * Reads a design's own colours and type, and makes the site wear them.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

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

		return array(
			'colors' => $colors,
			'fonts'  => self::fonts( $css, $vars ),
			'radius' => self::named_raw( $vars, array( 'radius', 'border-radius', 'rounded' ) ) ?? '',
			'source' => $vars,
		);
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
			 */
			$color = str_contains( strtolower( $value ), 'gradient' )
				? self::clean_gradient( $value, $vars )
				: self::to_hex( $value, $vars );

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
	private static function clean_gradient( string $value, array $vars ): ?string {
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
				'name'  => ucwords( str_replace( '-', ' ', $slug ) ),
				'color' => (string) ( $tokens['colors'][ $slug ] ?? '#000000' ),
			);
		}

		$data = self::user_data();

		$data['version']                               = 3;
		$data['isGlobalStylesUserThemeJSON']           = true;
		$data['settings']['color']['palette']['theme'] = $palette;

		$body    = (string) ( $tokens['fonts']['body'] ?? '' );
		$heading = (string) ( $tokens['fonts']['heading'] ?? '' );

		if ( '' !== $body ) {
			$data['styles']['typography']['fontFamily'] = $body;
		}

		if ( '' !== $heading ) {
			$data['styles']['elements']['heading']['typography']['fontFamily'] = $heading;
		}

		/*
		 * The theme's default is dark text on its own bright accent. A design
		 * whose accent is pale needs the opposite, so the button label is
		 * whichever of the two page colours can actually be read on the fill.
		 */
		$colors = $tokens['colors'];
		$label  = self::legible_on( $colors['accent'], $colors['base'], $colors['contrast'] );
		$slug   = $label === $colors['base'] ? 'base' : 'contrast';

		$data['styles']['elements']['button']['color']['text'] = 'var(--wp--preset--color--' . $slug . ')';

		self::save( $data );
	}

	/**
	 * Put the theme's own palette back.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$data = self::user_data();

		unset( $data['settings']['color']['palette'] );
		unset( $data['styles']['typography']['fontFamily'] );
		unset( $data['styles']['elements']['button']['color']['text'] );
		unset( $data['styles']['elements']['heading']['typography']['fontFamily'] );

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

		WP_Theme_JSON_Resolver::clean_cached_data();
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
	private static function custom_properties( string $css ): array {
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

				foreach ( $selectors as $selector ) {
					if ( ! isset( $fonts['heading'] ) && 1 === preg_match( '/(^|\s|,)h[1-3]\b/', $selector ) ) {
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
	private static function to_hex( string $value, array $vars ): ?string {
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
