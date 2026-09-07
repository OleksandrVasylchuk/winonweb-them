<?php
/**
 * The review of a section's reading: when it runs, and who is charged for it.
 *
 * Wrapping a section costs nothing. Asking a model what its fields should be
 * called costs a call per fresh section, and the build screen offers that as a
 * choice: "Straight through" is the free build, the guided one is the build
 * that spends. The claims under test are the two that keep that choice honest
 * — a straight-through build sends nothing anywhere, and a guided build's
 * reviews are priced in the report like every other call.
 *
 * No model, no key and no money: the reply is decided in advance through the
 * `qwerty_soft/model_reply` filter, which is what it exists for.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\DesignArchive;
use Qwerty\Soft\Support\ModelGateway;
use Qwerty\Soft\Support\SiteAssembler;

/**
 * Build the fixture design with the model's answers decided in advance.
 *
 * A route that reports itself ready is configured so the question "does the
 * build ask?" is answered by the build's own option and not by the absence
 * of a key. Every call is counted, and any call the test did not expect is
 * refused rather than let through to the network.
 *
 * @param bool $smart Whether the build asks for a model at all.
 * @return array{report:array<string,mixed>|WP_Error,reviews:int,others:int}
 */
function qsoft_build_with_stub( bool $smart ): array {
	$before_transport = get_option( ModelGateway::OPTION_TRANSPORT );
	$before_key       = get_option( 'qwerty_soft_anthropic_key' );

	update_option( ModelGateway::OPTION_TRANSPORT, 'api' );
	update_option( 'qwerty_soft_anthropic_key', 'sk-ant-test-not-a-real-key' );

	$counts = array(
		'reviews' => 0,
		'others'  => 0,
	);

	$stub = static function ( $given, string $system, string $prompt, array $schema ) use ( &$counts ) {
		unset( $given, $system, $prompt );

		// PlanReview's schema is the only one that asks for a record's singular name.
		if ( ! isset( $schema['properties']['item_name'] ) ) {
			++$counts['others'];

			return new WP_Error( 'qsoft_test_unexpected', 'a model call the test did not expect' );
		}

		++$counts['reviews'];

		return array(
			'title'       => 'Stubbed title',
			'kind'        => 'repeat',
			'fields'      => array(),
			'item_name'   => '',
			'item_fields' => array(),
			'_usage'      => array(
				'input_tokens'  => 100,
				'output_tokens' => 50,
			),
			'_model'      => 'claude-opus-5',
			'_transport'  => 'api',
		);
	};

	add_filter( 'qwerty_soft/model_reply', $stub, 10, 4 );

	try {
		$unpacked = DesignArchive::unpack( qsoft_fixture( 'design.zip' ), 'Fixture design' );

		if ( is_wp_error( $unpacked ) ) {
			return array(
				'report'  => $unpacked,
				'reviews' => 0,
				'others'  => 0,
			);
		}

		$root   = (string) $unpacked['path'];
		$report = SiteAssembler::build(
			$root,
			DesignArchive::index( $root ),
			array(
				'publish' => false,
				'smart'   => $smart,
			)
		);

		return array(
			'report'  => $report,
			'reviews' => $counts['reviews'],
			'others'  => $counts['others'],
		);
	} finally {
		remove_filter( 'qwerty_soft/model_reply', $stub, 10 );
		update_option( ModelGateway::OPTION_TRANSPORT, $before_transport );
		update_option( 'qwerty_soft_anthropic_key', $before_key );
	}
}

qsoft_group( 'PlanReview — a free build stays free, a guided build is priced whole' );

qsoft_test(
	'a build sent straight through asks the model nothing',
	static function (): void {
		$made   = qsoft_build_with_stub( false );
		$report = $made['report'];

		if ( ! qsoft_assert( is_array( $report ), 'the build completes', is_wp_error( $report ) ? $report->get_error_message() : $report ) ) {
			return;
		}

		qsoft_assert( 0 === $made['reviews'], 'no section was sent for review', $made['reviews'] );
		qsoft_assert( 0 === $made['others'], 'and nothing else reached a model', $made['others'] );
		qsoft_assert( ! isset( $report['ai'] ) || 0 === (int) ( $report['ai']['calls'] ?? 0 ), 'so the report has nothing to price', $report['ai'] ?? null );
	}
);

qsoft_test(
	'a guided build reviews each fresh section, and the report prices every review',
	static function (): void {
		$made   = qsoft_build_with_stub( true );
		$report = $made['report'];

		if ( ! qsoft_assert( is_array( $report ), 'the build completes', is_wp_error( $report ) ? $report->get_error_message() : $report ) ) {
			return;
		}

		if ( $made['others'] > 0 ) {
			qsoft_info( sprintf( '%d call(s) with another schema were refused by the stub', $made['others'] ) );
		}

		if ( ! qsoft_assert( $made['reviews'] > 0, 'fresh sections were sent for review', $made['reviews'] ) ) {
			return;
		}

		$ai = isset( $report['ai'] ) && is_array( $report['ai'] ) ? $report['ai'] : array();

		qsoft_assert( array() !== $ai, 'the report carries a model tally', array_keys( $report ) );
		qsoft_assert( (int) ( $ai['calls'] ?? 0 ) === $made['reviews'] + $made['others'], 'every review is a call in that tally', $ai );
		qsoft_assert( (int) ( $ai['failed'] ?? 0 ) === $made['others'], 'and only the refused calls count as failed', $ai );
		qsoft_assert( (int) ( $ai['input'] ?? 0 ) === 100 * $made['reviews'], 'the input tokens add up across the reviews', $ai );
		qsoft_assert( (int) ( $ai['output'] ?? 0 ) === 50 * $made['reviews'], 'so do the output tokens', $ai );

		// 100 in at $5 and 50 out at $25 per million: $0.00175 a review.
		qsoft_assert( abs( (float) ( $ai['cost'] ?? 0.0 ) - ( 0.00175 * $made['reviews'] ) ) < 0.0000001, 'and the cost is the price of those tokens', $ai );
		qsoft_assert( 'api' === (string) ( $ai['transport'] ?? '' ), 'billed to the route that answered', $ai );
	}
);

qsoft_finish();
