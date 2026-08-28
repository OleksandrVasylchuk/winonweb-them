<?php
/**
 * Proves the harness keeps its promise before any other test relies on it.
 *
 * A post inserted inside a test must be gone once the test ends, a file
 * written under uploads/ must be gone, and a test that throws must still be
 * rolled back. If any of this fails there is no point reading further results.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Looking past the object cache is the point of the check.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Local files the test creates and expects removed.

require __DIR__ . '/bootstrap.php';

$qsoft_post_id = 0;
$qsoft_option  = 'qwerty_soft_test_option_' . bin2hex( random_bytes( 4 ) );
$qsoft_file    = '';

qsoft_test(
	'Isolation: a post, an option and an upload inside a test',
	static function () use ( &$qsoft_post_id, $qsoft_option, &$qsoft_file ): void {
		$qsoft_post_id = (int) wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Isolation probe',
				'post_content' => 'If you can read this on the site, the harness is broken.',
			),
			true
		);

		qsoft_assert( $qsoft_post_id > 0, 'post was inserted inside the transaction' );
		qsoft_assert( null !== get_post( $qsoft_post_id ), 'post is readable inside the transaction' );

		update_option( $qsoft_option, 'probe', false );
		qsoft_assert( 'probe' === get_option( $qsoft_option ), 'option is readable inside the transaction' );

		$uploads    = wp_upload_dir();
		$qsoft_file = trailingslashit( (string) $uploads['path'] ) . 'qwerty-soft-signal-isolation-probe.txt';
		file_put_contents( $qsoft_file, 'probe' );
		qsoft_assert( is_file( $qsoft_file ), 'file was written under uploads/' );
	}
);

global $wpdb;

qsoft_group( 'Isolation: after the test' );

$qsoft_row = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $qsoft_post_id ) );
qsoft_assert( null === $qsoft_row, 'post was rolled back', $qsoft_row );

$qsoft_row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $qsoft_option ) );
qsoft_assert( null === $qsoft_row, 'option was rolled back', $qsoft_row );

qsoft_assert( false === get_option( $qsoft_option ), 'option cache does not remember the rolled-back value' );
qsoft_assert( '' !== $qsoft_file && ! file_exists( $qsoft_file ), 'upload was removed', $qsoft_file );

$qsoft_second = 0;

qsoft_test(
	'Isolation: a test that throws is still rolled back',
	static function () use ( &$qsoft_second ): void {
		$qsoft_second = (int) wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Isolation probe 2',
			),
			true
		);

		qsoft_assert( $qsoft_second > 0, 'post was inserted' );

		// Counted as a failure by the harness; corrected for below.
		throw new RuntimeException( 'deliberate' );
	}
);

// The throw above is reported as a failure by design; this file expects exactly that one.
$qsoft_expected_failure = $GLOBALS['qsoft_failures'];

qsoft_group( 'Isolation: after the throwing test' );

$qsoft_row = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $qsoft_second ) );
qsoft_assert( null === $qsoft_row, 'post from the throwing test was rolled back', $qsoft_row );

if ( 1 === $qsoft_expected_failure ) {
	--$GLOBALS['qsoft_failures'];
	qsoft_info( 'the deliberate exception above was expected and is not counted' );
} else {
	qsoft_assert( false, 'expected exactly one failure from the deliberate exception', $qsoft_expected_failure );
}

qsoft_finish();
