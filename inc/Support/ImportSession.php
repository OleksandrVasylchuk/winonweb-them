<?php
/**
 * Conversion work in progress, kept where a closed tab cannot take it.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

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
	private const META = '_wow_signal_import_session';

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
	private const JOB_META = '_wow_signal_build_job';

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
	 * Store the job after a step has changed it.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return void
	 */
	public static function update_job( array $job ): void {
		$job['updated'] = time();

		update_user_meta( get_current_user_id(), self::JOB_META, wp_slash( $job ) );
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
