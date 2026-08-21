<?php
/**
 * The CLI transport as a process, not as a model.
 *
 * Everything here runs a real program through the real proc_open() call. What
 * is being tested is the plumbing, which is where this transport can actually
 * go wrong and where the mistakes are silent:
 *
 * - A prompt larger than a pipe buffer has to arrive whole. A short write is
 *   a model answering half a question and returning something that looks fine.
 * - Both output streams have to be drained while the child is still writing,
 *   or a chatty run deadlocks instead of finishing.
 * - A run that never ends has to be killed rather than held until PHP gives up.
 * - A crash, an unreadable reply and a well-formed failure each have to come
 *   back as a sentence somebody can act on.
 *
 * None of that needs a model or a key, so it runs on every machine. The
 * stand-in is reached through the `wow_signal/claude_cli_command` filter, which
 * exists for machines that keep the real binary behind a wrapper.
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Wow\Signal\Support\ClaudeCli;

/**
 * Point the transport at the stand-in, in the given mode.
 *
 * @param string $mode Mode the stand-in should behave in.
 * @return callable The filter, so it can be removed again.
 */
function wow_fake_claude( string $mode ): callable {
	$stub = static function () use ( $mode ): array {
		return array( PHP_BINARY, wow_fixture( 'fake-claude.php' ), $mode );
	};

	add_filter( 'wow_signal/claude_cli_command', $stub, 10, 1 );

	return $stub;
}

/**
 * Run one generation against the stand-in and clean up afterwards.
 *
 * @param string $mode    Stand-in mode.
 * @param string $prompt  Prompt to send.
 * @param array  $options Options for generate().
 * @return array<string, mixed>|WP_Error
 */
function wow_fake_generate( string $mode, string $prompt = 'convert this', array $options = array() ) {
	$before = get_option( ClaudeCli::OPTION_BINARY );

	/*
	 * A path that exists, so binary() is satisfied; what actually runs is
	 * decided by the filter. PHP's own binary is the one file every machine
	 * running these tests is guaranteed to have.
	 */
	update_option( ClaudeCli::OPTION_BINARY, str_replace( '\\', '/', PHP_BINARY ) );
	ClaudeCli::forget();

	$version = wow_fake_claude( 'version' );
	$status  = ClaudeCli::status( true );
	remove_filter( 'wow_signal/claude_cli_command', $version, 10 );

	if ( ! $status['ready'] ) {
		update_option( ClaudeCli::OPTION_BINARY, $before );
		ClaudeCli::forget();

		return new WP_Error( 'wow_test_probe', 'the stand-in did not answer --version: ' . $status['reason'] );
	}

	$stub = wow_fake_claude( $mode );

	try {
		return ClaudeCli::generate(
			'system prompt',
			$prompt,
			array( 'type' => 'object' ),
			$options
		);
	} finally {
		remove_filter( 'wow_signal/claude_cli_command', $stub, 10 );
		update_option( ClaudeCli::OPTION_BINARY, $before );
		ClaudeCli::forget();
	}
}

wow_group( 'The CLI transport, run as a real process' );

wow_test(
	'a probe reports the version the command printed',
	static function (): void {
		if ( ! wow_assert( ClaudeCli::can_spawn(), 'this machine lets PHP start processes; without that the CLI route cannot be tested here' ) ) {
			return;
		}

		$before = get_option( ClaudeCli::OPTION_BINARY );

		update_option( ClaudeCli::OPTION_BINARY, str_replace( '\\', '/', PHP_BINARY ) );
		ClaudeCli::forget();

		$stub = wow_fake_claude( 'version' );

		try {
			$status = ClaudeCli::status( true );

			wow_assert( true === $status['ready'], 'the transport reports itself ready', $status );
			wow_assert( str_contains( $status['version'], 'Fake Claude' ), 'and carries the version it read', $status );
			wow_assert( '' === $status['reason'], 'with nothing to complain about', $status );
		} finally {
			remove_filter( 'wow_signal/claude_cli_command', $stub, 10 );
			update_option( ClaudeCli::OPTION_BINARY, $before );
			ClaudeCli::forget();
		}
	}
);

wow_test(
	'a prompt far larger than a pipe buffer arrives whole',
	static function (): void {
		if ( ! ClaudeCli::can_spawn() ) {
			wow_skip( 'this machine does not let PHP start processes' );
			return;
		}

		/*
		 * 400 KB. A Windows anonymous pipe holds a few kilobytes and a POSIX
		 * one 64 KB, so this is many buffers' worth: written in one blocking
		 * call before anything is read, it deadlocks, and written without
		 * checking the return value it silently truncates. The stand-in
		 * reports the byte count it actually received.
		 */
		$prompt = str_repeat( 'The design section to convert. ', 13000 );

		$reply = wow_fake_generate( 'reply', $prompt );

		if ( ! wow_assert( ! is_wp_error( $reply ), 'the call completed', is_wp_error( $reply ) ? $reply->get_error_message() : '' ) ) {
			return;
		}

		$markup = (string) ( $reply['markup'] ?? '' );

		/*
		 * The transport prepends the system prompt and a rule, so what arrives
		 * is longer than the prompt — never shorter. Shorter means bytes were
		 * dropped on the way.
		 */
		$received = preg_match( '/Received (\d+) bytes/', $markup, $m ) ? (int) $m[1] : 0;

		wow_assert( $received >= strlen( $prompt ), 'every byte of the prompt reached the command', $received . ' received, ' . strlen( $prompt ) . ' sent' );
		wow_assert( $received < strlen( $prompt ) + 20000, 'and nothing much beyond the system prompt was added', $received );
	}
);

wow_test(
	'a command that fills both pipes still finishes',
	static function (): void {
		if ( ! ClaudeCli::can_spawn() ) {
			wow_skip( 'this machine does not let PHP start processes' );
			return;
		}

		// 200 KB of stderr while stdout carries the reply: a parent that drains only one deadlocks.
		$reply = wow_fake_generate( 'noise' );

		wow_assert( ! is_wp_error( $reply ), 'a noisy run completes rather than hanging', is_wp_error( $reply ) ? $reply->get_error_message() : '' );
		wow_assert( is_array( $reply ) && str_contains( (string) ( $reply['markup'] ?? '' ), 'wp:group' ), 'and its reply is read from stdout regardless', $reply );
	}
);

wow_test(
	'a reply carries its usage, its model and its transport',
	static function (): void {
		if ( ! ClaudeCli::can_spawn() ) {
			wow_skip( 'this machine does not let PHP start processes' );
			return;
		}

		$reply = wow_fake_generate( 'reply' );

		if ( ! wow_assert( is_array( $reply ), 'the call completed', is_wp_error( $reply ) ? $reply->get_error_message() : '' ) ) {
			return;
		}

		wow_assert( 'cli' === ( $reply['_transport'] ?? '' ), 'the reply says which route answered', $reply['_transport'] ?? null );
		wow_assert( 320 === (int) ( $reply['_usage']['output_tokens'] ?? 0 ), 'usage comes back for the spend total', $reply['_usage'] ?? null );
		wow_assert( 0.0125 === (float) ( $reply['_notional_cost'] ?? 0 ), 'so does what the same work would have cost through the API', $reply['_notional_cost'] ?? null );

		/*
		 * A CLI turn runs a small model alongside the real one to name the
		 * session. Billing the conversion to that one would put the wrong
		 * model on the screen and price it wrongly.
		 */
		wow_assert( 'claude-sonnet-5' === (string) ( $reply['_model'] ?? '' ), 'the model that did the work is the one reported, not the one that named the session', $reply['_model'] ?? null );

		wow_assert( isset( $reply['summary'], $reply['editable'], $reply['changed'] ), 'the structured payload came through whole', array_keys( $reply ) );
	}
);

wow_test(
	'a run that never ends is stopped, not waited on',
	static function (): void {
		if ( ! ClaudeCli::can_spawn() ) {
			wow_skip( 'this machine does not let PHP start processes' );
			return;
		}

		$started = microtime( true );
		$reply   = wow_fake_generate( 'hang', 'convert this', array( 'timeout' => 2 ) );
		$elapsed = microtime( true ) - $started;

		if ( ! wow_assert( is_wp_error( $reply ), 'a hung command comes back as an error', $reply ) ) {
			return;
		}

		wow_assert( 'wow_signal_cli_timeout' === $reply->get_error_code(), 'named as a timeout', $reply->get_error_code() );
		wow_assert( $elapsed < 20, 'and stopped near its deadline rather than held until PHP gives up', round( $elapsed, 1 ) . 's' );
		wow_assert( str_contains( $reply->get_error_message(), '2 seconds' ), 'the message says how long it waited', $reply->get_error_message() );
	}
);

wow_test(
	'a crash, a failure and a garbled reply each come back as a sentence',
	static function (): void {
		if ( ! ClaudeCli::can_spawn() ) {
			wow_skip( 'this machine does not let PHP start processes' );
			return;
		}

		$crash = wow_fake_generate( 'crash' );

		wow_assert( is_wp_error( $crash ), 'a command that exits non-zero is an error', $crash );

		if ( is_wp_error( $crash ) ) {
			wow_assert( str_contains( $crash->get_error_message(), 'something went wrong' ), 'and what it printed to stderr is in the message', $crash->get_error_message() );
		}

		$garbage = wow_fake_generate( 'garbage' );

		wow_assert( is_wp_error( $garbage ), 'a reply that is not JSON is an error', $garbage );

		if ( is_wp_error( $garbage ) ) {
			wow_assert( 'wow_signal_cli_output' === $garbage->get_error_code(), 'named for what went wrong', $garbage->get_error_code() );
		}

		$failure = wow_fake_generate( 'failure' );

		wow_assert( is_wp_error( $failure ), 'a well-formed envelope reporting a failure is an error', $failure );

		if ( is_wp_error( $failure ) ) {
			$message = $failure->get_error_message();

			wow_assert( str_contains( $message, 'Credit balance is too low' ), 'carrying the reason the command gave', $message );
			wow_assert( ! str_contains( $message, 'second line' ), 'trimmed to one line, because it is shown inline', $message );
		}
	}
);

wow_finish();
