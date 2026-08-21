<?php
/**
 * A stand-in for the `claude` binary, for testing the CLI transport.
 *
 * The transport's risky part is not the model — it is the process: feeding a
 * prompt too large for a pipe buffer without deadlocking, draining both output
 * streams while the child is still writing, killing a run that never ends, and
 * reading the JSON envelope back. None of that needs a model, and all of it is
 * worth testing on every machine rather than only where an API key happens to
 * be configured.
 *
 * So this is a real program, spawned by the real proc_open() call, reached
 * through the `wow_signal/claude_cli_command` filter. It ignores the arguments
 * the theme passes and behaves according to the mode named in its first
 * argument:
 *
 *   version   print a version string, as `claude --version` does
 *   echo      read all of stdin and report how many bytes arrived
 *   reply     answer with a valid envelope carrying block markup
 *   noise     write a lot to stdout and stderr, to fill the pipe buffers
 *   hang      never exit, so the timeout has something to stop
 *   crash     exit non-zero with a message on stderr
 *   garbage   print something that is not JSON at all
 *   failure   a well-formed envelope that reports an error
 *
 * Run directly, never through the theme. Not shipped: tools/build-zip.mjs
 * excludes tests/.
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wow_mode = $argv[1] ?? 'reply';

if ( 'version' === $wow_mode ) {
	echo "9.9.9 (Fake Claude)\n";
	exit( 0 );
}

if ( 'hang' === $wow_mode ) {
	// Read nothing, answer nothing. The parent's deadline has to end this.
	while ( true ) {
		usleep( 100000 );
	}
}

if ( 'crash' === $wow_mode ) {
	fwrite( STDERR, "fake claude: something went wrong\n" );
	exit( 3 );
}

// Every remaining mode consumes the whole prompt first, the way the real one does.
$wow_stdin = '';

while ( ! feof( STDIN ) ) {
	$wow_chunk = fread( STDIN, 8192 );

	if ( false === $wow_chunk || '' === $wow_chunk ) {
		break;
	}

	$wow_stdin .= $wow_chunk;
}

if ( 'garbage' === $wow_mode ) {
	echo "not json, not even close\n";
	exit( 0 );
}

if ( 'noise' === $wow_mode ) {
	// Fill both pipes well past any buffer, so a parent that does not drain deadlocks.
	for ( $wow_i = 0; $wow_i < 200; $wow_i++ ) {
		fwrite( STDERR, str_repeat( 'e', 1024 ) . "\n" );
	}
}

$wow_envelope = array(
	'type'           => 'result',
	'subtype'        => 'success',
	'is_error'       => false,
	'session_id'     => 'fake-session',
	'total_cost_usd' => 0.0125,
	'usage'          => array(
		'input_tokens'                => 11,
		'cache_read_input_tokens'     => 2200,
		'cache_creation_input_tokens' => 0,
		'output_tokens'               => 320,
	),
	'modelUsage'     => array(
		'claude-haiku-4-5' => array( 'outputTokens' => 4 ),
		'claude-sonnet-5'  => array( 'outputTokens' => 320 ),
	),
);

if ( 'failure' === $wow_mode ) {
	$wow_envelope['is_error'] = true;
	$wow_envelope['subtype']  = 'error_during_execution';
	$wow_envelope['result']   = "Credit balance is too low\nsecond line that must not reach the message";

	echo wp_json_encode_fallback( $wow_envelope );
	exit( 0 );
}

/*
 * The reply reports the prompt's length back. A test that sends more than a
 * pipe buffer holds can then prove every byte arrived — a short write here
 * would be a model answering half a question, which is the failure this whole
 * arrangement exists to rule out.
 */
$wow_payload = array(
	'markup'   => "<!-- wp:group {\"tagName\":\"section\"} -->\n<section class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Received " . strlen( $wow_stdin ) . " bytes.</p>\n<!-- /wp:paragraph --></section>\n<!-- /wp:group -->",
	'summary'  => 'A section built by the stand-in.',
	'editable' => array( 'The paragraph' ),
	'concerns' => array(),
	'changed'  => array( 'nothing, this is a stand-in' ),
);

$wow_envelope['structured_output'] = $wow_payload;
$wow_envelope['result']            = wp_json_encode_fallback( $wow_payload );

echo wp_json_encode_fallback( $wow_envelope );

exit( 0 );

/**
 * JSON encoding without WordPress loaded.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_fallback( $value ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stand-alone stand-in program, never loaded into WordPress.
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded here.
}
