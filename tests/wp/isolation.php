<?php
/**
 * Proves the harness keeps its promise before any other test relies on it.
 *
 * A post inserted inside a test must be gone once the test ends, a file
 * written under uploads/ must be gone, and a test that throws must still be
 * rolled back. If any of this fails there is no point reading further results.
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Looking past the object cache is the point of the check.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Local files the test creates and expects removed.

require __DIR__ . '/bootstrap.php';

$wow_post_id = 0;
$wow_option  = 'wow_signal_test_option_' . bin2hex( random_bytes( 4 ) );
$wow_file    = '';

wow_test(
	'Isolation: a post, an option and an upload inside a test',
	static function () use ( &$wow_post_id, $wow_option, &$wow_file ): void {
		$wow_post_id = (int) wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Isolation probe',
				'post_content' => 'If you can read this on the site, the harness is broken.',
			),
			true
		);

		wow_assert( $wow_post_id > 0, 'post was inserted inside the transaction' );
		wow_assert( null !== get_post( $wow_post_id ), 'post is readable inside the transaction' );

		update_option( $wow_option, 'probe', false );
		wow_assert( 'probe' === get_option( $wow_option ), 'option is readable inside the transaction' );

		$uploads  = wp_upload_dir();
		$wow_file = trailingslashit( (string) $uploads['path'] ) . 'wow-signal-isolation-probe.txt';
		file_put_contents( $wow_file, 'probe' );
		wow_assert( is_file( $wow_file ), 'file was written under uploads/' );
	}
);

global $wpdb;

wow_group( 'Isolation: after the test' );

$wow_row = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $wow_post_id ) );
wow_assert( null === $wow_row, 'post was rolled back', $wow_row );

$wow_row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wow_option ) );
wow_assert( null === $wow_row, 'option was rolled back', $wow_row );

wow_assert( false === get_option( $wow_option ), 'option cache does not remember the rolled-back value' );
wow_assert( '' !== $wow_file && ! file_exists( $wow_file ), 'upload was removed', $wow_file );

$wow_second = 0;

wow_test(
	'Isolation: a test that throws is still rolled back',
	static function () use ( &$wow_second ): void {
		$wow_second = (int) wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Isolation probe 2',
			),
			true
		);

		wow_assert( $wow_second > 0, 'post was inserted' );

		// Counted as a failure by the harness; corrected for below.
		throw new RuntimeException( 'deliberate' );
	}
);

// The throw above is reported as a failure by design; this file expects exactly that one.
$wow_expected_failure = $GLOBALS['wow_failures'];

wow_group( 'Isolation: after the throwing test' );

$wow_row = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $wow_second ) );
wow_assert( null === $wow_row, 'post from the throwing test was rolled back', $wow_row );

if ( 1 === $wow_expected_failure ) {
	--$GLOBALS['wow_failures'];
	wow_info( 'the deliberate exception above was expected and is not counted' );
} else {
	wow_assert( false, 'expected exactly one failure from the deliberate exception', $wow_expected_failure );
}

wow_finish();
