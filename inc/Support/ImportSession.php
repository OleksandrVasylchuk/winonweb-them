<?php
/**
 * Conversion work in progress, kept where a closed tab cannot take it.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers converted sections between visits to the import screen.
 *
 * Conversions cost money and take a minute each. Holding them in a JavaScript
 * variable meant that closing the tab on section eight of fourteen threw away
 * both the work and what it cost — with nothing to show for it and no way to
 * resume except paying again.
 *
 * One page at a time, which is what the screen does anyway: opening a different
 * page replaces the record rather than accumulating one per page, so this
 * cannot grow without bound.
 *
 * The rendered preview is deliberately not stored. It is the largest part of a
 * result and the cheapest to rebuild — it is derived from markup that is stored,
 * so keeping it would trade a lot of database for nothing.
 */
final class ImportSession {

	/**
	 * User meta holding the work in progress.
	 */
	private const META = '_qwerty_soft_import_session';

	/**
	 * Drop work older than this.
	 *
	 * Long enough to survive a closed laptop overnight; short enough that a
	 * design edited since is not silently resumed against stale sections.
	 */
	private const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Store one converted section.
	 *
	 * @param string               $slug     Design slug.
	 * @param string               $file     Page file, relative to the design root.
	 * @param int                  $position Section position within the page.
	 * @param array<string, mixed> $result   Conversion result.
	 * @return void
	 */
	public static function remember( string $slug, string $file, int $position, array $result ): void {
		$stored = self::read();

		// A different page means the previous page's work is finished with.
		if ( ( $stored['slug'] ?? '' ) !== $slug || ( $stored['file'] ?? '' ) !== $file ) {
			$stored = array(
				'slug'    => $slug,
				'file'    => $file,
				'results' => array(),
			);
		}

		unset( $result['preview'] );

		$stored['results'][ (string) $position ] = $result;
		$stored['updated']                       = time();

		update_user_meta( get_current_user_id(), self::META, $stored );
	}

	/**
	 * Converted sections for a page, if any are still held.
	 *
	 * @param string $slug Design slug.
	 * @param string $file Page file.
	 * @return array<string, array<string, mixed>> Position => result.
	 */
	public static function recall( string $slug, string $file ): array {
		$stored = self::read();

		if ( ( $stored['slug'] ?? '' ) !== $slug || ( $stored['file'] ?? '' ) !== $file ) {
			return array();
		}

		$results = $stored['results'] ?? array();

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Throw the stored work away.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_user_meta( get_current_user_id(), self::META );
	}

	/**
	 * User meta holding a whole-site build in progress.
	 */
	private const JOB_META = '_qwerty_soft_build_job';

	/**
	 * A build that has not been touched for this long is abandoned.
	 *
	 * A step takes seconds; an hour of silence means the tab was closed.
	 */
	private const JOB_MAX_AGE = HOUR_IN_SECONDS;

	/**
	 * Begin a stepwise build and remember its record.
	 *
	 * One build per user at a time: starting another replaces it, the same
	 * way opening a different page replaces the conversion record. The job is
	 * the SiteAssembler job array plus the bookkeeping the screen needs.
	 *
	 * @param array<string, mixed> $job SiteAssembler::start() result.
	 * @return string Job ID to hand back to the browser.
	 */
	public static function start_job( array $job ): string {
		$id = wp_generate_password( 20, false, false );

		$job['id']      = $id;
		$job['updated'] = time();

		// A new build supersedes whatever the last one made.
		self::forget_report();

		/*
		 * Slashed on purpose: update_metadata() unslashes what it is given,
		 * and the job holds Windows paths and block markup, both of which
		 * carry backslashes that must survive the round trip.
		 */
		update_user_meta( get_current_user_id(), self::JOB_META, wp_slash( $job ) );

		return $id;
	}

	/**
	 * The build in progress, if it is the one the browser is asking about.
	 *
	 * @param string $id Job ID from the browser.
	 * @return array<string, mixed>|null
	 */
	public static function job( string $id ): ?array {
		$stored = get_user_meta( get_current_user_id(), self::JOB_META, true );

		if ( ! is_array( $stored ) || '' === $id || ( $stored['id'] ?? '' ) !== $id ) {
			return null;
		}

		if ( time() - (int) ( $stored['updated'] ?? 0 ) > self::JOB_MAX_AGE ) {
			return null;
		}

		return $stored;
	}

	/**
	 * The build in progress, whoever asked and whatever its ID.
	 *
	 * The ID check exists so one tab cannot advance another tab's build by
	 * accident. Watching it needs no such guard — and a build the server is
	 * running on its own has no tab that knows the ID at all.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function running(): ?array {
		$stored = get_user_meta( get_current_user_id(), self::JOB_META, true );

		if ( ! is_array( $stored ) || array() === $stored ) {
			return null;
		}

		/*
		 * An unattended build is allowed to be quiet for longer than one the
		 * browser is driving: a page of eight sections at high effort is
		 * minutes of one model call after another with nothing written down
		 * between them.
		 */
		$age = empty( $stored['unattended'] ) ? self::JOB_MAX_AGE : self::JOB_MAX_AGE * 6;

		return time() - (int) ( $stored['updated'] ?? 0 ) > $age ? null : $stored;
	}

	/**
	 * Store the job after a step has changed it.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return void
	 */
	public static function update_job( array $job ): void {
		$job['updated'] = time();

		/*
		 * A step holds the job as it was when the step began and writes it
		 * back when it ends, which for a page converted through a model is a
		 * quarter of an hour later. Everything in that copy is still right —
		 * it is the same step's own bookkeeping — except the two fields the
		 * step has been updating from underneath it. Written back wholesale
		 * they went out with the beat set to nothing, which told the watchdog
		 * the build had died and had it start the next page twice.
		 */
		$stored = get_user_meta( get_current_user_id(), self::JOB_META, true );

		if ( is_array( $stored ) ) {
			foreach ( array( 'heartbeat', 'working' ) as $live ) {
				if ( isset( $stored[ $live ] ) && ( ! isset( $job[ $live ] ) || $stored[ $live ] > $job[ $live ] ) ) {
					$job[ $live ] = $stored[ $live ];
				}
			}
		}

		update_user_meta( get_current_user_id(), self::JOB_META, wp_slash( $job ) );
	}

	/**
	 * Say the running job is still being worked on.
	 *
	 * A page converted section by section through a model takes a quarter of
	 * an hour and, until this existed, said nothing for the whole of it. From
	 * outside — the screen, or another cron tick — that is indistinguishable
	 * from a build whose process died, and both of them guessed wrong: the
	 * screen showed a frozen "2 of 7", and the tick started the same page
	 * again beside the one already building it.
	 *
	 * Written straight to user meta rather than through update_job(), because
	 * the caller holds a copy of the job from before the step began and
	 * writing that back would undo everything recorded since.
	 *
	 * @param int $user Whose build it is; the current user when 0.
	 * @return void
	 */
	public static function beat( int $user = 0 ): void {
		$user = $user > 0 ? $user : get_current_user_id();
		$job  = get_user_meta( $user, self::JOB_META, true );

		if ( ! is_array( $job ) ) {
			return;
		}

		$job['heartbeat'] = time();

		update_user_meta( $user, self::JOB_META, wp_slash( $job ) );
	}

	/**
	 * The build is over; drop its record.
	 *
	 * @return void
	 */
	public static function end_job(): void {
		delete_user_meta( get_current_user_id(), self::JOB_META );
	}

	/**
	 * Where the last finished build's report is kept.
	 *
	 * @var string
	 */
	private const REPORT_META = '_qwerty_soft_build_report';

	/**
	 * Keep what a finished build made, for the screen to find afterwards.
	 *
	 * A build ends by deleting its own job — correctly, because a finished job
	 * is not a running one and everything that reads it asks whether a build
	 * is in progress. But the report went with it, so the screen that had been
	 * watching for an hour was left with an empty upload form the moment the
	 * tab was reloaded: no list of pages, no links to them, nothing to say the
	 * hour had produced anything at all. This is the one part of a finished
	 * build worth outliving it.
	 *
	 * @param array<string, mixed> $report Report from finish().
	 * @return void
	 */
	public static function remember_report( array $report ): void {
		update_user_meta(
			get_current_user_id(),
			self::REPORT_META,
			wp_slash(
				array(
					'at'     => time(),
					'report' => $report,
				)
			)
		);
	}

	/**
	 * The last finished build's report, if there is one worth showing.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_report(): ?array {
		$stored = get_user_meta( get_current_user_id(), self::REPORT_META, true );

		if ( ! is_array( $stored ) || empty( $stored['report']['pages'] ) ) {
			return null;
		}

		return time() - (int) ( $stored['at'] ?? 0 ) > DAY_IN_SECONDS ? null : $stored;
	}

	/**
	 * Forget the last report, because a new build supersedes it.
	 *
	 * @return void
	 */
	public static function forget_report(): void {
		delete_user_meta( get_current_user_id(), self::REPORT_META );
	}

	/**
	 * The stored record, or an empty one if it is missing or stale.
	 *
	 * @return array<string, mixed>
	 */
	private static function read(): array {
		$stored = get_user_meta( get_current_user_id(), self::META, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		if ( time() - (int) ( $stored['updated'] ?? 0 ) > self::MAX_AGE ) {
			return array();
		}

		return $stored;
	}
}
