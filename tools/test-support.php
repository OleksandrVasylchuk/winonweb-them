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
}

$qsoft_root = dirname( __DIR__ );

require $qsoft_root . '/inc/Support/DesignTokens.php';
require $qsoft_root . '/inc/Support/BrandKit.php';
require $qsoft_root . '/inc/Support/Spend.php';
require $qsoft_root . '/inc/Support/SourceProject.php';
require $qsoft_root . '/inc/Support/DesignDocs.php';
require $qsoft_root . '/inc/Support/SectionPlan.php';
require $qsoft_root . '/inc/Support/BlockWriter.php';
require $qsoft_root . '/inc/Support/DesignField.php';
require $qsoft_root . '/inc/Support/DesignType.php';

use Qwerty\Soft\Support\BrandKit;
use Qwerty\Soft\Support\DesignTokens;
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
qsoft_assert( 'file:./style.css' === $qsoft_manifest['style'], 'the section carries its own stylesheet' );

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


printf( "\n%d checks, %d failure(s).\n", $qsoft_checks, $qsoft_failures );

exit( $qsoft_failures > 0 ? 1 : 0 );
