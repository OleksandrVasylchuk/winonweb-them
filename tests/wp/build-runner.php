<?php
/**
 * The watchdog on a build the server is running.
 *
 * A background build can stop in two ways that look identical from the import
 * screen: the process running a step dies, or WP-Cron never runs the tick that
 * was booked. Both leave a progress bar frozen at a number with a spinner over
 * it, which is the one state this theme is not allowed to show — so the runner
 * has to be able to say "this has stopped", say which of the two it is, and
 * carry the build on without waiting for the scheduler.
 *
 * These tests fabricate the job record directly rather than running a build:
 * what is under test is the reading of it.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\BuildRunner;
use Qwerty\Soft\Support\ImportSession;

/**
 * Which step the runner would take next, for a job in a given state.
 *
 * Reached by reflection rather than by making the method public: the order of
 * the build is worth asserting, and it is not worth widening the class's
 * surface to do it.
 *
 * @param array<string, mixed> $job Job record.
 * @return array<string, string>|null
 */
function qsoft_next_step( array $job ): ?array {
	$method = new ReflectionMethod( BuildRunner::class, 'next_step' );
	$method->setAccessible( true );

	return $method->invoke( null, $job );
}

/**
 * Write a build job straight onto the current user, as the runner stores it.
 *
 * @param array<string, mixed> $overrides Fields to set on top of a live-looking job.
 * @return void
 */
function qsoft_put_job( array $overrides = array() ): void {
	$job = array_merge(
		array(
			'id'         => 'test-job',
			'root'       => sys_get_temp_dir(),
			'slug'       => 'test-design',
			'pages'      => array( array( 'file' => 'en/reports.html' ) ),
			'completed'  => array(),
			'attempts'   => array(),
			'done'       => 2,
			'total'      => 4,
			'unattended' => true,
			'working'    => 'page:en/reports.html',
			'heartbeat'  => time(),
			'updated'    => time(),
		),
		$overrides
	);

	update_user_meta( get_current_user_id(), '_qwerty_soft_build_job', wp_slash( $job ) );
}

/** How long a step may be silent before it counts as stopped, plus a margin. */
const QSOFT_WELL_PAST_LEASE = 1200;

qsoft_group( 'The watchdog — a build that is working is left alone' );

qsoft_test(
	'no job at all is not a stall',
	static function (): void {
		delete_user_meta( get_current_user_id(), '_qwerty_soft_build_job' );

		qsoft_assert( null === BuildRunner::diagnose( get_current_user_id() ), 'nothing running, nothing to report' );
	}
);

qsoft_test(
	'a step that spoke a moment ago is working, however long it has been at it',
	static function (): void {
		qsoft_put_job();

		qsoft_assert( null === BuildRunner::diagnose( get_current_user_id() ), 'a fresh heartbeat is not a stall' );
	}
);

qsoft_test(
	'a build the browser is driving is not the watchdog\'s business',
	static function (): void {
		qsoft_put_job(
			array(
				'unattended' => false,
				'heartbeat'  => time() - QSOFT_WELL_PAST_LEASE,
				'updated'    => time() - QSOFT_WELL_PAST_LEASE,
			)
		);

		qsoft_assert(
			null === BuildRunner::diagnose( get_current_user_id() ),
			'an attended build stops when the tab does, which is not a fault'
		);
	}
);

qsoft_test(
	'a finished build is silent because it is done',
	static function (): void {
		qsoft_put_job(
			array(
				'completed' => array( 'finish' => true ),
				'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
				'updated'   => time() - QSOFT_WELL_PAST_LEASE,
			)
		);

		qsoft_assert( null === BuildRunner::diagnose( get_current_user_id() ), 'the end of a build is not a stall' );
	}
);

qsoft_test(
	'the later of the two clocks is the one that counts',
	static function (): void {
		// A step that wrote the job recently but has not beaten since is alive.
		qsoft_put_job(
			array(
				'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
				'updated'   => time(),
			)
		);

		qsoft_assert( null === BuildRunner::diagnose( get_current_user_id() ), 'a recent write keeps a quiet beat alive' );
	}
);

qsoft_group( 'The watchdog — a build that has stopped says so' );

qsoft_test(
	'a step silent past its lease is reported, with what it was on',
	static function (): void {
		wp_clear_scheduled_hook( BuildRunner::HOOK, array( get_current_user_id() ) );

		qsoft_put_job(
			array(
				'attempts'  => array( 'page:en/reports.html' => 2 ),
				'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
				'updated'   => time() - QSOFT_WELL_PAST_LEASE,
			)
		);

		$stall = BuildRunner::diagnose( get_current_user_id() );

		qsoft_assert( is_array( $stall ), 'a stalled build is reported' );
		qsoft_assert( 'page:en/reports.html' === ( $stall['step'] ?? '' ), 'the step it stopped on is named', $stall['step'] ?? null );
		qsoft_assert( 'reports.html' === ( $stall['label'] ?? '' ), 'the label is the page, not the path', $stall['label'] ?? null );
		qsoft_assert( ( $stall['quiet'] ?? 0 ) >= 900, 'how long it has been silent is reported', $stall['quiet'] ?? null );
		qsoft_assert( 2 === ( $stall['attempt'] ?? 0 ), 'attempts spent are reported', $stall['attempt'] ?? null );
		qsoft_assert( 1 === ( $stall['left'] ?? 0 ), 'attempts left are reported', $stall['left'] ?? null );
		qsoft_assert( false === ( $stall['scheduled'] ?? true ), 'no booking means the step died and was never picked up' );
	}
);

qsoft_test(
	'a booking that nothing has run is the other diagnosis',
	static function (): void {
		qsoft_put_job(
			array(
				'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
				'updated'   => time() - QSOFT_WELL_PAST_LEASE,
			)
		);

		wp_schedule_single_event( time() + 5, BuildRunner::HOOK, array( get_current_user_id() ) );

		$stall = BuildRunner::diagnose( get_current_user_id() );

		qsoft_assert( is_array( $stall ), 'a build waiting on a scheduler that never comes is still stalled' );
		qsoft_assert(
			true === ( $stall['scheduled'] ?? false ),
			'a tick is booked, so the screen can say cron is the reason rather than the step'
		);

		wp_clear_scheduled_hook( BuildRunner::HOOK, array( get_current_user_id() ) );
	}
);

qsoft_test(
	'the steps that are not pages are named in words',
	static function (): void {
		foreach ( array( 'chrome', 'finish' ) as $mark ) {
			qsoft_put_job(
				array(
					'working'   => $mark,
					'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
					'updated'   => time() - QSOFT_WELL_PAST_LEASE,
				)
			);

			$stall = BuildRunner::diagnose( get_current_user_id() );

			qsoft_assert(
				is_array( $stall ) && '' !== ( $stall['label'] ?? '' ) && $mark !== $stall['label'],
				$mark . ' is described rather than printed',
				$stall['label'] ?? null
			);
		}
	}
);

qsoft_group( 'Continuing by hand' );

qsoft_test(
	'resume drops the booking cron never honoured and runs the step itself',
	static function (): void {
		/*
		 * The design is gone from uploads, which is the cheapest step there
		 * is: it ends the build with a line in the log. What is under test is
		 * that resume() got as far as running a step at all without a tick
		 * ever firing, and that it cleared the stale booking on the way.
		 */
		qsoft_put_job(
			array(
				'root'      => sys_get_temp_dir() . '/qwerty-soft-signal-not-here-' . wp_generate_password( 8, false, false ),
				'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
				'updated'   => time() - QSOFT_WELL_PAST_LEASE,
			)
		);

		wp_schedule_single_event( time() + 600, BuildRunner::HOOK, array( get_current_user_id() ) );

		$more = BuildRunner::resume( get_current_user_id() );

		qsoft_assert( false === $more, 'a build whose design has gone has nothing more to do' );
		qsoft_assert( null === ImportSession::running(), 'the job was ended rather than left to stall again' );
		qsoft_assert(
			false === wp_next_scheduled( BuildRunner::HOOK, array( get_current_user_id() ) ),
			'the stale booking was cleared, so a continued build is not stuck one step per press'
		);
	}
);

qsoft_test(
	'resume on nobody is refused rather than run',
	static function (): void {
		qsoft_assert( false === BuildRunner::resume( 0 ), 'no user, no build to continue' );
	}
);

qsoft_group( 'One step, one worker' );

qsoft_test(
	'a second tick cannot start the step the first is already on',
	static function (): void {
		/*
		 * The bug this replaces: the guard was a read of the job record, and
		 * two ticks that both read before either wrote both passed it. WP-Cron
		 * hands out a second run of the same hook a minute after the first, so
		 * in practice one page was converted by two processes at once — twice
		 * the model calls, two pages written over each other, and a build that
		 * ran for hours to produce duplicates.
		 */
		$user = get_current_user_id();

		delete_option( 'qwerty_soft_build_lock_' . $user );

		qsoft_put_job( array( 'root' => sys_get_temp_dir() . '/gone-' . wp_generate_password( 6, false, false ) ) );

		$claim = new ReflectionMethod( BuildRunner::class, 'claim' );
		$claim->setAccessible( true );

		$job = ImportSession::running();

		qsoft_assert( true === $claim->invoke( null, $user, 'page:one.html', $job ), 'the first tick takes the step' );
		qsoft_assert( false === $claim->invoke( null, $user, 'page:one.html', $job ), 'the second is turned away from the same step' );
		qsoft_assert( false === $claim->invoke( null, $user, 'chrome', $job ), 'and from any other step of the same build' );

		$release = new ReflectionMethod( BuildRunner::class, 'release' );
		$release->setAccessible( true );
		$release->invoke( null, $user );

		qsoft_assert(
			true === $claim->invoke( null, $user, 'chrome', $job ),
			'once the first is done, the next step can be taken'
		);

		$release->invoke( null, $user );
	}
);

qsoft_test(
	'a claim held by a step that died is taken over, once',
	static function (): void {
		$user = get_current_user_id();

		delete_option( 'qwerty_soft_build_lock_' . $user );

		// A job whose last sign of life is older than the lease: its holder is gone.
		qsoft_put_job(
			array(
				'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
				'updated'   => time() - QSOFT_WELL_PAST_LEASE,
			)
		);

		add_option( 'qwerty_soft_build_lock_' . $user, 'page:dead.html|' . ( time() - QSOFT_WELL_PAST_LEASE ), '', false );

		$claim = new ReflectionMethod( BuildRunner::class, 'claim' );
		$claim->setAccessible( true );

		$job = ImportSession::running();

		qsoft_assert( true === $claim->invoke( null, $user, 'page:next.html', $job ), 'an abandoned claim is taken over' );
		qsoft_assert( false === $claim->invoke( null, $user, 'page:next.html', $job ), 'and only by one caller' );

		delete_option( 'qwerty_soft_build_lock_' . $user );
	}
);

qsoft_test(
	'one build\'s claim does not block another user\'s',
	static function (): void {
		$user = get_current_user_id();

		delete_option( 'qwerty_soft_build_lock_' . $user );
		delete_option( 'qwerty_soft_build_lock_' . ( $user + 1000 ) );

		qsoft_put_job();

		$claim = new ReflectionMethod( BuildRunner::class, 'claim' );
		$claim->setAccessible( true );

		$job = ImportSession::running();

		qsoft_assert( true === $claim->invoke( null, $user, 'page:one.html', $job ), 'this build claims its step' );
		qsoft_assert(
			true === $claim->invoke( null, $user + 1000, 'page:one.html', $job ),
			'another person\'s build is unaffected'
		);

		delete_option( 'qwerty_soft_build_lock_' . $user );
		delete_option( 'qwerty_soft_build_lock_' . ( $user + 1000 ) );
	}
);

qsoft_test(
	'a build stopped between steps names the step that has not started',
	static function (): void {
		qsoft_put_job(
			array(
				'completed' => array( 'page:en/reports.html' => true ),
				'done'      => 3,
				'heartbeat' => time() - QSOFT_WELL_PAST_LEASE,
				'updated'   => time() - QSOFT_WELL_PAST_LEASE,
			)
		);

		$stall = BuildRunner::diagnose( get_current_user_id() );

		qsoft_assert( is_array( $stall ), 'a build waiting between steps is still stopped' );
		qsoft_assert( true === ( $stall['between'] ?? false ), 'it is reported as between steps, not as a dead page' );
		qsoft_assert(
			'page:en/reports.html' !== ( $stall['step'] ?? '' ),
			'the finished page is not blamed for the silence',
			$stall['step'] ?? null
		);
		qsoft_assert(
			'chrome' === ( $stall['step'] ?? '' ),
			'the step named is the one due next',
			$stall['step'] ?? null
		);
	}
);

qsoft_test(
	'The chrome is built before the pages, and the home page leads them',
	static function (): void {
		/*
		 * The order is a decision, not an accident, so it is asserted rather
		 * than left to whoever next edits next_step().
		 *
		 * The header and footer used to come last, because the menu is a list
		 * of built pages and so needed their ids. That made the first thing
		 * anybody looks at the last thing to exist: a build could run for an
		 * hour and every page seen on the way wore the theme's own chrome
		 * instead of the design's, which reads as a failed import.
		 */
		$job = array(
			'pages'     => array(
				array( 'file' => 'en/index.html' ),
				array( 'file' => 'en/about.html' ),
			),
			'completed' => array(),
		);

		$next = qsoft_next_step( $job );

		qsoft_assert( 'chrome' === ( $next['key'] ?? '' ), 'the header and footer come first', $next );

		$job['completed']['chrome'] = true;
		$next                       = qsoft_next_step( $job );

		qsoft_assert(
			'page' === ( $next['key'] ?? '' ) && 'en/index.html' === ( $next['file'] ?? '' ),
			'then the home page, which the page list already sorts first',
			$next
		);

		$job['completed']['page:en/index.html'] = true;
		$next                                   = qsoft_next_step( $job );

		qsoft_assert(
			'page' === ( $next['key'] ?? '' ) && 'en/about.html' === ( $next['file'] ?? '' ),
			'then the rest',
			$next
		);

		$job['completed']['page:en/about.html'] = true;

		qsoft_assert(
			'finish' === ( qsoft_next_step( $job )['key'] ?? '' ),
			'and the links are made to work last of all',
			qsoft_next_step( $job )
		);
	}
);

qsoft_test(
	'Stopping is real, and continuing picks up where it stopped',
	static function (): void {
		/*
		 * Cancelling used to happen only in the browser: the button set a flag
		 * in the tab's own state and drew a stopped panel while the build
		 * carried on running on cron. Pressing Build again then produced a
		 * second worker beside the first, and closing the tab told nobody.
		 *
		 * So what is asserted is that the stop reaches the server, that it
		 * keeps the work rather than undoing it, and that continuing clears it.
		 */
		$user = get_current_user_id();

		ImportSession::start_job(
			array(
				'unattended' => true,
				'root'       => '',
				'pages'      => array( array( 'file' => 'en/index.html' ) ),
				'completed'  => array( 'chrome' => true ),
				'done'       => 1,
				'total'      => 3,
			)
		);

		qsoft_assert( ! BuildRunner::stopped(), 'a fresh build is not stopped' );
		qsoft_assert( BuildRunner::stop( $user ), 'stopping reports that there was something to stop' );
		qsoft_assert( BuildRunner::stopped(), 'and the server holds the stop, not the browser' );

		$job = ImportSession::running();

		qsoft_assert( null !== $job, 'the job survives being stopped' );
		qsoft_assert(
			! empty( $job['completed']['chrome'] ),
			'along with every step it had already finished — stopping is not undoing'
		);

		qsoft_assert(
			false === wp_next_scheduled( 'qwerty_soft_build_tick', array( $user ) ),
			'and nothing is left booked to carry it on behind the editor\'s back'
		);

		/*
		 * Continuing has to clear the flag before it does anything else, or
		 * the first step it takes would read the stop and stop again — a
		 * button that looks like it works and never advances.
		 */
		BuildRunner::resume( $user );

		qsoft_assert( ! BuildRunner::stopped(), 'continuing clears the stop' );

		ImportSession::end_job();

		qsoft_assert( ! BuildRunner::stop( $user ), 'and there is nothing to stop once the job is gone' );
	}
);

qsoft_finish();
