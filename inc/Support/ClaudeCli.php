<?php
/**
 * Runs a generation through the Claude Code CLI installed on the machine.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The second way this theme can reach a model: the `claude` binary.
 *
 * On a developer's own machine the CLI is already signed in to a Claude
 * subscription, so a conversion costs nothing beyond the plan that is being
 * paid for anyway. On shared hosting the binary is not there and process
 * spawning is usually disabled outright, which is why this is never the only
 * route — {@see ModelGateway} falls back to the API when a CLI is not usable.
 *
 * The invocation is deliberately austere. `--safe-mode` turns off CLAUDE.md,
 * skills, plugins, hooks and MCP servers so a conversion cannot be steered by
 * whatever happens to be configured on the machine; `--tools ""` removes the
 * tool loop entirely unless a screenshot has to be read; and `--json-schema`
 * makes the reply obey the same schema the API route enforces, so both
 * transports hand callers the same shape.
 *
 * Nothing is passed on the command line that could be large. The whole prompt
 * arrives on stdin, which sidesteps every argument-length limit — the one on
 * Windows is small enough that a real design section would hit it.
 */
final class ClaudeCli {

	/**
	 * Option holding an explicit path to the binary.
	 */
	public const OPTION_BINARY = 'qwerty_soft_claude_cli';

	/**
	 * Transient caching what a probe of the binary found.
	 */
	private const PROBE = 'qwerty_soft_cli_probe';

	/**
	 * How long a successful probe is trusted.
	 */
	private const PROBE_TTL = HOUR_IN_SECONDS;

	/**
	 * Seconds a version probe may take before it is treated as broken.
	 */
	private const PROBE_TIMEOUT = 20;

	/**
	 * Directories a CLI install commonly lands in, checked after PATH.
	 *
	 * @return array<int, string>
	 */
	private static function well_known(): array {
		$home = (string) ( getenv( 'HOME' ) ? getenv( 'HOME' ) : getenv( 'USERPROFILE' ) );

		$dirs = array( '/usr/local/bin', '/usr/bin', '/opt/homebrew/bin' );

		if ( '' !== $home ) {
			$dirs[] = rtrim( str_replace( '\\', '/', $home ), '/' ) . '/.local/bin';
			$dirs[] = rtrim( str_replace( '\\', '/', $home ), '/' ) . '/bin';
		}

		return $dirs;
	}

	/**
	 * Whether this server can spawn a process at all.
	 *
	 * Most shared hosts disable proc_open in php.ini. Asking before trying is
	 * what lets the settings screen explain the situation instead of showing a
	 * failed conversion.
	 *
	 * @return bool
	 */
	public static function can_spawn(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'proc_open', $disabled, true );
	}

	/**
	 * The binary to run, or an empty string when none was found.
	 *
	 * A constant wins over the option so a site can pin the path in
	 * wp-config.php, the same arrangement the API key has.
	 *
	 * @return string
	 */
	public static function binary(): string {
		if ( defined( 'QSOFT_CLAUDE_CLI' ) && is_string( QSOFT_CLAUDE_CLI ) ) {
			$pinned = trim( QSOFT_CLAUDE_CLI );

			return is_file( $pinned ) ? $pinned : '';
		}

		$stored = trim( (string) get_option( self::OPTION_BINARY, '' ) );

		if ( '' !== $stored ) {
			return is_file( $stored ) ? $stored : '';
		}

		return self::discover();
	}

	/**
	 * Whether the path came from a constant rather than the database.
	 *
	 * @return bool
	 */
	public static function binary_is_constant(): bool {
		return defined( 'QSOFT_CLAUDE_CLI' ) && '' !== trim( (string) QSOFT_CLAUDE_CLI );
	}

	/**
	 * Look for the binary on PATH and in the usual install directories.
	 *
	 * `which` is not shelled out to: on a host that allows proc_open the probe
	 * below runs the binary anyway, and on one that does not, shelling out
	 * would fail for the same reason. Walking PATH in PHP costs nothing and
	 * works identically on both systems.
	 *
	 * @return string
	 */
	private static function discover(): string {
		$windows = 'Windows' === PHP_OS_FAMILY;
		$names   = $windows ? array( 'claude.exe', 'claude.cmd', 'claude.bat' ) : array( 'claude' );

		$path = (string) getenv( 'PATH' );
		$dirs = '' === $path ? array() : explode( PATH_SEPARATOR, $path );
		$dirs = array_merge( $dirs, self::well_known() );

		foreach ( $dirs as $dir ) {
			$dir = rtrim( str_replace( '\\', '/', trim( $dir ) ), '/' );

			if ( '' === $dir ) {
				continue;
			}

			foreach ( $names as $name ) {
				$candidate = $dir . '/' . $name;

				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a conversion can actually be run through the CLI right now.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		$status = self::status();

		return (bool) $status['ready'];
	}

	/**
	 * What the CLI route looks like from here, in terms a person can act on.
	 *
	 * @param bool $fresh Re-run the probe instead of trusting the cached one.
	 * @return array{ready:bool,binary:string,version:string,reason:string}
	 */
	public static function status( bool $fresh = false ): array {
		$empty = array(
			'ready'   => false,
			'binary'  => '',
			'version' => '',
			'reason'  => '',
		);

		if ( ! self::can_spawn() ) {
			return array_merge(
				$empty,
				array( 'reason' => __( 'This server does not allow PHP to start other programs, so the command-line route cannot be used here. Use an API key instead.', 'qwerty-soft-signal' ) )
			);
		}

		$binary = self::binary();

		if ( '' === $binary ) {
			return array_merge(
				$empty,
				array( 'reason' => __( 'The claude command was not found. Install Claude Code on this machine, or enter the full path to the binary.', 'qwerty-soft-signal' ) )
			);
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::PROBE );

			if ( is_array( $cached ) && ( $cached['binary'] ?? '' ) === $binary ) {
				return array(
					'ready'   => (bool) ( $cached['ready'] ?? false ),
					'binary'  => $binary,
					'version' => (string) ( $cached['version'] ?? '' ),
					'reason'  => (string) ( $cached['reason'] ?? '' ),
				);
			}
		}

		$run = self::run( $binary, array( '--version' ), '', self::PROBE_TIMEOUT );

		if ( is_wp_error( $run ) ) {
			$status = array_merge(
				$empty,
				array(
					'binary' => $binary,
					'reason' => $run->get_error_message(),
				)
			);
		} elseif ( 0 !== $run['code'] ) {
			$status = array_merge(
				$empty,
				array(
					'binary' => $binary,
					'reason' => sprintf(
						/* translators: %d: process exit code. */
						__( 'The claude command exited with status %d instead of reporting its version.', 'qwerty-soft-signal' ),
						$run['code']
					),
				)
			);
		} else {
			$status = array(
				'ready'   => true,
				'binary'  => $binary,
				'version' => trim( $run['stdout'] ),
				'reason'  => '',
			);
		}

		set_transient( self::PROBE, $status, $status['ready'] ? self::PROBE_TTL : MINUTE_IN_SECONDS );

		return $status;
	}

	/**
	 * Throw away a cached probe, so the next check runs the binary again.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( self::PROBE );
	}

	/**
	 * Generate one structured reply through the CLI.
	 *
	 * The contract matches {@see AnthropicClient::generate()} exactly — the
	 * decoded object, plus `_usage`, `_model` and `_transport` — so a caller
	 * never has to know which route answered.
	 *
	 * @param string               $system  System prompt.
	 * @param string               $prompt  User message.
	 * @param array<string, mixed> $schema  JSON Schema the reply must satisfy.
	 * @param array<string, mixed> $options model, effort, timeout, images, dirs.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function generate( string $system, string $prompt, array $schema, array $options = array() ) {
		$status = self::status();

		if ( ! $status['ready'] ) {
			return new WP_Error( 'qwerty_soft_cli_unavailable', $status['reason'] );
		}

		$model  = isset( $options['model'] ) ? (string) $options['model'] : AnthropicClient::DEFAULT_MODEL;
		$effort = isset( $options['effort'] ) ? (string) $options['effort'] : 'high';
		$images = isset( $options['images'] ) && is_array( $options['images'] ) ? array_map( 'strval', $options['images'] ) : array();
		$dirs   = isset( $options['dirs'] ) && is_array( $options['dirs'] ) ? array_map( 'strval', $options['dirs'] ) : array();

		if ( ! array_key_exists( $effort, AnthropicClient::effort_levels() ) ) {
			$effort = 'high';
		}

		$args = array(
			'--print',
			'--output-format',
			'json',
			'--model',
			$model,

			/*
			 * Whatever this machine has configured for its own work — project
			 * instructions, skills, hooks, MCP servers — has nothing to do
			 * with converting a design, and any of it could change the answer.
			 * Safe mode leaves authentication and the built-in tools alone,
			 * which is all this needs.
			 */
			'--safe-mode',

			// A conversion is not a conversation; nothing here is worth resuming.
			'--no-session-persistence',

			// The same schema the API route enforces, enforced the same way.
			'--json-schema',
			(string) wp_json_encode( $schema ),
		);

		if ( ! AnthropicClient::is_legacy_model( $model ) ) {
			$args[] = '--effort';
			$args[] = $effort;
		}

		if ( array() === $images && array() === $dirs ) {
			/*
			 * No tools at all. The model has everything it needs in the
			 * prompt, and a tool loop could only wander off into the
			 * filesystem of whoever's machine this is.
			 */
			$args[] = '--tools';
			$args[] = '';
		} else {
			/*
			 * Reading is allowed for exactly the directories named and nothing
			 * else: no writing, no shell. Two callers need it. Screenshots are
			 * files and the CLI has no way to be handed an image inline; and a
			 * design that is an application is more source than one prompt can
			 * hold, so the reader is given the folder it was quoted from and
			 * can open the part of a file the brief had to cut.
			 */
			$args[] = '--tools';
			$args[] = 'Read';
			$args[] = '--allowed-tools';
			$args[] = 'Read';

			foreach ( self::image_dirs( $images, $dirs ) as $dir ) {
				$args[] = '--add-dir';
				$args[] = $dir;
			}
		}

		$stdin = self::compose( $system, $prompt, $images );

		/*
		 * A high-effort section genuinely takes minutes through the CLI, which
		 * carries a full agent turn rather than one HTTP request. The ceiling
		 * is still a ceiling: a run that hangs is killed rather than holding
		 * the admin request open until PHP gives up.
		 */
		$timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : 300;

		$run = self::run( $status['binary'], $args, $stdin, $timeout );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		return self::read_reply( $run, $model );
	}

	/**
	 * Turn the CLI's JSON envelope into the payload callers expect.
	 *
	 * @param array{code:int,stdout:string,stderr:string} $run   Finished process.
	 * @param string                                      $model Model that was asked for.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function read_reply( array $run, string $model ) {
		$envelope = json_decode( trim( $run['stdout'] ), true );

		if ( ! is_array( $envelope ) ) {
			$detail = trim( $run['stderr'] );

			if ( '' === $detail ) {
				$detail = trim( $run['stdout'] );
			}

			return new WP_Error(
				'qwerty_soft_cli_output',
				'' === $detail
					? __( 'The claude command produced no output.', 'qwerty-soft-signal' )
					: sprintf(
						/* translators: %s: the first line the command printed. */
						__( 'The claude command did not return a result: %s', 'qwerty-soft-signal' ),
						self::first_line( $detail )
					)
			);
		}

		if ( ! empty( $envelope['is_error'] ) || 'success' !== ( $envelope['subtype'] ?? 'success' ) ) {
			$detail = isset( $envelope['result'] ) && is_string( $envelope['result'] )
				? self::first_line( $envelope['result'] )
				: (string) ( $envelope['subtype'] ?? '' );

			return new WP_Error(
				'qwerty_soft_cli_failed',
				'' === $detail
					? __( 'The claude command reported a failure.', 'qwerty-soft-signal' )
					: sprintf(
						/* translators: %s: the error the command reported. */
						__( 'The claude command reported a failure: %s', 'qwerty-soft-signal' ),
						$detail
					)
			);
		}

		/*
		 * --json-schema puts the validated object in structured_output. The
		 * text in `result` is the same thing serialised, and is only read when
		 * an older CLI leaves structured_output out.
		 */
		$payload = isset( $envelope['structured_output'] ) && is_array( $envelope['structured_output'] )
			? $envelope['structured_output']
			: null;

		if ( null === $payload && isset( $envelope['result'] ) && is_string( $envelope['result'] ) ) {
			$payload = self::extract_object( $envelope['result'] );
		}

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'qwerty_soft_cli_payload', __( 'The reply did not match the expected format.', 'qwerty-soft-signal' ) );
		}

		$usage = isset( $envelope['usage'] ) && is_array( $envelope['usage'] ) ? $envelope['usage'] : array();

		$payload['_usage']     = $usage;
		$payload['_model']     = self::billed_model( $envelope, $model );
		$payload['_transport'] = 'cli';

		/*
		 * What the same work would have cost through the API. On a
		 * subscription nothing is billed for it, so it is reported as a
		 * comparison rather than added to the spend running total.
		 */
		$payload['_notional_cost'] = isset( $envelope['total_cost_usd'] ) ? (float) $envelope['total_cost_usd'] : 0.0;

		return $payload;
	}

	/**
	 * Which model actually answered.
	 *
	 * A CLI turn can involve more than one model — a small one writes the
	 * session title — so the one that produced the most output tokens is the
	 * one the conversion came from.
	 *
	 * @param array<string, mixed> $envelope Decoded CLI result.
	 * @param string               $asked    Model that was requested.
	 * @return string
	 */
	private static function billed_model( array $envelope, string $asked ): string {
		$usage = isset( $envelope['modelUsage'] ) && is_array( $envelope['modelUsage'] ) ? $envelope['modelUsage'] : array();

		$best   = $asked;
		$tokens = -1;

		foreach ( $usage as $name => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out = (int) ( $row['outputTokens'] ?? 0 );

			if ( $out > $tokens ) {
				$tokens = $out;
				$best   = (string) $name;
			}
		}

		return $best;
	}

	/**
	 * The system prompt, the task and any screenshots, as one stdin message.
	 *
	 * The CLI has no system/user split to hand a prompt to, so the system
	 * prompt is stated first and the task follows it under a rule. This is the
	 * same arrangement the copy-and-paste route uses, and it reads the same way
	 * to the model.
	 *
	 * @param string             $system System prompt.
	 * @param string             $prompt User message.
	 * @param array<int, string> $images Absolute paths to screenshots.
	 * @return string
	 */
	private static function compose( string $system, string $prompt, array $images ): string {
		$lines = array( $system, '', '---', '' );

		if ( array() !== $images ) {
			$lines[] = '## Screenshots of the finished design';
			$lines[] = '';
			$lines[] = 'Read each of these files before you answer. They show what the section is supposed to look like, which the markup alone cannot tell you.';
			$lines[] = '';

			foreach ( $images as $image ) {
				$lines[] = '- ' . str_replace( '\\', '/', $image );
			}

			$lines[] = '';
		}

		$lines[] = $prompt;

		return implode( "\n", $lines );
	}

	/**
	 * The directories the CLI is allowed to read screenshots from.
	 *
	 * @param array<int, string> $images Absolute image paths.
	 * @param array<int, string> $extra  Directories the caller added.
	 * @return array<int, string>
	 */
	private static function image_dirs( array $images, array $extra ): array {
		$dirs = array();

		foreach ( array_merge( $images, $extra ) as $path ) {
			$dir = is_dir( $path ) ? $path : dirname( $path );

			if ( is_dir( $dir ) ) {
				$dirs[ str_replace( '\\', '/', $dir ) ] = true;
			}
		}

		return array_keys( $dirs );
	}

	/**
	 * Pull the outermost JSON object out of a reply that carries prose too.
	 *
	 * @param string $text Reply text.
	 * @return array<string, mixed>|null
	 */
	private static function extract_object( string $text ) {
		$text = trim( $text );

		if ( 1 === preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $text, $fence ) ) {
			$text = $fence[1];
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The first line of a message, for error text that has to stay one line.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private static function first_line( string $text ): string {
		$line = trim( (string) strtok( trim( $text ), "\n" ) );

		return mb_strlen( $line ) > 300 ? mb_substr( $line, 0, 300 ) . '…' : $line;
	}

	/**
	 * Run the binary and collect what it wrote.
	 *
	 * The command is passed as an array, which keeps it away from a shell
	 * entirely: no quoting rules to get wrong, and nothing in a design's file
	 * names can be read as a shell operator.
	 *
	 * Every stream is a file rather than a pipe, and that is the whole design.
	 * Pipes look like the obvious choice and are a trap here:
	 *
	 * - A pipe buffer holds a few kilobytes on Windows. A conversion brief is
	 *   hundreds. Writing it in one call blocks part way through, while the
	 *   child blocks writing a reply nobody is reading — a deadlock that only
	 *   appears once a design is large enough, which is the worst time to
	 *   find it.
	 * - Draining as you write is the usual answer, and it does not work here:
	 *   stream_set_blocking() has no effect on the pipes proc_open() makes on
	 *   Windows, so every read blocks until the child closes the stream, and
	 *   stream_select() cannot be relied on to wake for them either. The
	 *   timeout below could not be enforced through a blocking read.
	 *
	 * Files have none of that. The child reads its prompt at its own pace and
	 * writes as much as it likes; the parent watches the process rather than
	 * the streams, so a run that never ends is stopped on time, and a prompt
	 * of any size arrives whole or not at all. The three files are removed
	 * before this returns, on every path out of it.
	 *
	 * @param string             $binary  Path to the binary.
	 * @param array<int, string> $args    Arguments.
	 * @param string             $stdin   Text to write to standard input.
	 * @param int                $timeout Seconds before the process is killed.
	 * @return array{code:int,stdout:string,stderr:string}|WP_Error
	 */
	private static function run( string $binary, array $args, string $stdin, int $timeout ) {
		$files = self::scratch_files();

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		list( $in, $out, $err ) = $files;

		$clean = static function () use ( $in, $out, $err ): void {
			foreach ( array( $in, $out, $err ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		};

		if ( false === file_put_contents( $in, $stdin ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A scratch file this method creates and deletes.
			$clean();

			return new WP_Error(
				'qwerty_soft_cli_scratch',
				__( 'The brief could not be written to a temporary file, so the claude command was not run.', 'qwerty-soft-signal' )
			);
		}

		/**
		 * Filter the command that is run, as an argv array.
		 *
		 * The binary is not always the thing to execute. A machine may keep
		 * Claude Code inside WSL or a container, or behind a wrapper script
		 * that sets an environment first, and the answer there is a different
		 * argv rather than a different path — `wsl claude …`, `docker exec …`.
		 * Whatever is returned is executed as an array, so it never reaches a
		 * shell and nothing in it needs escaping.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, string> $command The full argv, binary first.
		 * @param string             $binary  The resolved binary path.
		 * @param array<int, string> $args    The arguments the theme built.
		 */
		$command = (array) apply_filters(
			'qwerty_soft/claude_cli_command',
			array_merge( array( $binary ), $args ),
			$binary,
			$args
		);

		$pipes = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Running the locally installed Claude Code binary is the whole point of this class; the command is an array, so no shell is involved.
		$process = proc_open(
			array_map( 'strval', $command ),
			array(
				0 => array( 'file', $in, 'r' ),
				1 => array( 'file', $out, 'w' ),
				2 => array( 'file', $err, 'w' ),
			),
			$pipes,
			self::working_directory(),
			null
		);

		if ( ! is_resource( $process ) ) {
			$clean();

			return new WP_Error(
				'qwerty_soft_cli_spawn',
				__( 'The claude command could not be started. Check the path to the binary, and that the web server user is allowed to run it.', 'qwerty-soft-signal' )
			);
		}

		$deadline = microtime( true ) + $timeout;
		$code     = -1;

		while ( true ) {
			$status = proc_get_status( $process );

			if ( ! is_array( $status ) || empty( $status['running'] ) ) {
				/*
				 * proc_get_status() reports the real exit code only on the
				 * first call after the process ends; proc_close() would return
				 * -1 by then. Whichever of the two saw it first is the answer.
				 */
				$code = is_array( $status ) ? (int) $status['exitcode'] : -1;

				proc_close( $process );
				break;
			}

			if ( microtime( true ) > $deadline ) {
				proc_terminate( $process );
				proc_close( $process );
				$clean();

				return new WP_Error(
					'qwerty_soft_cli_timeout',
					sprintf(
						/* translators: %d: number of seconds. */
						__( 'The claude command was still running after %d seconds and was stopped. Try a lower effort setting, or a smaller section.', 'qwerty-soft-signal' ),
						$timeout
					)
				);
			}

			// A tenth of a second: fast enough not to add latency, slow enough not to spin a core.
			usleep( 100000 );
		}

		$stdout = is_file( $out ) ? (string) file_get_contents( $out ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A scratch file this method created.
		$stderr = is_file( $err ) ? (string) file_get_contents( $err ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A scratch file this method created.

		$clean();

		return array(
			'code'   => $code,
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * Three writable scratch paths for one run, or why there are none.
	 *
	 * The prompt carries the design's own markup, which is already on disk in
	 * the archive, so writing it to a scratch file adds no exposure. It is
	 * still given an unguessable name and removed as soon as the run is over.
	 *
	 * @return array{0:string,1:string,2:string}|WP_Error
	 */
	private static function scratch_files() {
		$dir = rtrim( str_replace( '\\', '/', get_temp_dir() ), '/' );

		if ( '' === $dir || ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return new WP_Error(
				'qwerty_soft_cli_temp',
				__( 'There is no writable temporary directory for the claude command to read its brief from.', 'qwerty-soft-signal' )
			);
		}

		$stem = $dir . '/qwerty-soft-signal-cli-' . wp_generate_password( 12, false );

		return array( $stem . '.in', $stem . '.out', $stem . '.err' );
	}

	/**
	 * Where the process is started from.
	 *
	 * The uploads directory: somewhere that exists, is writable, and holds
	 * nothing but this site's own files. Starting in the theme would put the
	 * theme's source in reach of a stray tool call, and starting in whatever
	 * the web server's working directory happens to be is unpredictable.
	 *
	 * @return string|null
	 */
	private static function working_directory(): ?string {
		$uploads = wp_get_upload_dir();

		$dir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		return '' !== $dir && is_dir( $dir ) ? $dir : null;
	}
}
