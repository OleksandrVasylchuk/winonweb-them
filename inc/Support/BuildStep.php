<?php
/**
 * One step of a site build, whoever asked for it.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The state machine one build step runs through, shared by both drivers.
 *
 * A build is driven either by the browser, one request per step, or by
 * WP-Cron, one tick per step. They used to be two copies of the same machine,
 * and the copies had drifted: the cron one honoured a stop, counted attempts,
 * set a page aside after three and came back to it at the end; the request
 * one did none of that, and took no claim on the step either — so two tabs,
 * or one double-click, built the same page side by side.
 *
 * Now there is one copy. Both drivers hand a job and a step in here and get
 * back what happened, in a shape each can answer with: a boolean for the tick,
 * a response or an error for the request. The job is updated in place, so the
 * caller holds the record as the step left it.
 */
final class BuildStep {

	/**
	 * How many times one step may be started before it is set aside.
	 *
	 * A step is booked with cron before it runs, so a step that kills the
	 * process still leaves a tick behind and gets another go. Without a
	 * ceiling that is an endless loop; with one, a step that cannot survive
	 * three attempts is reported and the build moves on to the next page.
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * The build has already finished; there is nothing to run.
	 *
	 * @var string
	 */
	public const FINISHED = 'finished';

	/**
	 * The build was stopped by hand and waits to be continued.
	 *
	 * @var string
	 */
	public const STOPPED = 'stopped';

	/**
	 * The design is no longer in uploads; the job has been ended.
	 *
	 * @var string
	 */
	public const GONE = 'gone';

	/**
	 * Another process holds the claim on this build's current step.
	 *
	 * @var string
	 */
	public const BUSY = 'busy';

	/**
	 * Pages set aside earlier were put back in the queue instead of finishing.
	 *
	 * @var string
	 */
	public const RETRYING = 'retrying';

	/**
	 * The step had used up its attempts and was set aside, or left out.
	 *
	 * @var string
	 */
	public const SET_ASIDE = 'set_aside';

	/**
	 * A page, or the chrome, was built.
	 *
	 * @var string
	 */
	public const BUILT = 'built';

	/**
	 * A page could not be built; the reason is in `result`.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * The finishing step failed; the reason is in `result`, the job is kept.
	 *
	 * @var string
	 */
	public const UNFINISHED = 'unfinished';

	/**
	 * The build finished and its job has been ended; the report is in `result`.
	 *
	 * @var string
	 */
	public const ENDED = 'ended';

	/**
	 * Run one step of a build.
	 *
	 * @param int                  $user Whose build it is.
	 * @param array<string, mixed> $job  Job record; updated in place.
	 * @param string               $key  Step: page, chrome or finish.
	 * @param string               $file Page file for a page step, else ''.
	 * @return array{state:string, more:bool, result:mixed, archive_removed:bool, retry:array<int, string>}
	 */
	public static function run( int $user, array &$job, string $key, string $file ): array {
		if ( ! empty( $job['completed']['finish'] ) ) {
			return self::outcome( self::FINISHED, false );
		}

		/*
		 * Read at the top of every step rather than only when a tick begins,
		 * so a build stopped in the middle of a long page stops at the end of
		 * that page instead of running to the end of its burst.
		 */
		if ( ! empty( $job['stopped'] ) ) {
			return self::outcome( self::STOPPED, false );
		}

		$root = self::root_of( $job );

		if ( '' === $root ) {
			ImportLog::add( 'build', __( 'The design this build was reading is no longer in uploads, so the build stopped.', 'qwerty-soft-signal' ) );
			ImportSession::end_job();

			return self::outcome( self::GONE, false );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			/*
			 * A structural page is milliseconds. A guided one is a model call
			 * per section, each of which can take minutes at high effort, so
			 * the ceiling has to be the length of the slowest page rather than
			 * of the fastest.
			 */
			set_time_limit( empty( $job['smart'] ) ? 120 : 1800 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- One step, bounded by the transport's own timeouts.
		}

		$mark = 'page' === $key ? 'page:' . $file : $key;

		// Before calling it finished: anything set aside earlier gets its second pass now.
		if ( 'finish' === $key && self::retry_deferred( $job ) ) {
			$retry = is_array( $job['retrying'] ?? null ) ? $job['retrying'] : array();
			unset( $job['retrying'] );

			/*
			 * A build the browser is driving cannot take a page back onto its
			 * list — the list was sent when the build started — so the second
			 * pass is handed to the server, which reads the job rather than
			 * the list. The screen follows a server-run build already.
			 */
			if ( empty( $job['unattended'] ) ) {
				$job['unattended'] = true;

				ImportLog::add( 'build', __( 'The second pass runs on the server. This screen follows it; you can close the tab.', 'qwerty-soft-signal' ) );
			}

			ImportSession::update_job( $job );
			BuildRunner::schedule( $user );

			return self::outcome( self::RETRYING, true, null, false, $retry );
		}

		/*
		 * Somebody else may already be on this step.
		 *
		 * add_option() is an INSERT against a unique key: exactly one caller
		 * can win it, whatever the timing. That is the claim — and it is the
		 * same claim for a tick and for a request, so a tab cannot start the
		 * page a tick is converting, nor a second tab the page a first one is.
		 */
		if ( ! BuildRunner::claim( $user, $mark, $job ) ) {
			return self::outcome( self::BUSY, true );
		}

		try {
			return self::perform( $user, $job, $key, $file, $mark, $root );
		} finally {
			// Whatever happened — a result, an error, a killed process mid-write.
			BuildRunner::release( $user );
		}
	}

	/**
	 * Do the work of one claimed step.
	 *
	 * Split out from run() only so the claim can be released on every way
	 * out of it, including the ones that throw.
	 *
	 * @param int                  $user Whose build it is.
	 * @param array<string, mixed> $job  Job record; updated in place.
	 * @param string               $key  Step key.
	 * @param string               $file Page file, or ''.
	 * @param string               $mark Step mark.
	 * @param string               $root Design root, known to exist.
	 * @return array{state:string, more:bool, result:mixed, archive_removed:bool, retry:array<int, string>}
	 */
	private static function perform( int $user, array &$job, string $key, string $file, string $mark, string $root ): array {
		$attempts          = is_array( $job['attempts'] ?? null ) ? $job['attempts'] : array();
		$attempts[ $mark ] = (int) ( $attempts[ $mark ] ?? 0 ) + 1;
		$job['attempts']   = $attempts;
		$job['working']    = $mark;
		$job['heartbeat']  = time();

		/*
		 * When this step was claimed, so a screen that was not here when it
		 * started can still show a clock running on the right row.
		 */
		$job['started'] = time();

		if ( $attempts[ $mark ] > self::MAX_ATTEMPTS ) {
			/*
			 * Set aside, not thrown away. Whatever stopped this step three
			 * times may well have been the machine being busy rather than the
			 * page being impossible, so the build carries on and comes back
			 * to it once everything else is done. Only a page that fails that
			 * second pass too is left out, and the build says so rather than
			 * quietly producing a site with a hole in it.
			 */
			$again = empty( $job['retried'] );

			ImportLog::add(
				'build',
				$again
					? sprintf(
						/* translators: 1: what was being built, 2: how many attempts. */
						__( '%1$s stopped the build %2$d times. Moving on for now; it gets another go once the rest is done.', 'qwerty-soft-signal' ),
						'' === $file ? $key : $file,
						self::MAX_ATTEMPTS
					)
					: sprintf(
						/* translators: %s: what was being built. */
						__( '%s could not be built on the second pass either, so the build finished without it.', 'qwerty-soft-signal' ),
						'' === $file ? $key : $file
					)
			);

			$job['completed'][ $mark ] = true;
			$job['done']               = 2 + count( $job['completed'] );

			if ( $again ) {
				$deferred        = is_array( $job['deferred'] ?? null ) ? $job['deferred'] : array();
				$deferred[]      = $mark;
				$job['deferred'] = array_values( array_unique( $deferred ) );
			}

			ImportSession::update_job( $job );
			self::book( $user, $job );

			return self::outcome( self::SET_ASIDE, true );
		}

		/*
		 * Written, and booked, before the work.
		 *
		 * WP-Cron deletes an event when it fires, so a step that never returns
		 * — a worker recycled, a model call the server cut off — used to leave
		 * an empty queue and a build frozen at "2 of 7". Every step is
		 * idempotent, a page built twice updates rather than duplicates, so
		 * the safe order is: book the retry, then do the work.
		 */
		ImportSession::update_job( $job );
		self::book( $user, $job );

		if ( 'page' === $key ) {
			ImportLog::add(
				'build',
				sprintf(
					/* translators: 1: page file, 2: step, 3: steps in total. */
					__( 'Building %1$s — step %2$d of %3$d…', 'qwerty-soft-signal' ),
					$file,
					(int) $job['done'] + 1,
					(int) $job['total']
				)
			);

			$started = microtime( true );
			$result  = SiteAssembler::page( $job, $file );

			$job['completed'][ 'page:' . $file ] = true;
			$job['done']                         = 2 + count( $job['completed'] );

			ImportSession::update_job( $job );

			if ( is_wp_error( $result ) ) {
				ImportLog::add(
					'build',
					sprintf(
						/* translators: 1: page file, 2: the reason. */
						__( '%1$s was not built: %2$s', 'qwerty-soft-signal' ),
						$file,
						$result->get_error_message()
					)
				);

				return self::outcome( self::FAILED, true, $result );
			}

			ImportLog::add(
				'build',
				sprintf(
					/* translators: 1: page title, 2: sections, 3: seconds. */
					__( 'Built “%1$s” — %2$d sections, %3$ds.', 'qwerty-soft-signal' ),
					(string) ( $result['title'] ?? $file ),
					(int) ( $result['sections'] ?? 0 ),
					(int) round( microtime( true ) - $started )
				),
				array( 'id' => (int) ( $result['id'] ?? 0 ) )
			);

			return self::outcome( self::BUILT, true, $result );
		}

		if ( 'chrome' === $key ) {
			ImportLog::add( 'build', __( 'Building the menu, the header and the footer…', 'qwerty-soft-signal' ) );

			$result = SiteAssembler::chrome( $job );

			$job['completed']['chrome'] = true;
			$job['done']                = 2 + count( $job['completed'] );

			ImportSession::update_job( $job );

			return self::outcome( self::BUILT, true, $result );
		}

		$report = SiteAssembler::finish( $job );

		$job['completed']['finish'] = true;
		$job['done']                = (int) $job['total'];

		ImportSession::update_job( $job );

		if ( is_wp_error( $report ) ) {
			ImportLog::add(
				'build',
				sprintf(
					/* translators: %s: the reason. */
					__( 'The build could not be finished: %s', 'qwerty-soft-signal' ),
					$report->get_error_message()
				)
			);

			return self::outcome( self::UNFINISHED, false, $report );
		}

		/*
		 * The design has done its job. Unless asked to keep it for another
		 * run, the unpacked copy goes — it is the largest thing an import
		 * leaves in uploads and nothing on the site refers to it.
		 */
		$archive_removed = false;

		if ( empty( $job['keep_archive'] ) ) {
			$archive_removed = DesignArchive::remove( $root );
		}

		ImportLog::add(
			'build',
			sprintf(
				/* translators: %d: number of pages. */
				_n( 'The build is done: %d page is on the site.', 'The build is done: %d pages are on the site.', count( (array) ( $report['pages'] ?? array() ) ), 'qwerty-soft-signal' ),
				count( (array) ( $report['pages'] ?? array() ) )
			)
		);

		ImportSession::end_job();

		return self::outcome( self::ENDED, false, $report, $archive_removed );
	}

	/**
	 * Put the pages that were set aside back in the queue, once, at the end.
	 *
	 * A step is given up on after three goes so one bad page cannot hold a
	 * build for ever. That is a safety valve, not a verdict: most of what
	 * stops a step is the moment rather than the page — a machine busy with
	 * the section before it, a model call the server cut off. So when
	 * everything else is built, whatever was set aside is queued again with a
	 * clean count, and this time the machine has nothing else to do.
	 *
	 * The marks put back are left on `$job['retrying']` for the caller to
	 * read and remove.
	 *
	 * @param array<string, mixed> $job Job record, updated in place.
	 * @return bool Whether anything was put back.
	 */
	private static function retry_deferred( array &$job ): bool {
		$deferred = is_array( $job['deferred'] ?? null ) ? $job['deferred'] : array();

		if ( array() === $deferred || ! empty( $job['retried'] ) ) {
			return false;
		}

		$attempts = is_array( $job['attempts'] ?? null ) ? $job['attempts'] : array();

		foreach ( $deferred as $mark ) {
			unset( $job['completed'][ $mark ], $attempts[ $mark ] );
		}

		$job['attempts'] = $attempts;
		$job['retried']  = true;
		$job['retrying'] = array_values( $deferred );
		$job['deferred'] = array();
		$job['done']     = 2 + count( $job['completed'] );

		ImportLog::add(
			'build',
			sprintf(
				/* translators: %d: how many pages are being tried again. */
				_n(
					'Everything else is built; trying the %d page that was set aside.',
					'Everything else is built; trying the %d pages that were set aside.',
					count( $deferred ),
					'qwerty-soft-signal'
				),
				count( $deferred )
			)
		);

		return true;
	}

	/**
	 * Book the next tick, for a build the server is running.
	 *
	 * A build the browser drives asks for its next step itself; booking a
	 * tick for it would have cron decline the job anyway.
	 *
	 * @param int                  $user Whose build it is.
	 * @param array<string, mixed> $job  Job record.
	 * @return void
	 */
	private static function book( int $user, array $job ): void {
		if ( ! empty( $job['unattended'] ) ) {
			BuildRunner::schedule( $user );
		}
	}

	/**
	 * Where the design this job is reading still is, or an empty string.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return string
	 */
	public static function root_of( array $job ): string {
		$root = (string) ( $job['root'] ?? '' );

		return '' !== $root && is_dir( $root ) ? $root : '';
	}

	/**
	 * Shape one outcome.
	 *
	 * @param string             $state           One of the state constants.
	 * @param bool               $more            Whether the build has more to do.
	 * @param mixed              $result          What the step produced, or its WP_Error.
	 * @param bool               $archive_removed Whether the unpacked design was removed.
	 * @param array<int, string> $retry           Marks put back in the queue.
	 * @return array{state:string, more:bool, result:mixed, archive_removed:bool, retry:array<int, string>}
	 */
	private static function outcome( string $state, bool $more, $result = null, bool $archive_removed = false, array $retry = array() ): array {
		return array(
			'state'           => $state,
			'more'            => $more,
			'result'          => $result,
			'archive_removed' => $archive_removed,
			'retry'           => $retry,
		);
	}

	/**
	 * Whether an outcome carries an error the caller should pass on.
	 *
	 * @param array{state:string, more:bool, result:mixed} $outcome One outcome of run().
	 * @return bool
	 */
	public static function failed( array $outcome ): bool {
		return $outcome['result'] instanceof WP_Error;
	}
}
