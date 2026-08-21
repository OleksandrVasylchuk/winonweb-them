<?php
/**
 * States what the design's CSS actually resolves to, element by element.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

defined( 'ABSPATH' ) || exit;

/**
 * The layout brief a model gets instead of a stylesheet.
 *
 * Handing over raw CSS asks the reader to run the cascade in its head. It has
 * to find which of forty rules reach this `div`, apply them in specificity
 * order, and resolve the `var()`s — and the answer to "is this two columns or
 * a stack" is buried three files away in a rule that was never on screen.
 * Every conversion mistake worth fixing in this theme's history came from that
 * question being answered wrong.
 *
 * {@see CssIndex} already runs that cascade for the structural converter. This
 * turns the same resolved values into lines a model reads without doing any
 * work:
 *
 *     div.grid — grid: 1.06fr .72fr · gap 48px · items center · padding 96px 0
 *     h2.section-head__title — Fraunces 44px/1.1 · weight 600 · #f5f3ef
 *
 * Only what changes a conversion decision is listed. Rules the design never
 * wrote are simply absent, which is itself information: an element with no
 * line under it is an ordinary block in the flow.
 */
final class DesignFacts {

	/**
	 * Elements always worth a line, whatever the design said about them.
	 *
	 * @var array<int, string>
	 */
	private const ALWAYS = array( 'section', 'header', 'footer', 'nav', 'aside', 'article', 'main', 'form', 'table', 'img', 'video', 'svg', 'h1', 'h2', 'h3' );

	/**
	 * Properties that decide how children are laid out.
	 *
	 * @var array<int, string>
	 */
	private const LAYOUT = array( 'display', 'grid-template-columns', 'flex-direction', 'flex-wrap', 'align-items', 'justify-content', 'row-gap', 'column-gap' );

	/**
	 * Properties that describe the box itself.
	 *
	 * @var array<int, string>
	 */
	private const BOX = array( 'max-width', 'width', 'min-height', 'aspect-ratio', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-bottom', 'border-radius', 'border-width', 'border-style', 'border-color', 'box-shadow', 'opacity' );

	/**
	 * Properties that describe how text and surfaces look.
	 *
	 * @var array<int, string>
	 */
	private const PAINT = array( 'background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-transform', 'text-align' );

	/**
	 * Lines one brief may run to before it is cut.
	 */
	private const MAX_LINES = 90;

	/**
	 * How deep into the section the walk goes.
	 *
	 * Past this the elements are words inside a sentence, not layout.
	 */
	private const MAX_DEPTH = 7;

	/**
	 * The resolved-CSS brief for one section of a design.
	 *
	 * @param string        $html Section markup, as SectionSplitter produced it.
	 * @param CssIndex|null $css  The design's stylesheets, or null when there are none.
	 * @return string Markdown-ready lines, or an empty string when nothing is worth saying.
	 */
	public static function for_section( string $html, ?CssIndex $css ): string {
		if ( null === $css || '' === trim( $html ) ) {
			return '';
		}

		$dom = new DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof DOMElement ) {
			return '';
		}

		$lines   = array();
		$cut     = false;
		$context = array();

		self::walk( $body, $css, 0, $lines, $cut, $context );

		if ( array() === $lines ) {
			return '';
		}

		if ( $cut ) {
			$lines[] = '… (deeper elements omitted; they inherit from the ones above)';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Walk the section, writing a line for every element that earns one.
	 *
	 * @param DOMElement         $node    Element to describe.
	 * @param CssIndex           $css     Resolver.
	 * @param int                $depth   Current depth.
	 * @param array<int, string> $lines   Collected lines, appended to.
	 * @param bool               $cut     Set when the walk stopped early.
	 * @param array<string, int> $context Counts of elements already described, by descriptor.
	 * @return void
	 */
	private static function walk( DOMElement $node, CssIndex $css, int $depth, array &$lines, bool &$cut, array &$context ): void {
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( count( $lines ) >= self::MAX_LINES ) {
				$cut = true;

				return;
			}

			$line = self::describe( $child, $css, $depth, $context );

			if ( '' !== $line ) {
				$lines[] = $line;
			}

			/*
			 * Only a depth stop that actually leaves something unsaid counts
			 * as one. Reaching the limit on an element with no children of its
			 * own puts "deeper elements omitted" under a brief that omitted
			 * nothing, which teaches the reader to distrust the note in the
			 * cases where it is true.
			 */
			if ( $depth + 1 > self::MAX_DEPTH ) {
				foreach ( $child->childNodes as $grandchild ) {
					if ( $grandchild instanceof DOMElement ) {
						$cut = true;
						break;
					}
				}

				continue;
			}

			self::walk( $child, $css, $depth + 1, $lines, $cut, $context );
		}
	}

	/**
	 * One element's line, or an empty string when it says nothing new.
	 *
	 * Repeats are described once. A grid of nine identical cards is nine
	 * copies of the same three facts, and spending the brief on the other
	 * eight buys nothing — the model is told how many there were instead.
	 *
	 * @param DOMElement         $node    Element.
	 * @param CssIndex           $css     Resolver.
	 * @param int                $depth   Depth, for the indent.
	 * @param array<string, int> $context Descriptors already written.
	 * @return string
	 */
	private static function describe( DOMElement $node, CssIndex $css, int $depth, array &$context ): string {
		$tag        = strtolower( $node->tagName );
		$descriptor = self::descriptor( $node );

		$styles = $css->styles_for( $node );

		$layout = self::pick( $styles, self::LAYOUT );
		$box    = self::pick( $styles, self::BOX );
		$paint  = self::pick( $styles, self::PAINT );

		$always = in_array( $tag, self::ALWAYS, true );

		if ( ! $always && array() === $layout && array() === $box && array() === $paint ) {
			return '';
		}

		$seen = isset( $context[ $descriptor ] ) ? (int) $context[ $descriptor ] : 0;

		$context[ $descriptor ] = $seen + 1;

		if ( $seen > 0 ) {
			// The second one is worth a note; the rest are silent.
			return 1 === $seen
				? str_repeat( '  ', $depth ) . '- ' . $descriptor . ' — repeats, same styles as above'
				: '';
		}

		$parts = array_filter(
			array(
				self::layout_phrase( $layout ),
				self::box_phrase( $box ),
				self::paint_phrase( $paint ),
				self::note_phrase( $node, $styles ),
			)
		);

		if ( array() === $parts ) {
			return '';
		}

		return str_repeat( '  ', $depth ) . '- ' . $descriptor . ' — ' . implode( ' · ', $parts );
	}

	/**
	 * How an element is named in the brief: tag, classes, id.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private static function descriptor( DOMElement $node ): string {
		$name = strtolower( $node->tagName );

		$id = trim( $node->getAttribute( 'id' ) );

		if ( '' !== $id ) {
			$name .= '#' . $id;
		}

		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		$classes = array_values( array_filter( is_array( $classes ) ? $classes : array() ) );

		foreach ( array_slice( $classes, 0, 3 ) as $class ) {
			$name .= '.' . $class;
		}

		return $name;
	}

	/**
	 * The properties from a set that the design actually declared.
	 *
	 * @param array<string, string> $styles     Resolved styles.
	 * @param array<int, string>    $properties Properties of interest.
	 * @return array<string, string>
	 */
	private static function pick( array $styles, array $properties ): array {
		$found = array();

		foreach ( $properties as $property ) {
			$value = isset( $styles[ $property ] ) ? trim( (string) $styles[ $property ] ) : '';

			if ( '' !== $value && 'initial' !== $value && 'unset' !== $value ) {
				$found[ $property ] = $value;
			}
		}

		return $found;
	}

	/**
	 * "grid: 1.06fr .72fr · gap 48px · items center".
	 *
	 * @param array<string, string> $layout Layout properties.
	 * @return string
	 */
	private static function layout_phrase( array $layout ): string {
		$display = strtolower( $layout['display'] ?? '' );

		if ( '' === $display ) {
			return '';
		}

		if ( 'none' === $display ) {
			return 'hidden in the design (display:none)';
		}

		$parts = array();

		if ( str_contains( $display, 'grid' ) ) {
			$tracks = trim( $layout['grid-template-columns'] ?? '' );

			$parts[] = '' !== $tracks
				? 'grid: ' . self::compact( $tracks )
				: 'grid';
		} elseif ( str_contains( $display, 'flex' ) ) {
			$direction = strtolower( $layout['flex-direction'] ?? 'row' );

			$parts[] = 'flex ' . ( str_starts_with( $direction, 'column' ) ? 'column' : 'row' );

			if ( str_contains( strtolower( $layout['flex-wrap'] ?? '' ), 'wrap' ) ) {
				$parts[] = 'wraps';
			}
		} elseif ( 'inline-block' === $display || 'inline-flex' === $display ) {
			$parts[] = $display;
		} else {
			return '';
		}

		$gap = self::gap_phrase( $layout );

		if ( '' !== $gap ) {
			$parts[] = $gap;
		}

		$align = trim( $layout['align-items'] ?? '' );

		if ( '' !== $align && 'normal' !== $align && 'stretch' !== $align ) {
			$parts[] = 'items ' . $align;
		}

		$justify = trim( $layout['justify-content'] ?? '' );

		if ( '' !== $justify && 'normal' !== $justify && 'flex-start' !== $justify ) {
			$parts[] = 'justify ' . $justify;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * "gap 48px", or "gap 24px/48px" when the two axes differ.
	 *
	 * @param array<string, string> $layout Layout properties.
	 * @return string
	 */
	private static function gap_phrase( array $layout ): string {
		$row    = trim( $layout['row-gap'] ?? '' );
		$column = trim( $layout['column-gap'] ?? '' );

		if ( '' === $row && '' === $column ) {
			return '';
		}

		if ( '' === $row || $row === $column ) {
			return 'gap ' . ( '' !== $column ? $column : $row );
		}

		if ( '' === $column ) {
			return 'row gap ' . $row;
		}

		return 'gap ' . $row . '/' . $column;
	}

	/**
	 * "max 1200px · padding 96px 0 · radius 18px".
	 *
	 * @param array<string, string> $box Box properties.
	 * @return string
	 */
	private static function box_phrase( array $box ): string {
		$parts = array();

		$max = trim( $box['max-width'] ?? '' );

		if ( '' !== $max && 'none' !== $max ) {
			$parts[] = 'max ' . $max;
		}

		$min_height = trim( $box['min-height'] ?? '' );

		if ( '' !== $min_height ) {
			$parts[] = 'min-height ' . $min_height;
		}

		$ratio = trim( $box['aspect-ratio'] ?? '' );

		if ( '' !== $ratio ) {
			$parts[] = 'ratio ' . $ratio;
		}

		$padding = self::edges( $box, 'padding' );

		if ( '' !== $padding ) {
			$parts[] = 'padding ' . $padding;
		}

		$margin_top    = trim( $box['margin-top'] ?? '' );
		$margin_bottom = trim( $box['margin-bottom'] ?? '' );

		if ( '' !== $margin_top || '' !== $margin_bottom ) {
			$parts[] = 'margin ' . ( '' !== $margin_top ? $margin_top : '0' ) . ' / ' . ( '' !== $margin_bottom ? $margin_bottom : '0' );
		}

		$radius = trim( $box['border-radius'] ?? '' );

		if ( '' !== $radius && '0' !== $radius && '0px' !== $radius ) {
			$parts[] = 'radius ' . $radius;
		}

		$border = self::border_phrase( $box );

		if ( '' !== $border ) {
			$parts[] = $border;
		}

		if ( '' !== trim( $box['box-shadow'] ?? '' ) && 'none' !== strtolower( trim( $box['box-shadow'] ) ) ) {
			$parts[] = 'has a shadow';
		}

		$opacity = trim( $box['opacity'] ?? '' );

		if ( '' !== $opacity && '1' !== $opacity ) {
			$parts[] = 'opacity ' . $opacity;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * The four padding values, collapsed the way CSS writes them.
	 *
	 * @param array<string, string> $box    Box properties.
	 * @param string                $prefix Property prefix.
	 * @return string
	 */
	private static function edges( array $box, string $prefix ): string {
		$top    = trim( $box[ $prefix . '-top' ] ?? '' );
		$right  = trim( $box[ $prefix . '-right' ] ?? '' );
		$bottom = trim( $box[ $prefix . '-bottom' ] ?? '' );
		$left   = trim( $box[ $prefix . '-left' ] ?? '' );

		if ( '' === $top && '' === $right && '' === $bottom && '' === $left ) {
			return '';
		}

		$top    = '' !== $top ? $top : '0';
		$right  = '' !== $right ? $right : '0';
		$bottom = '' !== $bottom ? $bottom : '0';
		$left   = '' !== $left ? $left : '0';

		if ( '0' === $top && '0' === $right && '0' === $bottom && '0' === $left ) {
			return '';
		}

		if ( $top === $bottom && $right === $left ) {
			return $top === $right ? $top : $top . ' ' . $right;
		}

		return $top . ' ' . $right . ' ' . $bottom . ' ' . $left;
	}

	/**
	 * "1px solid #22282f", when there is a visible border.
	 *
	 * @param array<string, string> $box Box properties.
	 * @return string
	 */
	private static function border_phrase( array $box ): string {
		$width = trim( $box['border-width'] ?? '' );
		$style = strtolower( trim( $box['border-style'] ?? '' ) );

		if ( '' === $width || '0' === $width || '0px' === $width || 'none' === $style ) {
			return '';
		}

		$colour = trim( $box['border-color'] ?? '' );

		return 'border ' . $width . ( '' !== $style ? ' ' . $style : '' ) . ( '' !== $colour ? ' ' . $colour : '' );
	}

	/**
	 * "Fraunces 44px/1.1 · weight 600 · #f5f3ef on #0b0f14 · centred".
	 *
	 * @param array<string, string> $paint Paint properties.
	 * @return string
	 */
	private static function paint_phrase( array $paint ): string {
		$parts = array();

		$family = trim( $paint['font-family'] ?? '' );
		$size   = trim( $paint['font-size'] ?? '' );
		$height = trim( $paint['line-height'] ?? '' );

		$type = '';

		if ( '' !== $family ) {
			$type = self::first_family( $family );
		}

		if ( '' !== $size ) {
			$type = '' !== $type ? $type . ' ' . $size : $size;
		}

		if ( '' !== $height && '' !== $type ) {
			$type .= '/' . $height;
		}

		if ( '' !== $type ) {
			$parts[] = $type;
		}

		$weight = trim( $paint['font-weight'] ?? '' );

		if ( '' !== $weight && 'normal' !== $weight && '400' !== $weight ) {
			$parts[] = 'weight ' . $weight;
		}

		$transform = strtolower( trim( $paint['text-transform'] ?? '' ) );

		if ( '' !== $transform && 'none' !== $transform ) {
			$parts[] = $transform;
		}

		$spacing = trim( $paint['letter-spacing'] ?? '' );

		if ( '' !== $spacing && 'normal' !== $spacing && '0' !== $spacing ) {
			$parts[] = 'tracking ' . $spacing;
		}

		$colour     = trim( $paint['color'] ?? '' );
		$background = trim( $paint['background-color'] ?? '' );

		if ( '' !== $colour && '' !== $background ) {
			$parts[] = $colour . ' on ' . $background;
		} elseif ( '' !== $colour ) {
			$parts[] = 'text ' . $colour;
		} elseif ( '' !== $background ) {
			$parts[] = 'on ' . $background;
		}

		$image = trim( $paint['background-image'] ?? '' );

		if ( '' !== $image && 'none' !== strtolower( $image ) ) {
			$parts[] = str_contains( strtolower( $image ), 'gradient' ) ? 'gradient background' : 'picture background';
		}

		$align = strtolower( trim( $paint['text-align'] ?? '' ) );

		if ( 'center' === $align ) {
			$parts[] = 'centred';
		} elseif ( 'right' === $align || 'end' === $align ) {
			$parts[] = 'right-aligned';
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Anything else about the element the model should be warned about.
	 *
	 * @param DOMElement            $node   Element.
	 * @param array<string, string> $styles Resolved styles.
	 * @return string
	 */
	private static function note_phrase( DOMElement $node, array $styles ): string {
		$notes = array();

		$position = strtolower( trim( $styles['position'] ?? '' ) );

		if ( in_array( $position, array( 'absolute', 'fixed', 'sticky' ), true ) ) {
			$notes[] = $position . '-positioned in the design';
		}

		if ( $node->hasAttribute( 'data-animate' ) || $node->hasAttribute( 'data-count-to' ) || $node->hasAttribute( 'data-counter' ) ) {
			$notes[] = 'driven by JavaScript in the design';
		}

		return implode( ' · ', $notes );
	}

	/**
	 * The first family in a font stack, unquoted.
	 *
	 * @param string $family font-family value.
	 * @return string
	 */
	private static function first_family( string $family ): string {
		$first = trim( (string) strtok( $family, ',' ) );

		return trim( $first, "\"' " );
	}

	/**
	 * Collapse runs of whitespace so a track list stays on one line.
	 *
	 * @param string $value CSS value.
	 * @return string
	 */
	private static function compact( string $value ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}
}
