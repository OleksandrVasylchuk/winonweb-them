<?php
/**
 * Runs a build without a browser holding it open.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The build, continued by the server, one step per tick.
 *
 * A build the browser drives is a request per step, which is fine for a
 * twelve-page structural import that finishes in seconds and wrong for the
 * kind of run this theme now supports: eleven pages read out of components,
 * each page corrected section by section and then reviewed, an hour or more
 * end to end. That build cannot depend on a tab staying open, a laptop staying
 * awake, or a request staying alive.
 *
 * So the same steps run from WP-Cron instead. Every step is already idempotent
 * — a page built twice updates rather than duplicates — and the job already
 * lives on the user rather than in the request, which is what makes moving it
 * to a background tick a small change rather than a second engine.
 *
 * The honest limits, stated because they decide whether this works on a given
 * site: WP-Cron fires on requests, so a site nobody visits advances only when
 * somebody opens it — the import screen polling its own log is usually that
 * somebody. A site with a real system cron, or one with `DISABLE_WP_CRON`
 * pointed at a real scheduler, runs it properly unattended.
 */
final class BuildRunner {

	/**
	 * The cron hook one step is run on.
	 *
	 * @var string
	 */
	public const HOOK = 'qwerty_soft_build_tick';

	/**
	 * How the patrol remembers it has just looked.
	 *
	 * @var string
	 */
	private const PATROL = 'qwerty_soft_build_patrol';

	/**
	 * The option one tick holds while it works on a step.
	 *
	 * @var string
	 */
	private const LOCK = 'qwerty_soft_build_lock';

	/**
	 * Seconds between ticks. Short: the work is inside the step, not the wait.
	 *
	 * @var int
	 */
	private const INTERVAL = 5;

	/**
	 * Seconds of silence before a step is treated as abandoned.
	 *
	 * This was 300, which is exactly the command line transport's own timeout —
	 * so a single model call that ran to its limit was indistinguishable from
	 * a dead process, and the watchdog started the same page in a second one
	 * while the first was still working. Both converted it, both wrote it, and
	 * the build took hours to produce duplicates.
	 *
	 * The number has to clear the longest a step can legitimately be silent.
	 * A section beats before and after every call now, so that is one call
	 * rather than a whole section — but a call can be slow, a machine can be
	 * busy, and being wrong in this direction costs duplicated work and
	 * duplicated money. Being wrong in the other direction only delays the
	 * recovery of a build that has genuinely died, which the screen reports
	 * and a button can carry on by hand.
	 *
	 * @var int
	 */
	private const LEASE = 900;

	/**
	 * How many times one step may be started before it is given up on.
	 *
	 * A step is booked with cron before it runs, so a step that kills the
	 * process still leaves a tick behind and gets another go. Without a
	 * ceiling that is an endless loop; with one, a step that cannot survive
	 * three attempts is reported and the build moves on to the next page.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * How long one tick keeps working before handing back to cron.
	 *
	 * A tick that did exactly one step depended on the next tick arriving, and
	 * WP-Cron only arrives when somebody makes a request — so on a quiet site a
	 * twelve-page build advanced one page per visitor. Working in bursts turns
	 * that into "one visitor finishes it", and a build that needs longer than
	 * this hands back cleanly and continues on the next tick.
	 *
	 * @var int
	 */
	private const TICK_SECONDS = 240;

	/**
	 * Listen for the tick.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( self::HOOK, array( self::class, 'tick' ), 10, 1 );

		/*
		 * And a patrol on every request, admin or front end, so a build is
		 * never waiting on somebody opening the right screen.
		 */
		add_action( 'wp_loaded', array( self::class, 'patrol' ) );
	}

	/**
	 * Put a tick back for any build on the site that has stopped without ending.
	 *
	 * The one guarantee worth making about an unattended build is that it
	 * cannot end up stopped for good. Three things can stop it: the process
	 * running a step dies, WP-Cron loses the event when it fires it, or
	 * nothing on the site happens for long enough that cron never runs. The
	 * first two are handled by booking the next tick before the work and by
	 * this patrol; the third is not a stall, it is a site nobody is using, and
	 * the first visit afterwards picks the build up.
	 *
	 * Costs one indexed meta read a minute at most, and nothing at all on a
	 * site with no build running.
	 *
	 * @return void
	 */
	public static function patrol(): void {
		if ( false !== get_transient( self::PATROL ) ) {
			return;
		}

		set_transient( self::PATROL, 1, MINUTE_IN_SECONDS );

		global $wpdb;

		$owners = wp_cache_get( 'qwerty_soft_build_owners', 'qwerty-soft-signal' );

		if ( ! is_array( $owners ) ) {
			$owners = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached on the line below; no core API asks "who has this meta key".
				$wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 20", '_qwerty_soft_build_job' )
			);

			wp_cache_set( 'qwerty_soft_build_owners', $owners, 'qwerty-soft-signal', MINUTE_IN_SECONDS );
		}

		foreach ( (array) $owners as $owner ) {
			self::revive( (int) $owner );
		}
	}

	/**
	 * Ask for the next step to run soon, for this user's job.
	 *
	 * @param int $user Whose build it is.
	 * @return void
	 */
	public static function schedule( int $user ): void {
		if ( $user <= 0 ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK, array( $user ) ) ) {
			wp_schedule_single_event( time() + self::INTERVAL, self::HOOK, array( $user ) );
		}

		/*
		 * Nudge WordPress into running its queue now rather than on whoever
		 * happens to load a page next. On a site with a real cron this is a
		 * no-op; on a quiet local install it is the difference between a
		 * build that continues and one that waits.
		 */
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Put a tick back for a running build that has none.
	 *
	 * The screen polls its own log while it is open, and that poll is the one
	 * moment a stalled build can be noticed from the outside: an unattended
	 * job that is not finished, with nothing scheduled to carry it, is a build
	 * whose last step took the process with it. Booking another tick restarts
	 * it from the step it was on.
	 *
	 * @param int $user Whose build it is.
	 * @return bool Whether a tick had to be put back.
	 */
	public static function revive( int $user ): bool {
		if ( $user <= 0 || wp_next_scheduled( self::HOOK, array( $user ) ) ) {
			return false;
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user );

		$job = ImportSession::running();

		/*
		 * Only a build that has gone quiet. One still reporting sections is
		 * working, however long it has been at it, and putting a second tick
		 * behind it would start the same page a second time.
		 */
		$beat    = null === $job ? 0 : self::last_sign_of_life( $job );
		$stalled = null !== $job
			&& ! empty( $job['unattended'] )
			&& empty( $job['completed']['finish'] )
			&& time() - $beat >= self::LEASE;

		wp_set_current_user( $previous );

		if ( ! $stalled ) {
			return false;
		}

		self::schedule( $user );

		return true;
	}

	/**
	 * What is wrong with this user's build, if anything is.
	 *
	 * Reviving a stalled build works when the reason it stalled was a lost
	 * event: something books a new tick and cron runs it. It does nothing at
	 * all when cron itself is the thing not running — a booking already sits
	 * in the queue, so there is nothing to book, and the build waits on a
	 * scheduler that will never come. That is the state this reports: a job
	 * that has said nothing for longer than a step is allowed to be silent.
	 *
	 * Reported rather than acted on, because the screen can do the two things
	 * this cannot. It can say what happened in a sentence, and it can offer to
	 * run the step in the request instead of waiting for cron at all.
	 *
	 * @param int $user Whose build it is.
	 * @return array<string, mixed>|null The stall, or null while all is well.
	 */
	public static function diagnose( int $user ): ?array {
		if ( $user <= 0 ) {
			return null;
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user );

		$job = ImportSession::running();

		wp_set_current_user( $previous );

		if ( null === $job || empty( $job['unattended'] ) || ! empty( $job['completed']['finish'] ) ) {
			return null;
		}

		$quiet = time() - self::last_sign_of_life( $job );

		if ( $quiet < self::LEASE ) {
			return null;
		}

		$mark      = (string) ( $job['working'] ?? '' );
		$completed = is_array( $job['completed'] ?? null ) ? $job['completed'] : array();

		/*
		 * There are two ways to be silent, and naming the wrong one is worse
		 * than saying nothing. A step still marked as being worked on is one
		 * that died part-way. A step that finished, with the build stopped
		 * before the next was claimed, is a build waiting between steps — and
		 * reporting that as "nothing has happened on the page you can see was
		 * built" reads as a lie about work that plainly succeeded.
		 */
		$between = '' === $mark || ! empty( $completed[ $mark ] );

		if ( $between ) {
			$next = self::next_step( $job );
			$mark = null === $next
				? ''
				: ( 'page' === $next['key'] ? 'page:' . $next['file'] : (string) $next['key'] );
		}

		$attempts = is_array( $job['attempts'] ?? null ) ? $job['attempts'] : array();
		$attempt  = (int) ( $attempts[ $mark ] ?? 0 );

		return array(
			'quiet'     => $quiet,
			'step'      => $mark,
			'label'     => self::label_of( $mark ),
			'between'   => $between,
			'attempt'   => $attempt,
			'max'       => self::MAX_ATTEMPTS,
			'left'      => max( 0, self::MAX_ATTEMPTS - $attempt ),

			/*
			 * The one fact that decides which sentence the screen shows. A
			 * booking that exists next to a build that has not moved means
			 * cron is not running; no booking means the step died and was
			 * never picked up.
			 */
			'scheduled' => (bool) wp_next_scheduled( self::HOOK, array( $user ) ),
		);
	}

	/**
	 * Stop a build where it stands, and leave it resumable.
	 *
	 * Cancelling used to happen only in the browser: the button set a flag in
	 * the tab's own state and drew a stopped panel, while the build carried on
	 * running on cron. Closing the tab, or pressing Build again, then produced
	 * a second worker beside the first. The button was saying something that
	 * was not true.
	 *
	 * The job itself is kept, along with every step it has already completed.
	 * Stopping is not undoing: the pages made so far stay on the site, and
	 * continuing later picks up at the next step rather than at the beginning.
	 *
	 * @param int $user Whose build it is.
	 * @return bool Whether there was a build to stop.
	 */
	public static function stop( int $user ): bool {
		$job = ImportSession::running();

		if ( null === $job || ! empty( $job['completed']['finish'] ) ) {
			return false;
		}

		$job['stopped'] = time();

		ImportSession::update_job( $job );

		/*
		 * The booking goes with it. A tick already in flight finishes its
		 * current step and then sees the flag; one that has not started never
		 * runs, because nothing is booked for it any more.
		 */
		wp_clear_scheduled_hook( self::HOOK, array( $user ) );

		/*
		 * And the lease, so continuing does not have to wait fifteen minutes
		 * for a worker that will never come back to look dead.
		 */
		self::release( $user );

		ImportLog::add( 'build', __( 'Build stopped. What it has made so far is on the site, and it can be continued.', 'qwerty-soft-signal' ) );

		return true;
	}

	/**
	 * Whether a build has been stopped by hand.
	 *
	 * @param array<string, mixed>|null $job Job record, or null to read it.
	 * @return bool
	 */
	public static function stopped( ?array $job = null ): bool {
		$job = null === $job ? ImportSession::running() : $job;

		return null !== $job && ! empty( $job['stopped'] );
	}

	/**
	 * Carry a stalled build on now, in this request, rather than on cron.
	 *
	 * The button behind this exists because "wait for the scheduler" is not an
	 * answer on a site whose scheduler is the problem. One step is run here,
	 * where the person pressing it can watch it happen; the step books its own
	 * next tick before it starts, so a site whose cron does work carries on by
	 * itself afterwards and a site whose cron does not asks again.
	 *
	 * @param int $user Whose build it is.
	 * @return bool Whether there is still more to do afterwards.
	 */
	public static function resume( int $user ): bool {
		if ( $user <= 0 ) {
			return false;
		}

		/*
		 * Continuing clears the stop, or the first thing the next step would
		 * do is stop again.
		 */
		$job = ImportSession::running();

		if ( null !== $job && ! empty( $job['stopped'] ) ) {
			unset( $job['stopped'] );

			ImportSession::update_job( $job );
		}

		/*
		 * Drop the booking cron never honoured. Left in place it stops step()
		 * from booking a live one, so a build continued by hand would advance
		 * exactly one step per press for ever.
		 */
		wp_clear_scheduled_hook( self::HOOK, array( $user ) );

		return self::tick( $user, false );
	}

	/**
	 * Take the right to work on one step, or find somebody already has it.
	 *
	 * The lock is an option row, because `add_option()` is an INSERT against a
	 * unique key and MySQL settles the race for us: one caller gets true, the
	 * rest get false, however close together they arrive. A read-then-write
	 * check cannot do that, and the difference is three model calls per
	 * section rather than one.
	 *
	 * A lock is only ever stolen from a step that has stopped reporting for a
	 * whole lease, which is the same test the watchdog uses to decide a build
	 * has died. The clock is the job's own heartbeat rather than a second one
	 * kept here, so a page that is still converting sections cannot be robbed
	 * of its claim halfway through.
	 *
	 * @param int                  $user Whose build it is.
	 * @param string               $mark Step being claimed.
	 * @param array<string, mixed> $job  Job record as this tick read it.
	 * @return bool Whether this caller may do the work.
	 */
	private static function claim( int $user, string $mark, array $job ): bool {
		$name = self::LOCK . '_' . $user;
		$now  = time();

		if ( add_option( $name, $mark . '|' . $now, '', false ) ) {
			return true;
		}

		/*
		 * Held by a step that is still alive — including this same step, which
		 * is how a tick that fires while a page is converting is turned away.
		 */
		if ( $now - self::last_sign_of_life( $job ) < self::LEASE ) {
			return false;
		}

		/*
		 * The work has been silent for a whole lease, so its process is gone.
		 * But a claim taken a moment ago is not: without this second clock,
		 * every tick arriving while the job record was still stale would steal
		 * the lock from the one that had just taken it, and the stampede this
		 * whole method exists to stop would happen anyway — one tick later.
		 */
		$held  = (string) get_option( $name, '' );
		$taken = (int) substr( (string) strrchr( $held, '|' ), 1 );

		if ( $taken > 0 && $now - $taken < self::LEASE ) {
			return false;
		}

		/*
		 * Deleting before adding keeps the INSERT as the thing that decides:
		 * two ticks arriving together at an abandoned lock still produce one
		 * winner, because only one of their INSERTs can succeed.
		 */
		delete_option( $name );

		return (bool) add_option( $name, $mark . '|' . $now, '', false );
	}

	/**
	 * Give the claim back, so the next step can be taken.
	 *
	 * @param int $user Whose build it is.
	 * @return void
	 */
	private static function release( int $user ): void {
		delete_option( self::LOCK . '_' . $user );
	}

	/**
	 * What a step mark is called when a person has to read it.
	 *
	 * @param string $mark Step mark, as stored on the job.
	 * @return string
	 */
	private static function label_of( string $mark ): string {
		if ( 'chrome' === $mark ) {
			return __( 'the menu, header and footer', 'qwerty-soft-signal' );
		}

		if ( 'finish' === $mark ) {
			return __( 'the front page and links', 'qwerty-soft-signal' );
		}

		if ( 0 === strpos( $mark, 'page:' ) ) {
			return basename( substr( $mark, 5 ) );
		}

		return '' === $mark ? __( 'the build', 'qwerty-soft-signal' ) : $mark;
	}

	/**
	 * Carry the build belonging to one user as far as this tick allows.
	 *
	 * @param int  $user  Whose build it is.
	 * @param bool $burst Whether to keep going for the length of a tick. False
	 *                    runs a single step, for a caller holding a request
	 *                    open while it happens.
	 * @return bool Whether the build has more to do.
	 */
	public static function tick( int $user = 0, bool $burst = true ): bool {
		if ( $user <= 0 ) {
			return false;
		}

		/*
		 * Cron runs as nobody. The job, the log and the banked conversions all
		 * hang off the user who started the build, so the runner becomes that
		 * user for the length of the step and puts the context back after.
		 */
		$previous = get_current_user_id();
		wp_set_current_user( $user );

		$more = false;

		try {
			/*
			 * Keep going while there is time in this tick. Each step returns
			 * whether the build is still unfinished; the loop stops on the
			 * end of the job, on an error, or when the burst is spent.
			 */
			$until = microtime( true ) + self::TICK_SECONDS;

			do {
				$more = self::step( $user );
			} while ( $more && $burst && microtime( true ) < $until );
		} catch ( \Throwable $error ) {
			ImportLog::add(
				'build',
				sprintf(
					/* translators: %s: the error. */
					__( 'The background build stopped on an error: %s', 'qwerty-soft-signal' ),
					$error->getMessage()
				)
			);

			$more = false;
		} finally {
			wp_set_current_user( $previous );
		}

		return $more;
	}

	/**
	 * One step of the build, with a user already in context.
	 *
	 * @param int $user Whose build it is.
	 * @return bool Whether there is more to do.
	 */
	private static function step( int $user ): bool {
		$job = ImportSession::running();

		if ( null === $job || empty( $job['unattended'] ) ) {
			return false;
		}

		if ( ! empty( $job['completed']['finish'] ) ) {
			return false;
		}

		/*
		 * Read at the top of every step rather than only when a tick begins,
		 * so a build stopped in the middle of a long page stops at the end of
		 * that page instead of running to the end of its burst.
		 */
		if ( ! empty( $job['stopped'] ) ) {
			return false;
		}

		$root = self::root_of( $job );

		if ( '' === $root ) {
			ImportLog::add( 'build', __( 'The design this build was reading is no longer in uploads, so the build stopped.', 'qwerty-soft-signal' ) );
			ImportSession::end_job();

			return false;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( empty( $job['smart'] ) ? 120 : 1800 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- One step, bounded by the transport's own timeouts.
		}

		$next = self::next_step( $job );

		if ( null === $next ) {
			return false;
		}

		// Before calling it finished: anything set aside earlier gets its second pass now.
		if ( 'finish' === $next['key'] && self::retry_deferred( $job ) ) {
			ImportSession::update_job( $job );
			self::schedule( $user );

			return true;
		}

		/*
		 * Booked before the work, not after.
		 *
		 * WP-Cron deletes an event when it fires and this used to ask for the
		 * next one only once the step had returned. So any step that never
		 * returned — a worker recycled, a model call the server cut off, a
		 * process killed — left an empty queue and a build frozen at "2 of 7"
		 * with nothing scheduled to move it. Every step is idempotent, a page
		 * built twice updates rather than duplicates, so the safe order is:
		 * book the retry, then do the work.
		 */
		$mark = ( 'page' === $next['key'] ? 'page:' . $next['file'] : (string) $next['key'] );

		/*
		 * Somebody else may already be on this step.
		 *
		 * This used to be a read of the job record — "is `working` this mark,
		 * and has it beaten recently?" — and a read is not a claim. Two ticks
		 * that both read before either wrote both passed it, and WP-Cron hands
		 * out a second run of the same hook sixty seconds after the first when
		 * the first has not returned. The result was three processes
		 * converting one page through one model, three pages built over each
		 * other, and a build that took three hours to produce duplicates.
		 *
		 * add_option() is an INSERT against a unique key: exactly one caller
		 * can win it, whatever the timing. That is the claim.
		 */
		if ( ! self::claim( $user, $mark, $job ) ) {
			return false;
		}

		try {
			return self::perform( $user, $job, $next, $mark );
		} finally {
			// Whatever happened — a result, an error, a killed process mid-write.
			self::release( $user );
		}
	}

	/**
	 * Do the work of one claimed step.
	 *
	 * Split out from step() only so the claim can be released on every way
	 * out of it, including the ones that throw.
	 *
	 * @param int                           $user Whose build it is.
	 * @param array<string, mixed>          $job  Job record.
	 * @param array{key:string,file:string} $next Step to run.
	 * @param string                        $mark Step mark.
	 * @return bool Whether there is more to do.
	 */
	private static function perform( int $user, array $job, array $next, string $mark ): bool {
		$root = self::root_of( $job );

		$attempts          = is_array( $job['attempts'] ?? null ) ? $job['attempts'] : array();
		$attempts[ $mark ] = (int) ( $attempts[ $mark ] ?? 0 ) + 1;
		$job['attempts']   = $attempts;
		$job['working']    = $mark;
		$job['heartbeat']  = time();

		/*
		 * When this step was claimed, so a screen that was not here when it
		 * started can still show a clock running on the right row. Without it
		 * a tab rejoining an unattended build has a bar, a number, and no way
		 * to tell a step that is working from one that is not.
		 */
		$job['started'] = time();

		if ( $attempts[ $mark ] > self::MAX_ATTEMPTS ) {
			/*
			 * Set aside, not thrown away. Whatever stopped this step three
			 * times may well have been the machine being busy rather than the
			 * page being impossible, so the build carries on and comes back
			 * to it once everything else is done — see next_step(). Only a
			 * page that fails that second pass too is left out, and the build
			 * says so rather than quietly producing a site with a hole in it.
			 */
			$again = empty( $job['retried'] );

			ImportLog::add(
				'build',
				$again
					? sprintf(
						/* translators: 1: what was being built, 2: how many attempts. */
						__( '%1$s stopped the build %2$d times. Moving on for now; it gets another go once the rest is done.', 'qwerty-soft-signal' ),
						'' === $next['file'] ? $next['key'] : $next['file'],
						self::MAX_ATTEMPTS
					)
					: sprintf(
						/* translators: %s: what was being built. */
						__( '%s could not be built on the second pass either, so the build finished without it.', 'qwerty-soft-signal' ),
						'' === $next['file'] ? $next['key'] : $next['file']
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
			self::schedule( $user );

			return true;
		}

		ImportSession::update_job( $job );
		self::schedule( $user );

		if ( 'page' === $next['key'] ) {
			$file = (string) $next['file'];

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

			ImportLog::add(
				'build',
				is_wp_error( $result )
					? sprintf(
						/* translators: 1: page file, 2: the reason. */
						__( '%1$s was not built: %2$s', 'qwerty-soft-signal' ),
						$file,
						$result->get_error_message()
					)
					: sprintf(
						/* translators: 1: page title, 2: sections, 3: seconds. */
						__( 'Built “%1$s” — %2$d sections, %3$ds.', 'qwerty-soft-signal' ),
						(string) ( $result['title'] ?? $file ),
						(int) ( $result['sections'] ?? 0 ),
						(int) round( microtime( true ) - $started )
					)
			);

			return true;
		}

		if ( 'chrome' === $next['key'] ) {
			ImportLog::add( 'build', __( 'Building the menu, the header and the footer…', 'qwerty-soft-signal' ) );

			SiteAssembler::chrome( $job );

			$job['completed']['chrome'] = true;
			$job['done']                = 2 + count( $job['completed'] );

			ImportSession::update_job( $job );

			return true;
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

			return false;
		}

		if ( empty( $job['keep_archive'] ) ) {
			DesignArchive::remove( $root );
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

		return false;
	}

	/**
	 * The first step of the job that has not been done.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return array{key:string,file:string}|null
	 */
	private static function next_step( array $job ): ?array {
		$completed = is_array( $job['completed'] ?? null ) ? $job['completed'] : array();

		/*
		 * The header and the footer first, then the home page, then the rest.
		 *
		 * They used to come last, because the menu is a list of built pages and
		 * so needed their ids. That made the first thing anybody looked at the
		 * last thing to exist: a build could run for an hour and every page
		 * seen along the way wore the theme's own chrome rather than the
		 * design's, which reads as the import having failed.
		 *
		 * The dependency is broken by building the menu on the design's own
		 * links and rewriting them to real pages at the end — the same trick
		 * already used for the links inside pages and inside blocks.
		 */
		if ( empty( $completed['chrome'] ) ) {
			return array(
				'key'  => 'chrome',
				'file' => '',
			);
		}

		// Pages are ordered with the index first, so the home page follows.
		foreach ( (array) ( $job['pages'] ?? array() ) as $page ) {
			$file = (string) ( $page['file'] ?? '' );

			if ( '' !== $file && empty( $completed[ 'page:' . $file ] ) ) {
				return array(
					'key'  => 'page',
					'file' => $file,
				);
			}
		}

		return array(
			'key'  => 'finish',
			'file' => '',
		);
	}

	/**
	 * When the build last showed any sign of being alive.
	 *
	 * The beat a step writes between sections, or failing that the last time
	 * anything wrote the job at all. Taking the later of the two matters
	 * because they are written by different things at different moments, and
	 * reading only one of them has already produced both mistakes worth
	 * avoiding: a live build declared dead, and a dead one left alone.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return int Unix time.
	 */
	public static function last_sign_of_life( array $job ): int {
		return max( (int) ( $job['heartbeat'] ?? 0 ), (int) ( $job['updated'] ?? 0 ) );
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
	 * Where the design this job is reading still is, or an empty string.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return string
	 */
	private static function root_of( array $job ): string {
		$root = (string) ( $job['root'] ?? '' );

		return '' !== $root && is_dir( $root ) ? $root : '';
	}
}
