<?php
/**
 * Plain-text extraction from parsed blocks.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the visible text out of a parsed block tree.
 *
 * Used for structured data, where schema.org wants prose rather than markup.
 */
final class BlockText {

	/**
	 * Collect the text of a parsed block and everything nested inside it.
	 *
	 * @param array<string, mixed> $block Parsed block, as produced by parse_blocks().
	 * @return string
	 */
	public static function from_block( array $block ): string {
		$text = '';

		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$text .= ' ' . wp_strip_all_tags( $block['innerHTML'] );
		}

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner ) {
				if ( is_array( $inner ) ) {
					$text .= ' ' . self::from_block( $inner );
				}
			}
		}

		return self::normalise( $text );
	}

	/**
	 * Collect the text of a list of parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string
	 */
	public static function from_blocks( array $blocks ): string {
		$text = '';

		foreach ( $blocks as $block ) {
			if ( is_array( $block ) ) {
				$text .= ' ' . self::from_block( $block );
			}
		}

		return self::normalise( $text );
	}

	/**
	 * Collapse whitespace and decode entities.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function normalise( string $text ): string {
		$decoded   = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$collapsed = preg_replace( '/\s+/u', ' ', $decoded );

		return trim( is_string( $collapsed ) ? $collapsed : $decoded );
	}
}
