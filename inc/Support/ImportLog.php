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
 * Deliberately small. Two hundred lines, each one sentence, one file — this is
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
	 * How many lines are kept; the oldest fall off the end.
	 *
	 * @var int
	 */
	private const MAX_LINES = 200;

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

		$log[] = array(
			'seq'     => self::next_sequence( $log ),
			'at'      => time(),
			'stage'   => $stage,
			'message' => $message,
			'data'    => $data,
		);

		if ( count( $log ) > self::MAX_LINES ) {
			$log = array_slice( $log, -self::MAX_LINES );
		}

		update_user_meta( $user, self::META, wp_slash( $log ) );
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
