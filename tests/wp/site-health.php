<?php
/**
 * The Site Health tests the theme registers.
 *
 * Site Health is unforgiving about shape: a test callback that returns an
 * array without label, status and test keys breaks the whole screen, not just
 * its own row. So the shape is what is checked, plus the two verdicts this
 * machine must pass for the importer to be usable at all.
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Wow\Signal\Modules\SiteHealth;
use Wow\Signal\Theme;

/**
 * Check one Site Health result has what the screen needs.
 *
 * @param string               $name   Which test.
 * @param array<string, mixed> $result What it returned.
 * @return void
 */
function wow_check_health_shape( string $name, array $result ): void {
	foreach ( array( 'label', 'status', 'test' ) as $key ) {
		wow_assert( isset( $result[ $key ] ) && is_string( $result[ $key ] ) && '' !== $result[ $key ], $name . ': has a non-empty "' . $key . '"', $result );
	}

	wow_assert( in_array( $result['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ), $name . ': status is one Site Health understands', $result['status'] ?? null );
	wow_assert( isset( $result['badge']['label'], $result['badge']['color'] ), $name . ': carries a badge' );
	wow_assert( isset( $result['description'] ) && str_starts_with( (string) $result['description'], '<p>' ), $name . ': description is a paragraph' );
	wow_assert( ( $result['test'] ?? '' ) === $name, $name . ': "test" names itself', $result['test'] ?? null );
}

wow_test(
	'Site Health: registration and result shape',
	static function (): void {
		$module = Theme::instance()->module( SiteHealth::class );

		if ( ! wow_assert( $module instanceof SiteHealth, 'the SiteHealth module is booted' ) ) {
			return;
		}

		$tests = apply_filters( 'site_status_tests', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Asking WordPress's own filter what the theme registered.

		wow_assert( isset( $tests['direct']['wow_signal_extensions']['test'] ), 'extensions test is registered as a direct test' );
		wow_assert( isset( $tests['direct']['wow_signal_uploads']['test'] ), 'uploads test is registered as a direct test' );
		wow_assert( isset( $tests['async']['wow_signal_outbound']['test'] ), 'outbound test is registered as an async test' );
		wow_assert( ! empty( $tests['async']['wow_signal_outbound']['has_rest'] ), 'outbound test runs over REST' );
		wow_assert( str_contains( (string) ( $tests['async']['wow_signal_outbound']['test'] ?? '' ), 'wow-signal/v1/health/outbound' ), 'outbound test points at the theme route', $tests['async']['wow_signal_outbound']['test'] ?? null );
		wow_assert( is_callable( $tests['async']['wow_signal_outbound']['async_direct_test'] ?? null ), 'outbound test has a direct fallback for WP-CLI' );

		foreach ( array( 'wow_signal_extensions', 'wow_signal_uploads' ) as $name ) {
			wow_assert( is_callable( $tests['direct'][ $name ]['test'] ?? null ), $name . ': callback is callable' );
			wow_assert( '' !== (string) ( $tests['direct'][ $name ]['label'] ?? '' ), $name . ': has a label' );
		}

		$extensions = $module->test_extensions();
		$uploads    = $module->test_uploads();

		wow_check_health_shape( 'wow_signal_extensions', $extensions );
		wow_check_health_shape( 'wow_signal_uploads', $uploads );

		wow_assert( 'good' === ( $extensions['status'] ?? '' ), 'extensions: zip and dom are available on this machine', $extensions['label'] ?? null );
		wow_assert( 'good' === ( $uploads['status'] ?? '' ), 'uploads: folder is writable on this machine', $uploads['label'] ?? null );

		// Outbound does two HEAD requests with a five-second timeout; only the shape is asserted.
		$outbound = $module->test_outbound();
		wow_check_health_shape( 'wow_signal_outbound', $outbound );
		wow_info( 'outbound verdict on this machine: ' . (string) ( $outbound['status'] ?? '?' ) . ' — ' . (string) ( $outbound['label'] ?? '' ) );

		// Debug information, shown under Site Health → Info.
		$info = apply_filters( 'debug_information', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Same: WordPress's own filter.
		$keys = array_filter( array_keys( $info ), static fn( $k ): bool => str_starts_with( (string) $k, 'wow' ) );
		wow_assert( array() !== $keys, 'theme adds a section to Site Health → Info', array_keys( $info ) );
	}
);

wow_finish();
