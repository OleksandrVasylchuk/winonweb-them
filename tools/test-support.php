<?php
/**
 * Unit tests for the Support classes that hold real logic.
 *
 * These run without WordPress and without a database: BrandKit and Spend are
 * pure functions of their inputs, which is what makes them testable here and
 * why the logic worth testing was put in them rather than in a module.
 *
 * The BrandKit sweep is the important one. The claim it backs — that a client
 * cannot produce an inaccessible palette from the setup screen — is only worth
 * as much as the check behind it, so the check ships with the theme instead of
 * living in somebody's terminal history.
 *
 * Run: npm run test:unit
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// The Support classes guard on ABSPATH; they touch nothing else in WordPress.
define( 'ABSPATH', __DIR__ );

if ( ! function_exists( '__' ) ) {
	/**
	 * Stand-in for WordPress's translation function.
	 *
	 * @param string $text   Text to return.
	 * @param string $domain Unused.
	 * @return string
	 */
	function __( string $text, string $domain = '' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stand-in for WordPress's filter dispatcher.
	 *
	 * @param string $hook  Unused.
	 * @param mixed  $value Value to return unchanged.
	 * @return mixed
	 */
	function apply_filters( string $hook, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		unset( $hook );

		return $value;
	}
}

$wow_root = dirname( __DIR__ );

require $wow_root . '/inc/Support/DesignTokens.php';
require $wow_root . '/inc/Support/BrandKit.php';
require $wow_root . '/inc/Support/Spend.php';

use Wow\Signal\Support\BrandKit;
use Wow\Signal\Support\DesignTokens;
use Wow\Signal\Support\Spend;

$wow_failures = 0;
$wow_checks   = 0;

/**
 * Assert a condition, counting the result.
 *
 * @param bool   $passed Whether the assertion held.
 * @param string $label  What was being asserted.
 * @return void
 */
function wow_assert( bool $passed, string $label ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	++$GLOBALS['wow_checks'];

	if ( $passed ) {
		return;
	}

	++$GLOBALS['wow_failures'];
	echo '  FAIL  ' . $label . "\n";
}

/**
 * Announce a group of checks.
 *
 * @param string $title Group name.
 * @return void
 */
function wow_group( string $title ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	echo "\n=== " . $title . " ===\n";
}

/*
 * The colour contract, kept in the same shape as tools/contrast-audit.mjs so
 * the two cannot drift into disagreeing about what "accessible" means.
 */
$wow_contract = array(
	array( 'contrast', 'base', 4.5 ),
	array( 'contrast', 'surface', 4.5 ),
	array( 'contrast', 'surface-2', 4.5 ),
	array( 'muted', 'base', 4.5 ),
	array( 'muted', 'surface', 4.5 ),
	array( 'muted', 'surface-2', 4.5 ),
	array( 'accent-ink', 'base', 4.5 ),
	array( 'accent-ink', 'surface', 4.5 ),
	array( 'accent-ink', 'surface-2', 4.5 ),
	array( 'accent-2', 'base', 4.5 ),
	array( 'accent-2', 'surface', 4.5 ),
	array( 'accent-3', 'base', 4.5 ),
	array( 'accent-3', 'surface', 4.5 ),
	array( 'success', 'base', 4.5 ),
	array( 'success', 'surface', 4.5 ),
	array( 'warning', 'base', 4.5 ),
	array( 'warning', 'surface', 4.5 ),
	array( 'base', 'accent', 4.5 ),
	array( 'base', 'accent-2', 4.5 ),
	array( 'base', 'accent-3', 4.5 ),
	array( 'base', 'contrast', 4.5 ),
	array( 'border-strong', 'base', 3.0 ),
	array( 'border-strong', 'surface', 3.0 ),
	array( 'border-strong', 'surface-2', 3.0 ),
	array( 'accent', 'base', 3.0 ),
	array( 'accent', 'surface', 3.0 ),
	array( 'accent', 'surface-2', 3.0 ),
);

/**
 * Build a hex colour from hue, saturation and lightness.
 *
 * @param float $h Hue in degrees.
 * @param float $s Saturation, 0-1.
 * @param float $l Lightness, 0-1.
 * @return string
 */
function wow_hsl( float $h, float $s, float $l ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	$h = fmod( $h, 360.0 ) / 360.0;

	if ( $s < 0.000001 ) {
		$v = (int) round( $l * 255 );

		return sprintf( '#%02x%02x%02x', $v, $v, $v );
	}

	$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - ( $l * $s );
	$p = ( 2 * $l ) - $q;

	$channel = static function ( float $t ) use ( $p, $q ): float {
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
	};

	return sprintf(
		'#%02x%02x%02x',
		(int) round( $channel( $h + 1 / 3 ) * 255 ),
		(int) round( $channel( $h ) * 255 ),
		(int) round( $channel( $h - 1 / 3 ) * 255 )
	);
}

// ---------------------------------------------------------------- BrandKit

wow_group( 'BrandKit — every derived palette clears the contrast contract' );

$wow_brands = array();

// The hue wheel at several saturations and lightnesses.
foreach ( range( 0, 345, 15 ) as $wow_hue ) {
	foreach ( array( array( 0.9, 0.5 ), array( 0.4, 0.35 ), array( 0.15, 0.7 ), array( 1.0, 0.85 ), array( 0.05, 0.2 ) ) as $wow_sl ) {
		$wow_brands[] = wow_hsl( (float) $wow_hue, $wow_sl[0], $wow_sl[1] );
	}
}

// Plus the awkward ones. Pure black and white are greyscale, which is where an
// HSL round-trip divides by zero if the early return is ever broken again.
$wow_brands = array_merge( $wow_brands, array( '#000000', '#ffffff', '#808080', '#123456', '#ffff00', '#00ff00' ) );

$wow_pairs   = 0;
$wow_tightest = array( 'slack' => INF );

foreach ( array( 'dark', 'light' ) as $wow_mode ) {
	foreach ( $wow_brands as $wow_accent ) {
		$wow_tokens  = BrandKit::derive( array( 'mode' => $wow_mode, 'accent' => $wow_accent ) );
		$wow_palette = $wow_tokens['colors'];

		foreach ( $wow_contract as $wow_pair ) {
			list( $wow_fg, $wow_bg, $wow_min ) = $wow_pair;

			$wow_ratio = DesignTokens::contrast( $wow_palette[ $wow_fg ], $wow_palette[ $wow_bg ] );
			++$wow_pairs;

			if ( $wow_ratio - $wow_min < $wow_tightest['slack'] ) {
				$wow_tightest = array(
					'slack'  => $wow_ratio - $wow_min,
					'mode'   => $wow_mode,
					'accent' => $wow_accent,
					'fg'     => $wow_fg,
					'bg'     => $wow_bg,
					'ratio'  => $wow_ratio,
					'min'    => $wow_min,
				);
			}

			wow_assert(
				$wow_ratio >= $wow_min,
				sprintf( '%s %s: %s on %s = %.2f, needs %.1f', $wow_mode, $wow_accent, $wow_fg, $wow_bg, $wow_ratio, $wow_min )
			);
		}
	}
}

printf(
	"  %d pairs over %d brand colours x 2 modes\n  tightest: %s %s  %s on %s = %.2f (min %.1f)\n",
	$wow_pairs,
	count( $wow_brands ),
	$wow_tightest['mode'],
	$wow_tightest['accent'],
	$wow_tightest['fg'],
	$wow_tightest['bg'],
	$wow_tightest['ratio'],
	$wow_tightest['min']
);

wow_group( 'BrandKit — companion accents stay in one family' );

/*
 * A fixed rotation direction sends amber to yellow-green and then green, which
 * reads as a traffic light rather than as one brand. Both directions have to
 * travel toward the blue-violet arc instead.
 */
foreach ( array( '#d32f2f' => 'red', '#f5a524' => 'amber', '#84cc16' => 'lime', '#0f766e' => 'teal', '#2563eb' => 'blue', '#c2185b' => 'magenta' ) as $wow_hex => $wow_name ) {
	$wow_colors = BrandKit::derive( array( 'mode' => 'dark', 'accent' => $wow_hex ) )['colors'];

	// Nothing may land in the 60-140 degree band, which is where the
	// yellow-green companions that made this look wrong used to appear.
	foreach ( array( 'accent-2', 'accent-3' ) as $wow_slot ) {
		$wow_h = wow_hue_of( $wow_colors[ $wow_slot ] );

		wow_assert(
			$wow_h < 55.0 || $wow_h > 145.0,
			sprintf( '%s: %s landed at %d degrees, inside the yellow-green band', $wow_name, $wow_slot, (int) $wow_h )
		);
	}
}

wow_group( 'BrandKit — gradients and shadows are well formed' );

foreach ( array( 'dark', 'light' ) as $wow_mode ) {
	foreach ( array( '#c2185b', '#0f766e', '#000000', '#ffffff' ) as $wow_accent ) {
		$wow_colors    = BrandKit::derive( array( 'mode' => $wow_mode, 'accent' => $wow_accent ) )['colors'];
		$wow_gradients = BrandKit::gradients( $wow_colors, 'dark' === $wow_mode );
		$wow_shadows   = BrandKit::shadows( $wow_colors, 'dark' === $wow_mode );

		wow_assert( array( 'aurora', 'aurora-soft', 'signal-fade' ) === array_column( $wow_gradients, 'slug' ), 'gradient slugs match the theme' );
		wow_assert( array( 'soft', 'card', 'glow' ) === array_column( $wow_shadows, 'slug' ), 'shadow slugs match the theme' );

		foreach ( $wow_gradients as $wow_preset ) {
			wow_assert( 1 === preg_match( '/^linear-gradient\(/', $wow_preset['gradient'] ), 'gradient is a linear-gradient: ' . $wow_preset['slug'] );
			wow_assert( 0 === preg_match( '/rgba\([^)]*,\s*\)/', $wow_preset['gradient'] ), 'gradient has no empty alpha: ' . $wow_preset['slug'] );
		}

		foreach ( $wow_shadows as $wow_preset ) {
			wow_assert( '' !== trim( $wow_preset['shadow'] ), 'shadow is not empty: ' . $wow_preset['slug'] );
			wow_assert( 0 === preg_match( '/rgba\([^)]*,\s*\)/', $wow_preset['shadow'] ), 'shadow has no empty alpha: ' . $wow_preset['slug'] );
		}
	}
}

// -------------------------------------------------------------------- Spend

wow_group( 'Spend — cost arithmetic' );

// One million input tokens on Opus 5 is its list input price, by definition.
wow_assert(
	abs( Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-opus-5' ) - 5.00 ) < 0.0001,
	'1M input tokens on Opus 5 costs its list input rate'
);

wow_assert(
	abs( Spend::cost( array( 'output_tokens' => 1000000 ), 'claude-opus-5' ) - 25.00 ) < 0.0001,
	'1M output tokens on Opus 5 costs its list output rate'
);

wow_assert(
	Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-haiku-4-5' ) < Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-opus-5' ),
	'Haiku costs less than Opus for the same tokens'
);

// Cached reads are a tenth of fresh input, so they must not be counted as full.
wow_assert(
	Spend::cost( array( 'cache_read_input_tokens' => 1000000 ), 'claude-opus-5' )
		< Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-opus-5' ),
	'cache reads are cheaper than fresh input'
);

wow_assert( 0.0 === Spend::cost( array(), 'claude-opus-5' ), 'an empty usage block costs nothing' );

// An unknown model must not be priced as if it were Opus: the rate is unknown.
wow_assert( null === Spend::price( 'not-a-real-model' ), 'an unknown model has no price' );
wow_assert( ! Spend::knows( 'not-a-real-model' ), 'an unknown model is reported as unknown' );
wow_assert( Spend::knows( 'claude-haiku-4-5' ), 'a listed model is reported as known' );

wow_assert(
	0.0 === Spend::cost( array( 'input_tokens' => 1000 ), 'not-a-real-model' ),
	'an unknown model costs nothing rather than being priced as Opus'
);

wow_group( 'Spend — estimates' );

$wow_small = Spend::estimate( 2000, 'claude-opus-5' );
$wow_large = Spend::estimate( 200000, 'claude-opus-5' );

wow_assert( $wow_small > 0.0, 'a small section has a non-zero estimate' );
wow_assert( $wow_large > $wow_small, 'a larger section estimates higher' );
wow_assert( Spend::estimate( 0, 'claude-opus-5' ) > 0.0, 'an empty section still costs its output' );
wow_assert( Spend::estimate( -5, 'claude-opus-5' ) > 0.0, 'a negative size does not produce a negative price' );

// ------------------------------------------------------------------ report

printf( "\n%d checks, %d failure(s).\n", $wow_checks, $wow_failures );

exit( $wow_failures > 0 ? 1 : 0 );

/**
 * Hue of a hex colour, in degrees.
 *
 * @param string $hex Colour.
 * @return float
 */
function wow_hue_of( string $hex ): float { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	$r = (float) hexdec( substr( $hex, 1, 2 ) ) / 255.0;
	$g = (float) hexdec( substr( $hex, 3, 2 ) ) / 255.0;
	$b = (float) hexdec( substr( $hex, 5, 2 ) ) / 255.0;

	$high  = max( $r, $g, $b );
	$low   = min( $r, $g, $b );
	$range = $high - $low;

	if ( $range < 0.000001 ) {
		return 0.0;
	}

	if ( $high === $r ) {
		$hue = ( $g - $b ) / $range + ( $g < $b ? 6.0 : 0.0 );
	} elseif ( $high === $g ) {
		$hue = ( $b - $r ) / $range + 2.0;
	} else {
		$hue = ( $r - $g ) / $range + 4.0;
	}

	return $hue * 60.0;
}
