<?php
/**
 * What past imports taught this site's importer.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A journal the importer writes at the end of every build and reads at the
 * start of the next.
 *
 * Every archive teaches something: this one carried two sites in one zip,
 * that one was a React application whose pages had to be read out of a
 * router, the third registered a record type on top of WooCommerce's URLs.
 * The fixes live in code, but the *pattern* — what archives turn out to be,
 * which defences fire, how faithful the result measured — used to live in
 * nobody's head from one import to the next.
 *
 * So each build records what it saw and how it went, and the next one starts
 * knowing it: the screen says what earlier imports ran into, and the model
 * that reviews sections is told the studio's history in one paragraph, which
 * is the difference between an importer that is patched and one that learns.
 *
 * Deliberately small and factual. Twenty entries, counters and percentages —
 * a measurement journal, not a diary; anything worth acting on automatically
 * gets promoted to code, where it can be tested.
 */
final class Lessons {

	/**
	 * Option the journal is kept in.
	 *
	 * @var string
	 */
	private const OPTION = 'qwerty_soft_lessons';

	/**
	 * Entries kept; the oldest fall away.
	 *
	 * @var int
	 */
	private const MAX = 20;

	/**
	 * Entries the brief counts from; the older ones are history, not advice.
	 *
	 * The journal keeps twenty so a person can read back that far. The model
	 * is told about the last ten. Counters that ran over the whole journal
	 * never forgot anything: one bad archive from a year ago went on
	 * warning every review about listings until it fell off the end, and
	 * the fixes that had since gone into code counted for nothing against
	 * it. What the last ten builds ran into is what the next one is likely
	 * to run into.
	 *
	 * @var int
	 */
	private const RECENT = 10;

	/**
	 * Option the running build's counters are kept in.
	 *
	 * An option rather than a static, because a build is many requests — the
	 * plan in one, each page on its own cron tick, the finish in another —
	 * and a counter that lived in the process died with it. record() folds
	 * these in and clears them.
	 *
	 * @var string
	 */
	private const NOTES = 'qwerty_soft_lessons_notes';

	/**
	 * Count one thing the build just noticed or defended against.
	 *
	 * @param string $what A short key: shell_skipped, listing_downgraded, divergent_single, slug_collision, extra_source.
	 * @return void
	 */
	public static function note( string $what ): void {
		$notes = self::notes();

		$notes[ $what ] = ( $notes[ $what ] ?? 0 ) + 1;

		update_option( self::NOTES, $notes, false );
	}

	/**
	 * The counters so far, for the record.
	 *
	 * @return array<string, int>
	 */
	public static function notes(): array {
		$stored = get_option( self::NOTES, array() );

		return is_array( $stored ) ? array_map( 'intval', $stored ) : array();
	}

	/**
	 * Write one build's entry.
	 *
	 * @param array<string, mixed> $entry What the build saw and how it went.
	 * @return void
	 */
	public static function record( array $entry ): void {
		$entries = self::all();

		$entry['at']    = time();
		$entry['notes'] = array_merge( self::notes(), is_array( $entry['notes'] ?? null ) ? $entry['notes'] : array() );

		$entries[] = $entry;

		if ( count( $entries ) > self::MAX ) {
			$entries = array_slice( $entries, -self::MAX );
		}

		update_option( self::OPTION, $entries, false );
		delete_option( self::NOTES );
	}

	/**
	 * Every entry, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * The journal as one paragraph a model or a person can absorb.
	 *
	 * @return string Empty when nothing has been learned yet.
	 */
	public static function brief(): string {
		return self::summarise( self::all() );
	}

	/**
	 * The paragraph, from a given journal.
	 *
	 * The first sentence counts the whole journal — how many archives, of
	 * what kinds — because that is a fact about the site. The counters and
	 * the fidelity warning come from the recent entries only, because those
	 * are advice, and advice goes stale: see {@see self::RECENT}.
	 *
	 * @param array<int, array<string, mixed>> $entries Journal entries, oldest first.
	 * @return string Empty when the journal is empty.
	 */
	public static function summarise( array $entries ): string {
		$entries = array_values( $entries );

		if ( array() === $entries ) {
			return '';
		}

		$kinds  = array();
		$counts = array();
		$thin   = 0;

		foreach ( $entries as $entry ) {
			$kind = (string) ( $entry['kind'] ?? '' );

			if ( '' !== $kind ) {
				$kinds[ $kind ] = ( $kinds[ $kind ] ?? 0 ) + 1;
			}
		}

		foreach ( array_slice( $entries, -self::RECENT ) as $entry ) {
			foreach ( (array) ( $entry['notes'] ?? array() ) as $what => $times ) {
				$counts[ (string) $what ] = ( $counts[ (string) $what ] ?? 0 ) + (int) $times;
			}

			if ( (int) ( $entry['fidelity_min'] ?? 100 ) < 80 ) {
				++$thin;
			}
		}

		$said = array();

		foreach ( $kinds as $kind => $times ) {
			$said[] = $times . '× ' . $kind;
		}

		$sentences = sprintf(
			/* translators: 1: number of earlier imports, 2: what kinds they were. */
			__( 'This site has imported %1$d design archives before (%2$s).', 'qwerty-soft-signal' ),
			count( $entries ),
			implode( ', ', $said )
		);

		$named = array(
			'shell_skipped'      => __( 'empty application shells listed among the pages', 'qwerty-soft-signal' ),
			'listing_downgraded' => __( 'sections that looked like record listings but could not name their record', 'qwerty-soft-signal' ),
			'divergent_single'   => __( 'repeated cards whose rows carried different unplanned text', 'qwerty-soft-signal' ),
			'slug_collision'     => __( 'record types whose URL base another plugin already owned', 'qwerty-soft-signal' ),
			'extra_source'       => __( 'archives carrying more than one site, each with its own stylesheet', 'qwerty-soft-signal' ),
		);

		foreach ( $counts as $what => $times ) {
			if ( isset( $named[ $what ] ) && $times > 0 ) {
				$sentences .= ' ' . sprintf(
					/* translators: 1: how many times, 2: what was encountered. */
					__( 'Earlier archives held %1$d× %2$s.', 'qwerty-soft-signal' ),
					$times,
					$named[ $what ]
				);
			}
		}

		if ( $thin > 0 ) {
			$sentences .= ' ' . sprintf(
				/* translators: %d: how many recent imports measured below the fidelity floor. */
				__( '%d of the most recent builds measured below 80%% of the design\'s copy on at least one page, always in a repeated or listing section — treat those with extra care.', 'qwerty-soft-signal' ),
				$thin
			);
		}

		return $sentences;
	}
}
