<?php
/**
 * The Site Health tests the theme registers.
 *
 * Site Health is unforgiving about shape: a test callback that returns an
 * array without label, status and test keys breaks the whole screen, not just
 * its own row. So the shape is what is checked, plus the two verdicts this
 * machine must pass for the importer to be usable at all.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Modules\SiteHealth;
use Qwerty\Soft\Theme;

/**
 * Check one Site Health result has what the screen needs.
 *
 * @param string               $name   Which test.
 * @param array<string, mixed> $result What it returned.
 * @return void
 */
function qsoft_check_health_shape( string $name, array $result ): void {
	foreach ( array( 'label', 'status', 'test' ) as $key ) {
		qsoft_assert( isset( $result[ $key ] ) && is_string( $result[ $key ] ) && '' !== $result[ $key ], $name . ': has a non-empty "' . $key . '"', $result );
	}

	qsoft_assert( in_array( $result['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ), $name . ': status is one Site Health understands', $result['status'] ?? null );
	qsoft_assert( isset( $result['badge']['label'], $result['badge']['color'] ), $name . ': carries a badge' );
	qsoft_assert( isset( $result['description'] ) && str_starts_with( (string) $result['description'], '<p>' ), $name . ': description is a paragraph' );
	qsoft_assert( ( $result['test'] ?? '' ) === $name, $name . ': "test" names itself', $result['test'] ?? null );
}

qsoft_test(
	'Site Health: registration and result shape',
	static function (): void {
		$module = Theme::instance()->module( SiteHealth::class );

		if ( ! qsoft_assert( $module instanceof SiteHealth, 'the SiteHealth module is booted' ) ) {
			return;
		}

		$tests = apply_filters( 'site_status_tests', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Asking WordPress's own filter what the theme registered.

		qsoft_assert( isset( $tests['direct']['qwerty_soft_extensions']['test'] ), 'extensions test is registered as a direct test' );
		qsoft_assert( isset( $tests['direct']['qwerty_soft_uploads']['test'] ), 'uploads test is registered as a direct test' );
		qsoft_assert( isset( $tests['async']['qwerty_soft_outbound']['test'] ), 'outbound test is registered as an async test' );
		qsoft_assert( ! empty( $tests['async']['qwerty_soft_outbound']['has_rest'] ), 'outbound test runs over REST' );
		qsoft_assert( str_contains( (string) ( $tests['async']['qwerty_soft_outbound']['test'] ?? '' ), 'qwerty-soft-signal/v1/health/outbound' ), 'outbound test points at the theme route', $tests['async']['qwerty_soft_outbound']['test'] ?? null );
		qsoft_assert( is_callable( $tests['async']['qwerty_soft_outbound']['async_direct_test'] ?? null ), 'outbound test has a direct fallback for WP-CLI' );

		foreach ( array( 'qwerty_soft_extensions', 'qwerty_soft_uploads' ) as $name ) {
			qsoft_assert( is_callable( $tests['direct'][ $name ]['test'] ?? null ), $name . ': callback is callable' );
			qsoft_assert( '' !== (string) ( $tests['direct'][ $name ]['label'] ?? '' ), $name . ': has a label' );
		}

		$extensions = $module->test_extensions();
		$uploads    = $module->test_uploads();

		qsoft_check_health_shape( 'qwerty_soft_extensions', $extensions );
		qsoft_check_health_shape( 'qwerty_soft_uploads', $uploads );

		qsoft_assert( 'good' === ( $extensions['status'] ?? '' ), 'extensions: zip and dom are available on this machine', $extensions['label'] ?? null );
		qsoft_assert( 'good' === ( $uploads['status'] ?? '' ), 'uploads: folder is writable on this machine', $uploads['label'] ?? null );

		// Outbound does two HEAD requests with a five-second timeout; only the shape is asserted.
		$outbound = $module->test_outbound();
		qsoft_check_health_shape( 'qwerty_soft_outbound', $outbound );
		qsoft_info( 'outbound verdict on this machine: ' . (string) ( $outbound['status'] ?? '?' ) . ' — ' . (string) ( $outbound['label'] ?? '' ) );

		// Debug information, shown under Site Health → Info.
		$info = apply_filters( 'debug_information', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Same: WordPress's own filter.
		$keys = array_filter( array_keys( $info ), static fn( $k ): bool => str_starts_with( (string) $k, 'qwerty-soft-signal' ) );
		qsoft_assert( array() !== $keys, 'theme adds a section to Site Health → Info', array_keys( $info ) );
	}
);

qsoft_test(
	'A build is refused when nothing could edit what it makes',
	static function (): void {
		/*
		 * The refusal exists because the failure it prevents is silent and
		 * total. Without ACF Pro a wrapped import still produces correct pages
		 * — the design's markup, its stylesheet, its words — and not one of
		 * them can be changed afterwards: every section reads "Unsupported" in
		 * the editor and the footer has no screen to be edited from.
		 *
		 * Finding that out costs the whole build, so it is checked first.
		 */
		$editable = Qwerty\Soft\Support\SiteOptions::editable();

		qsoft_info( 'ACF Pro on this machine: ' . ( $editable ? 'active' : 'not active' ) );

		if ( $editable ) {
			qsoft_assert(
				function_exists( 'acf_add_local_field_group' ),
				'the plugin that reports as ready really does register field groups'
			);
		}

		/*
		 * Pro specifically. Blocks and the repeater are both Pro features, so
		 * the free plugin passes a plain function_exists() test and then fails
		 * at the point it is actually needed — which is the middle of a build.
		 */
		if ( $editable ) {
			qsoft_assert(
				( defined( 'ACF_PRO' ) && ACF_PRO )
					|| ( function_exists( 'acf_get_setting' ) && (bool) acf_get_setting( 'pro' ) ),
				'and it is the Pro edition, which is what blocks and repeaters need'
			);
		}

		$request = new WP_REST_Request( 'POST', '/qwerty-soft-signal/v1/build/start' );
		$request->set_param( 'slug', 'no-such-design-' . wp_generate_password( 8, false, false ) );

		$answer = rest_do_request( $request );

		/*
		 * Whatever else happens, a build must never be refused for the wrong
		 * reason: on a machine that has ACF, the missing design is what should
		 * stop it, not the plugin.
		 */
		$code = is_wp_error( $answer->as_error() ) ? $answer->as_error()->get_error_code() : '';

		if ( $editable ) {
			qsoft_assert(
				'qwerty_soft_needs_acf' !== $code,
				'a site with ACF Pro is never told to install ACF Pro',
				$code
			);

			return;
		}

		qsoft_assert(
			'qwerty_soft_needs_acf' === $code,
			'a site without ACF Pro is stopped before the build rather than after it',
			$code
		);
	}
);

qsoft_finish();
