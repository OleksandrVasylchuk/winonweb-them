<?php
/**
 * Brand colours in, a complete accessible palette out.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the theme's thirteen colour tokens from one to three brand colours.
 *
 * A client knows two things about their brand: it is dark or light, and it is
 * this colour. They do not know what `surface-2` is for, and asking them is how
 * a palette ends up with 2:1 body text. So the screen collects the two things
 * they know and this class derives the rest, then walks every derived colour
 * away from its background until it clears the same contract
 * tools/contrast-audit.mjs enforces on the shipped palettes:
 *
 *   - text and accent fills clear 4.5:1 against every page and card colour;
 *   - control borders clear 3:1;
 *   - a label on an accent fill is `base`, so `base` vs `accent` is the same
 *     pair as `accent` vs `base` and one adjustment satisfies both.
 *
 * The result goes to DesignTokens::apply(), which writes it into the site's
 * user global styles — the same record the Site Editor writes. Nothing here
 * touches theme.json, so a theme update cannot overwrite a client's brand and
 * a read-only theme directory cannot break the screen.
 */
final class BrandKit {

	/**
	 * Largest lightness step the walk will take, as a fraction.
	 */
	private const STEP = 0.01;

	/**
	 * Give up after this many steps rather than loop forever.
	 *
	 * A hundred steps of 0.01 covers the whole lightness axis, so reaching the
	 * limit means the target is unreachable, not that the walk was too short.
	 */
	private const MAX_STEPS = 100;

	/**
	 * Overshoot the required ratio by this much.
	 *
	 * The walk stops the moment it clears the threshold, which puts a derived
	 * colour on exactly 4.50:1 often enough to matter. A pair that sits on the
	 * line passes the audit today and fails it the moment anyone nudges a
	 * neutral, so the walk takes one more step than it strictly needs.
	 */
	private const MARGIN = 0.06;

	/**
	 * Build a full token set from what the setup screen collected.
	 *
	 * @param array{mode?:string,accent?:string,accent-2?:string,accent-3?:string,heading?:string} $brand Brand input.
	 * @return array{colors:array<string,string>,fonts:array<string,string>} Tokens for DesignTokens::apply().
	 */
	public static function derive( array $brand ): array {
		$dark   = 'light' !== ( $brand['mode'] ?? 'dark' );
		$accent = self::hex( $brand['accent'] ?? '' ) ?? ( $dark ? '#22d3ee' : '#0e6f85' );

		list( $hue, $saturation ) = self::hsl( $accent );

		$neutrals = self::neutrals( $hue, $saturation, $dark );
		$grounds  = array( $neutrals['base'], $neutrals['surface'], $neutrals['surface-2'] );

		/*
		 * Secondary and tertiary accents are the client's if they gave them and
		 * a hue rotation of the primary if they did not. Rotating rather than
		 * picking keeps a one-colour brand looking deliberate instead of
		 * looking like the theme's colours leaked through.
		 */
		$step   = self::rotation( $hue );
		$second = self::hex( $brand['accent-2'] ?? '' ) ?? self::rotate( $accent, $step );
		$third  = self::hex( $brand['accent-3'] ?? '' ) ?? self::rotate( $accent, $step * 2 );

		$colors = array_merge(
			$neutrals,
			array(
				'accent'   => self::legible( $accent, $grounds, 4.5, $dark ),
				'accent-2' => self::legible( $second, $grounds, 4.5, $dark ),
				'accent-3' => self::legible( $third, $grounds, 4.5, $dark ),
				'success'  => self::legible( self::from_hsl( 152.0, 0.62, $dark ? 0.62 : 0.28 ), $grounds, 4.5, $dark ),
				'warning'  => self::legible( self::from_hsl( 42.0, 0.92, $dark ? 0.62 : 0.30 ), $grounds, 4.5, $dark ),
			)
		);

		// accent-ink is the same colour: one walk already cleared it as text.
		$colors['accent-ink'] = $colors['accent'];

		$fonts = array();

		if ( in_array( $brand['heading'] ?? '', array( 'sans', 'mono' ), true ) ) {
			$fonts['heading'] = 'var(--wp--preset--font-family--' . $brand['heading'] . ')';
		}

		return array(
			'colors' => $colors,
			'fonts'  => $fonts,
		);
	}

	/**
	 * Gradient presets built from a derived palette.
	 *
	 * The theme defines three gradients and a style variation replaces all
	 * three, because WordPress swaps the set wholesale rather than merging it.
	 * A brand palette has to do the same or it inherits the last variation's
	 * gradients — the failure mode being an amber gradient over a pink site.
	 *
	 * @param array<string, string> $colors Derived palette.
	 * @param bool                  $dark   Whether the palette is dark.
	 * @return array<int, array<string, string>> Presets in theme.json shape.
	 */
	public static function gradients( array $colors, bool $dark ): array {
		return array(
			array(
				'slug'     => 'aurora',
				'name'     => __( 'Aurora', 'qwerty-soft-signal' ),
				'gradient' => sprintf(
					'linear-gradient(120deg, %1$s 0%%, %2$s 52%%, %3$s 100%%)',
					$colors['accent'],
					$colors['accent-2'],
					$colors['accent-3']
				),
			),
			array(
				'slug'     => 'aurora-soft',
				'name'     => $dark ? __( 'Aurora soft — on dark', 'qwerty-soft-signal' ) : __( 'Aurora soft — on light', 'qwerty-soft-signal' ),
				'gradient' => sprintf(
					'linear-gradient(160deg, %1$s 0%%, %2$s 60%%, %3$s 100%%)',
					$colors['surface'],
					$colors['surface-2'],
					self::mix( $colors['surface-2'], $colors['accent-2'], $dark ? 0.14 : 0.10 )
				),
			),
			array(
				'slug'     => 'signal-fade',
				'name'     => __( 'Signal fade — accent to transparent', 'qwerty-soft-signal' ),
				'gradient' => sprintf(
					'linear-gradient(180deg, %1$s 0%%, %2$s 100%%)',
					self::rgba( $colors['accent'], $dark ? 0.16 : 0.12 ),
					self::rgba( $colors['base'], 0.0 )
				),
			),
		);
	}

	/**
	 * Shadow presets built from a derived palette.
	 *
	 * Shadows are cast in the page's own darkest colour rather than in black,
	 * so a warm palette gets a warm shadow instead of a grey smudge.
	 *
	 * @param array<string, string> $colors Derived palette.
	 * @param bool                  $dark   Whether the palette is dark.
	 * @return array<int, array<string, string>> Presets in theme.json shape.
	 */
	public static function shadows( array $colors, bool $dark ): array {
		$ground = $dark ? $colors['base'] : $colors['contrast'];

		return array(
			array(
				'slug'   => 'soft',
				'name'   => __( 'Soft', 'qwerty-soft-signal' ),
				'shadow' => '0 1px 2px ' . self::rgba( $ground, $dark ? 0.45 : 0.08 ),
			),
			array(
				'slug'   => 'card',
				'name'   => __( 'Card', 'qwerty-soft-signal' ),
				'shadow' => $dark
					? '0 12px 32px -12px ' . self::rgba( $ground, 0.7 )
					: '0 12px 32px -14px ' . self::rgba( $ground, 0.22 ),
			),
			array(
				'slug'   => 'glow',
				'name'   => __( 'Signal glow', 'qwerty-soft-signal' ),
				'shadow' => sprintf(
					'0 0 0 1px %1$s, 0 18px 48px -%2$dpx %3$s',
					self::rgba( $colors['accent'], $dark ? 0.35 : 0.28 ),
					$dark ? 18 : 20,
					self::rgba( $colors['accent'], $dark ? 0.45 : 0.35 )
				),
			),
		);
	}

	/**
	 * A colour as an rgba() string.
	 *
	 * @param string $hex   Colour.
	 * @param float  $alpha Opacity, 0-1.
	 * @return string
	 */
	private static function rgba( string $hex, float $alpha ): string {
		return sprintf(
			'rgba(%d, %d, %d, %s)',
			(int) hexdec( substr( $hex, 1, 2 ) ),
			(int) hexdec( substr( $hex, 3, 2 ) ),
			(int) hexdec( substr( $hex, 5, 2 ) ),
			rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' )
		);
	}

	/**
	 * Blend two colours.
	 *
	 * @param string $from   Base colour.
	 * @param string $toward Colour to move toward.
	 * @param float  $amount How far, 0-1.
	 * @return string #rrggbb.
	 */
	private static function mix( string $from, string $toward, float $amount ): string {
		$channel = static function ( int $offset ) use ( $from, $toward, $amount ): int {
			$a = (int) hexdec( substr( $from, $offset, 2 ) );
			$b = (int) hexdec( substr( $toward, $offset, 2 ) );

			return (int) round( $a + ( ( $b - $a ) * $amount ) );
		};

		return sprintf( '#%02x%02x%02x', $channel( 1 ), $channel( 3 ), $channel( 5 ) );
	}

	/**
	 * The eight colours that are not accents.
	 *
	 * Page, card and raised-card grounds carry a trace of the brand hue so the
	 * palette reads as one family; the saturation is capped low enough that
	 * they stay neutral to the eye. Text and border colours are then walked
	 * away from those grounds until they clear the contract.
	 *
	 * @param float $hue        Brand hue, degrees.
	 * @param float $saturation Brand saturation, 0-1.
	 * @param bool  $dark       Whether the palette is dark.
	 * @return array<string, string> Neutral tokens.
	 */
	private static function neutrals( float $hue, float $saturation, bool $dark ): array {
		$tint = min( $saturation, 0.4 );

		if ( $dark ) {
			$grounds = array(
				'base'      => self::from_hsl( $hue, $tint * 0.45, 0.055 ),
				'surface'   => self::from_hsl( $hue, $tint * 0.42, 0.098 ),
				'surface-2' => self::from_hsl( $hue, $tint * 0.40, 0.145 ),
				'border'    => self::from_hsl( $hue, $tint * 0.38, 0.24 ),
			);
		} else {
			$grounds = array(
				'base'      => self::from_hsl( $hue, $tint * 0.20, 0.995 ),
				'surface'   => self::from_hsl( $hue, $tint * 0.30, 0.965 ),
				'surface-2' => self::from_hsl( $hue, $tint * 0.34, 0.925 ),
				'border'    => self::from_hsl( $hue, $tint * 0.34, 0.875 ),
			);
		}

		$against = array( $grounds['base'], $grounds['surface'], $grounds['surface-2'] );

		$grounds['contrast']      = self::legible( self::from_hsl( $hue, $tint * 0.22, $dark ? 0.96 : 0.06 ), $against, 4.5, $dark );
		$grounds['muted']         = self::legible( self::from_hsl( $hue, $tint * 0.30, $dark ? 0.78 : 0.34 ), $against, 4.5, $dark );
		$grounds['border-strong'] = self::legible( self::from_hsl( $hue, $tint * 0.30, $dark ? 0.62 : 0.46 ), $against, 3.0, $dark );

		return $grounds;
	}

	/**
	 * Walk a colour away from its backgrounds until it clears a ratio.
	 *
	 * Direction is fixed by the palette: on a dark palette every foreground
	 * gets lighter, on a light one every foreground gets darker. Moving toward
	 * the backgrounds instead would satisfy the worst pair by ruining the best.
	 *
	 * @param string             $color       Starting colour.
	 * @param array<int, string> $backgrounds Colours it has to be read on.
	 * @param float              $min         Required ratio.
	 * @param bool               $lighten     Walk toward white rather than black.
	 * @return string Adjusted colour.
	 */
	private static function legible( string $color, array $backgrounds, float $min, bool $lighten ): string {
		list( $hue, $saturation, $lightness ) = self::hsl( $color );

		for ( $step = 0; $step < self::MAX_STEPS; $step++ ) {
			$current = self::from_hsl( $hue, $saturation, $lightness );
			$worst   = INF;

			foreach ( $backgrounds as $background ) {
				$worst = min( $worst, DesignTokens::contrast( $current, $background ) );
			}

			if ( $worst >= $min + self::MARGIN ) {
				return $current;
			}

			$lightness = $lighten ? $lightness + self::STEP : $lightness - self::STEP;

			/*
			 * Pure white or pure black always clears a dark or light ground, so
			 * the walk cannot run off the end without having already returned —
			 * except when saturation is holding it back, which the clamp fixes.
			 */
			if ( $lightness >= 1.0 || $lightness <= 0.0 ) {
				$lightness  = $lighten ? 1.0 : 0.0;
				$saturation = 0.0;
			}
		}

		return self::from_hsl( $hue, 0.0, $lighten ? 1.0 : 0.0 );
	}

	/**
	 * Which way round the wheel the companion accents should go.
	 *
	 * Rotating a fixed direction is what most generators do, and it is why
	 * their warm palettes look wrong: amber rotated forwards lands on
	 * yellow-green and then green, which reads as a traffic light rather than
	 * as one brand. Both directions are harmonious if they travel *toward* the
	 * blue-violet-magenta arc, which is where the theme's own default sits
	 * (cyan to violet to fuchsia).
	 *
	 * So greens and cyans rotate forwards; reds, ambers and magentas rotate
	 * back. Either way the family ends up in the same part of the wheel.
	 *
	 * @param float $hue Primary hue, degrees.
	 * @return int Degrees to rotate for the first companion.
	 */
	private static function rotation( float $hue ): int {
		return ( $hue >= 90.0 && $hue < 270.0 ) ? 42 : -42;
	}

	/**
	 * Turn a colour of any hue into the same colour rotated round the wheel.
	 *
	 * @param string $hex     Source colour.
	 * @param int    $degrees Rotation.
	 * @return string Rotated colour.
	 */
	private static function rotate( string $hex, int $degrees ): string {
		list( $hue, $saturation, $lightness ) = self::hsl( $hex );

		return self::from_hsl( fmod( $hue + (float) $degrees, 360.0 ), $saturation, $lightness );
	}

	/**
	 * Validate a user-supplied colour.
	 *
	 * @param string $value Candidate.
	 * @return string|null Normalised #rrggbb, or null if it is not a colour.
	 */
	public static function hex( string $value ): ?string {
		$value = strtolower( trim( $value ) );

		if ( 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
			return $value;
		}

		if ( 1 === preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $parts ) ) {
			return '#' . $parts[1] . $parts[1] . $parts[2] . $parts[2] . $parts[3] . $parts[3];
		}

		return null;
	}

	/**
	 * Decompose a colour into hue, saturation and lightness.
	 *
	 * @param string $hex Colour.
	 * @return array{0:float,1:float,2:float} Hue in degrees, saturation and lightness 0-1.
	 */
	private static function hsl( string $hex ): array {
		/*
		 * Cast every channel to float. PHP's `/` returns an integer when the
		 * division is exact, so a pure black or pure white channel comes back
		 * as int 0 or int 1 — and `0.0 === $range` is then false against int 0,
		 * which sends a greyscale colour into the divide below instead of the
		 * early return.
		 */
		$red   = (float) hexdec( substr( $hex, 1, 2 ) ) / 255.0;
		$green = (float) hexdec( substr( $hex, 3, 2 ) ) / 255.0;
		$blue  = (float) hexdec( substr( $hex, 5, 2 ) ) / 255.0;

		$high  = max( $red, $green, $blue );
		$low   = min( $red, $green, $blue );
		$range = $high - $low;

		$lightness = ( $high + $low ) / 2.0;

		if ( $range < 0.000001 ) {
			return array( 0.0, 0.0, $lightness );
		}

		$saturation = $lightness > 0.5
			? $range / ( 2.0 - $high - $low )
			: $range / ( $high + $low );

		if ( $high === $red ) {
			$hue = ( $green - $blue ) / $range + ( $green < $blue ? 6.0 : 0.0 );
		} elseif ( $high === $green ) {
			$hue = ( $blue - $red ) / $range + 2.0;
		} else {
			$hue = ( $red - $green ) / $range + 4.0;
		}

		return array( $hue * 60.0, $saturation, $lightness );
	}

	/**
	 * Compose a colour from hue, saturation and lightness.
	 *
	 * @param float $hue        Degrees.
	 * @param float $saturation 0-1.
	 * @param float $lightness  0-1.
	 * @return string #rrggbb.
	 */
	private static function from_hsl( float $hue, float $saturation, float $lightness ): string {
		$saturation = max( 0.0, min( 1.0, $saturation ) );
		$lightness  = max( 0.0, min( 1.0, $lightness ) );
		$hue        = fmod( fmod( $hue, 360.0 ) + 360.0, 360.0 ) / 360.0;

		if ( $saturation < 0.000001 ) {
			$value = (int) round( $lightness * 255 );

			return sprintf( '#%02x%02x%02x', $value, $value, $value );
		}

		$q = $lightness < 0.5
			? $lightness * ( 1.0 + $saturation )
			: $lightness + $saturation - ( $lightness * $saturation );
		$p = ( 2.0 * $lightness ) - $q;

		return sprintf(
			'#%02x%02x%02x',
			(int) round( self::channel( $p, $q, $hue + 1 / 3 ) * 255 ),
			(int) round( self::channel( $p, $q, $hue ) * 255 ),
			(int) round( self::channel( $p, $q, $hue - 1 / 3 ) * 255 )
		);
	}

	/**
	 * One RGB channel of an HSL colour.
	 *
	 * @param float $p Lower bound.
	 * @param float $q Upper bound.
	 * @param float $t Channel position, 0-1 after wrapping.
	 * @return float Channel value, 0-1.
	 */
	private static function channel( float $p, float $q, float $t ): float {
		if ( $t < 0 ) {
			++$t;
		}

		if ( $t > 1 ) {
			--$t;
		}

		if ( $t < 1 / 6 ) {
			return $p + ( ( $q - $p ) * 6 * $t );
		}

		if ( $t < 1 / 2 ) {
			return $q;
		}

		if ( $t < 2 / 3 ) {
			return $p + ( ( $q - $p ) * ( 2 / 3 - $t ) * 6 );
		}

		return $p;
	}
}
