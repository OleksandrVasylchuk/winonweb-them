<?php
/**
 * What the importer is doing right now, written down as it happens.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A running account of the import, kept per person and read by the screen.
 *
 * An import of a real handoff is minutes of work and can be hours: a thousand
 * files unpacked, a dozen documents read, eleven pages read out of components,
 * nine sections converted on each of them. A progress bar can say "step 7 of
 * 20" and nothing else, which for a long run is indistinguishable from a
 * hung one — and when it does finish, there is no record of what happened.
 *
 * So every step that takes real time writes a line here as it starts and as it
 * ends. The screen polls for the lines it has not seen, which also means the
 * account survives a reload: the log lives on the user, not in the tab.
 *
 * Deliberately bounded. Each line is one sentence, in one meta row — this is
 * a narration, not an audit trail, and it is thrown away with the session.
 */
final class ImportLog {

	/**
	 * User meta the lines are kept in.
	 *
	 * @var string
	 */
	private const META = '_qwerty_soft_import_log';

	/**
	 * How many lines are kept.
	 *
	 * This was two hundred, and a build writes a line per section: a real
	 * handoff of eleven pages ran past it inside the first page, and every
	 * line before that — what the unpack skipped, which archives it opened,
	 * what the design was diagnosed as — had been overwritten by the time
	 * anybody wanted to read it.
	 *
	 * @var int
	 */
	public const MAX_LINES = 1500;

	/**
	 * How many of the first lines are never dropped.
	 *
	 * The start of the account is the part worth keeping when the rest has to
	 * go: it says what was unpacked and what was left out, which is what a
	 * missing page is traced back to. So the log keeps its head and its tail,
	 * and the middle is what falls out when it overflows.
	 *
	 * @var int
	 */
	public const HEAD_LINES = 100;

	/**
	 * Write one line.
	 *
	 * @param string               $stage   Which part of the import: unpack, read, build, render.
	 * @param string               $message What happened, as a sentence for a person.
	 * @param array<string, mixed> $data    Optional numbers the screen may show.
	 * @return void
	 */
	public static function add( string $stage, string $message, array $data = array() ): void {
		$user = get_current_user_id();

		if ( 0 === $user ) {
			return;
		}

		$log = self::lines();

		/*
		 * A line is text, and the screen sets it as text. Titles arrive here
		 * as WordPress stores them — "vCISO &#038; AI Security" — and a line
		 * read back through the REST API showed exactly that. Decoded once,
		 * here, so no message has to remember to.
		 */
		$message = trim( html_entity_decode( wp_strip_all_tags( $message ), ENT_QUOTES, 'UTF-8' ) );

		$log[] = array(
			'seq'     => self::next_sequence( $log ),
			'at'      => time(),
			'stage'   => $stage,
			'message' => $message,
			'data'    => $data,
		);

		update_user_meta( $user, self::META, wp_slash( self::trim( $log ) ) );
	}

	/**
	 * Cut an overflowing log down to its first lines and its latest ones.
	 *
	 * Pure, so it can be tested without a user to hang the meta on.
	 *
	 * @param array<int, array<string, mixed>> $log  Lines, oldest first.
	 * @param int                              $max  How many to keep in all.
	 * @param int                              $head How many of the first to keep always.
	 * @return array<int, array<string, mixed>>
	 */
	public static function trim( array $log, int $max = self::MAX_LINES, int $head = self::HEAD_LINES ): array {
		$log = array_values( $log );

		if ( count( $log ) <= $max ) {
			return $log;
		}

		$head = max( 0, min( $head, $max ) );
		$tail = $max - $head;

		return array_merge(
			array_slice( $log, 0, $head ),
			$tail > 0 ? array_slice( $log, -$tail ) : array()
		);
	}

	/**
	 * Everything written since a given line.
	 *
	 * @param int $since Sequence number the caller already has.
	 * @return array<int, array<string, mixed>>
	 */
	public static function since( int $since ): array {
		return array_values(
			array_filter(
				self::lines(),
				static function ( array $line ) use ( $since ): bool {
					return (int) $line['seq'] > $since;
				}
			)
		);
	}

	/**
	 * Start a fresh account, for a run that has just begun.
	 *
	 * @return void
	 */
	public static function clear(): void {
		$user = get_current_user_id();

		if ( 0 !== $user ) {
			delete_user_meta( $user, self::META );
		}
	}

	/**
	 * The lines as stored.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function lines(): array {
		$user = get_current_user_id();

		if ( 0 === $user ) {
			return array();
		}

		$stored = get_user_meta( $user, self::META, true );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * The next sequence number.
	 *
	 * Sequence rather than time: two lines written in the same second are
	 * still ordered, and the screen asks for "everything after 41" rather
	 * than juggling clocks that disagree.
	 *
	 * @param array<int, array<string, mixed>> $log Current lines.
	 * @return int
	 */
	private static function next_sequence( array $log ): int {
		$last = end( $log );

		return is_array( $last ) ? (int) $last['seq'] + 1 : 1;
	}
}
