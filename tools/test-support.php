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
 * @package Qwerty\Soft
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

if ( ! function_exists( '_n' ) ) {
	/**
	 * Stand-in for WordPress's plural translation function.
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number How many.
	 * @param string $domain Unused.
	 * @return string
	 */
	function _n( string $single, string $plural, int $number, string $domain = '' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		unset( $domain );

		return 1 === $number ? $single : $plural;
	}

	/**
	 * Encode as JSON, the way WordPress would.
	 *
	 * @param mixed $data  What to encode.
	 * @param int   $flags Encoding flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the stand-in for that alternative.
	}

	/**
	 * Put one slash on the end and no more.
	 *
	 * @param string $path A path.
	 * @return string
	 */
	function trailingslashit( string $path ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		return rtrim( $path, "/\\" ) . '/';
	}

	/**
	 * The words without the tags around them.
	 *
	 * @param string $text Markup.
	 * @return string
	 */
	function wp_strip_all_tags( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $text );

		return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This is the stand-in for that alternative.
	}
}

$qsoft_root = dirname( __DIR__ );

// Where the theme is, for the classes that read their own directory.
define( 'WP_PLUGIN_DIR', $qsoft_root . '/tests/fixtures/no-plugins-here' );

if ( ! function_exists( 'get_template_directory' ) ) {
	/**
	 * Stand-in: the theme under test is this checkout.
	 *
	 * @return string
	 */
	function get_template_directory(): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		return dirname( __DIR__ );
	}

	/**
	 * Stand-in for WordPress's path normaliser.
	 *
	 * @param string $path A path.
	 * @return string
	 */
	function wp_normalize_path( string $path ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		return str_replace( '\\', '/', $path );
	}

	/**
	 * Stand-in: no plugins are active in a test run.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	function is_plugin_active( string $plugin ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness stand-in for the real function.
		unset( $plugin );

		return false;
	}
}

require $qsoft_root . '/inc/Support/DesignTokens.php';
require $qsoft_root . '/inc/Support/BrandKit.php';
require $qsoft_root . '/inc/Support/Spend.php';
require $qsoft_root . '/inc/Support/SourceProject.php';
require $qsoft_root . '/inc/Support/DesignDocs.php';
require $qsoft_root . '/inc/Support/SectionPlan.php';
require $qsoft_root . '/inc/Support/BlockWriter.php';
require $qsoft_root . '/inc/Support/DesignField.php';
require $qsoft_root . '/inc/Support/DesignType.php';
require $qsoft_root . '/inc/Support/ShopKit.php';
require $qsoft_root . '/inc/Support/DesignNeeds.php';
require $qsoft_root . '/inc/Support/ImportLog.php';
require $qsoft_root . '/inc/Support/DesignArchive.php';

// Time constants WordPress defines; ClaudeCli names one in a class constant.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

require $qsoft_root . '/inc/Support/AnthropicClient.php';
require $qsoft_root . '/inc/Support/ClaudeCli.php';
require $qsoft_root . '/inc/Support/Lessons.php';
require $qsoft_root . '/inc/Support/PlanReview.php';

use Qwerty\Soft\Support\AnthropicClient;
use Qwerty\Soft\Support\BrandKit;
use Qwerty\Soft\Support\ClaudeCli;
use Qwerty\Soft\Support\DesignTokens;
use Qwerty\Soft\Support\Lessons;
use Qwerty\Soft\Support\PlanReview;
use Qwerty\Soft\Support\SourceProject;
use Qwerty\Soft\Support\Spend;

$qsoft_failures = 0;
$qsoft_checks   = 0;

/**
 * Assert a condition, counting the result.
 *
 * @param bool   $passed Whether the assertion held.
 * @param string $label  What was being asserted.
 * @return void
 */
function qsoft_assert( bool $passed, string $label ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	++$GLOBALS['qsoft_checks'];

	if ( $passed ) {
		return;
	}

	++$GLOBALS['qsoft_failures'];
	echo '  FAIL  ' . $label . "\n";
}

/**
 * Announce a group of checks.
 *
 * @param string $title Group name.
 * @return void
 */
function qsoft_group( string $title ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	echo "\n=== " . $title . " ===\n";
}

/*
 * The colour contract, kept in the same shape as tools/contrast-audit.mjs so
 * the two cannot drift into disagreeing about what "accessible" means.
 */
$qsoft_contract = array(
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
function qsoft_hsl( float $h, float $s, float $l ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
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

qsoft_group( 'BrandKit — every derived palette clears the contrast contract' );

$qsoft_brands = array();

// The hue wheel at several saturations and lightnesses.
foreach ( range( 0, 345, 15 ) as $qsoft_hue ) {
	foreach ( array( array( 0.9, 0.5 ), array( 0.4, 0.35 ), array( 0.15, 0.7 ), array( 1.0, 0.85 ), array( 0.05, 0.2 ) ) as $qsoft_sl ) {
		$qsoft_brands[] = qsoft_hsl( (float) $qsoft_hue, $qsoft_sl[0], $qsoft_sl[1] );
	}
}

// Plus the awkward ones. Pure black and white are greyscale, which is where an
// HSL round-trip divides by zero if the early return is ever broken again.
$qsoft_brands = array_merge( $qsoft_brands, array( '#000000', '#ffffff', '#808080', '#123456', '#ffff00', '#00ff00' ) );

$qsoft_pairs   = 0;
$qsoft_tightest = array( 'slack' => INF );

foreach ( array( 'dark', 'light' ) as $qsoft_mode ) {
	foreach ( $qsoft_brands as $qsoft_accent ) {
		$qsoft_tokens  = BrandKit::derive( array( 'mode' => $qsoft_mode, 'accent' => $qsoft_accent ) );
		$qsoft_palette = $qsoft_tokens['colors'];

		foreach ( $qsoft_contract as $qsoft_pair ) {
			list( $qsoft_fg, $qsoft_bg, $qsoft_min ) = $qsoft_pair;

			$qsoft_ratio = DesignTokens::contrast( $qsoft_palette[ $qsoft_fg ], $qsoft_palette[ $qsoft_bg ] );
			++$qsoft_pairs;

			if ( $qsoft_ratio - $qsoft_min < $qsoft_tightest['slack'] ) {
				$qsoft_tightest = array(
					'slack'  => $qsoft_ratio - $qsoft_min,
					'mode'   => $qsoft_mode,
					'accent' => $qsoft_accent,
					'fg'     => $qsoft_fg,
					'bg'     => $qsoft_bg,
					'ratio'  => $qsoft_ratio,
					'min'    => $qsoft_min,
				);
			}

			qsoft_assert(
				$qsoft_ratio >= $qsoft_min,
				sprintf( '%s %s: %s on %s = %.2f, needs %.1f', $qsoft_mode, $qsoft_accent, $qsoft_fg, $qsoft_bg, $qsoft_ratio, $qsoft_min )
			);
		}
	}
}

printf(
	"  %d pairs over %d brand colours x 2 modes\n  tightest: %s %s  %s on %s = %.2f (min %.1f)\n",
	$qsoft_pairs,
	count( $qsoft_brands ),
	$qsoft_tightest['mode'],
	$qsoft_tightest['accent'],
	$qsoft_tightest['fg'],
	$qsoft_tightest['bg'],
	$qsoft_tightest['ratio'],
	$qsoft_tightest['min']
);

qsoft_group( 'BrandKit — companion accents stay in one family' );

/*
 * A fixed rotation direction sends amber to yellow-green and then green, which
 * reads as a traffic light rather than as one brand. Both directions have to
 * travel toward the blue-violet arc instead.
 */
foreach ( array( '#d32f2f' => 'red', '#f5a524' => 'amber', '#84cc16' => 'lime', '#0f766e' => 'teal', '#2563eb' => 'blue', '#c2185b' => 'magenta' ) as $qsoft_hex => $qsoft_name ) {
	$qsoft_colors = BrandKit::derive( array( 'mode' => 'dark', 'accent' => $qsoft_hex ) )['colors'];

	// Nothing may land in the 60-140 degree band, which is where the
	// yellow-green companions that made this look wrong used to appear.
	foreach ( array( 'accent-2', 'accent-3' ) as $qsoft_slot ) {
		$qsoft_h = qsoft_hue_of( $qsoft_colors[ $qsoft_slot ] );

		qsoft_assert(
			$qsoft_h < 55.0 || $qsoft_h > 145.0,
			sprintf( '%s: %s landed at %d degrees, inside the yellow-green band', $qsoft_name, $qsoft_slot, (int) $qsoft_h )
		);
	}
}

qsoft_group( 'BrandKit — gradients and shadows are well formed' );

foreach ( array( 'dark', 'light' ) as $qsoft_mode ) {
	foreach ( array( '#c2185b', '#0f766e', '#000000', '#ffffff' ) as $qsoft_accent ) {
		$qsoft_colors    = BrandKit::derive( array( 'mode' => $qsoft_mode, 'accent' => $qsoft_accent ) )['colors'];
		$qsoft_gradients = BrandKit::gradients( $qsoft_colors, 'dark' === $qsoft_mode );
		$qsoft_shadows   = BrandKit::shadows( $qsoft_colors, 'dark' === $qsoft_mode );

		qsoft_assert( array( 'aurora', 'aurora-soft', 'signal-fade' ) === array_column( $qsoft_gradients, 'slug' ), 'gradient slugs match the theme' );
		qsoft_assert( array( 'soft', 'card', 'glow' ) === array_column( $qsoft_shadows, 'slug' ), 'shadow slugs match the theme' );

		foreach ( $qsoft_gradients as $qsoft_preset ) {
			qsoft_assert( 1 === preg_match( '/^linear-gradient\(/', $qsoft_preset['gradient'] ), 'gradient is a linear-gradient: ' . $qsoft_preset['slug'] );
			qsoft_assert( 0 === preg_match( '/rgba\([^)]*,\s*\)/', $qsoft_preset['gradient'] ), 'gradient has no empty alpha: ' . $qsoft_preset['slug'] );
		}

		foreach ( $qsoft_shadows as $qsoft_preset ) {
			qsoft_assert( '' !== trim( $qsoft_preset['shadow'] ), 'shadow is not empty: ' . $qsoft_preset['slug'] );
			qsoft_assert( 0 === preg_match( '/rgba\([^)]*,\s*\)/', $qsoft_preset['shadow'] ), 'shadow has no empty alpha: ' . $qsoft_preset['slug'] );
		}
	}
}

// -------------------------------------------------------------------- Spend

qsoft_group( 'Spend — cost arithmetic' );

// One million input tokens on Opus 5 is its list input price, by definition.
qsoft_assert(
	abs( Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-opus-5' ) - 5.00 ) < 0.0001,
	'1M input tokens on Opus 5 costs its list input rate'
);

qsoft_assert(
	abs( Spend::cost( array( 'output_tokens' => 1000000 ), 'claude-opus-5' ) - 25.00 ) < 0.0001,
	'1M output tokens on Opus 5 costs its list output rate'
);

qsoft_assert(
	Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-haiku-4-5' ) < Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-opus-5' ),
	'Haiku costs less than Opus for the same tokens'
);

// Cached reads are a tenth of fresh input, so they must not be counted as full.
qsoft_assert(
	Spend::cost( array( 'cache_read_input_tokens' => 1000000 ), 'claude-opus-5' )
		< Spend::cost( array( 'input_tokens' => 1000000 ), 'claude-opus-5' ),
	'cache reads are cheaper than fresh input'
);

qsoft_assert( 0.0 === Spend::cost( array(), 'claude-opus-5' ), 'an empty usage block costs nothing' );

// An unknown model must not be priced as if it were Opus: the rate is unknown.
qsoft_assert( null === Spend::price( 'not-a-real-model' ), 'an unknown model has no price' );
qsoft_assert( ! Spend::knows( 'not-a-real-model' ), 'an unknown model is reported as unknown' );
qsoft_assert( Spend::knows( 'claude-haiku-4-5' ), 'a listed model is reported as known' );

qsoft_assert(
	0.0 === Spend::cost( array( 'input_tokens' => 1000 ), 'not-a-real-model' ),
	'an unknown model costs nothing rather than being priced as Opus'
);

qsoft_group( 'Spend — estimates' );

$qsoft_small = Spend::estimate( 2000, 'claude-opus-5' );
$qsoft_large = Spend::estimate( 200000, 'claude-opus-5' );

qsoft_assert( $qsoft_small > 0.0, 'a small section has a non-zero estimate' );
qsoft_assert( $qsoft_large > $qsoft_small, 'a larger section estimates higher' );
qsoft_assert( Spend::estimate( 0, 'claude-opus-5' ) > 0.0, 'an empty section still costs its output' );
qsoft_assert( Spend::estimate( -5, 'claude-opus-5' ) > 0.0, 'a negative size does not produce a negative price' );

// 2,000 chars: 500 tokens in at $5, the typical 2,200 out at $25.
qsoft_assert( abs( $qsoft_small - 0.0575 ) < 0.000001, 'a small section is priced at its input plus the typical output' );

// 200,000 chars: 50,000 in, and the output grows with it — 30,000 out, not 2,200.
qsoft_assert( abs( $qsoft_large - 1.0 ) < 0.000001, 'a large section\'s output estimate scales with its input' );

qsoft_assert(
	2200 === Spend::output_tokens( 0 ) && 2200 === Spend::output_tokens( 3000 ) && 3000 === Spend::output_tokens( 5000 ),
	'the output estimate is the typical figure until the input outgrows it'
);

// ------------------------------------- SourceProject: reading an application

/*
 * The half of the source route that has a right answer. Which URLs an
 * application serves and which component answers each of them is read out of
 * the router, and if that reading is wrong every page after it is a page of
 * the wrong thing — so it is checked here, against a project written on disk,
 * rather than trusted because a model would probably notice.
 */
echo "\n=== SourceProject — routes read out of a router ===\n";

$qsoft_app = sys_get_temp_dir() . '/qsoft-source-' . uniqid();

qsoft_write_files(
	$qsoft_app,
	array(
		'package.json'                  => '{ "dependencies": { "react": "^18.0.0", "wouter": "^3.0.0" } }',
		'tsconfig.json'                 => '{ "compilerOptions": { "paths": { "@/*": ["./src/*"] } } }',
		'src/App.tsx'                   => implode(
			"\n",
			array(
				'import { HomePage, InfoPage } from "@/pages/SitePages";',
				'import Detail from "./pages/Detail";',
				'export default function App() {',
				'  return (<Switch>',
				'    <Route path="/" component={HomePage} />',
				'    <Route path="/contact">{() => <InfoPage section="contact" />}</Route>',
				'    <Route path="/products/:slug">{({ slug }) => <Detail slug={slug} />}</Route>',
				'  </Switch>);',
				'}',
			)
		),
		'src/pages/SitePages.tsx'       => 'import Layout from "@/components/Layout"; import { items } from "@/data/catalogue"; export function HomePage() { return <Layout>{items.length}</Layout>; } export function InfoPage() { return <h1>Info</h1>; }',
		'src/pages/Detail.tsx'          => 'export default function Detail() { return <h1>One product</h1>; }',
		'src/components/Layout.tsx'     => 'import { Button } from "@/components/ui/button"; export default function Layout({ children }) { return <div>{children}<Button /></div>; }',
		'src/components/ui/button.tsx'  => 'export function Button() { return <button />; }',
		'src/data/catalogue.ts'         => 'export const items = [' . str_repeat( '{ "name": "one" },', 900 ) . '];',
		'src/index.css'                 => ':root { --brand: #0e6f85; }',
		'public/assets/hero.png'        => 'not really a png',
		'node_modules/react/index.js'   => 'module.exports = {};',
		'node_modules/react/App.tsx'    => 'export default function Nope() { return <Route path="/nope" component={Nope} />; }',
	)
);

$qsoft_project = SourceProject::find( $qsoft_app );

qsoft_assert( null !== $qsoft_project, 'an application inside a design is found' );

if ( null !== $qsoft_project ) {
	$qsoft_routes = $qsoft_project->routes();
	$qsoft_paths  = array_column( $qsoft_routes, 'path' );

	qsoft_assert( 'react' === $qsoft_project->framework(), 'the framework is read from package.json' );
	qsoft_assert( 3 === count( $qsoft_routes ), 'every declared route is found, and no route from node_modules' );
	qsoft_assert( in_array( '/', $qsoft_paths, true ), 'the component={} form is read' );
	qsoft_assert( in_array( '/contact', $qsoft_paths, true ), 'the function-child form is read' );

	$qsoft_by_path = array_combine( $qsoft_paths, $qsoft_routes );

	qsoft_assert( 'HomePage' === $qsoft_by_path['/']['component'], 'the route names its component' );
	qsoft_assert( 'src/pages/SitePages.tsx' === $qsoft_by_path['/']['file'], 'a named import resolves through the @ alias' );
	qsoft_assert( false === $qsoft_by_path['/']['dynamic'], 'a plain route is not dynamic' );
	qsoft_assert( true === $qsoft_by_path['/products/:slug']['dynamic'], 'a route with a parameter is marked dynamic' );
	qsoft_assert( 'home' === $qsoft_by_path['/']['slug'] && 'contact' === $qsoft_by_path['/contact']['slug'], 'each route has a file name for its page' );

	$qsoft_bundle = $qsoft_project->bundle( $qsoft_by_path['/'] );
	$qsoft_files  = array_column( $qsoft_bundle['files'], 'path' );

	qsoft_assert( in_array( 'src/pages/SitePages.tsx', $qsoft_files, true ), 'the page component is in the bundle' );
	qsoft_assert( in_array( 'src/components/Layout.tsx', $qsoft_files, true ), 'what the page imports is in the bundle' );
	qsoft_assert( in_array( 'src/App.tsx', $qsoft_files, true ), 'the router is in the bundle, so route props can be read' );
	qsoft_assert( ! in_array( 'src/components/ui/button.tsx', $qsoft_files, true ), 'interface primitives are named, not quoted' );
	qsoft_assert( in_array( 'src/components/ui/button.tsx', $qsoft_bundle['ui'], true ), 'and they are named' );
	qsoft_assert( array() !== $qsoft_bundle['styles'], 'the stylesheet is in the bundle' );
	qsoft_assert( in_array( 'public/assets/hero.png', $qsoft_bundle['images'], true ), 'pictures that exist are listed' );

	$qsoft_data = array_values(
		array_filter(
			$qsoft_bundle['files'],
			static function ( array $file ): bool {
				return 'src/data/catalogue.ts' === $file['path'];
			}
		)
	);

	qsoft_assert( array() !== $qsoft_data && $qsoft_data[0]['truncated'], 'a catalogue is sampled rather than quoted whole' );
	qsoft_assert( array() !== $qsoft_data && strlen( $qsoft_data[0]['code'] ) <= 4000, 'and the sample is bounded' );

	foreach ( $qsoft_files as $qsoft_path ) {
		qsoft_assert( ! str_contains( $qsoft_path, 'node_modules' ), 'nothing from node_modules reaches the brief: ' . $qsoft_path );
	}
}

qsoft_remove_tree( $qsoft_app );

qsoft_assert( ! is_dir( $qsoft_app ), 'the test cleans up after itself' );

/*
 * A handoff package is regularly more than one application: the site, an admin
 * panel, a widget that is meant to ship as a plugin. Which of them belongs on
 * a WordPress site is a product decision rather than a fact about the files,
 * so all of them have to reach the screen — taking only the largest, which is
 * what one arsort() used to do, silently dropped the rest.
 */
echo "\n=== SourceProject — every application in a handoff, not the biggest one ===\n";

$qsoft_pack = sys_get_temp_dir() . '/qsoft-pack-' . uniqid();

qsoft_write_files(
	$qsoft_pack,
	array(
		// The site: two routes, several components.
		'01_Site/web/package.json'          => '{ "dependencies": { "react": "^18.0.0" } }',
		'01_Site/web/src/App.tsx'           => implode(
			"\n",
			array(
				'import Home from "./pages/Home";',
				'import About from "./pages/About";',
				'export default function App() {',
				'  return (<Switch>',
				'    <Route path="/" component={Home} />',
				'    <Route path="/about" component={About} />',
				'  </Switch>);',
				'}',
			)
		),
		'01_Site/web/src/pages/Home.tsx'    => 'export default function Home() { return <h1>Home</h1>; }',
		'01_Site/web/src/pages/About.tsx'   => 'export default function About() { return <h1>About</h1>; }',
		'01_Site/web/src/components/Nav.tsx' => 'export default function Nav() { return <nav />; }',

		// The widget meant to ship as a plugin: its own project, one route.
		'02_Widget/plugin/package.json'     => '{ "dependencies": { "react": "^18.0.0" } }',
		'02_Widget/plugin/src/App.tsx'      => implode(
			"\n",
			array(
				'import Panel from "./pages/Panel";',
				'export default function App() {',
				'  return (<Switch><Route path="/" component={Panel} /></Switch>);',
				'}',
			)
		),
		'02_Widget/plugin/src/pages/Panel.tsx' => 'export default function Panel() { return <h1>Panel</h1>; }',
	)
);

$qsoft_all = SourceProject::all( $qsoft_pack );

qsoft_assert( 2 === count( $qsoft_all ), 'both applications are found', count( $qsoft_all ) );

$qsoft_dirs = array_map(
	static function ( SourceProject $project ): string {
		return $project->relative();
	},
	$qsoft_all
);

qsoft_assert( in_array( '01_Site/web', $qsoft_dirs, true ), 'the site is one of them', implode( ', ', $qsoft_dirs ) );
qsoft_assert( in_array( '02_Widget/plugin', $qsoft_dirs, true ), 'the plugin is the other', implode( ', ', $qsoft_dirs ) );
qsoft_assert( '01_Site/web' === $qsoft_dirs[0], 'the biggest comes first, so it can be the default', $qsoft_dirs[0] );

$qsoft_biggest = SourceProject::find( $qsoft_pack );

qsoft_assert(
	null !== $qsoft_biggest && '01_Site/web' === $qsoft_biggest->relative(),
	'find() still answers with the biggest, so nothing that called it changed behaviour'
);

qsoft_assert( 'web' === $qsoft_all[0]->name(), 'a project is named after its own directory', $qsoft_all[0]->name() );
qsoft_assert( 4 === $qsoft_all[0]->components(), 'its size is reported for the chooser', $qsoft_all[0]->components() );

$qsoft_second = null;

foreach ( $qsoft_all as $qsoft_candidate ) {
	if ( '02_Widget/plugin' === $qsoft_candidate->relative() ) {
		$qsoft_second = $qsoft_candidate;
	}
}

qsoft_assert(
	null !== $qsoft_second && 1 === count( $qsoft_second->routes() ),
	'the smaller application keeps its own routes rather than the winner\'s'
);

qsoft_remove_tree( $qsoft_pack );

// ------------------------------------ DesignDocs: what the handoff says

/*
 * A handoff's own writing is worth more than its markup for some questions —
 * which colours are the brand's, which copy is final — but only if the right
 * pages are read. This checks the ranking, the de-duplication and the budget,
 * because a digest that spends its room on a change register is a digest that
 * taught the model nothing.
 */
echo "\n=== DesignDocs — the handoff's own writing, ranked ===\n";

$qsoft_docs_dir = sys_get_temp_dir() . '/qsoft-docs-' . uniqid();
$qsoft_register = "# Change register\n" . str_repeat( "Version bump, no design change.\n", 200 );

qsoft_write_files(
	$qsoft_docs_dir,
	array(
		'START-HERE-DEVELOPER.md'           => "# Start here\nThe site is a lighting catalogue.",
		'docs/UI-DESIGN-SYSTEM.md'          => "# UI Design System\nNavy surfaces, gold actions.",
		'docs/ROUTE-INVENTORY.md'           => "# Routes\n/ , /products, /contact",
		'docs/CHANGE-REGISTER-v2.1.4.md'    => $qsoft_register,
		'src/docs/CHANGE-REGISTER-v2.1.4.md' => $qsoft_register,
		'docs/qa/ACCEPTANCE-CHECKLIST.md'   => "# Acceptance\nEvery box ticked.",
		'governance/GOVERNANCE-STANDARD.md' => "# Governance\nProcess, not design.",
		'notes/CONTENT_DECISIONS.md'        => "# Content decisions\nThe hero copy is final.",
		'src/components/Button.tsx'         => 'export function Button() {}',

		/*
		 * Machine output that looks like a document. Both of these outranked
		 * the design system once, purely by sitting at the top level with a
		 * .txt on the end.
		 */
		'SHA256SUMS.txt'                    => str_repeat( "9f2c3a1b7e5d4c6a8b0e2f4a6c8e0b2d4f6a8c0e2b4d6f8a0c2e4b6d8f0a2c4e  dist/index.js\n", 40 ),
		'FILE-MANIFEST.txt'                 => "path,size\ndist/index.js,1024\n",
	)
);

$qsoft_digest = \Qwerty\Soft\Support\DesignDocs::digest( $qsoft_docs_dir, 40000 );
$qsoft_read   = $qsoft_digest['files'];

qsoft_assert( array() !== $qsoft_read, 'the documentation is found' );
qsoft_assert( 'START-HERE-DEVELOPER.md' === ( $qsoft_read[0] ?? '' ), 'the start-here page is read first' );
qsoft_assert( in_array( 'docs/UI-DESIGN-SYSTEM.md', $qsoft_read, true ), 'the design system is read' );
qsoft_assert( in_array( 'docs/ROUTE-INVENTORY.md', $qsoft_read, true ), 'the route inventory is read' );
qsoft_assert( in_array( 'notes/CONTENT_DECISIONS.md', $qsoft_read, true ), 'the content decisions are read' );

foreach ( array( 'CHANGE-REGISTER', 'ACCEPTANCE', 'GOVERNANCE' ) as $qsoft_paperwork ) {
	$qsoft_hit = array_filter(
		$qsoft_read,
		static function ( string $file ) use ( $qsoft_paperwork ): bool {
			return str_contains( $file, $qsoft_paperwork );
		}
	);

	qsoft_assert( array() === $qsoft_hit, 'process paperwork is left out: ' . $qsoft_paperwork );
}

qsoft_assert( ! str_contains( $qsoft_digest['text'], 'export function Button' ), 'source files are not documentation' );
qsoft_assert( ! str_contains( $qsoft_digest['text'], '9f2c3a1b7e5d' ), 'a checksum list is not documentation' );
qsoft_assert( ! str_contains( $qsoft_digest['text'], 'FILE-MANIFEST' ), 'a file manifest is not documentation' );
qsoft_assert( 'START-HERE-DEVELOPER.md' === ( $qsoft_digest['files'][0] ?? '' ), 'and neither of them outranks the start-here page' );
qsoft_assert( str_contains( $qsoft_digest['text'], 'Navy surfaces, gold actions.' ), 'the digest quotes what it read' );

$qsoft_small = \Qwerty\Soft\Support\DesignDocs::digest( $qsoft_docs_dir, 120 );

qsoft_assert( strlen( $qsoft_small['text'] ) < strlen( $qsoft_digest['text'] ), 'a smaller budget quotes less' );

qsoft_remove_tree( $qsoft_docs_dir );

// ------------------------------------------------------------------ report

/**
 * Write a set of files, creating the directories they need.
 *
 * @param string                $root  Directory to write into.
 * @param array<string, string> $files Relative path => contents.
 * @return void
 */
function qsoft_write_files( string $root, array $files ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	foreach ( $files as $path => $contents ) {
		$target = $root . '/' . $path;
		$dir    = dirname( $target );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The test harness runs without WordPress.
		}

		file_put_contents( $target, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
	}
}

/**
 * Delete a directory and everything under it.
 *
 * @param string $dir Directory.
 * @return void
 */
function qsoft_remove_tree( string $dir ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See above.
			continue;
		}

		unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- See above.
	}

	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See above.
}

/**
 * Hue of a hex colour, in degrees.
 *
 * @param string $hex Colour.
 * @return float
 */
function qsoft_hue_of( string $hex ): float { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test harness helper.
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

// -------------------------------------------------------------- SectionPlan

/*
 * A section is no longer translated into the theme's blocks — it is wrapped in
 * one of its own, keeping the design's markup and stylesheet. What that block
 * holds comes from here, and three shapes have to be told apart correctly or
 * the whole import is wrong in a way nobody sees until the site is built:
 * one-off content, a short list the design owns, and a listing that should be
 * a post type.
 */
echo "\n=== SectionPlan — what a section's block will hold ===\n";

$qsoft_hero = Qwerty\Soft\Support\SectionPlan::of(
	'<div class="hero">
		<p class="eyebrow">Fixture Co</p>
		<h1>Ship the signal, not the noise</h1>
		<p class="lede">A small, fast site for a team.</p>
		<a class="button" href="Contact.dc.html">Start a project</a>
	</div>'
);

$qsoft_names = array_column( $qsoft_hero['fields'], 'name' );
$qsoft_types = array_combine( $qsoft_names, array_column( $qsoft_hero['fields'], 'type' ) );

qsoft_assert( 'single' === $qsoft_hero['kind'], 'a hero is one-off content' );
qsoft_assert( null === $qsoft_hero['item'], 'and has no repeating item' );
qsoft_assert( array( 'eyebrow', 'heading', 'lede', 'button' ) === $qsoft_names, 'its fields are named from the design\'s own classes' );
qsoft_assert( 'text' === $qsoft_types['heading'], 'a heading is a single-line field' );
qsoft_assert( 'link' === $qsoft_types['button'], 'an anchor becomes a link field' );

$qsoft_cards = Qwerty\Soft\Support\SectionPlan::of(
	'<section class="reports"><h2>Reports</h2><div class="grid">
		<article class="card"><img src="a.jpg" alt=""><h3>China</h3><p>One</p></article>
		<article class="card"><img src="b.jpg" alt=""><h3>Eurasia</h3><p>Two</p></article>
		<article class="card"><img src="c.jpg" alt=""><h3>Central</h3><p>Three</p></article>
		<article class="card"><img src="d.jpg" alt=""><h3>Global</h3><p>Four</p></article>
		<article class="card"><img src="e.jpg" alt=""><h3>Risk</h3><p>Five</p></article>
		<article class="card"><img src="f.jpg" alt=""><h3>Tools</h3><p>Six</p></article>
	</div></section>'
);

qsoft_assert( 'listing' === $qsoft_cards['kind'], 'six identical cards are a listing, not section content' );
qsoft_assert( 'article.card' === $qsoft_cards['item']['selector'], 'the card is named by tag and class' );
qsoft_assert( 6 === $qsoft_cards['item']['count'], 'all six are counted' );
qsoft_assert( array( 'image', 'subheading', 'text' ) === array_column( $qsoft_cards['item']['fields'], 'name' ), 'one row has the card\'s own fields' );
qsoft_assert( array( 'subheading' ) === array_column( $qsoft_cards['fields'], 'name' ), 'the section keeps its heading and does not claim the cards\' text' );

$qsoft_facts = Qwerty\Soft\Support\SectionPlan::of(
	'<section class="facts"><h2>Core focus</h2>
		<div class="fact"><span class="label">Since</span><strong>2004</strong></div>
		<div class="fact"><span class="label">Method</span><strong>Evidence</strong></div>
		<div class="fact"><span class="label">Output</span><strong>Research</strong></div>
	</section>'
);

qsoft_assert( 'repeat' === $qsoft_facts['kind'], 'three tiles are the section\'s own furniture, not records' );
qsoft_assert( 2 === count( $qsoft_facts['item']['fields'] ), 'the label and the value are both fields' );

/*
 * The inline rule cuts both ways, and the second half matters more: emphasis
 * inside a sentence is part of the sentence. A paragraph that yielded three
 * fields — text, bold, text — would give an editor a broken sentence in three
 * boxes.
 */
$qsoft_emphasis = Qwerty\Soft\Support\SectionPlan::of( '<div class="lede"><p>Led by <strong>Robert Khoubian</strong>, CEO.</p></div>' );

qsoft_assert( 1 === count( $qsoft_emphasis['fields'] ), 'a bold word inside a paragraph is not its own field' );

$qsoft_pair = Qwerty\Soft\Support\SectionPlan::of(
	'<div class="row"><div class="col"><h3>One</h3></div><div class="col"><h3>Two</h3></div></div>'
);

qsoft_assert( 'single' === $qsoft_pair['kind'], 'two columns are a layout, not a list' );

// --------------------------------------------------------------- BlockWriter

/*
 * The writer is the whole of the new import, so the checks are about the one
 * property it exists to have: what comes out is the markup that went in, with
 * values substituted and nothing else disturbed.
 */
echo "\n=== BlockWriter — a section becomes a block ===\n";

$qsoft_hero_html = '<section class="section-dark hero"><div class="hero-grid">'
	. '<p class="eyebrow">China Trade</p><h1>Food trade intelligence.</h1>'
	. '<p class="hero-lede">A research hub.</p>'
	. '<a class="btn btn-primary" href="reports.html">Explore</a>'
	. '<img class="hero-photo" src="img/rk.jpg" alt="Robert"></div></section>';

$qsoft_hero_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_hero_html );
$qsoft_hero_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_hero_html, $qsoft_hero_plan );

/*
 * The measurement that motivated the rewrite. A translated page lost 190 of
 * the 253 class names its stylesheet targeted; a wrapped one cannot lose any,
 * because nothing rewrites the markup.
 */
foreach ( array( 'section-dark', 'hero', 'hero-grid', 'eyebrow', 'hero-lede', 'btn-primary', 'hero-photo' ) as $qsoft_class ) {
	qsoft_assert(
		false !== strpos( $qsoft_hero_render, $qsoft_class ),
		sprintf( 'the design keeps its own class "%s"', $qsoft_class )
	);
}

/*
 * The design's own words appear exactly once, as the fallback the field
 * reads when nothing is stored — never as markup. A block dragged out of the
 * inserter onto a second page has no stored values at all, and used to come up
 * as an empty frame; what it shows now is the section as delivered.
 */
$qsoft_hero_markup = (string) preg_replace( '/<\?php.*?\?>/s', '', $qsoft_hero_render );

qsoft_assert(
	false === strpos( $qsoft_hero_markup, 'Food trade intelligence.' ),
	'the design\'s copy is replaced by a field rather than baked in'
);

qsoft_assert(
	false !== strpos( $qsoft_hero_render, 'DesignField::value( \'heading\', $block ?? null, \'Food trade intelligence.\' )' ),
	'and is kept as that field\'s fallback, so an unfilled block still says something'
);

qsoft_assert(
	false !== strpos( $qsoft_hero_render, "DesignField::value( 'heading'" )
		&& false !== strpos( $qsoft_hero_render, 'esc_html(' ),
	'a heading becomes an escaped field'
);

/*
 * Attributes are where the first attempt broke: `href` and `src` are URI
 * attributes, so the serialiser percent-encoded the placeholder and the link
 * shipped with a literal token for an address.
 */
qsoft_assert(
	false !== strpos( $qsoft_hero_render, 'href="<?php echo \Qwerty\Soft\Support\DesignField::url(' ),
	'a link\'s address is a field, not a percent-encoded placeholder'
);

qsoft_assert(
	false === strpos( $qsoft_hero_render, '%7B' ),
	'no placeholder survives into the markup'
);

qsoft_assert(
	false === strpos( $qsoft_hero_render, 'QSOFT' ),
	'no marker survives into the markup'
);

$qsoft_cards_html = '<section class="section"><h2>Reports</h2><div class="reports-grid">'
	. str_repeat( '<article class="report-card"><h3>T</h3><p>Body.</p></article>', 6 )
	. '</div></section>';

$qsoft_cards_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_cards_html );
$qsoft_cards_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_cards_html, $qsoft_cards_plan );

qsoft_assert(
	1 === substr_count( $qsoft_cards_render, 'report-card' ),
	'six drawn cards become one card and a loop'
);

qsoft_assert(
	false !== strpos( $qsoft_cards_render, 'DesignListing::rows(' ),
	'a listing reads the site\'s own records'
);

qsoft_assert(
	1 === substr_count( $qsoft_cards_render, 'endforeach' ),
	'the loop is closed exactly once'
);

$qsoft_tiles_html = '<section class="stats-band"><div class="stats-grid">'
	. str_repeat( '<div class="stat"><span>Label</span></div>', 3 )
	. '</div></section>';

$qsoft_tiles_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_tiles_html );
$qsoft_tiles_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_tiles_html, $qsoft_tiles_plan );

qsoft_assert(
	false !== strpos( $qsoft_tiles_render, "DesignField::rows( 'items'" ),
	'a short fixed list is a repeater, not a query'
);

// The block has to be findable, or it is generated and unusable.
$qsoft_manifest = json_decode( Qwerty\Soft\Support\BlockWriter::block_json( 'home-hero', 'Hero', true ), true );

qsoft_assert( 'qs/design-home-hero' === $qsoft_manifest['name'], 'a generated block is named under qs/design-' );
qsoft_assert( 'qs' === $qsoft_manifest['category'], 'a generated block sits in the Qwerty Soft category so it can be reused' );
qsoft_assert( 'render.php' === $qsoft_manifest['acf']['renderTemplate'], 'the manifest points ACF at the template' );
qsoft_assert( 'qs-design-canonical' === $qsoft_manifest['style'], 'the section wears the design\'s canonical stylesheet, never a slice' );
qsoft_assert( 'qs-design-canonical' === $qsoft_manifest['viewScript'], 'the design\'s own script rides the same handle' );

$qsoft_group = json_decode( Qwerty\Soft\Support\BlockWriter::fields_json( 'home-hero', 'Hero', $qsoft_hero_plan ), true );

qsoft_assert(
	'qs/design-home-hero' === $qsoft_group['location'][0][0]['value'],
	'the field group is attached to its own block and no other'
);

$qsoft_types = array_column( $qsoft_group['fields'], 'type', 'name' );

qsoft_assert( 'image' === ( $qsoft_types['hero_photo'] ?? '' ), 'a picture is an image field, pickable from the library' );
qsoft_assert( 'link' === ( $qsoft_types['btn'] ?? '' ), 'a button is a link field, so the URL comes from the admin' );

// A name that a filesystem or Windows path length would refuse.
qsoft_assert(
	'a-b' === Qwerty\Soft\Support\BlockWriter::slug( '  A// b!! ' ),
	'a slug is reduced to what every filesystem accepts'
);

qsoft_assert(
	40 >= strlen( Qwerty\Soft\Support\BlockWriter::slug( str_repeat( 'section', 20 ) ) ),
	'a slug stays short enough for a Windows path'
);

/**
 * The JSON attributes out of a self-closing block comment.
 *
 * @param string $markup Block markup.
 * @return array<string, mixed>
 */
function qsoft_block_attributes( string $markup ): array { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Prefixed; the sniff does not see qsoft_ as one.
	$open  = strpos( $markup, '{' );
	$close = strrpos( $markup, '}' );

	if ( false === $open || false === $close || $close <= $open ) {
		return array();
	}

	$decoded = json_decode( substr( $markup, $open, $close - $open + 1 ), true );

	return is_array( $decoded ) ? $decoded : array();
}

/*
 * The values, which are what stop a wrapped page rendering as an empty frame.
 * The template echoes fields; if nothing carries the designer's copy into
 * those fields, the build produces a beautifully styled blank.
 */
$qsoft_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_hero_html, $qsoft_hero_plan );

qsoft_assert( 'Food trade intelligence.' === ( $qsoft_values['heading'] ?? '' ), 'the heading keeps the words the designer wrote' );
qsoft_assert( 'China Trade' === ( $qsoft_values['eyebrow'] ?? '' ), 'the kicker keeps its words' );
qsoft_assert( 'img/rk.jpg' === ( $qsoft_values['hero_photo'] ?? '' ), 'a picture is reported as the archive wrote it, for the caller to resolve' );
qsoft_assert( 'reports.html' === ( $qsoft_values['btn']['url'] ?? '' ), 'a button keeps its address' );
qsoft_assert( 'Explore' === ( $qsoft_values['btn']['title'] ?? '' ), 'a button keeps its label' );

$qsoft_tile_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_tiles_html, $qsoft_tiles_plan );

qsoft_assert(
	3 === count( (array) ( $qsoft_tile_values['items'] ?? array() ) ),
	'a repeat carries one row per thing the design drew'
);

$qsoft_listing_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_cards_html, $qsoft_cards_plan );

qsoft_assert(
	! isset( $qsoft_listing_values['items'] ),
	'a listing starts empty, because its rows are the site\'s records rather than the section\'s content'
);

// The block as it sits in a page, which is what the editor opens.
$qsoft_instance = Qwerty\Soft\Support\BlockWriter::instance( 'home-hero', $qsoft_hero_plan, $qsoft_values );

qsoft_assert(
	str_starts_with( $qsoft_instance, '<!-- wp:qs/design-home-hero {' ),
	'the page places the block by its registered name'
);

$qsoft_placed = qsoft_block_attributes( $qsoft_instance );

qsoft_assert( 'Food trade intelligence.' === ( $qsoft_placed['data']['heading'] ?? '' ), 'the copy travels with the block instance' );
qsoft_assert(
	'field_qs_home_hero_heading' === ( $qsoft_placed['data']['_heading'] ?? '' ),
	'each value names the field it belongs to, as ACF requires'
);

$qsoft_tile_instance = Qwerty\Soft\Support\BlockWriter::instance( 'home-stats', $qsoft_tiles_plan, $qsoft_tile_values );
$qsoft_tile_placed   = qsoft_block_attributes( $qsoft_tile_instance );

qsoft_assert( 3 === ( $qsoft_tile_placed['data']['items'] ?? 0 ), 'a repeater records how many rows it has' );
qsoft_assert(
	isset( $qsoft_tile_placed['data']['items_0_label'] ),
	'repeater rows are written flat, the way ACF stores them'
);

/*
 * The group has to name the block by the name it is registered under. ACF
 * prefixes only what it registers itself; a rule naming `acf/…` for a block
 * declared in a block.json attaches the fields to nothing, and the symptom is
 * a block with an empty sidebar.
 */
qsoft_assert(
	'qs/design-home-hero' === $qsoft_group['location'][0][0]['value'],
	'the field group names the block as it is registered'
);


// ---------------------------------------------------------- the dated line

/*
 * The one piece of the design's copy that must not be frozen. Wrapped
 * verbatim, every site built from an archive that says "© 2026" is wrong from
 * the next New Year, and it is the kind of wrong nobody notices for eleven
 * months.
 */
echo "\n=== DesignField — a copyright year follows the clock ===\n";

$qsoft_footer_html = '<footer class="site-footer"><p class="small">© 2019 Fixture Co. All rights reserved.</p></footer>';
$qsoft_footer_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_footer_html );
$qsoft_footer_php  = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_footer_html, $qsoft_footer_plan );

qsoft_assert(
	false !== strpos( $qsoft_footer_php, 'DesignField::dated(' ),
	'a copyright line is rendered through the clock rather than frozen'
);

qsoft_assert(
	false === strpos( (string) preg_replace( '/<\?php.*?\?>/s', '', $qsoft_footer_php ), '2019' ),
	'the design\'s year does not survive into the template'
);

qsoft_assert(
	str_contains( Qwerty\Soft\Support\DesignField::dated( '© 2019 Fixture Co.' ), gmdate( 'Y' ) ),
	'the year read back is this one'
);

qsoft_assert(
	str_contains( Qwerty\Soft\Support\DesignField::dated( '© 2019 Fixture Co.' ), 'Fixture Co.' ),
	'and the words around it are the editor\'s, untouched'
);

/*
 * Only a copyright line. A price of "2019 EUR" or a report titled "2019
 * Outlook" would be rewritten every January by a rule that looked for a year
 * alone, which is worse than the problem it solves.
 */
$qsoft_year_html = '<section class="report"><h2>The 2019 Outlook</h2></section>';
$qsoft_year_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_year_html );

qsoft_assert(
	false === strpos( (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_year_html, $qsoft_year_plan ), 'DesignField::dated(' ),
	'a year that is not a copyright notice is left alone'
);

// ------------------------------------------------- chrome reads the options

/*
 * The header and footer are on every page, so their words belong to the site
 * rather than to one placement. Held with the block, the same telephone number
 * would be edited in two template parts and hoped to agree.
 */
echo "\n=== BlockWriter — the chrome reads the site's own content ===\n";

$qsoft_chrome_dir = sys_get_temp_dir() . '/qsoft-chrome-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write(
	$qsoft_footer_html,
	$qsoft_footer_plan,
	'site-footer',
	'Site footer',
	'',
	$qsoft_chrome_dir,
	'index.html',
	'option'
);

$qsoft_chrome_php   = (string) file_get_contents( $qsoft_chrome_dir . '/render.php' );
$qsoft_chrome_group = json_decode( (string) file_get_contents( $qsoft_chrome_dir . '/fields.json' ), true );

qsoft_assert(
	false !== strpos( $qsoft_chrome_php, 'DesignField::site(' ),
	'a chrome field is read from the site rather than from the block'
);

qsoft_assert(
	'options_page' === ( $qsoft_chrome_group['location'][0][0]['param'] ?? '' ),
	'and its field group is attached to the options page, so one edit reaches every page'
);

$qsoft_chrome_values = Qwerty\Soft\Support\BlockWriter::option_values(
	'site-footer',
	$qsoft_footer_plan,
	Qwerty\Soft\Support\BlockWriter::values( $qsoft_footer_html, $qsoft_footer_plan )
);

qsoft_assert(
	array() !== array_filter( $qsoft_chrome_values, static fn( $k ) => str_starts_with( (string) $k, 'options_' ), ARRAY_FILTER_USE_KEY ),
	'the design\'s own words are seeded as site content rather than left blank'
);

// ------------------------------------------- a long group is divided

/*
 * Nineteen fields in one list is a wall. "Link — Ongoing assurance" says what
 * the link is and nothing about which of three columns it stands in, and the
 * design already answers that: its columns are elements, and every field
 * carries the address SectionPlan recorded. So the division is the markup's
 * own, and each part is called what the design calls it.
 */
echo "\n=== BlockWriter — a long field group is divided the way the design divides the section ===\n";

/*
 * Three parts of a footer and its small print, each built differently so that
 * nothing in it is a run of identical siblings: this is a wall of fields, not
 * a list, and the divider is what is under test rather than SectionPlan's
 * reading of a repeat.
 */
$qsoft_wall_html = '<footer class="site-footer"><div class="container"><div class="footer-grid">'
	. '<div class="footer-brand">'
	. '<a class="brand" href="index.html"><span>Fixture</span></a>'
	. '<p>Everything a fixture needs, delivered by people who have done it before.</p>'
	. '</div>'
	. '<div>'
	. '<div class="footer-title">Managed Program</div>'
	. '<a class="lead" href="managed.html">Leadership and roadmap</a>'
	. '<a class="rule" href="compliance.html">Compliance and privacy</a>'
	. '</div>'
	. '<div class="footer-address">'
	. '<div class="footer-title">Where we are</div>'
	. '<p>Nineteen Fixture Street, in the part of town with the good coffee.</p>'
	. '<address>Open on the days the coffee shop is open, which is most of them.</address>'
	. '</div>'
	. '</div><div class="copyright">'
	. '<span>© 2019 Fixture Co. All rights reserved.</span>'
	. '<em>Managed security and compliance.</em>'
	. '</div></div></footer>';

$qsoft_wall_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_wall_html );
$qsoft_wall_dir  = sys_get_temp_dir() . '/qsoft-wall-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write(
	$qsoft_wall_html,
	$qsoft_wall_plan,
	'site-footer-wall01',
	'Site footer',
	'',
	$qsoft_wall_dir,
	'index.html',
	'option'
);

$qsoft_wall_group = json_decode( (string) file_get_contents( $qsoft_wall_dir . '/fields.json' ), true );
$qsoft_wall_tabs  = array();

foreach ( (array) ( $qsoft_wall_group['fields'] ?? array() ) as $qsoft_wall_field ) {
	if ( 'tab' === ( $qsoft_wall_field['type'] ?? '' ) ) {
		$qsoft_wall_tabs[] = (string) $qsoft_wall_field['label'];
	}
}

qsoft_assert(
	count( $qsoft_wall_tabs ) > 1,
	'a group long enough to be a wall is divided',
	$qsoft_wall_tabs
);


foreach ( array( 'Footer brand', 'Footer address' ) as $qsoft_wall_named ) {
	qsoft_assert(
		in_array( $qsoft_wall_named, $qsoft_wall_tabs, true ),
		sprintf( 'a part the design named by its class is called "%s"', $qsoft_wall_named ),
		$qsoft_wall_tabs
	);
}

foreach ( array( 'Managed Program' ) as $qsoft_wall_column ) {
	qsoft_assert(
		in_array( $qsoft_wall_column, $qsoft_wall_tabs, true ),
		sprintf( 'a column the design headed "%s" is the "%s" tab', $qsoft_wall_column, $qsoft_wall_column ),
		$qsoft_wall_tabs
	);
}

qsoft_assert(
	in_array( 'Copyright', $qsoft_wall_tabs, true ),
	'and the small print is its own part rather than the tail of the last column',
	$qsoft_wall_tabs
);

/*
 * Every field still there, in the order the design put them: a divider is a
 * heading over the list, never a filter on it.
 */
$qsoft_wall_names = array();

foreach ( (array) ( $qsoft_wall_group['fields'] ?? array() ) as $qsoft_wall_field ) {
	if ( 'tab' !== ( $qsoft_wall_field['type'] ?? '' ) ) {
		$qsoft_wall_names[] = (string) $qsoft_wall_field['name'];
	}
}

qsoft_assert(
	count( $qsoft_wall_names ) === count( (array) $qsoft_wall_plan['fields'] ),
	'dividing the group loses no field',
	array( count( $qsoft_wall_names ), count( (array) $qsoft_wall_plan['fields'] ) )
);

qsoft_assert(
	0 === count( array_filter( $qsoft_wall_names, static fn( string $name ): bool => ! str_starts_with( $name, 'site_footer_wall01_' ) ) ),
	'and every name still carries the block it belongs to',
	$qsoft_wall_names
);

/*
 * Eleven copies of "Choose a page, or paste a web address" is not eleven times
 * the help — it is the eleven labels that do differ held apart by the sentence
 * that does not. Said once where it is first met, and again in the next tab,
 * which is a screen of its own.
 */
$qsoft_wall_said = array();
$qsoft_wall_seen = array();

foreach ( (array) ( $qsoft_wall_group['fields'] ?? array() ) as $qsoft_wall_field ) {
	if ( 'tab' === ( $qsoft_wall_field['type'] ?? '' ) ) {
		$qsoft_wall_seen = array();

		continue;
	}

	$qsoft_wall_says = (string) ( $qsoft_wall_field['instructions'] ?? '' );

	if ( '' === $qsoft_wall_says ) {
		continue;
	}

	$qsoft_wall_said[] = isset( $qsoft_wall_seen[ $qsoft_wall_says ] );

	$qsoft_wall_seen[ $qsoft_wall_says ] = true;
}

qsoft_assert(
	! in_array( true, $qsoft_wall_said, true ),
	'a field type is explained once in a tab, not under all four of its links'
);

qsoft_assert(
	1 === ( $qsoft_wall_group['menu_order'] ?? 0 ),
	'the footer sits under the header when the two share the options screen',
	$qsoft_wall_group['menu_order'] ?? null
);

/*
 * The copyright line is read as words rather than as markup, because the year
 * is written into the words. Offered as a rich field it kept the design's own
 * `<span id="year">`, which the page then escaped and printed — "© 2026 <span
 * id="year"></span> Fixture Co." on every page.
 */
$qsoft_wall_dated = null;

foreach ( (array) ( $qsoft_wall_group['fields'] ?? array() ) as $qsoft_wall_field ) {
	$qsoft_wall_default = $qsoft_wall_field['default_value'] ?? '';

	// A link's default is its address and its words, which is an array.
	if ( is_string( $qsoft_wall_default ) && str_contains( $qsoft_wall_default, 'All rights reserved' ) ) {
		$qsoft_wall_dated = $qsoft_wall_field;
	}
}

qsoft_assert(
	is_array( $qsoft_wall_dated ) && ! str_contains( (string) $qsoft_wall_dated['default_value'], '<' ),
	'a copyright line is kept as words, so the year can be written into them',
	$qsoft_wall_dated['default_value'] ?? null
);

/*
 * And a group short enough to read at a glance is left alone: a row of tabs
 * over five fields is one more thing between an editor and the field they came
 * for.
 */
$qsoft_short_dir = sys_get_temp_dir() . '/qsoft-short-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write(
	$qsoft_footer_html,
	$qsoft_footer_plan,
	'site-footer-short1',
	'Site footer',
	'',
	$qsoft_short_dir,
	'index.html',
	'option'
);

$qsoft_short_group = json_decode( (string) file_get_contents( $qsoft_short_dir . '/fields.json' ), true );

qsoft_assert(
	0 === count( array_filter( (array) $qsoft_short_group['fields'], static fn( array $field ): bool => 'tab' === ( $field['type'] ?? '' ) ) ),
	'a group short enough to read at a glance is not divided'
);

/*
 * The same section, kept with the block instead of with the site: the sidebar
 * is a column about 280 pixels across, where five tabs wrap into a stack of
 * stubs and a field given a third of the width is 90 pixels of box under a
 * two-line label. So the division is drawn down the page as accordions, and
 * nothing is put side by side.
 */
$qsoft_side_dir = sys_get_temp_dir() . '/qsoft-side-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write(
	$qsoft_wall_html,
	$qsoft_wall_plan,
	'design-wall01',
	'A section',
	'',
	$qsoft_side_dir,
	'index.html'
);

$qsoft_side_group = json_decode( (string) file_get_contents( $qsoft_side_dir . '/fields.json' ), true );
$qsoft_side_kinds = array();
$qsoft_side_wide  = 0;

foreach ( (array) ( $qsoft_side_group['fields'] ?? array() ) as $qsoft_side_field ) {
	$qsoft_side_kinds[] = (string) $qsoft_side_field['type'];

	if ( isset( $qsoft_side_field['wrapper']['width'] ) ) {
		++$qsoft_side_wide;
	}
}

qsoft_assert(
	in_array( 'accordion', $qsoft_side_kinds, true ) && ! in_array( 'tab', $qsoft_side_kinds, true ),
	'a section kept with its block divides down the sidebar rather than across it',
	$qsoft_side_kinds
);

qsoft_assert(
	0 === $qsoft_side_wide,
	'and nothing in the sidebar is put side by side',
	$qsoft_side_wide
);

$qsoft_wall_wide = 0;

foreach ( (array) ( $qsoft_wall_group['fields'] ?? array() ) as $qsoft_wall_field ) {
	if ( isset( $qsoft_wall_field['wrapper']['width'] ) ) {
		++$qsoft_wall_wide;
	}
}

qsoft_assert(
	$qsoft_wall_wide > 0,
	'while the Site content screen, which is as wide as the page, still puts a run of links in a row',
	$qsoft_wall_wide
);

/*
 * A divider is a handle, not the sentence the design wrote on the heading it
 * came from. "Complete AI agent go-live review" over a tab strip is a strip of
 * one tab.
 */
foreach ( array( $qsoft_wall_group, $qsoft_side_group ) as $qsoft_hand_group ) {
	foreach ( (array) ( $qsoft_hand_group['fields'] ?? array() ) as $qsoft_hand_field ) {
		if ( ! in_array( (string) $qsoft_hand_field['type'], array( 'tab', 'accordion' ), true ) ) {
			continue;
		}

		qsoft_assert(
			mb_strlen( (string) $qsoft_hand_field['label'] ) <= 28,
			sprintf( 'a divider called "%s" is short enough to be a handle', $qsoft_hand_field['label'] )
		);
	}
}

foreach ( array( $qsoft_wall_dir, $qsoft_short_dir, $qsoft_side_dir ) as $qsoft_wall_gone ) {
	array_map( 'unlink', (array) glob( $qsoft_wall_gone . '/*' ) );
	rmdir( $qsoft_wall_gone );
}

/*
 * The header is the one thing a visitor sees on every page, and it used to be
 * the one part of a design thrown away and rebuilt from the theme's own site
 * title. Wrapped like everything else it keeps its brand, its call to action
 * and its classes — while the list of links inside it stays a real menu, so
 * publishing a page does not also mean editing a block.
 */
$qsoft_hdr_html = '<header class="site-header"><div class="nav-wrap">'
	. '<a class="brand" href="index.html"><span class="brand-mark">RK</span><span class="brand-text">Studio</span></a>'
	. '<nav class="primary-nav"><!--qs:menu--></nav>'
	. '<a class="btn nav-cta" href="contact.html">Get in touch</a>'
	. '</div></header>';

$qsoft_hdr_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_hdr_html );
$qsoft_hdr_dir  = sys_get_temp_dir() . '/qsoft-hdr-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write( $qsoft_hdr_html, $qsoft_hdr_plan, 'site-header-abc123', 'Header', '', $qsoft_hdr_dir, 'i.html', 'option', true );

$qsoft_hdr_php = (string) file_get_contents( $qsoft_hdr_dir . '/render.php' );

foreach ( array( 'site-header', 'nav-wrap', 'brand', 'brand-mark', 'brand-text', 'primary-nav', 'nav-cta' ) as $qsoft_hdr_class ) {
	qsoft_assert(
		false !== strpos( $qsoft_hdr_php, $qsoft_hdr_class ),
		sprintf( 'the header keeps its own "%s"', $qsoft_hdr_class )
	);
}

qsoft_assert(
	false !== strpos( $qsoft_hdr_php, 'DesignField::menu()' ),
	'and renders whatever menu the site has, looked up when the page is drawn'
);

qsoft_assert(
	false === strpos( $qsoft_hdr_php, 'qs:menu' ),
	'with nothing of the marker left behind'
);

/*
 * A link built out of elements keeps them. The brand is an anchor wrapping a
 * badge and a wordmark; writing a text field into it took both away, and the
 * stylesheet was left with rules for elements that no longer existed.
 */
qsoft_assert(
	1 === substr_count( $qsoft_hdr_php, 'brand-mark' ) && 1 === substr_count( $qsoft_hdr_php, 'brand-text' ),
	'a link made of markup keeps its markup, and only its address becomes a field'
);

foreach ( (array) glob( $qsoft_hdr_dir . '/*' ) as $qsoft_leftover ) {
	unlink( (string) $qsoft_leftover );
}

rmdir( $qsoft_hdr_dir );

/*
 * Two footers from two designs must not write to the same rows.
 *
 * ACF keeps an option under `options_{field name}` and that key is the whole
 * of its identity — the group it belongs to does not appear in it. So a footer
 * calling a field `link` and the next design's footer doing the same wrote to
 * one row, and because seeding leaves an existing value alone, the second site
 * quietly displayed the first one's address. It was found by two designs'
 * footers turning up mixed together on one screen.
 */
$qsoft_ns_a = '<footer class="f"><p>Studio A</p><a href="a.html">Contact A</a></footer>';
$qsoft_ns_b = '<footer class="f"><p>Studio B</p><a href="b.html">Contact B</a></footer>';
$qsoft_ns   = array();

foreach ( array( 'site-footer-aaa111' => $qsoft_ns_a, 'site-footer-bbb222' => $qsoft_ns_b ) as $qsoft_ns_slug => $qsoft_ns_html ) {
	$qsoft_ns_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_ns_html );
	$qsoft_ns_dir  = sys_get_temp_dir() . '/qsoft-ns-' . $qsoft_ns_slug . '-' . getmypid();

	Qwerty\Soft\Support\BlockWriter::write( $qsoft_ns_html, $qsoft_ns_plan, $qsoft_ns_slug, 'Footer', '', $qsoft_ns_dir, '', 'option' );

	$qsoft_ns_group = json_decode( (string) file_get_contents( $qsoft_ns_dir . '/fields.json' ), true );

	preg_match_all(
		"/DesignField::site\( '([a-z0-9_]+)' \)/",
		(string) file_get_contents( $qsoft_ns_dir . '/render.php' ),
		$qsoft_ns_read
	);

	$qsoft_ns[ $qsoft_ns_slug ] = array(
		'stored' => array_keys(
			array_filter(
				Qwerty\Soft\Support\BlockWriter::option_values(
					$qsoft_ns_slug,
					$qsoft_ns_plan,
					Qwerty\Soft\Support\BlockWriter::values( $qsoft_ns_html, $qsoft_ns_plan )
				),
				static fn( $k ) => str_starts_with( (string) $k, 'options_' ),
				ARRAY_FILTER_USE_KEY
			)
		),
		'fields' => array_column( (array) $qsoft_ns_group['fields'], 'name' ),
		'reads'  => array_unique( $qsoft_ns_read[1] ),
	);

	foreach ( (array) glob( $qsoft_ns_dir . '/*' ) as $qsoft_leftover ) {
		unlink( (string) $qsoft_leftover );
	}

	rmdir( $qsoft_ns_dir );
}

qsoft_assert(
	array() === array_intersect( $qsoft_ns['site-footer-aaa111']['stored'], $qsoft_ns['site-footer-bbb222']['stored'] ),
	'two designs\' footers write to different option rows'
);

/*
 * All three have to agree, or the block writes where the template does not
 * read and the page comes out blank — the exact failure this guards.
 */
foreach ( $qsoft_ns as $qsoft_ns_slug => $qsoft_ns_seen ) {
	$qsoft_ns_expected = array_map(
		static fn( string $n ): string => 'options_' . $n,
		$qsoft_ns_seen['fields']
	);

	qsoft_assert(
		array() === array_diff( $qsoft_ns_expected, $qsoft_ns_seen['stored'] ),
		sprintf( '%s: the field group and the stored rows agree', $qsoft_ns_slug )
	);

	qsoft_assert(
		array() === array_diff( $qsoft_ns_seen['reads'], $qsoft_ns_seen['fields'] ),
		sprintf( '%s: the template reads only fields the group defines', $qsoft_ns_slug )
	);
}

foreach ( (array) glob( $qsoft_chrome_dir . '/*' ) as $qsoft_leftover ) {
	unlink( (string) $qsoft_leftover );
}

rmdir( $qsoft_chrome_dir );

// ------------------------------------------- the block draws without ACF

/*
 * The failure this guards against was silent and total. A generated block
 * named only `acf.renderTemplate`, so on a site without ACF Pro WordPress had
 * nothing to render it with: every page came out structurally correct and
 * visually empty — an `h1` and nothing else — with no error anywhere to say
 * why, and the editor showing "your site doesn't include support for this
 * block". The words were already in the block; only the renderer was missing.
 */
echo "\n=== BlockWriter — a section still draws when ACF is not installed ===\n";

$qsoft_manifest_2 = json_decode( Qwerty\Soft\Support\BlockWriter::block_json( 'home-hero', 'Hero', false ), true );

qsoft_assert(
	'file:./render.php' === ( $qsoft_manifest_2['render'] ?? '' ),
	'the manifest names WordPress\'s own renderer, not only ACF\'s'
);

qsoft_assert(
	'render.php' === ( $qsoft_manifest_2['acf']['renderTemplate'] ?? '' ),
	'and still names ACF\'s, so the fields stay editable where the plugin is there'
);

qsoft_assert(
	false === strpos( $qsoft_hero_render, 'get_field(' ),
	'the template never calls get_field() directly, which would be fatal without the plugin'
);

/*
 * The stamp is what lets a rebuild replace a block written before this fix
 * while leaving alone every block somebody has since edited by hand.
 */
qsoft_assert(
	Qwerty\Soft\Support\BlockWriter::VERSION === ( $qsoft_manifest_2['qsDesignVersion'] ?? 0 ),
	'a generated block records which writer made it'
);

qsoft_assert(
	! Qwerty\Soft\Support\BlockWriter::current( sys_get_temp_dir() . '/qsoft-nothing-here' ),
	'a block that does not exist is not mistaken for a current one'
);

/*
 * Two hyphens end an HTML comment, and a block's attributes live inside one.
 * A date range, or an em-dash somebody typed as `--`, would close it early and
 * spill the rest of the JSON onto the page as text.
 */
$qsoft_dash_html = '<section class="x"><p>The 2019--2024 range</p></section>';
$qsoft_dash_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_dash_html );

$qsoft_dash_instance = Qwerty\Soft\Support\BlockWriter::instance(
	'x',
	$qsoft_dash_plan,
	Qwerty\Soft\Support\BlockWriter::values( $qsoft_dash_html, $qsoft_dash_plan )
);

$qsoft_dash_json = qsoft_block_attributes( $qsoft_dash_instance );

qsoft_assert(
	false === strpos(
		(string) substr(
			$qsoft_dash_instance,
			(int) strpos( $qsoft_dash_instance, '{' ),
			( (int) strrpos( $qsoft_dash_instance, '}' ) ) - ( (int) strpos( $qsoft_dash_instance, '{' ) ) + 1
		),
		'--'
	),
	'a value holding two hyphens cannot close the block comment it sits in'
);

qsoft_assert(
	'The 2019--2024 range' === ( $qsoft_dash_json['data']['text'] ?? '' ),
	'and the words are read back exactly as the designer wrote them'
);

qsoft_assert(
	'qs/design-x' === ( $qsoft_dash_json['name'] ?? '' ),
	'the block names itself with one slash, not an escaped backslash'
);

/*
 * The scope used to be whatever the last write() left behind, so a page
 * section rendered after a footer produced a template reading the options
 * page — correct only for as long as nobody called things in another order.
 */
$qsoft_scope_dir = sys_get_temp_dir() . '/qsoft-scope-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write( $qsoft_footer_html, $qsoft_footer_plan, 'f', 'Footer', '', $qsoft_scope_dir, '', 'option' );

qsoft_assert(
	false === strpos( (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_hero_html, $qsoft_hero_plan ), '::site(' ),
	'a section rendered after a footer does not inherit the footer\'s scope'
);

foreach ( (array) glob( $qsoft_scope_dir . '/*' ) as $qsoft_leftover ) {
	unlink( (string) $qsoft_leftover );
}

rmdir( $qsoft_scope_dir );

/*
 * A rebuild must speak the names the block already reads.
 *
 * A block that exists is never rewritten, so its template keeps the names it
 * was built with. The values for a rebuild come from a fresh plan, and the
 * model that names a fresh plan is under no obligation to answer the same way
 * twice — so the template would read `heading` while the block carried
 * `title`, and every field on the page would render empty. Silently, and only
 * on the second build.
 */
$qsoft_adopt_dir = sys_get_temp_dir() . '/qsoft-adopt-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write( $qsoft_hero_html, $qsoft_hero_plan, 'home-hero', 'Hero', '', $qsoft_adopt_dir );

// What a second build would come back with: the same section, renamed.
$qsoft_renamed              = $qsoft_hero_plan;
$qsoft_renamed['fields'][1] = array_merge( $qsoft_renamed['fields'][1], array( 'name' => 'title' ) );

qsoft_assert(
	'title' === $qsoft_renamed['fields'][1]['name'],
	'the second reading really does disagree with the first'
);

$qsoft_adopted = Qwerty\Soft\Support\BlockWriter::adopt( $qsoft_renamed, $qsoft_adopt_dir );

qsoft_assert(
	'heading' === $qsoft_adopted['fields'][1]['name'],
	'a rebuild takes the names from the block rather than from the new reading'
);

// A section that genuinely changed must not have its fields zipped onto the old ones.
$qsoft_shorter           = $qsoft_renamed;
$qsoft_shorter['fields'] = array_slice( $qsoft_shorter['fields'], 0, 2 );

qsoft_assert(
	'title' === Qwerty\Soft\Support\BlockWriter::adopt( $qsoft_shorter, $qsoft_adopt_dir )['fields'][1]['name'],
	'a plan of a different shape is left alone rather than mismatched'
);

foreach ( (array) glob( $qsoft_adopt_dir . '/*' ) as $qsoft_leftover ) {
	unlink( (string) $qsoft_leftover );
}

rmdir( $qsoft_adopt_dir );

/*
 * The check that was missing, and its absence cost a whole build.
 *
 * The writer emits PHP as text, so a mistake in escaping produces a file that
 * is confidently wrong rather than obviously broken: `$block` written into a
 * double-quoted string was interpolated at generation time and vanished,
 * leaving `rows( 'items',  ?? null )` on every page of a design. Asserting on
 * substrings would not have caught it — only asking PHP to parse the result
 * does.
 */
foreach (
	array(
		'hero' => $qsoft_hero_render,
		'listing' => $qsoft_cards_render,
		'repeat' => $qsoft_tiles_render,
		'dated footer' => $qsoft_footer_php,
		'chrome' => $qsoft_chrome_php,
	) as $qsoft_what => $qsoft_code
) {
	$qsoft_parses = true;

	try {
		token_get_all( $qsoft_code, TOKEN_PARSE );
	} catch ( ParseError $qsoft_broken ) {
		$qsoft_parses = false;

		echo '        ' . $qsoft_broken->getMessage() . "\n";
	}

	qsoft_assert( $qsoft_parses, sprintf( 'the %s template is valid PHP', $qsoft_what ) );
}


// ------------------------------------------- six cards become six records

/*
 * The whole point of telling a listing apart from a repeat.
 *
 * Six report cards held as content mean adding a seventh report by editing a
 * page. Held as records they mean pressing Add New, and the card the designer
 * drew is the template that draws it. That is the difference between handing
 * over a site and handing over a mockup.
 */
echo "\n=== DesignType — a listing becomes something to add to ===\n";

qsoft_assert( 'qs_report' === Qwerty\Soft\Support\DesignType::key( 'Report' ), 'a name becomes a post type key' );
qsoft_assert( 'qs_case_study' === Qwerty\Soft\Support\DesignType::key( 'Case study' ), 'two words become one key' );
qsoft_assert( '' === Qwerty\Soft\Support\DesignType::key( '  ' ), 'a name that says nothing makes no key' );

/*
 * WordPress refuses a post type key longer than twenty characters, and refuses
 * it by doing nothing — the type simply never exists, and the listing that
 * needed it draws the last three posts instead.
 */
qsoft_assert(
	20 >= strlen( Qwerty\Soft\Support\DesignType::key( 'Extremely long name of a thing' ) ),
	'a long name is cut to what WordPress will accept'
);

/*
 * The same word from two sections has to give the same key, or the home page's
 * three reports and the reports page's six become two lists that drift apart.
 */
qsoft_assert(
	Qwerty\Soft\Support\DesignType::key( 'Report' ) === Qwerty\Soft\Support\DesignType::key( 'report' ),
	'two sections naming the same thing share one list'
);

foreach ( array( 'Report' => 'Reports', 'Case study' => 'Case studies', 'Analysis' => 'Analyses', 'Company' => 'Companies' ) as $qsoft_one => $qsoft_many ) {
	qsoft_assert(
		$qsoft_many === Qwerty\Soft\Support\DesignType::plural( $qsoft_one ),
		sprintf( '"%s" is listed as "%s"', $qsoft_one, $qsoft_many )
	);
}

$qsoft_cards_type = Qwerty\Soft\Support\DesignType::describe(
	'Report',
	(array) ( $qsoft_cards_plan['item']['fields'] ?? array() )
);

qsoft_assert( null !== $qsoft_cards_type, 'a listing describes a type to hold its records' );
qsoft_assert( 'qs_report' === ( $qsoft_cards_type['key'] ?? '' ), 'named after the thing, not after the section' );
qsoft_assert( 'Reports' === ( $qsoft_cards_type['plural'] ?? '' ), 'and listed in the admin under its plural' );

qsoft_assert(
	array() !== (array) ( $qsoft_cards_type['fields'] ?? array() ),
	'the record carries the card\'s own fields, so nothing the designer drew is lost'
);

qsoft_assert(
	null === Qwerty\Soft\Support\DesignType::describe( 'Report', array() ),
	'a listing with nothing on its cards describes no type'
);

// The block must actually read that type rather than whatever posts exist.
$qsoft_type_dir = sys_get_temp_dir() . '/qsoft-type-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write( $qsoft_cards_html, $qsoft_cards_plan, 'home-reports', 'Reports', '', $qsoft_type_dir, 'i.html', 'block', false, 'Report' );

$qsoft_type_php = (string) file_get_contents( $qsoft_type_dir . '/render.php' );

qsoft_assert(
	false !== strpos( $qsoft_type_php, "'qs_report'" ),
	'the listing block draws its own records rather than the last three posts'
);

qsoft_assert(
	is_file( $qsoft_type_dir . '/type.json' ),
	'and the type is described beside the block, so it goes when the import goes'
);

$qsoft_type_json = json_decode( (string) file_get_contents( $qsoft_type_dir . '/type.json' ), true );

qsoft_assert( 'qs_report' === ( $qsoft_type_json['key'] ?? '' ), 'the description names the type' );

$qsoft_type_group = Qwerty\Soft\Support\DesignType::group( $qsoft_type_json );

qsoft_assert(
	'post_type' === ( $qsoft_type_group['location'][0][0]['param'] ?? '' ),
	'the record\'s fields are attached to its own editing screen'
);

qsoft_assert(
	array_column( (array) $qsoft_type_group['fields'], 'name' ) === array_column( (array) $qsoft_type_json['fields'], 'name' ),
	'and are exactly the card\'s fields, by name — which is how a record draws right'
);

/*
 * A listing whose records nobody named falls back to ordinary posts rather
 * than to nothing. A grid showing the last three posts is wrong in a way
 * somebody notices and fixes; a grid showing nothing reads as a failed import.
 */
$qsoft_unnamed_dir = sys_get_temp_dir() . '/qsoft-unnamed-' . getmypid();

Qwerty\Soft\Support\BlockWriter::write( $qsoft_cards_html, $qsoft_cards_plan, 'home-reports-2', 'Reports', '', $qsoft_unnamed_dir, 'i.html' );

qsoft_assert(
	! is_file( $qsoft_unnamed_dir . '/type.json' ),
	'a listing the model did not name describes no type'
);

foreach ( array( $qsoft_type_dir, $qsoft_unnamed_dir ) as $qsoft_dir ) {
	foreach ( (array) glob( $qsoft_dir . '/*' ) as $qsoft_leftover ) {
		unlink( (string) $qsoft_leftover );
	}

	rmdir( $qsoft_dir );
}


// ------------------------------------------- forms, shops, and the shop kit

/*
 * The two halves of "the theme ships from zero". A design that needs a shop
 * has to be recognised as needing one — and the shop half it needs has to be
 * somewhere to be added from. A design that needs a form needs nothing
 * installed at all, which is the finding this checks does not regress into a
 * plugin recommendation.
 */
echo "\n=== DesignNeeds, ShopKit, DesignForm — what an archive asks the site for ===\n";

$qsoft_form_html = '<section class="contact-section"><h2>Talk to us</h2>'
	. '<form class="contact-form" action="#" method="get">'
	. '<label for="n">Name</label><input id="n" name="name" type="text">'
	. '<label for="e">Email</label><input id="e" name="email" type="email">'
	. '<label for="m">Message</label><textarea id="m" name="message" rows="5"></textarea>'
	. '<button type="submit" class="btn btn-primary">Send</button>'
	. '</form></section>';

$qsoft_form_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_form_html );
$qsoft_form_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_form_html, $qsoft_form_plan );

qsoft_assert(
	false !== strpos( $qsoft_form_render, 'DesignForm::fields()' ),
	'an imported form gets the hidden half the handler reads'
);

qsoft_assert(
	false !== strpos( $qsoft_form_render, 'DesignForm::action()' ),
	'and posts where the handler is listening'
);

qsoft_assert(
	false !== strpos( $qsoft_form_render, 'method="post"' ),
	'by POST, whatever the design had it set to'
);

foreach ( array( 'qsoft_name', 'qsoft_email', 'qsoft_message' ) as $qsoft_field ) {
	qsoft_assert(
		false !== strpos( $qsoft_form_render, 'name="' . $qsoft_field . '"' ),
		sprintf( 'the control the handler reads as %s is named for it', $qsoft_field )
	);
}

foreach ( array( 'contact-section', 'contact-form', 'btn-primary' ) as $qsoft_class ) {
	qsoft_assert(
		false !== strpos( $qsoft_form_render, $qsoft_class ),
		sprintf( 'and the form keeps its own class "%s"', $qsoft_class )
	);
}

/*
 * The one form that must be left alone. Wiring a search box to the contact
 * handler would email the studio every search anybody ever ran.
 */
$qsoft_search_html   = '<section class="find"><form role="search" class="search-form" action="/">'
	. '<input type="search" name="s" placeholder="Search"><button type="submit">Go</button></form></section>';
$qsoft_search_render = (string) Qwerty\Soft\Support\BlockWriter::render_php(
	$qsoft_search_html,
	Qwerty\Soft\Support\SectionPlan::of( $qsoft_search_html )
);

qsoft_assert(
	false === strpos( $qsoft_search_render, 'DesignForm::' ),
	'a search box is not a contact form and is left as it was drawn'
);

$qsoft_needs_dir = sys_get_temp_dir() . '/qsoft-needs-' . getmypid();

qsoft_write_files(
	$qsoft_needs_dir,
	array(
		'Shop.html'    => '<section class="products"><article class="card"><h3>A thing</h3>'
			. '<button class="btn" data-sku="A-1">Add to cart</button></article></section>',
		'Contact.html' => $qsoft_form_html,
		'Search.html'  => $qsoft_search_html,
		'app.min.js'   => 'var addToCart=1;/* minified, and skipped */',
	)
);

$qsoft_needs = Qwerty\Soft\Support\DesignNeeds::scan( $qsoft_needs_dir );
$qsoft_keys  = array_column( $qsoft_needs, 'key' );

qsoft_assert(
	in_array( 'woocommerce', $qsoft_keys, true ),
	'an add-to-cart button in the markup asks for a shop, with no catalogue file in sight'
);

qsoft_assert(
	in_array( 'contact-form', $qsoft_keys, true ),
	'a form in the markup is reported as a finding'
);

$qsoft_shop_row = $qsoft_needs[ (int) array_search( 'woocommerce', $qsoft_keys, true ) ];
$qsoft_form_row = $qsoft_needs[ (int) array_search( 'contact-form', $qsoft_keys, true ) ];

qsoft_assert(
	'' === (string) $qsoft_form_row['plugin'],
	'and the form finding recommends no plugin — the theme answers that one itself'
);

qsoft_assert(
	1 === (int) $qsoft_form_row['forms'],
	'the search box is not counted as a form to wire up'
);

qsoft_assert(
	false !== strpos( (string) $qsoft_shop_row['why'], 'Shop.html' ),
	'the shop recommendation names the file that gave the shop away'
);

qsoft_assert(
	false === $qsoft_shop_row['ready'] && '' !== (string) $qsoft_shop_row['button'],
	'and offers the button that installs WooCommerce and the shop templates'
);

qsoft_remove_tree( $qsoft_needs_dir );

/*
 * The shop half itself. It ships folded, one directory to the side of where
 * WordPress looks, and a clean checkout has none of it unfolded — which is
 * the whole claim: the theme a client activates has no shop in it.
 */
$qsoft_kit = Qwerty\Soft\Support\ShopKit::files();

qsoft_assert(
	Qwerty\Soft\Support\ShopKit::available() && count( $qsoft_kit ) >= 8,
	'the folded shop kit is in the theme, templates and module together'
);

qsoft_assert(
	isset( $qsoft_kit['shop-kit/templates/cart.html'] )
		&& 'templates/cart.html' === $qsoft_kit['shop-kit/templates/cart.html']
		&& 'inc/Modules/WooCommerce.php' === ( $qsoft_kit['shop-kit/inc/Modules/WooCommerce.php'] ?? '' ),
	'and every file in it knows where in the theme it belongs'
);

qsoft_assert(
	! Qwerty\Soft\Support\ShopKit::installed(),
	'a checkout with nothing to sell has no shop templates unfolded'
);

foreach ( array_values( $qsoft_kit ) as $qsoft_destination ) {
	qsoft_assert(
		! is_file( $qsoft_root . '/' . $qsoft_destination ),
		sprintf( 'and %s is not in the theme until a design asks for it', $qsoft_destination )
	);
}

echo "\n=== BlockWriter — the section keeps what the designer drew, and says where each field is ===\n";

/*
 * A heading with a highlighted half and a deliberate line break is one
 * heading. Flattened to words it read "Program,Built" on a live site, with
 * the highlight's rule left targeting nothing.
 */
$qsoft_rich_html = '<section class="hero"><h1><span class="gradient-text">Your Program,</span><br />Built.</h1><p class="lead">Plain words.</p></section>';
$qsoft_rich_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_rich_html );
$qsoft_rich_head = null;
$qsoft_rich_lead = null;

foreach ( $qsoft_rich_plan['fields'] as $qsoft_field ) {
	if ( 'heading' === $qsoft_field['name'] ) {
		$qsoft_rich_head = $qsoft_field;
	}

	if ( 'lead' === $qsoft_field['name'] ) {
		$qsoft_rich_lead = $qsoft_field;
	}
}

qsoft_assert(
	is_array( $qsoft_rich_head ) && ! empty( $qsoft_rich_head['rich'] ),
	'a heading holding inline markup is planned as a rich field'
);

qsoft_assert(
	is_array( $qsoft_rich_lead ) && empty( $qsoft_rich_lead['rich'] ),
	'and a paragraph of plain words is not'
);

$qsoft_rich_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_rich_html, $qsoft_rich_plan );

qsoft_assert(
	false !== strpos( (string) ( $qsoft_rich_values['heading'] ?? '' ), '<span class="gradient-text">Your Program,</span>' )
		&& false !== strpos( (string) ( $qsoft_rich_values['heading'] ?? '' ), '<br>' ),
	'its value keeps the span, its class and the line break'
);

$qsoft_rich_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_rich_html, $qsoft_rich_plan );

qsoft_assert(
	false !== strpos( $qsoft_rich_render, 'DesignField::inline(' )
		&& false !== strpos( $qsoft_rich_render, 'data-qs-field="heading" data-qs-type="rich"' ),
	'the template echoes it through inline() and marks the element as that field'
);

qsoft_assert(
	false !== strpos( $qsoft_rich_render, 'data-qs-field="lead" data-qs-type="textarea"' ),
	'and a plain field is marked with its own type'
);

qsoft_assert(
	false === strpos( Qwerty\Soft\Support\DesignField::unmarked( '<h1 data-qs-field="heading" data-qs-type="rich">A</h1><li data-qs-row="items" data-qs-index="2">b</li>' ), 'data-qs-' ),
	'the marks come off for a visitor'
);

$qsoft_rich_fields = json_decode( Qwerty\Soft\Support\BlockWriter::fields_json( 'hero', 'Hero', $qsoft_rich_plan, $qsoft_rich_html ), true );
$qsoft_rich_acf    = null;

foreach ( (array) ( $qsoft_rich_fields['fields'] ?? array() ) as $qsoft_field ) {
	if ( 'heading' === ( $qsoft_field['name'] ?? '' ) ) {
		$qsoft_rich_acf = $qsoft_field;
	}
}

qsoft_assert(
	is_array( $qsoft_rich_acf ) && 'textarea' === $qsoft_rich_acf['type'] && false !== strpos( (string) $qsoft_rich_acf['instructions'], '<br>' ),
	'the field group gives it a box tall enough for markup and says which tags it keeps'
);

/*
 * The sanitiser is the escaping for that field, so it has to hold on both
 * sides: keep the tags a design uses, drop everything that could carry script.
 */
$qsoft_dirty = '<span class="x" onclick="evil()">A</span><script>alert(1)</script><b>B</b><a href="javascript:evil()" class="l">c</a><div>d</div>';
$qsoft_clean = Qwerty\Soft\Support\DesignField::inline( $qsoft_dirty );

qsoft_assert(
	false !== strpos( $qsoft_clean, '<span class="x">A</span>' )
		&& false !== strpos( $qsoft_clean, '<b>B</b>' )
		&& false === strpos( $qsoft_clean, 'onclick' )
		&& false === strpos( $qsoft_clean, '<script' )
		&& false === strpos( $qsoft_clean, 'javascript:' )
		&& false === strpos( $qsoft_clean, '<div' ),
	'inline() keeps the allowed tags and their class, and strips handlers, scripts, script URLs and block tags'
);

/*
 * A button that is words plus an icon. The words used to stay frozen in the
 * template while the field carried a title nothing read.
 */
$qsoft_icon_html   = '<section><a class="btn" href="contact.html">Talk to us <svg viewBox="0 0 1 1"><path d="M0 0"/></svg></a></section>';
$qsoft_icon_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_icon_html );
$qsoft_icon_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_icon_html, $qsoft_icon_plan );

qsoft_assert(
	false !== strpos( $qsoft_icon_render, 'DesignField::link_text(' )
		&& false !== strpos( $qsoft_icon_render, '?> <svg' )
		&& false === strpos( $qsoft_icon_render, '>Talk to us <svg' ),
	'a link with an icon in it gets its words from the field and keeps the icon'
);

$qsoft_brand_html   = '<section><a class="brand" href="/"><span class="mark"></span><span class="wordmark">Acme</span></a></section>';
$qsoft_brand_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_brand_html );
$qsoft_brand_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_brand_html, $qsoft_brand_plan );

qsoft_assert(
	false !== strpos( $qsoft_brand_render, '<span class="mark"></span>' )
		&& false !== strpos( $qsoft_brand_render, '<span class="wordmark" data-qs-field="wordmark"' )
		&& false === strpos( $qsoft_brand_render, 'link_text(' ),
	'while a link whose words live in its own elements keeps the structure and makes the wordmark its own field'
);

/*
 * A `<picture>` shows its `<source>` before its `<img>`, so a replaced
 * picture kept drawing the archive's own file.
 */
$qsoft_pic_html   = '<section><picture><source srcset="a.webp" type="image/webp"><img src="a.jpg" alt="A"></picture></section>';
$qsoft_pic_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_pic_html );
$qsoft_pic_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_pic_html, $qsoft_pic_plan );

qsoft_assert(
	false === strpos( $qsoft_pic_render, '<source' ) && false !== strpos( $qsoft_pic_render, 'data-qs-type="image"' ),
	'a picture that becomes a field loses its sources, so the field is what is shown'
);

/*
 * The holder of a repeat is not always only rows. A grid's heading and its
 * "view all" link used to be deleted along with the extra rows — or, when
 * the cards came after the heading, the heading was kept as the row.
 */
$qsoft_grid_html = '<section class="cases"><div class="grid"><h2>Our work</h2>'
	. '<article class="card"><h3>Alpha</h3></article><article class="card"><h3>Beta</h3></article><article class="card"><h3>Gamma</h3></article>'
	. '<a class="all" href="cases.html">All cases</a></div></section>';
$qsoft_grid_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_grid_html );

qsoft_assert(
	'repeat' === $qsoft_grid_plan['kind'] && 'article.card' === ( $qsoft_grid_plan['item']['selector'] ?? '' ),
	'three cards beside a heading and a link are still a repeat of cards'
);

$qsoft_grid_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_grid_html, $qsoft_grid_plan );
$qsoft_grid_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_grid_html, $qsoft_grid_plan );

qsoft_assert(
	false !== strpos( $qsoft_grid_render, '<h2' )
		&& false !== strpos( $qsoft_grid_render, 'class="all"' )
		&& 1 === substr_count( $qsoft_grid_render, 'class="card"' ),
	'the template keeps the heading and the link and one card'
);

qsoft_assert(
	3 === count( (array) ( $qsoft_grid_values['items'] ?? array() ) )
		&& 'Gamma' === ( $qsoft_grid_values['items'][2]['heading'] ?? $qsoft_grid_values['items'][2]['subheading'] ?? '' ),
	'and the values read three rows, none of them the heading'
);

qsoft_assert(
	false !== strpos( $qsoft_grid_render, 'data-qs-row="items"' )
		&& false !== strpos( $qsoft_grid_render, 'as $qsoft_i => $qsoft_row' )
		&& false !== strpos( $qsoft_grid_render, '(int) ( $qsoft_i ?? 0 )' ),
	'each rendered row is marked with which row it is'
);

/*
 * The outermost list is the list. Three cards each holding four bullets used
 * to lose to the first card's four bullets, so one card got a repeater and
 * two got flat fields.
 */
$qsoft_cards = '';

foreach ( array( 'One', 'Two', 'Three' ) as $qsoft_n ) {
	$qsoft_cards .= '<article class="card"><h3>' . $qsoft_n . '</h3><ul>'
		. '<li>' . $qsoft_n . ' a</li><li>' . $qsoft_n . ' b</li><li>' . $qsoft_n . ' c</li><li>' . $qsoft_n . ' d</li>'
		. '</ul></article>';
}

$qsoft_nested_html = '<section><div class="grid">' . $qsoft_cards . '</div></section>';
$qsoft_nested_plan = Qwerty\Soft\Support\SectionPlan::of( $qsoft_nested_html );

qsoft_assert(
	'repeat' === $qsoft_nested_plan['kind'] && 'article.card' === ( $qsoft_nested_plan['item']['selector'] ?? '' ),
	'cards each holding a list of bullets are a repeat of the card, not of the first card\'s bullets'
);

qsoft_assert(
	5 === count( (array) ( $qsoft_nested_plan['item']['fields'] ?? array() ) ),
	'and a row holds the heading and every bullet, so no card is shaped differently from the others'
);

/*
 * The same cards, each with a different icon glyph in an element that is
 * not a field. Now the rows diverge, the section is kept whole — and every
 * card gets the same flat treatment rather than the first one a repeater.
 */
$qsoft_iconed = '';

foreach ( array( '◆|One', '⌁|Two', '↻|Three' ) as $qsoft_pair ) {
	list( $qsoft_glyph, $qsoft_n ) = explode( '|', $qsoft_pair );

	$qsoft_iconed .= '<article class="card"><div class="icon">' . $qsoft_glyph . '</div><h3>' . $qsoft_n . '</h3><ul>'
		. '<li>' . $qsoft_n . ' a</li><li>' . $qsoft_n . ' b</li><li>' . $qsoft_n . ' c</li><li>' . $qsoft_n . ' d</li>'
		. '</ul></article>';
}

$qsoft_iconed_html   = '<section><div class="grid">' . $qsoft_iconed . '</div></section>';
$qsoft_iconed_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_iconed_html );
$qsoft_iconed_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_iconed_html, $qsoft_iconed_plan );

qsoft_assert(
	'repeat' === $qsoft_iconed_plan['kind']
		&& 6 === count( (array) ( $qsoft_iconed_plan['item']['fields'] ?? array() ) )
		&& false !== strpos( $qsoft_iconed_render, 'data-qs-field="icon"' ),
	'a glyph in a box of its own is a field of the row, so the third card keeps its own icon'
);

/*
 * Words that are not in a paragraph are still words: an eyebrow in a div, a
 * bullet with an icon in front of it, a card that is one big link.
 */
$qsoft_misc_html = '<section><div class="eyebrow">Why us</div>'
	. '<ul><li><svg class="tick" viewBox="0 0 1 1"><path d="M0 0"/></svg> Fast delivery</li><li><svg class="tick" viewBox="0 0 1 1"><path d="M0 0"/></svg> Fair prices</li></ul>'
	. '<a class="card" href="alpha.html"><h3>Alpha</h3><p>The first.</p></a></section>';
$qsoft_misc_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_misc_html );
$qsoft_misc_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_misc_html, $qsoft_misc_plan );
$qsoft_misc_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_misc_html, $qsoft_misc_plan );
$qsoft_misc_names  = array_map( static fn( array $f ): string => (string) $f['name'], $qsoft_misc_plan['fields'] );

qsoft_assert(
	in_array( 'eyebrow', $qsoft_misc_names, true ) && 'Why us' === ( $qsoft_misc_values['eyebrow'] ?? '' ),
	'a div with words of its own is a field named after its class'
);

qsoft_assert(
	2 === substr_count( $qsoft_misc_render, '<svg class="tick"' )
		&& false !== strpos( $qsoft_misc_render, '</svg> <?php echo esc_html(' )
		&& 'Fast delivery' === ( $qsoft_misc_values['text'] ?? '' ),
	'a bullet with an icon keeps the icon and makes only its words the field'
);

qsoft_assert(
	in_array( 'card', $qsoft_misc_names, true )
		&& in_array( 'subheading', $qsoft_misc_names, true )
		&& '' === ( $qsoft_misc_values['card']['title'] ?? 'x' )
		&& 'Alpha' === ( $qsoft_misc_values['subheading'] ?? '' )
		&& false !== strpos( $qsoft_misc_render, 'data-qs-field="card" data-qs-type="link"' ),
	'a card that is one link keeps its address as a field and its heading as another'
);

$qsoft_same = '';

foreach ( array( 'One', 'Two', 'Three' ) as $qsoft_n ) {
	$qsoft_same .= '<article class="card"><h3>' . $qsoft_n . '</h3><ul><li>Fast</li><li>Safe</li><li>Cheap</li></ul></article>';
}

$qsoft_same_plan = Qwerty\Soft\Support\SectionPlan::of( '<section><div class="grid">' . $qsoft_same . '</div></section>' );

qsoft_assert(
	'repeat' === $qsoft_same_plan['kind'] && 'article.card' === ( $qsoft_same_plan['item']['selector'] ?? '' ),
	'cards whose bullets are the same are a repeat of the card, with the bullets inside the row'
);

/*
 * A row whose name is its own text and whose caption is inside it. Reading
 * only what was under the row made the caption a field and froze every
 * logo's name to the first row's: five logos all called BUYME.
 */
$qsoft_logos = '';

foreach ( array( 'BUYME|Digital commerce', 'Magenta|Medical', 'CLEW|Health technology' ) as $qsoft_pair ) {
	list( $qsoft_brand, $qsoft_sector ) = explode( '|', $qsoft_pair );

	$qsoft_logos .= '<div class="logo-name">' . $qsoft_brand . '<small>' . $qsoft_sector . '</small></div>';
}

$qsoft_logos_html   = '<section class="logo-strip"><div class="logos">' . $qsoft_logos . '</div></section>';
$qsoft_logos_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_logos_html );
$qsoft_logos_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_logos_html, $qsoft_logos_plan );
$qsoft_logos_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_logos_html, $qsoft_logos_plan );

qsoft_assert(
	'repeat' === $qsoft_logos_plan['kind']
		&& 1 === count( (array) ( $qsoft_logos_plan['item']['fields'] ?? array() ) )
		&& ! empty( $qsoft_logos_plan['item']['fields'][0]['rich'] ),
	'a row that is its own words plus a caption is one rich field of the row'
);

qsoft_assert(
	'Magenta<small>Medical</small>' === ( $qsoft_logos_values['items'][1]['logo_name'] ?? '' )
		&& false === strpos( $qsoft_logos_render, '>BUYME' ),
	'each row keeps its own name and caption, and no name is frozen into the template'
);

/*
 * A card numbered by an attribute the stylesheet draws has no text to be a
 * field, and every card came out wearing the first card's number.
 */
$qsoft_numbered = '';

foreach ( array( '01', '02', '03' ) as $qsoft_no ) {
	$qsoft_numbered .= '<article class="card" data-no="' . $qsoft_no . '" data-kind="plain"><h3>Step ' . $qsoft_no . '</h3></article>';
}

$qsoft_numbered_html   = '<section><div class="grid">' . $qsoft_numbered . '</div></section>';
$qsoft_numbered_plan   = Qwerty\Soft\Support\SectionPlan::of( $qsoft_numbered_html );
$qsoft_numbered_render = (string) Qwerty\Soft\Support\BlockWriter::render_php( $qsoft_numbered_html, $qsoft_numbered_plan );
$qsoft_numbered_values = Qwerty\Soft\Support\BlockWriter::values( $qsoft_numbered_html, $qsoft_numbered_plan );
$qsoft_numbered_names  = array_map( static fn( array $f ): string => (string) $f['name'], (array) ( $qsoft_numbered_plan['item']['fields'] ?? array() ) );

qsoft_assert(
	in_array( 'no', $qsoft_numbered_names, true ) && ! in_array( 'kind', $qsoft_numbered_names, true ),
	'an attribute that differs between rows is a field of the row; one that does not is not'
);

qsoft_assert(
	false !== strpos( $qsoft_numbered_render, 'data-no="<?php echo esc_attr( (string) ( $qsoft_row[\'no\'] ?? \'\' ) ); ?>"' )
		&& '03' === ( $qsoft_numbered_values['items'][2]['no'] ?? '' ),
	'the attribute is written back from the row, and each row keeps its own value'
);

qsoft_group( 'ImportLog — a long build keeps the start of its account as well as the end' );

/*
 * The log used to keep its last two hundred lines and nothing else. A build
 * writes a line per section, so the unpack's skip list — the one part worth
 * reading when a page is missing — had gone before anybody looked.
 */
$qsoft_log_lines = array();

for ( $qsoft_i = 1; $qsoft_i <= 40; $qsoft_i++ ) {
	$qsoft_log_lines[] = array(
		'seq'     => $qsoft_i,
		'message' => 'line ' . $qsoft_i,
	);
}

$qsoft_trimmed = Qwerty\Soft\Support\ImportLog::trim( $qsoft_log_lines, 20, 5 );

qsoft_assert( 20 === count( $qsoft_trimmed ), 'an overflowing log is cut to the maximum' );
qsoft_assert(
	array( 1, 2, 3, 4, 5 ) === array_column( array_slice( $qsoft_trimmed, 0, 5 ), 'seq' ),
	'the first lines survive the cut'
);
qsoft_assert(
	26 === (int) $qsoft_trimmed[5]['seq'] && 40 === (int) end( $qsoft_trimmed )['seq'],
	'and the most recent lines follow them, with the middle gone'
);
qsoft_assert(
	$qsoft_log_lines === Qwerty\Soft\Support\ImportLog::trim( $qsoft_log_lines, 40, 5 ),
	'a log within its limit is left exactly as it is'
);
qsoft_assert(
	array( 1, 2, 3 ) === array_column( Qwerty\Soft\Support\ImportLog::trim( $qsoft_log_lines, 3, 10 ), 'seq' ),
	'a head larger than the whole limit keeps only the head'
);
$qsoft_tail_only = Qwerty\Soft\Support\ImportLog::trim( $qsoft_log_lines, 10, 0 );

qsoft_assert(
	array( 31, 40 ) === array( (int) $qsoft_tail_only[0]['seq'], (int) end( $qsoft_tail_only )['seq'] ),
	'no head at all is the old behaviour: the tail alone'
);
qsoft_assert(
	Qwerty\Soft\Support\ImportLog::MAX_LINES >= 1500 && Qwerty\Soft\Support\ImportLog::HEAD_LINES >= 100,
	'the shipped limits hold a real build and its unpack'
);

qsoft_group( 'DesignArchive — names the archive uses are names this system can hold' );

/*
 * ASCII names pass through untouched: the links between the design's own
 * pages depend on it, and `page.dc.html` must not become `page.dc_.html`.
 */
qsoft_assert( 'Home.dc.html' === Qwerty\Soft\Support\DesignArchive::clean_file_name( 'Home.dc.html' ), 'a plain file name is kept as it is' );
qsoft_assert( 'my images' === Qwerty\Soft\Support\DesignArchive::clean_directory_name( 'my images' ), 'a plain folder name is kept as it is' );
qsoft_assert( 'index-php.txt' === Qwerty\Soft\Support\DesignArchive::clean_file_name( 'index.php' ), 'server source is still neutralised' );
qsoft_assert( 'dir-con' === Qwerty\Soft\Support\DesignArchive::clean_directory_name( 'con' ), 'a reserved Windows device name is still renamed' );

/*
 * Cyrillic used to become dashes — `------.jpg`, the same dashes for every
 * name of that length, and a page that still named the original. WordPress's
 * remove_accents() knows nothing of it, so the archive carries its own table.
 */
qsoft_assert( 'foto.jpg' === Qwerty\Soft\Support\DesignArchive::clean_file_name( 'фото.jpg' ), 'a Cyrillic name is spelled in Latin letters, extension kept', Qwerty\Soft\Support\DesignArchive::clean_file_name( 'фото.jpg' ) );
qsoft_assert( 'hero-kartinka.png' === Qwerty\Soft\Support\DesignArchive::clean_file_name( 'hero-картинка.png' ), 'mixed names come out whole', Qwerty\Soft\Support\DesignArchive::clean_file_name( 'hero-картинка.png' ) );
qsoft_assert( 'izobrazheniya' === Qwerty\Soft\Support\DesignArchive::clean_directory_name( 'изображения' ), 'a folder name gets the same treatment', Qwerty\Soft\Support\DesignArchive::clean_directory_name( 'изображения' ) );
qsoft_assert( 'Kiyiv-Shchuka' === Qwerty\Soft\Support\DesignArchive::clean_directory_name( 'Київ-Щука' ), 'Ukrainian letters and capitals are covered', Qwerty\Soft\Support\DesignArchive::clean_directory_name( 'Київ-Щука' ) );

/*
 * A name no transliteration reaches (there is no remove_accents() in this
 * harness, and the table stops at Cyrillic) becomes a short stable hash
 * rather than dashes, so different names stay different files.
 */
$qsoft_cjk = Qwerty\Soft\Support\DesignArchive::clean_file_name( '写真.jpg' );

qsoft_assert( 1 === preg_match( '/^[a-f0-9]{8}\.jpg$/', $qsoft_cjk ), 'a name with nothing spellable in it becomes a hash, and keeps its extension', $qsoft_cjk );
qsoft_assert( $qsoft_cjk === Qwerty\Soft\Support\DesignArchive::clean_file_name( '写真.jpg' ), 'the same name is spelled the same way every time' );
qsoft_assert(
	$qsoft_cjk !== Qwerty\Soft\Support\DesignArchive::clean_file_name( '画像.jpg' ),
	'two different names of the same length stay two different files'
);
qsoft_assert(
	1 === preg_match( '/^hero-[a-f0-9]{8}\.png$/', Qwerty\Soft\Support\DesignArchive::clean_file_name( 'hero-写真.png' ) ),
	'what could be spelled is kept, and the hash marks what could not'
);
qsoft_assert(
	1 === preg_match( '/^[a-f0-9]{8}$/', Qwerty\Soft\Support\DesignArchive::clean_directory_name( '写真' ) ),
	'a folder name no table reaches is hashed too'
);
qsoft_assert(
	'' !== Qwerty\Soft\Support\DesignArchive::clean_file_name( "\xE4\xF3\xF0.jpg" ) && str_ends_with( Qwerty\Soft\Support\DesignArchive::clean_file_name( "\xE4\xF3\xF0.jpg" ), '.jpg' ),
	'a name that is not valid UTF-8 is still given a usable ASCII spelling'
);

/*
 * The path budget on the file at the end of the tree. The rule is the same
 * rule Windows enforces, applied before fopen() rather than found out from
 * it, with the extension kept and a hash so the shortening is stable.
 */
$qsoft_long_dir  = '/uploads/designs/slug/' . str_repeat( 'folder/', 6 );
$qsoft_long_name = str_repeat( 'a-very-long-photograph-name-', 5 ) . '.jpg';
$qsoft_fitted    = Qwerty\Soft\Support\DesignArchive::fit_path( $qsoft_long_dir . $qsoft_long_name, 120 );

qsoft_assert( is_string( $qsoft_fitted ) && strlen( $qsoft_fitted ) <= 120, 'a path past the limit is brought under it', $qsoft_fitted );
qsoft_assert( is_string( $qsoft_fitted ) && str_ends_with( $qsoft_fitted, '.jpg' ), 'and keeps its extension', $qsoft_fitted );
qsoft_assert( is_string( $qsoft_fitted ) && str_starts_with( $qsoft_fitted, $qsoft_long_dir ), 'and its folders', $qsoft_fitted );
qsoft_assert( is_string( $qsoft_fitted ) && 1 === preg_match( '#/a-very-long[a-z-]*-[a-f0-9]{8}\.jpg$#', $qsoft_fitted ), 'the readable start of the name is kept, with a hash of the whole', $qsoft_fitted );
qsoft_assert( $qsoft_fitted === Qwerty\Soft\Support\DesignArchive::fit_path( $qsoft_long_dir . $qsoft_long_name, 120 ), 'the same long name shortens the same way every time' );
qsoft_assert(
	$qsoft_long_dir . 'short.jpg' === Qwerty\Soft\Support\DesignArchive::fit_path( $qsoft_long_dir . 'short.jpg', 120 ),
	'a path within the limit is not touched'
);
qsoft_assert(
	null === Qwerty\Soft\Support\DesignArchive::fit_path( $qsoft_long_dir . $qsoft_long_name, 60 ),
	'when the folders alone are past the limit, no file name can help, and the caller is told so'
);
qsoft_assert(
	Qwerty\Soft\Support\DesignArchive::PATH_LIMIT <= 260,
	'the shipped limit is the one Windows enforces, or under it'
);

/*
 * Where an archive inside the design is opened. The counter for a folder
 * already taken used to be built on the last try — `x-2-3-4` — and the
 * short name for a long one was never checked again once a counter was on it.
 */
$qsoft_taken = static function ( string $path ): bool {
	return in_array( basename( $path ), array( 'photos', 'photos-2', 'photos-3' ), true );
};

qsoft_assert(
	'/designs/slug/assets/photos-4' === Qwerty\Soft\Support\DesignArchive::nested_folder( '/designs/slug/assets/photos.zip', $qsoft_taken ),
	'a folder already taken gets one counter, not one per try',
	Qwerty\Soft\Support\DesignArchive::nested_folder( '/designs/slug/assets/photos.zip', $qsoft_taken )
);
qsoft_assert(
	'/designs/slug/assets/photos' === Qwerty\Soft\Support\DesignArchive::nested_folder( '/designs/slug/assets/photos.zip', static fn( string $p ): bool => false ),
	'a free folder is named after the archive'
);

// Nine folders deep is 121 characters of parent; the readable name would run to 166.
$qsoft_deep      = '/designs/slug/' . str_repeat( 'deep-folder/', 9 ) . 'a-very-long-archive-name-for-the-photos-2026.zip';
$qsoft_deep_base = Qwerty\Soft\Support\DesignArchive::nested_folder( $qsoft_deep, static fn( string $p ): bool => false );

qsoft_assert( strlen( $qsoft_deep_base ) <= 150 && 1 === preg_match( '#/z-[a-f0-9]{8}$#', $qsoft_deep_base ), 'a name that would not fit is swapped for a short stable one', $qsoft_deep_base );
qsoft_assert( $qsoft_deep_base === Qwerty\Soft\Support\DesignArchive::nested_folder( $qsoft_deep, static fn( string $p ): bool => false ), 'the same archive always opens into the same folder' );

// Exactly 149 characters readable: it fits until "-2" is put on it.
$qsoft_edge      = '/designs/slug/' . str_repeat( 'x', 120 ) . '/archive-name-x.zip';
$qsoft_edge_once = Qwerty\Soft\Support\DesignArchive::nested_folder( $qsoft_edge, static fn( string $p ): bool => str_ends_with( $p, '/archive-name-x' ) );

qsoft_assert(
	strlen( $qsoft_edge_once ) <= 150 && 1 === preg_match( '#/z-[a-f0-9]{8}-2$#', $qsoft_edge_once ),
	'a readable name that fits until its counter is put on it is swapped for the short one, counter and all',
	$qsoft_edge_once
);

// ------------------------------------------------------- AnthropicClient

qsoft_group( 'AnthropicClient — the request, and what it caches' );

$qsoft_brief = "Convert the section below.\n\n### The section markup\n\n```html\n<section><h2>Hello</h2></section>\n```";
$qsoft_body  = AnthropicClient::request_body( 'system prompt', $qsoft_brief, array( 'type' => 'object' ) );

qsoft_assert( 16000 === $qsoft_body['max_tokens'], 'the output ceiling starts at the default' );
qsoft_assert( 4000 === AnthropicClient::request_body( 's', 'p', array(), array( 'max_tokens' => 4000 ) )['max_tokens'], 'and is the caller\'s when the caller names one' );
qsoft_assert( array( 'type' => 'adaptive' ) === $qsoft_body['thinking'] && 'high' === $qsoft_body['output_config']['effort'] && 'default' === $qsoft_body['fallbacks'], 'the default model thinks adaptively at high effort, with server-side fallbacks' );
qsoft_assert( array( 'type' => 'ephemeral' ) === ( $qsoft_body['system'][0]['cache_control'] ?? null ), 'the system prompt carries a cache breakpoint' );

$qsoft_turn = $qsoft_body['messages'][0]['content'];

qsoft_assert( is_array( $qsoft_turn ) && 1 === count( $qsoft_turn ) && $qsoft_brief === ( $qsoft_turn[0]['text'] ?? '' ), 'a first request sends the brief as one block' );
qsoft_assert( array( 'type' => 'ephemeral' ) === ( $qsoft_turn[0]['cache_control'] ?? null ), 'with a breakpoint on it, so a retry can hit it' );

$qsoft_retry = "Your last answer to this could not be used. Fix it and return the whole section again.\n\n### What was wrong\n\nA brace.\n\n---\n\n### The original task, unchanged\n\n" . $qsoft_brief;
$qsoft_again = AnthropicClient::request_body( 'system prompt', $qsoft_retry, array( 'type' => 'object' ) )['messages'][0]['content'];

qsoft_assert( 2 === count( $qsoft_again ) && $qsoft_brief === $qsoft_again[0]['text'], 'a correction sends the brief first, byte for byte the block already cached' );
qsoft_assert( isset( $qsoft_again[0]['cache_control'] ) && ! isset( $qsoft_again[1]['cache_control'] ), 'the breakpoint stays on the brief, not on the complaint' );
qsoft_assert(
	str_starts_with( (string) $qsoft_again[1]['text'], 'Your last answer' )
		&& str_contains( (string) $qsoft_again[1]['text'], 'A brace.' )
		&& ! str_contains( (string) $qsoft_again[1]['text'], 'original task' ),
	'and the complaint follows, without the heading that only marked the join'
);
qsoft_assert( array( $qsoft_brief, '' ) === AnthropicClient::split( $qsoft_brief ), 'a prompt with no resent brief is all stable' );

$qsoft_haiku = AnthropicClient::request_body( 's', 'p', array(), array( 'model' => 'claude-haiku-4-5', 'effort' => 'xhigh' ) );

qsoft_assert(
	! isset( $qsoft_haiku['thinking'] ) && ! isset( $qsoft_haiku['fallbacks'] ) && ! isset( $qsoft_haiku['output_config']['effort'] ),
	'Haiku 4.5 is spared the parameters it rejects'
);
qsoft_assert( 'claude-opus-5' === AnthropicClient::request_body( 's', 'p', array(), array( 'model' => 'claude-9' ) )['model'], 'an unknown model falls back to the default' );

qsoft_assert(
	32000 === AnthropicClient::raised( 16000 ) && 32000 === AnthropicClient::raised( 20000 ) && 8000 === AnthropicClient::raised( 4000 ),
	'a cut-off reply is retried with twice the room, up to the cap'
);
qsoft_assert( 32000 === AnthropicClient::raised( 32000 ) && 40000 === AnthropicClient::raised( 40000 ), 'and at the cap there is no room left to retry with' );

// ------------------------------------------------------------- ClaudeCli

qsoft_group( 'ClaudeCli — a batch launcher is run through its interpreter' );

$qsoft_argv = array( '--print', '--json-schema', '{"type":"object","properties":{"a b":{"type":"string"}}}', '--tools', '' );

qsoft_assert(
	array_merge( array( 'cmd.exe', '/c', 'C:/Users/me/AppData/Roaming/npm/claude.cmd' ), $qsoft_argv ) === ClaudeCli::command( 'C:/Users/me/AppData/Roaming/npm/claude.cmd', $qsoft_argv ),
	'an npm claude.cmd is started by cmd.exe /c, arguments intact'
);
qsoft_assert( array( 'cmd.exe', '/c', 'D:/tools/CLAUDE.BAT', '--version' ) === ClaudeCli::command( 'D:/tools/CLAUDE.BAT', array( '--version' ) ), 'and so is a .bat, whatever its case' );
qsoft_assert( array_merge( array( 'C:/Users/me/.local/bin/claude.exe' ), $qsoft_argv ) === ClaudeCli::command( 'C:/Users/me/.local/bin/claude.exe', $qsoft_argv ), 'a real program is started as itself' );
qsoft_assert( array( '/usr/local/bin/claude', '--version' ) === ClaudeCli::command( '/usr/local/bin/claude', array( '--version' ) ), 'and so is a binary with no extension' );

// ------------------------------------------------------------ PlanReview

qsoft_group( 'PlanReview — what a reply may change' );

$qsoft_reading = array(
	'kind'   => 'repeat',
	'fields' => array(
		array(
			'name' => 'label',
			'type' => 'text',
			'path' => '0/0',
		),
		array(
			'name' => 'label_2',
			'type' => 'text',
			'path' => '0/1',
		),
		array(
			'name' => 'btn',
			'type' => 'link',
			'path' => '0/2',
		),
	),
	'item'   => array(
		'selector' => 'article.card',
		'count'    => 3,
		'fields'   => array(
			array(
				'name' => 'subheading',
				'type' => 'text',
				'path' => '0',
			),
			array(
				'name' => 'text',
				'type' => 'richtext',
				'path' => '1',
			),
		),
	),
);

$qsoft_short = PlanReview::apply(
	$qsoft_reading,
	array(
		'kind'        => 'repeat',
		'fields'      => array(
			array(
				'name'  => 'eyebrow',
				'label' => 'Eyebrow',
			),
			array(
				'name'  => 'read_more',
				'label' => 'Read more',
			),
		),
		'item_fields' => array(
			array(
				'name'  => 'heading',
				'label' => 'Heading',
			),
		),
	)
);

qsoft_assert( array( 'label', 'label_2', 'btn' ) === array_column( $qsoft_short['fields'], 'name' ), 'a list one short is refused whole, so no name lands on the wrong field' );
qsoft_assert( array( 'subheading', 'text' ) === array_column( $qsoft_short['item']['fields'], 'name' ), 'and so is a row list of the wrong length' );

$qsoft_full = PlanReview::apply(
	$qsoft_reading,
	array(
		'kind'        => 'listing',
		'fields'      => array(
			array(
				'name'  => 'Eyebrow',
				'label' => 'Eyebrow',
			),
			array(
				'name'  => 'heading',
				'label' => 'Section heading',
			),
			array(
				'name'  => '2nd link!',
				'label' => '',
			),
		),
		'item_fields' => array(
			array(
				'name'  => 'heading',
				'label' => 'Card heading',
			),
			array(
				'name'  => 'heading',
				'label' => 'Card text',
			),
		),
	)
);

qsoft_assert( array( 'eyebrow', 'heading', 'f_2nd_link' ) === array_column( $qsoft_full['fields'], 'name' ), 'a list of the right length renames one for one, reduced to what ACF accepts' );
qsoft_assert(
	'Eyebrow' === ( $qsoft_full['fields'][0]['label'] ?? '' ) && 'Section heading' === ( $qsoft_full['fields'][1]['label'] ?? '' ) && ! isset( $qsoft_full['fields'][2]['label'] ),
	'labels are taken when given and left alone when empty'
);
qsoft_assert( array( '0/0', '0/1', '0/2' ) === array_column( $qsoft_full['fields'], 'path' ) && 'link' === $qsoft_full['fields'][2]['type'], 'paths and types are never the model\'s to change' );
qsoft_assert( array( 'heading', 'text' ) === array_column( $qsoft_full['item']['fields'], 'name' ), 'a second field claiming a taken name keeps its structural name' );
qsoft_assert( 'listing' === $qsoft_full['kind'], 'the kind is taken when there is something to repeat' );

$qsoft_solo = PlanReview::apply(
	array(
		'kind'   => 'single',
		'fields' => array(),
		'item'   => null,
	),
	array(
		'kind'   => 'listing',
		'fields' => array(),
	)
);

qsoft_assert( 'single' === $qsoft_solo['kind'], 'but a one-off section is never made a listing' );

// --------------------------------------------------------------- Lessons

qsoft_group( 'Lessons — the brief weighs recent builds, not the whole journal' );

qsoft_assert( '' === Lessons::summarise( array() ), 'an empty journal says nothing' );

$qsoft_old = array_fill(
	0,
	5,
	array(
		'kind'         => 'static',
		'notes'        => array( 'listing_downgraded' => 3 ),
		'fidelity_min' => 40,
	)
);
$qsoft_new = array_fill(
	0,
	10,
	array(
		'kind'         => 'react',
		'notes'        => array(),
		'fidelity_min' => 96,
	)
);

$qsoft_said = Lessons::summarise( array_merge( $qsoft_old, $qsoft_new ) );

qsoft_assert( str_starts_with( $qsoft_said, 'This site has imported 15 design archives before (5× static, 10× react).' ), 'the count and the kinds cover the whole journal' );
qsoft_assert( ! str_contains( $qsoft_said, 'Earlier archives held' ), 'a counter from before the last ten builds no longer warns anyone' );
qsoft_assert( ! str_contains( $qsoft_said, 'below 80%' ), 'nor does a thin build from back then' );

$qsoft_new[0]['notes']        = array( 'listing_downgraded' => 1 );
$qsoft_new[9]['notes']        = array( 'listing_downgraded' => 2 );
$qsoft_new[9]['fidelity_min'] = 70;

$qsoft_said = Lessons::summarise( array_merge( $qsoft_old, $qsoft_new ) );

qsoft_assert( str_contains( $qsoft_said, 'Earlier archives held 3× sections that looked like record listings' ), 'recent counters are added up, and the older ones are not' );
qsoft_assert( str_contains( $qsoft_said, '1 of the most recent builds measured below 80%' ), 'one recent thin build is one, not six' );

$qsoft_said = Lessons::summarise( array_merge( $qsoft_old, array_slice( $qsoft_new, 0, 3 ) ) );

qsoft_assert( str_contains( $qsoft_said, 'Earlier archives held 16× sections' ) && str_contains( $qsoft_said, '5 of the most recent builds' ), 'a journal of ten or fewer counts every entry' );

printf( "\n%d checks, %d failure(s).\n", $qsoft_checks, $qsoft_failures );

exit( $qsoft_failures > 0 ? 1 : 0 );
