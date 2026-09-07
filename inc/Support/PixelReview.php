<?php
/**
 * Looks at a built page beside its design, and has the blocks corrected until they agree.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The loop every earlier pass lacked.
 *
 * A build measured itself in words and elements, which catches a lost row
 * and a stamped repeat but not a wrong colour, a collapsed grid or a font
 * that fell back — and nothing that ran ever looked at the finished pixels.
 * This does. Both pages are photographed in the same browser at the same
 * width; where they part ways, the model is given the two photographs, the
 * difference between them, the design's own file and the generated blocks,
 * and is asked to correct the blocks. Then both are photographed again.
 *
 * It runs only where it can: the `claude` binary on this machine, Node and
 * Playwright beside the theme. A build elsewhere skips it and says so.
 */
final class PixelReview {

	/**
	 * How many times the model may be asked about one page.
	 */
	public const ROUNDS = 2;

	/**
	 * Share of differing pixels at or below which a page is left alone.
	 */
	public const GOOD = 4.0;

	/**
	 * Transient holding the one-time key that lets the browser see a draft.
	 */
	private const TOKEN = 'qwerty_soft_review_token';

	/**
	 * Seconds a photograph of two pages may take before it is abandoned.
	 */
	private const SHOOT_TIMEOUT = 300;

	/**
	 * Whether this machine can run the loop, or why it cannot.
	 *
	 * @return string Empty when everything is in place.
	 */
	public static function unavailable(): string {
		if ( 'cli' !== ModelGateway::resolve() ) {
			return __( 'The pixel review needs Claude Code on this machine: it edits the generated blocks as files.', 'qwerty-soft-signal' );
		}

		if ( '' === self::node() ) {
			return __( 'The pixel review needs Node beside the theme, to photograph both pages.', 'qwerty-soft-signal' );
		}

		if ( ! is_file( QSOFT_DIR . '/node_modules/playwright/package.json' ) ) {
			return __( 'The pixel review needs Playwright: run `npm install && npx playwright install chromium` in the theme.', 'qwerty-soft-signal' );
		}

		return '';
	}

	/**
	 * Review one built page against its design.
	 *
	 * @param array<string, mixed> $job Job record: root, model, effort.
	 * @param array<string, mixed> $row The page's row of the report: id, file, slug, title.
	 * @return array<string, mixed>|null What happened, or null when the loop could not run.
	 */
	public static function page( array $job, array $row ): ?array {
		$why = self::unavailable();

		if ( '' !== $why ) {
			ImportLog::add( 'review', $why );

			return null;
		}

		$id     = (int) ( $row['id'] ?? 0 );
		$file   = (string) ( $row['file'] ?? '' );
		$root   = rtrim( str_replace( '\\', '/', (string) ( $job['root'] ?? '' ) ), '/' );
		$origin = $root . '/' . ltrim( $file, '/' );

		if ( $id <= 0 || ! is_file( $origin ) ) {
			return null;
		}

		$blocks = self::blocks_of( $id );

		if ( array() === $blocks ) {
			return null;
		}

		$name  = BlockWriter::slug( (string) ( $row['slug'] ?? basename( $file, '.html' ) ) );
		$out   = self::out_dir( $name );
		$calls = array();
		$seen  = array();
		$first = null;
		$last  = null;

		for ( $round = 1; $round <= self::ROUNDS + 1; $round++ ) {
			$shot = self::shoot( $name, $id, $origin, $out );

			if ( is_wp_error( $shot ) ) {
				ImportLog::add( 'review', $shot->get_error_message() );

				break;
			}

			$last = $shot;

			if ( null === $first ) {
				$first = $shot;
			}

			ImportLog::add(
				'review',
				sprintf(
					/* translators: 1: page title, 2: share of pixels that differ, 3: round number. */
					__( '"%1$s": %2$s%% of pixels differ from the design (look %3$d).', 'qwerty-soft-signal' ),
					(string) ( $row['title'] ?? $name ),
					(string) $shot['percent'],
					$round
				)
			);

			if ( (float) $shot['percent'] <= self::GOOD || $round > self::ROUNDS ) {
				break;
			}

			$reply = ClaudeCli::agent(
				self::system(),
				self::task( $origin, $blocks, $shot, $round ),
				self::schema(),
				array(
					'model'   => (string) ( $job['model'] ?? AnthropicClient::DEFAULT_MODEL ),
					'effort'  => (string) ( $job['effort'] ?? 'high' ),
					'images'  => array( $shot['origin'], $shot['live'], $shot['diff'] ),
					'dirs'    => array_merge( array( $root, BlockWriter::dir() ), array_column( $blocks, 'dir' ) ),
					'timeout' => 900,
				)
			);

			if ( is_wp_error( $reply ) ) {
				$calls[] = array(
					'ok'    => false,
					'error' => $reply->get_error_message(),
				);

				ImportLog::add( 'review', $reply->get_error_message() );

				break;
			}

			$calls[] = array(
				'ok'        => true,
				'usage'     => (array) ( $reply['_usage'] ?? array() ),
				'model'     => (string) ( $reply['_model'] ?? '' ),
				'transport' => (string) ( $reply['_transport'] ?? 'cli' ),
			);

			$changed = array();

			foreach ( (array) ( $reply['changed'] ?? array() ) as $change ) {
				$path = (string) ( $change['file'] ?? '' );

				if ( '' !== $path ) {
					$changed[] = $path;
					$seen[]    = array(
						'file' => $path,
						'why'  => (string) ( $change['why'] ?? '' ),
					);
				}
			}

			ImportLog::add(
				'review',
				'' === trim( (string) ( $reply['notes'] ?? '' ) )
					? sprintf(
						/* translators: %d: how many files. */
						_n( 'The review changed %d file.', 'The review changed %d files.', count( $changed ), 'qwerty-soft-signal' ),
						count( $changed )
					)
					: sprintf(
						/* translators: 1: how many files, 2: the model's note. */
						__( 'The review changed %1$d file(s): %2$s', 'qwerty-soft-signal' ),
						count( $changed ),
						trim( (string) $reply['notes'] )
					)
			);

			if ( array() === $changed || ! empty( $reply['done'] ) ) {
				// Nothing more to be done from here; the next look would say the same.
				if ( array() === $changed ) {
					break;
				}
			}
		}

		return array(
			'before'  => is_array( $first ) ? (float) $first['percent'] : null,
			'after'   => is_array( $last ) ? (float) $last['percent'] : null,
			'changed' => $seen,
			'report'  => $out . '/report.html',
			'calls'   => $calls,
		);
	}

	/**
	 * Let the review's browser see a draft, once, from this machine only.
	 *
	 * Runs on `init`. The key is minted by shoot() for one photograph, lives
	 * for a minute, and is accepted only from the loopback address — the
	 * headless browser runs beside PHP, and nobody else has any business
	 * presenting it.
	 *
	 * @return void
	 */
	public static function admit(): void {
		if ( ! isset( $_GET['qsoft_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key itself is the credential, checked below.
			return;
		}

		$given = sanitize_text_field( wp_unslash( (string) $_GET['qsoft_review'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked against the minted key.
		$from  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( ! in_array( $from, array( '127.0.0.1', '::1' ), true ) ) {
			return;
		}

		$minted = get_transient( self::TOKEN );

		if ( ! is_array( $minted ) || '' === $given || ! hash_equals( (string) ( $minted['key'] ?? '' ), $given ) ) {
			return;
		}

		wp_set_current_user( (int) ( $minted['user'] ?? 0 ) );

		// The photograph is of the page, not of the toolbar across its top.
		add_filter( 'show_admin_bar', '__return_false' );
	}

	/**
	 * Photograph both pages and measure the difference.
	 *
	 * @param string $name   Page name, for the files.
	 * @param int    $id     The built page.
	 * @param string $origin Absolute path of the design's file.
	 * @param string $out    Directory the pictures go in.
	 * @return array{percent:float,grew:int,origin:string,live:string,diff:string}|WP_Error
	 */
	private static function shoot( string $name, int $id, string $origin, string $out ) {
		$key = wp_generate_password( 32, false );

		set_transient(
			self::TOKEN,
			array(
				'key'  => $key,
				'user' => get_current_user_id(),
			),
			MINUTE_IN_SECONDS * 5
		);

		$live = add_query_arg(
			array(
				'preview'      => 'true',
				'qsoft_review' => $key,
			),
			(string) get_permalink( $id )
		);

		$manifest = $out . '/manifest.json';

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The theme's own artifacts directory.
			$manifest,
			(string) wp_json_encode(
				array(
					array(
						'name'   => $name,
						'live'   => $live,
						'origin' => $origin,
					),
				),
				JSON_PRETTY_PRINT
			)
		);

		$run = self::node_run(
			array( QSOFT_DIR . '/tools/pixel-diff.mjs', '--manifest', $manifest, '--out', $out ),
			self::SHOOT_TIMEOUT
		);

		delete_transient( self::TOKEN );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$report = json_decode( (string) file_get_contents( $out . '/report.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Written by the script just run.
		$row    = is_array( $report ) ? ( $report['rows'][0] ?? null ) : null;

		if ( ! is_array( $row ) || ! isset( $row['percent'] ) || null === $row['percent'] ) {
			return new WP_Error(
				'qwerty_soft_review_shot',
				sprintf(
					/* translators: %s: what the script said. */
					__( 'The pages could not be photographed: %s', 'qwerty-soft-signal' ),
					(string) ( $row['error'] ?? trim( (string) $run['stdout'] . ' ' . (string) $run['stderr'] ) )
				)
			);
		}

		return array(
			'percent' => (float) $row['percent'],
			'grew'    => (int) ( $row['grew'] ?? 0 ),
			'origin'  => $out . '/' . $name . '-origin.png',
			'live'    => $out . '/' . $name . '-live.png',
			'diff'    => $out . '/' . $name . '-diff.png',
		);
	}

	/**
	 * The generated blocks a page is made of.
	 *
	 * @param int $id Page.
	 * @return array<int, array{name:string,dir:string}>
	 */
	private static function blocks_of( int $id ): array {
		$page = get_post( $id );

		if ( null === $page ) {
			return array();
		}

		$found = array();

		foreach ( parse_blocks( (string) $page->post_content ) as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			if ( ! str_starts_with( $name, 'qs/design-' ) ) {
				continue;
			}

			$dir = BlockWriter::dir() . '/' . substr( $name, strlen( 'qs/design-' ) );

			if ( is_dir( $dir ) && ! isset( $found[ $name ] ) ) {
				$found[ $name ] = array(
					'name' => $name,
					'dir'  => $dir,
				);
			}
		}

		return array_values( $found );
	}

	/**
	 * What the model is, for this task.
	 *
	 * @return string
	 */
	public static function system(): string {
		return implode(
			"\n",
			array(
				'You are correcting a WordPress page that was generated from a static HTML design, so that it looks like the design.',
				'',
				'How the page is made: each section of the design became a block under blocks/design/{slug}/ — render.php holds the design\'s own markup with field values echoed in, fields.json is the ACF field group, and the design\'s stylesheet is installed whole as blocks/design/_canonical.css (every selector lifted by `:is(.qs-design, .qs-design *)`, which is deliberate).',
				'',
				'Rules you must keep:',
				'- The design is the brief. Make the built page match it; never make the design match the page.',
				'- Keep the design\'s class names, structure and values. Do not translate anything to theme presets.',
				'- Keep every `data-qs-field`, `data-qs-type`, `data-qs-row` and `data-qs-index` attribute and every `<?php … ?>` echo exactly as it is; the editor depends on them. You may move them with the element they sit on, never remove them.',
				'- Escape everything you echo the way the existing echoes do (esc_html, esc_attr, DesignField::url, DesignField::inline).',
				'- Edit only files under the directories you were given. Do not create blocks, delete blocks or touch theme.json.',
				'- Prefer the smallest change that fixes what the difference image shows: a missing class on a wrapper, a rule the stylesheet lost, a picture that did not resolve, a repeated row rendered once.',
				'- If the remaining difference is content the site legitimately owns — a live menu, real records instead of sample cards, the year — leave it and say so.',
				'',
				'Answer with the JSON the schema asks for: the files you changed and why, whether another look would help, and a short note.',
			)
		);
	}

	/**
	 * The task for one round.
	 *
	 * @param string                                    $origin Design file.
	 * @param array<int, array{name:string,dir:string}> $blocks The page's blocks.
	 * @param array<string, mixed>                      $shot   The photographs.
	 * @param int                                       $round  Which round this is.
	 * @return string
	 */
	public static function task( string $origin, array $blocks, array $shot, int $round ): string {
		$lines = array(
			sprintf( '## Look %d', $round ),
			'',
			sprintf( '%s%% of the pixels differ between the design and the built page%s.', (string) $shot['percent'], 0 !== (int) $shot['grew'] ? sprintf( ' (the built page is %+dpx taller)', (int) $shot['grew'] ) : '' ),
			'',
			'Three screenshots, full page, same width:',
			'- design:     ' . str_replace( '\\', '/', (string) $shot['origin'] ),
			'- built page: ' . str_replace( '\\', '/', (string) $shot['live'] ),
			'- difference: ' . str_replace( '\\', '/', (string) $shot['diff'] ) . '  (red where they differ)',
			'',
			'The design\'s own file, with its stylesheet linked from it: ' . str_replace( '\\', '/', $origin ),
			'',
			'The blocks this page is built from, top to bottom:',
		);

		foreach ( $blocks as $block ) {
			$lines[] = '- ' . $block['name'] . '  →  ' . str_replace( '\\', '/', $block['dir'] ) . '/';
		}

		$lines[] = '';
		$lines[] = 'The stylesheet they share: ' . str_replace( '\\', '/', BlockWriter::dir() ) . '/_canonical.css';
		$lines[] = '';
		$lines[] = 'Read the three screenshots first. Find what the difference image shows, find the block or rule responsible, and fix it in place. Then answer.';

		return implode( "\n", $lines );
	}

	/**
	 * What the model has to answer with.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'changed', 'done', 'notes' ),
			'properties'           => array(
				'changed' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'file', 'why' ),
						'properties'           => array(
							'file' => array( 'type' => 'string' ),
							'why'  => array( 'type' => 'string' ),
						),
					),
				),
				'done'    => array(
					'type'        => 'boolean',
					'description' => 'True when what remains is content the site owns and another look would not help.',
				),
				'notes'   => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Where one page's photographs go.
	 *
	 * @param string $name Page name.
	 * @return string Absolute directory.
	 */
	private static function out_dir( string $name ): string {
		$dir = QSOFT_DIR . '/artifacts/pixels/review/' . $name;

		wp_mkdir_p( $dir );

		return str_replace( '\\', '/', $dir );
	}

	/**
	 * The Node binary, on PATH or where it usually is.
	 *
	 * @return string Absolute path, or empty.
	 */
	public static function node(): string {
		$candidates = array( 'node' );

		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$candidates[] = 'C:/Program Files/nodejs/node.exe';
			$candidates[] = (string) getenv( 'LOCALAPPDATA' ) . '/Programs/nodejs/node.exe';
		} else {
			$candidates[] = '/usr/local/bin/node';
			$candidates[] = '/usr/bin/node';
		}

		foreach ( $candidates as $candidate ) {
			if ( 'node' !== $candidate && ! is_file( $candidate ) ) {
				continue;
			}

			$run = self::node_run( array( '-v' ), 10, $candidate );

			if ( ! is_wp_error( $run ) && str_starts_with( trim( (string) $run['stdout'] ), 'v' ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Run Node with arguments, to files rather than pipes, with a deadline.
	 *
	 * @param array<int, string> $args    Arguments.
	 * @param int                $timeout Seconds.
	 * @param string             $binary  Node, when already known.
	 * @return array{code:int,stdout:string,stderr:string}|WP_Error
	 */
	private static function node_run( array $args, int $timeout, string $binary = '' ) {
		$binary = '' === $binary ? self::node() : $binary;

		if ( '' === $binary || ! function_exists( 'proc_open' ) ) {
			return new WP_Error( 'qwerty_soft_review_node', __( 'Node could not be run from PHP on this machine.', 'qwerty-soft-signal' ) );
		}

		$stdout = (string) tempnam( sys_get_temp_dir(), 'qsr' );
		$stderr = (string) tempnam( sys_get_temp_dir(), 'qsr' );

		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- A local script of the theme's own, run with an explicit argument list.
			array_merge( array( $binary ), $args ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'file', $stdout, 'w' ),
				2 => array( 'file', $stderr, 'w' ),
			),
			$pipes,
			QSOFT_DIR
		);

		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'qwerty_soft_review_node', __( 'Node could not be started.', 'qwerty-soft-signal' ) );
		}

		fclose( $pipes[0] );

		$deadline = microtime( true ) + $timeout;
		$code     = -1;

		while ( true ) {
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				$code = (int) $status['exitcode'];

				break;
			}

			if ( microtime( true ) > $deadline ) {
				proc_terminate( $process );
				proc_close( $process );

				return new WP_Error( 'qwerty_soft_review_node', __( 'The photograph took too long and was abandoned.', 'qwerty-soft-signal' ) );
			}

			usleep( 200000 );
		}

		proc_close( $process );

		$result = array(
			'code'   => $code,
			'stdout' => (string) file_get_contents( $stdout ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Temporary file of this process.
			'stderr' => (string) file_get_contents( $stderr ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Temporary file of this process.
		);

		unlink( $stdout ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary file of this process.
		unlink( $stderr ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary file of this process.

		if ( 0 !== $code ) {
			return new WP_Error(
				'qwerty_soft_review_node',
				sprintf(
					/* translators: %s: the first line the script printed. */
					__( 'The photograph failed: %s', 'qwerty-soft-signal' ),
					trim( (string) strtok( trim( $result['stderr'] . "\n" . $result['stdout'] ), "\n" ) )
				)
			);
		}

		return $result;
	}
}
