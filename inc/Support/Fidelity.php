<?php
/**
 * How much of the design a built page actually shows.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * Measures a built page against the page it was built from.
 *
 * Every fault this importer has shipped had the same shape: the build reported
 * success, the site rendered less than the design, and somebody found out by
 * looking — days later, page by page. The rows that vanished from a listing,
 * the cards a repeat stamped from the wrong template, the table that lost
 * five hundred of its seven hundred words: all of them were measurable at
 * build time, and none of them were measured.
 *
 * So this measures. Two numbers per page, both against the source file the
 * sections were cut from: how much of the design's text survives a render of
 * the built blocks, and how many of the design's elements-with-classes do.
 * Neither is a screenshot diff — a rule this code can run on every build
 * beats a judgement it cannot — but between them they catch what "built 10
 * sections" hides: the words are the content and the classes are the layout,
 * and a page that keeps ~all of both is the design.
 */
final class Fidelity {

	/**
	 * Below this share of the design's words, a page is reported as thin.
	 *
	 * Ninety would flag honest wins — a deduplicated label, a menu lifted out
	 * of a section — as failures. The faults worth a concern were never
	 * subtle: the broken pages of past imports measured 14–47%.
	 */
	private const FLOOR = 80;

	/**
	 * Measure one built page against its source file.
	 *
	 * @param string $root Design root directory.
	 * @param string $file Page file, archive-relative.
	 * @param int    $id   The page the build made.
	 * @return array{words:int, rendered:int, ratio:int, structure:int}|null Null when there is nothing to compare.
	 */
	public static function of( string $root, string $file, int $id ) {
		$page = get_post( $id );

		if ( null === $page || ! is_file( trailingslashit( $root ) . $file ) ) {
			return null;
		}

		$split = SectionSplitter::split( trailingslashit( $root ) . $file, $root );

		$source_words  = 0;
		$source_tokens = array();

		foreach ( (array) ( $split['sections'] ?? array() ) as $section ) {
			$source_words += self::words( (string) $section['html'] );

			$source_tokens = array_merge( $source_tokens, self::tokens( (string) $section['html'] ) );
		}

		if ( 0 === $source_words ) {
			return null;
		}

		$rendered_words  = 0;
		$rendered_tokens = array();

		/*
		 * Rendered as the page, not as nobody. A listing filters its records
		 * by the language of the page it stands on, and it reads that from
		 * the global post — measured without one, every multilingual listing
		 * rendered a mixed-language grid the real page never shows.
		 */
		$previous = $GLOBALS['post'] ?? null;

		$GLOBALS['post'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below; the render must see the page it belongs to.

		/*
		 * From the block's own data, not through ACF. The field groups ACF
		 * holds in this request were read from disk at init, before this
		 * build rewrote them; read through those, a rewritten repeater lost
		 * every field it had gained and a whole page measured 74%.
		 */
		$was_raw          = DesignField::$raw;
		DesignField::$raw = true;

		foreach ( parse_blocks( (string) $page->post_content ) as $block ) {
			if ( ! is_string( $block['blockName'] ?? null ) || ! str_starts_with( (string) $block['blockName'], 'qs/design-' ) ) {
				continue;
			}

			/*
			 * A block written earlier in this same request is not registered
			 * yet — registration happens at init, and the finish step measures
			 * pages whose blocks the very same tick just wrote. Unregistered,
			 * render_block() returns nothing and a perfectly good page
			 * measured 0%, which is the one lie this class exists to end.
			 */
			$name = (string) $block['blockName'];

			if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				$dir = BlockWriter::dir() . '/' . substr( $name, strlen( 'qs/design-' ) );

				if ( is_file( $dir . '/block.json' ) ) {
					register_block_type( $dir );
				}
			}

			$html = render_block( $block );

			$rendered_words += self::words( $html );

			$rendered_tokens = array_merge( $rendered_tokens, self::tokens( $html ) );
		}

		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Putting back what was borrowed above.

		DesignField::$raw = $was_raw;

		/*
		 * Counted, not merely present. `array_diff()` asked whether each
		 * token appeared at all, so one rendered `div.card` covered the nine
		 * the design drew and a repeat that stamped a single row still
		 * measured 100% — the exact fault this number exists to catch. Each
		 * token now has to appear as many times as the design used it.
		 */
		$wanted = array_count_values( $source_tokens );
		$got    = array_count_values( $rendered_tokens );
		$kept   = 0;

		foreach ( $wanted as $token => $count ) {
			$kept += min( $count, (int) ( $got[ $token ] ?? 0 ) );
		}

		return array(
			'words'     => $source_words,
			'rendered'  => $rendered_words,
			'ratio'     => (int) min( 200, round( 100 * $rendered_words / $source_words ) ),
			'structure' => array() === $source_tokens ? 100 : (int) round( 100 * $kept / count( $source_tokens ) ),
		);
	}

	/**
	 * The concern a measurement earns, if it earns one.
	 *
	 * @param array<string, mixed>|null $measured What of() returned.
	 * @param string                    $title    The page, for the sentence.
	 * @return string Empty when the page holds up.
	 */
	public static function concern( $measured, string $title ): string {
		if ( ! is_array( $measured ) ) {
			return '';
		}

		if ( (int) $measured['ratio'] < self::FLOOR ) {
			return sprintf(
				/* translators: 1: page title, 2: percentage of the design's words the page renders. */
				__( '"%1$s" renders %2$d%% of the words the design wrote on it. A repeated section, a listing, or a table may have lost its rows — compare the page against the design.', 'qwerty-soft-signal' ),
				$title,
				(int) $measured['ratio']
			);
		}

		if ( (int) $measured['ratio'] > 150 ) {
			return sprintf(
				/* translators: 1: page title, 2: percentage of the design's words the page renders. */
				__( '"%1$s" renders %2$d%% of the design\'s words — more than the design wrote. A repeated section is probably stamping one row\'s content onto every row.', 'qwerty-soft-signal' ),
				$title,
				(int) $measured['ratio']
			);
		}

		return '';
	}

	/**
	 * Words in a piece of markup.
	 *
	 * @param string $html Markup.
	 * @return int
	 */
	private static function words( string $html ): int {
		$text = trim( (string) preg_replace( '#\s+#u', ' ', wp_strip_all_tags( $html ) ) );

		return '' === $text ? 0 : count( (array) preg_split( '#\s+#u', $text ) );
	}

	/**
	 * Every element with its classes, in document order.
	 *
	 * @param string $html Markup.
	 * @return array<int, string>
	 */
	private static function tokens( string $html ): array {
		$dom = new DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$tokens = array();
		$body   = ( new DOMXPath( $dom ) )->query( '//body' )->item( 0 );

		if ( $body instanceof DOMNode ) {
			self::walk( $body, $tokens );
		}

		return $tokens;
	}

	/**
	 * One node's subtree, flattened to tag.class tokens.
	 *
	 * @param DOMNode            $node   Where to start.
	 * @param array<int, string> $tokens Written to.
	 * @return void
	 */
	private static function walk( DOMNode $node, array &$tokens ): void {
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$classes = trim( (string) preg_replace( '#\s+#', ' ', $child->getAttribute( 'class' ) ) );
			$classes = implode( '.', array_diff( explode( ' ', $classes ), array( 'qs-design', '' ) ) );

			$tokens[] = $child->tagName . ( '' !== $classes ? '.' . $classes : '' );

			self::walk( $child, $tokens );
		}
	}
}
