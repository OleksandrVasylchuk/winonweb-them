<?php
/**
 * Design takeover: an imported design replaces the theme's type scale,
 * spacing, radii, shadows and gradients, and reset() puts every one back.
 *
 * The extract() step is checked on the small fixture and, when it can be found,
 * on a real export; apply() and reset() run inside the rolled-back transaction
 * and are judged by what WordPress itself then reports through
 * wp_get_global_settings() and qsoft_global_stylesheet().
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\DesignTokens;

/**
 * The stylesheet a visitor actually receives.
 *
 * Since WordPress 6.7 the custom CSS — theme.json's `styles.css` and the
 * user-level sheet that replaces it — is no longer part of what
 * wp_get_global_stylesheet() returns by default; wp_enqueue_global_styles()
 * appends it as its own `custom-css` slice. A test that asks only for the
 * default types is looking at half the page.
 *
 * @return string
 */
function qsoft_global_stylesheet(): string {
	return wp_get_global_stylesheet() . wp_get_global_stylesheet( array( 'custom-css' ) );
}


/**
 * The theme's own theme.json, decoded.
 *
 * @return array<string, mixed>
 */
function qsoft_theme_json(): array {
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/theme.json' ), true );

	return is_array( $data ) ? $data : array();
}

/**
 * Slugs of a preset list in theme.json.
 *
 * @param array<string, mixed> $theme theme.json.
 * @param array<int, string>   $path  Path to the list.
 * @return array<int, string>
 */
function qsoft_theme_slugs( array $theme, array $path ): array {
	$node = $theme;

	foreach ( $path as $key ) {
		$node = $node[ $key ] ?? array();
	}

	return array_map( static fn( array $preset ): string => (string) $preset['slug'], (array) $node );
}

/**
 * Every six-digit hex colour in the theme's own gradients.
 *
 * @param array<string, mixed> $theme theme.json.
 * @return array<int, string>
 */
function qsoft_theme_gradient_hexes( array $theme ): array {
	$hexes = array();

	foreach ( (array) ( $theme['settings']['color']['gradients'] ?? array() ) as $preset ) {
		if ( preg_match_all( '/#[0-9a-f]{6}\b/i', (string) $preset['gradient'], $found ) ) {
			$hexes = array_merge( $hexes, array_map( 'strtolower', $found[0] ) );
		}
	}

	return array_values( array_unique( $hexes ) );
}

/**
 * A CSS length in pixels, clamp() read at its largest.
 *
 * @param string $value Length.
 * @return float|null
 */
function qsoft_px( string $value ): ?float {
	if ( 1 === preg_match( '/^clamp\((.+)\)$/i', trim( $value ), $inner ) ) {
		$parts = array_map( 'trim', explode( ',', $inner[1] ) );
		$value = end( $parts );
	}

	if ( 1 !== preg_match( '/^(-?\d*\.?\d+)(px|rem|em)$/', trim( $value ), $found ) ) {
		return null;
	}

	return (float) $found[1] * ( 'px' === $found[2] ? 1 : 16 );
}

/**
 * Where a real export lives: the env, the uploads folder, else a zip in Downloads.
 *
 * The zip is unpacked under uploads/qwerty-soft-signal-designs and removed again
 * before the run ends.
 *
 * @return string Directory, or empty when none can be found.
 */
function qsoft_real_design(): string {
	$configured = getenv( 'QSOFT_DESIGN_ROOT' );

	if ( is_string( $configured ) && is_dir( $configured ) ) {
		return rtrim( str_replace( '\\', '/', $configured ), '/' );
	}

	$uploads = wp_upload_dir();
	$base    = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) . '/qwerty-soft-signal-designs';

	foreach ( (array) glob( $base . '/robert-khoubian*' ) as $dir ) {
		if ( is_dir( (string) $dir ) && array() !== (array) glob( $dir . '/*.css' ) + (array) glob( $dir . '/assets/*.css' ) ) {
			return str_replace( '\\', '/', (string) $dir );
		}
	}

	$home = getenv( 'USERPROFILE' ) ?: getenv( 'HOME' ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Either may be unset.

	if ( ! is_string( $home ) || '' === $home || ! class_exists( 'ZipArchive' ) ) {
		return '';
	}

	$zips = (array) glob( str_replace( '\\', '/', $home ) . '/Downloads/robert_khoubian*.zip' );

	if ( array() === $zips ) {
		return '';
	}

	$target = $base . '/qs-test-robert';
	$zip    = new ZipArchive();

	if ( true !== $zip->open( (string) $zips[0] ) ) {
		return '';
	}

	wp_mkdir_p( $target );
	$zip->extractTo( $target );
	$zip->close();

	// Unpacked outside qsoft_test(), so outside the harness's snapshot: removed by hand at the end.
	$GLOBALS['qsoft_unpacked_design'] = $target;

	foreach ( (array) glob( $target . '/*', GLOB_ONLYDIR ) as $inner ) {
		if ( array() !== (array) glob( $inner . '/assets/*.css' ) ) {
			return str_replace( '\\', '/', (string) $inner );
		}
	}

	return $target;
}

/**
 * The checks every extract() result has to pass.
 *
 * @param array<string, mixed> $tokens extract() result.
 * @param array<string, mixed> $theme  theme.json.
 * @param string               $label  Design name for the report.
 * @return void
 */
function qsoft_check_extract( array $tokens, array $theme, string $label ): void {
	$font_slugs     = qsoft_theme_slugs( $theme, array( 'settings', 'typography', 'fontSizes' ) );
	$spacing_slugs  = qsoft_theme_slugs( $theme, array( 'settings', 'spacing', 'spacingSizes' ) );
	$gradient_slugs = qsoft_theme_slugs( $theme, array( 'settings', 'color', 'gradients' ) );
	$shadow_slugs   = qsoft_theme_slugs( $theme, array( 'settings', 'shadow', 'presets' ) );
	$radius_keys    = array_keys( (array) ( $theme['settings']['custom']['radius'] ?? array() ) );

	foreach ( array( 'font_sizes', 'typography', 'spacing_sizes', 'layout', 'radii', 'shadows', 'gradients', 'blocks', 'css' ) as $key ) {
		qsoft_assert( array_key_exists( $key, $tokens ), $label . ': extract() returns ' . $key );
	}

	// Type scale: the theme's slugs, rising, within sane bounds.
	qsoft_assert( array_keys( $tokens['font_sizes'] ) === $font_slugs, $label . ': fontSizes use exactly the theme slugs in order', array_keys( $tokens['font_sizes'] ) );

	$previous = 0.0;

	foreach ( $tokens['font_sizes'] as $slug => $entry ) {
		$px = qsoft_px( (string) $entry['size'] );

		qsoft_assert( null !== $px && $px >= 10 && $px <= 160, $label . ': font size ' . $slug . ' is a plausible length', $entry['size'] );
		qsoft_assert( null !== $px && $px > $previous, $label . ': font size ' . $slug . ' is larger than the one before', $entry['size'] );

		if ( is_array( $entry['fluid'] ) ) {
			qsoft_assert( qsoft_px( $entry['fluid']['min'] ) < qsoft_px( $entry['fluid']['max'] ), $label . ': fluid range of ' . $slug . ' is ordered', $entry['fluid'] );
		}

		$previous = (float) $px;
	}

	$medium = qsoft_px( (string) $tokens['font_sizes']['medium']['size'] );
	qsoft_assert( null !== $medium && $medium >= 14 && $medium <= 20, $label . ': body size (medium) is 14–20px', $tokens['font_sizes']['medium'] );

	// Elements.
	$typography = $tokens['typography'];

	qsoft_assert( 'var(--wp--preset--font-size--medium)' === ( $typography['body']['typography']['fontSize'] ?? '' ), $label . ': body font size points at the medium preset' );
	qsoft_assert( isset( $typography['heading']['typography']['fontWeight'], $typography['heading']['typography']['lineHeight'] ), $label . ': heading defaults carry weight and line-height', $typography['heading'] ?? null );

	$rank = array();

	foreach ( range( 1, 6 ) as $level ) {
		$reference = (string) ( $typography[ 'h' . $level ]['typography']['fontSize'] ?? '' );
		$slug      = str_replace( array( 'var(--wp--preset--font-size--', ')' ), '', $reference );

		qsoft_assert( in_array( $slug, $font_slugs, true ), $label . ': h' . $level . ' references a theme font-size slug', $reference );

		$rank[] = array_search( $slug, $font_slugs, true );
	}

	$sorted = $rank;
	rsort( $sorted );

	qsoft_assert( $rank === $sorted, $label . ': h1 through h6 never grow', $rank );

	qsoft_assert( isset( $typography['button']['color']['background'] ), $label . ': button has a background', $typography['button'] ?? null );
	qsoft_assert( isset( $typography['button']['border']['radius'] ), $label . ': button has a radius', $typography['button'] ?? null );
	qsoft_assert( isset( $typography['button']['spacing']['padding']['top'] ), $label . ': button has padding', $typography['button'] ?? null );
	qsoft_assert( isset( $typography['button'][':hover']['color']['background'] ), $label . ': button has a hover fill', $typography['button'] ?? null );

	// Spacing: the theme's slugs, rising.
	// Numeric slugs come back as integer keys from PHP; compare as strings.
	qsoft_assert( array_map( 'strval', array_keys( $tokens['spacing_sizes'] ) ) === $spacing_slugs, $label . ': spacingSizes use exactly the theme slugs in order', array_keys( $tokens['spacing_sizes'] ) );

	$previous = 0.0;

	foreach ( $tokens['spacing_sizes'] as $slug => $size ) {
		$px = qsoft_px( (string) $size );

		qsoft_assert( null !== $px && $px >= 4 && $px <= 240, $label . ': spacing ' . $slug . ' is a plausible length', $size );
		qsoft_assert( null !== $px && $px > $previous, $label . ': spacing ' . $slug . ' is larger than the one before', $size );

		$previous = (float) $px;
	}

	qsoft_assert( 1 === preg_match( '/^var\(--wp--preset--spacing--\d+\)$/', (string) ( $tokens['layout']['blockGap'] ?? '' ) ), $label . ': blockGap references a spacing preset', $tokens['layout'] );

	// Shape.
	qsoft_assert( array_keys( $tokens['radii'] ) === $radius_keys, $label . ': radius keys are the theme\'s', array_keys( $tokens['radii'] ) );
	qsoft_assert( '999px' === ( $tokens['radii']['pill'] ?? '' ), $label . ': pill radius stays 999px' );

	$sm = qsoft_px( (string) $tokens['radii']['sm'] );
	$md = qsoft_px( (string) $tokens['radii']['md'] );
	$lg = qsoft_px( (string) $tokens['radii']['lg'] );

	qsoft_assert( null !== $sm && null !== $md && null !== $lg && $sm < $md && $md < $lg && $lg <= 72, $label . ': radii rise sm < md < lg', $tokens['radii'] );

	qsoft_assert( array_keys( $tokens['shadows'] ) === $shadow_slugs, $label . ': shadow slugs are the theme\'s', array_keys( $tokens['shadows'] ) );
	qsoft_assert( array_keys( $tokens['gradients'] ) === $gradient_slugs, $label . ': gradient slugs are the theme\'s', array_keys( $tokens['gradients'] ) );

	$theme_hexes = qsoft_theme_gradient_hexes( $theme );

	foreach ( $tokens['gradients'] as $slug => $gradient ) {
		qsoft_assert( 1 === preg_match( '/^(linear|radial|conic)-gradient\(.+\)$/i', (string) $gradient ), $label . ': gradient ' . $slug . ' is a gradient', $gradient );

		foreach ( $theme_hexes as $hex ) {
			qsoft_assert( ! str_contains( strtolower( (string) $gradient ), $hex ), $label . ': gradient ' . $slug . ' does not carry the theme colour ' . $hex, $gradient );
		}
	}

	foreach ( $tokens['shadows'] as $slug => $shadow ) {
		qsoft_assert( ! str_contains( (string) $shadow, '34, 211, 238' ) && ! str_contains( (string) $shadow, '34,211,238' ), $label . ': shadow ' . $slug . ' is not the theme\'s cyan glow', $shadow );
	}
}

// ------------------------------------------------------------ extract()

$qsoft_theme   = qsoft_theme_json();
$qsoft_fixture = qsoft_fixture( 'design' );

qsoft_group( 'extract(): the fixture design' );

$qsoft_fixture_tokens = DesignTokens::extract( $qsoft_fixture );

qsoft_check_extract( $qsoft_fixture_tokens, $qsoft_theme, 'fixture' );

// The fixture's own facts.
qsoft_assert( '1rem' === $qsoft_fixture_tokens['font_sizes']['medium']['size'], 'fixture: body is 16px', $qsoft_fixture_tokens['font_sizes']['medium'] );
qsoft_assert( 'var(--wp--custom--radius--pill)' === ( $qsoft_fixture_tokens['typography']['button']['border']['radius'] ?? '' ), 'fixture: the 999px button is a pill' );
qsoft_assert( 'var(--wp--preset--color--accent)' === ( $qsoft_fixture_tokens['typography']['button']['color']['background'] ?? '' ), 'fixture: button fill is the accent' );
qsoft_assert( '8px' === $qsoft_fixture_tokens['radii']['sm'] && '12px' === $qsoft_fixture_tokens['radii']['md'], 'fixture: radii are the design\'s 8px and --radius 12px', $qsoft_fixture_tokens['radii'] );
qsoft_assert( str_contains( (string) $qsoft_fixture_tokens['css'], '.qs-header{position:static' ), 'fixture: a non-sticky design header undoes the theme\'s sticky blurred one', $qsoft_fixture_tokens['css'] );
qsoft_assert( '0 12px 40px rgba(11,15,26,.2)' === ( $qsoft_fixture_tokens['shadows']['soft'] ?? '' ), 'fixture: the popup shadow is carried over', $qsoft_fixture_tokens['shadows'] );

// ------------------------------------------------------ a real export

qsoft_group( 'extract(): a real export' );

$qsoft_real = qsoft_real_design();

if ( '' === $qsoft_real ) {
	qsoft_skip( 'no real export found (set QSOFT_DESIGN_ROOT, or place the robert-khoubian design under uploads/qwerty-soft-signal-designs/)' );
} else {
	qsoft_info( 'design: ' . $qsoft_real );

	$qsoft_real_tokens = DesignTokens::extract( $qsoft_real );

	qsoft_check_extract( $qsoft_real_tokens, $qsoft_theme, 'export' );

	if ( str_contains( $qsoft_real, 'robert' ) ) {
		qsoft_assert( '6rem' === $qsoft_real_tokens['font_sizes']['display']['size'], 'export: the 96px hero heading is the display size', $qsoft_real_tokens['font_sizes']['display'] );
		qsoft_assert( is_array( $qsoft_real_tokens['font_sizes']['display']['fluid'] ), 'export: a clamp() heading keeps a fluid range', $qsoft_real_tokens['font_sizes']['display'] );
		qsoft_assert( 'var(--wp--preset--font-size--display)' === $qsoft_real_tokens['typography']['h1']['typography']['fontSize'], 'export: h1 references display' );
		qsoft_assert( '73.75rem' === ( $qsoft_real_tokens['layout']['contentSize'] ?? '' ), 'export: contentSize is the 1180px container', $qsoft_real_tokens['layout'] );
		qsoft_assert( '90rem' === ( $qsoft_real_tokens['layout']['wideSize'] ?? '' ), 'export: wideSize is capped at 1440px', $qsoft_real_tokens['layout'] );
		qsoft_assert( '1.55' === ( $qsoft_real_tokens['typography']['body']['typography']['lineHeight'] ?? '' ), 'export: body line-height is the design\'s', $qsoft_real_tokens['typography']['body'] );
		qsoft_assert( 'var(--wp--preset--color--contrast)' === ( $qsoft_real_tokens['typography']['link']['color']['text'] ?? '' ), 'export: a{color:inherit} becomes contrast', $qsoft_real_tokens['typography']['link'] ?? null );
		qsoft_assert( 'none' === ( $qsoft_real_tokens['typography']['link']['typography']['textDecoration'] ?? '' ), 'export: links are not underlined' );
		qsoft_assert( '#d6b869' === ( $qsoft_real_tokens['typography']['button'][':hover']['color']['background'] ?? '' ), 'export: button hover is the design\'s', $qsoft_real_tokens['typography']['button'][':hover'] ?? null );
		qsoft_assert( '14px' === $qsoft_real_tokens['radii']['sm'] && '22px' === $qsoft_real_tokens['radii']['md'], 'export: --radius-sm and --radius are honoured', $qsoft_real_tokens['radii'] );
		qsoft_assert( '' === $qsoft_real_tokens['css'], 'export: a sticky blurred design header leaves the theme header alone', $qsoft_real_tokens['css'] );
		qsoft_assert( str_contains( $qsoft_real_tokens['gradients']['aurora'], '#06111f' ), 'export: aurora is now the design\'s navy gradient', $qsoft_real_tokens['gradients'] );
		qsoft_assert( '5.75rem' === $qsoft_real_tokens['spacing_sizes']['90'], 'export: the 92px section padding is the top spacing slot', $qsoft_real_tokens['spacing_sizes'] );
	}
}

// ----------------------------------------------------- apply() / reset()

$qsoft_apply_tokens = isset( $qsoft_real_tokens ) ? $qsoft_real_tokens : $qsoft_fixture_tokens;

qsoft_test(
	'apply(): the site reports the design\'s values; reset() restores the theme\'s',
	static function () use ( $qsoft_apply_tokens, $qsoft_theme ): void {
		// Start clean, whatever a previous import left in this database.
		DesignTokens::reset();

		$settings_before   = wp_get_global_settings();
		$styles_before     = wp_get_global_styles();
		$stylesheet_before = qsoft_global_stylesheet();

		$theme_hexes = qsoft_theme_gradient_hexes( $qsoft_theme );

		foreach ( $theme_hexes as $hex ) {
			qsoft_assert( str_contains( strtolower( $stylesheet_before ), $hex ), 'before: the theme gradient colour ' . $hex . ' is on the page' );
		}

		DesignTokens::apply( $qsoft_apply_tokens );

		$settings = wp_get_global_settings();
		$styles   = wp_get_global_styles();
		$sheet    = qsoft_global_stylesheet();

		// Font sizes.
		$sizes = array();

		foreach ( (array) ( $settings['typography']['fontSizes']['theme'] ?? array() ) as $preset ) {
			$sizes[ (string) $preset['slug'] ] = (string) $preset['size'];
		}

		foreach ( $qsoft_apply_tokens['font_sizes'] as $slug => $entry ) {
			qsoft_assert( ( $sizes[ $slug ] ?? null ) === $entry['size'], 'fontSizes.' . $slug . ' is the design\'s ' . $entry['size'], $sizes[ $slug ] ?? null );
		}

		qsoft_assert( ! isset( $settings['typography']['fontSizes']['custom'] ), 'fontSizes replace the theme list rather than adding a custom one' );

		$spacing = array();

		foreach ( (array) ( $settings['spacing']['spacingSizes']['theme'] ?? array() ) as $preset ) {
			$spacing[ (string) $preset['slug'] ] = (string) $preset['size'];
		}

		foreach ( $qsoft_apply_tokens['spacing_sizes'] as $slug => $size ) {
			qsoft_assert( ( $spacing[ $slug ] ?? null ) === $size, 'spacingSizes.' . $slug . ' is the design\'s ' . $size, $spacing[ $slug ] ?? null );
		}

		$gradients = array();

		foreach ( (array) ( $settings['color']['gradients']['theme'] ?? array() ) as $preset ) {
			$gradients[ (string) $preset['slug'] ] = (string) $preset['gradient'];
		}

		qsoft_assert( $gradients === $qsoft_apply_tokens['gradients'], 'gradients are the design\'s, under the theme slugs', $gradients );

		$shadows = array();

		foreach ( (array) ( $settings['shadow']['presets']['theme'] ?? array() ) as $preset ) {
			$shadows[ (string) $preset['slug'] ] = (string) $preset['shadow'];
		}

		qsoft_assert( $shadows === $qsoft_apply_tokens['shadows'], 'shadows are the design\'s, under the theme slugs', $shadows );

		foreach ( $qsoft_apply_tokens['radii'] as $key => $value ) {
			qsoft_assert( ( $settings['custom']['radius'][ $key ] ?? null ) === $value, 'custom.radius.' . $key . ' is ' . $value, $settings['custom']['radius'] ?? null );
		}

		qsoft_assert( ( $settings['custom']['focusRing']['width'] ?? null ) === ( $settings_before['custom']['focusRing']['width'] ?? '' ), 'other custom settings are untouched' );

		if ( isset( $qsoft_apply_tokens['layout']['contentSize'] ) ) {
			qsoft_assert( ( $settings['layout']['contentSize'] ?? null ) === $qsoft_apply_tokens['layout']['contentSize'], 'layout.contentSize is the design\'s', $settings['layout'] ?? null );
			qsoft_assert( ( $settings['layout']['wideSize'] ?? null ) === $qsoft_apply_tokens['layout']['wideSize'], 'layout.wideSize is the design\'s', $settings['layout'] ?? null );
		}

		// Styles.
		qsoft_assert( ( $styles['spacing']['blockGap'] ?? null ) === $qsoft_apply_tokens['layout']['blockGap'], 'blockGap is the design\'s', $styles['spacing'] ?? null );
		qsoft_assert( ( $styles['elements']['h1']['typography']['fontSize'] ?? null ) === $qsoft_apply_tokens['typography']['h1']['typography']['fontSize'], 'h1 font size is the design\'s slot', $styles['elements']['h1'] ?? null );
		qsoft_assert( ( $styles['elements']['button']['border']['radius'] ?? null ) === $qsoft_apply_tokens['typography']['button']['border']['radius'], 'button radius is the design\'s', $styles['elements']['button'] ?? null );
		qsoft_assert( ( $styles['elements']['button'][':hover']['color']['background'] ?? null ) === $qsoft_apply_tokens['typography']['button'][':hover']['color']['background'], 'button hover is the design\'s', $styles['elements']['button'][':hover'] ?? null );
		qsoft_assert( ( $styles['elements']['heading']['typography']['fontWeight'] ?? null ) === $qsoft_apply_tokens['typography']['heading']['typography']['fontWeight'], 'heading weight is the design\'s', $styles['elements']['heading'] ?? null );

		// The stylesheet the visitor gets.
		// A fluid preset is emitted as clamp(min, …, max); a fixed one literally.
		foreach ( $qsoft_apply_tokens['font_sizes'] as $slug => $entry ) {
			$pattern = is_array( $entry['fluid'] )
				? '/--wp--preset--font-size--' . preg_quote( (string) $slug, '/' ) . ':\s*clamp\(' . preg_quote( $entry['fluid']['min'], '/' ) . ',.*?' . preg_quote( $entry['size'], '/' ) . '\)/'
				: '/--wp--preset--font-size--' . preg_quote( (string) $slug, '/' ) . ':\s*' . preg_quote( $entry['size'], '/' ) . '\b/';

			qsoft_assert( 1 === preg_match( $pattern, $sheet ), 'stylesheet declares --wp--preset--font-size--' . $slug . ' as the design\'s ' . $entry['size'] );
		}

		foreach ( $theme_hexes as $hex ) {
			qsoft_assert( ! str_contains( strtolower( $sheet ), $hex ), 'stylesheet no longer carries the theme gradient colour ' . $hex );
		}

		qsoft_assert( ! str_contains( $sheet, '34, 211, 238' ), 'stylesheet no longer carries the theme\'s cyan glow' );

		if ( '' !== $qsoft_apply_tokens['css'] ) {
			qsoft_assert( str_contains( $sheet, '.skip-link' ) && str_contains( $sheet, 'design takeover: header' ), 'theme critical CSS survives alongside the header override' );
		}

		// The record.
		$recorded = get_option( DesignTokens::OPTION );

		qsoft_assert( is_array( $recorded ) && in_array( 'settings.typography.fontSizes.theme', $recorded, true ) && in_array( 'settings.spacing.spacingSizes.theme', $recorded, true ) && in_array( 'settings.color.gradients.theme', $recorded, true ), 'the option records the written paths', $recorded );

		// Idempotent.
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$first   = (string) get_post( $post_id )->post_content;

		DesignTokens::apply( $qsoft_apply_tokens );

		$second = (string) get_post( $post_id )->post_content;

		qsoft_assert( $first === $second, 'apply() twice leaves the user global styles byte-identical' );
		qsoft_assert( get_option( DesignTokens::OPTION ) === $recorded, 'apply() twice records the same paths' );

		// Reset.
		DesignTokens::reset();

		$settings_after   = wp_get_global_settings();
		$styles_after     = wp_get_global_styles();
		$stylesheet_after = qsoft_global_stylesheet();

		qsoft_assert( $settings_after === $settings_before, 'reset(): wp_get_global_settings() is identical to before apply()', array_diff_key( $settings_after, $settings_before ) + array_diff_key( $settings_before, $settings_after ) );
		qsoft_assert( $styles_after === $styles_before, 'reset(): wp_get_global_styles() is identical to before apply()' );
		qsoft_assert( $stylesheet_after === $stylesheet_before, 'reset(): the stylesheet is identical to before apply()' );
		qsoft_assert( false === get_option( DesignTokens::OPTION ), 'reset(): the option is removed' );

		$raw = json_decode( (string) get_post( $post_id )->post_content, true );

		/*
		 * styles.css is DesignStylesheet's slice, not the takeover's: a site
		 * that already carries an imported design keeps it across a token
		 * apply/reset cycle, so it is excluded from this check.
		 */
		qsoft_assert( ! isset( $raw['settings']['typography'] ) && ! isset( $raw['settings']['spacing'] ) && ! isset( $raw['styles']['elements'] ), 'reset(): nothing of the takeover remains in the user post', $raw );
	}
);

if ( isset( $GLOBALS['qsoft_unpacked_design'] ) && is_dir( (string) $GLOBALS['qsoft_unpacked_design'] ) ) {
	qsoft_remove_tree( (string) $GLOBALS['qsoft_unpacked_design'] );
	qsoft_info( 'removed the unpacked export from uploads/' );
}

qsoft_finish();
