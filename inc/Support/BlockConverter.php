<?php
/**
 * Deterministic HTML to Gutenberg block conversion.
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
 * Turns a design section into blocks without asking a model.
 *
 * A model writes better copy decisions than this ever will, but it is not
 * always available: a key costs money, a subscription needs a human to ferry
 * text. So the baseline is structural and free. Headings stay headings, lists
 * stay lists, repeated sibling cards become columns, and every value that
 * would have been a literal is mapped onto a theme preset instead.
 *
 * The result is honest rather than clever: it will not invent a layout the
 * markup does not describe, and anything it cannot express it reports rather
 * than approximating. A model pass on top of this improves it; without one the
 * site still works.
 */
final class BlockConverter {

	/**
	 * Inline elements kept verbatim inside a text block.
	 *
	 * @var array<int, string>
	 */
	private const INLINE_KEEP = array( 'a', 'strong', 'b', 'em', 'i', 'span', 'br', 'code', 'sub', 'sup', 'mark', 'abbr', 'time', 'small', 'u' );

	/**
	 * Elements that never survive into page content.
	 *
	 * @var array<int, string>
	 */
	private const DROP = array( 'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'svg', 'canvas', 'form', 'input', 'select', 'textarea', 'button' );

	/**
	 * Class-name fragments that mark a short label above a heading.
	 *
	 * @var array<int, string>
	 */
	private const EYEBROW_HINTS = array( 'eyebrow', 'kicker', 'overline', 'tagline', 'label', 'badge', 'chip', 'tag', 'pill' );

	/**
	 * Class-name fragments that mark a call-to-action link.
	 *
	 * @var array<int, string>
	 */
	private const BUTTON_HINTS = array( 'btn', 'button', 'cta' );

	/**
	 * Notes gathered while converting the current section.
	 *
	 * @var array<int, string>
	 */
	private array $concerns = array();

	/**
	 * What a person will be able to edit in the current section.
	 *
	 * @var array<int, string>
	 */
	private array $editable = array();

	/**
	 * Whether this section may still emit an h1.
	 *
	 * @var bool
	 */
	private bool $allow_h1 = false;

	/**
	 * The palette slug this section's band should use.
	 *
	 * @var string
	 */
	private string $background = 'base';

	/**
	 * The design's own gradient for this band, if it has one.
	 *
	 * @var string
	 */
	private string $gradient = '';

	/**
	 * Whether this band needs light text on it.
	 *
	 * @var bool
	 */
	private bool $on_dark = false;

	/**
	 * How deep into nested column sets the walk currently is.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Section class name to the colour the design paints it, as hex.
	 *
	 * @var array<string, string>
	 */
	private array $section_colors = array();

	/**
	 * The palette the site is about to wear, slug to hex.
	 *
	 * @var array<string, string>
	 */
	private array $palette = array();

	/**
	 * Teach the converter what the design's own colours are.
	 *
	 * Without this every band falls back to the page colour, which is honest
	 * but flat. With it, a section the design painted grey comes out on the
	 * palette slug closest to that grey — so the rhythm of the original
	 * survives without a single literal colour reaching the markup.
	 *
	 * @param array<string, string> $section_colors Class name to hex.
	 * @param array<string, string> $palette        Slug to hex.
	 * @return void
	 */
	public function use_design( array $section_colors, array $palette ): void {
		$this->section_colors = $section_colors;
		$this->palette        = $palette;
	}

	/**
	 * Convert one section record from SectionSplitter.
	 *
	 * @param array<string, mixed> $section  Section record.
	 * @param bool                 $is_first Whether this is the page's first section.
	 * @return array{markup:string,summary:string,editable:array<int,string>,concerns:array<int,string>}
	 */
	public function convert( array $section, bool $is_first = false ): array {
		$this->concerns   = array();
		$this->editable   = array();
		$this->allow_h1   = $is_first;
		$this->background = $this->band_colour( $section );

		$dom  = self::load( (string) $section['html'] );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		$inner = $body instanceof DOMNode ? $this->children( $body ) : '';

		if ( '' === trim( $inner ) ) {
			return array(
				'markup'   => '',
				'summary'  => '',
				'editable' => array(),
				'concerns' => array( __( 'This section held nothing that could become content.', 'wow-signal' ) ),
			);
		}

		$position = (int) $section['position'];
		$markup   = $this->band( $inner, $position, (string) $section['label'] );

		return array(
			'markup'   => $markup,
			'summary'  => $this->summary( $section ),
			'editable' => array_values( array_unique( $this->editable ) ),
			'concerns' => array_values( array_unique( $this->concerns ) ),
		);
	}

	/**
	 * Wrap a section's blocks in a full-width band.
	 *
	 * Bands alternate between the page and surface colours so consecutive
	 * sections read as separate, which is what the borders and shadows in a
	 * static design were doing.
	 *
	 * @param string $inner    Inner block markup.
	 * @param int    $position Section index.
	 * @param string $label    Section label, kept as the block's name in the editor.
	 * @return string
	 */
	private function band( string $inner, int $position, string $label ): string {
		$padding = 0 === $position ? '90' : '80';
		$spacing = array(
			'padding' => array(
				'top'    => 'var:preset|spacing|' . $padding,
				'bottom' => 'var:preset|spacing|' . $padding,
			),
		);

		$attrs = array(
			'tagName'  => 'section',
			'metadata' => array( 'name' => $label ),
			'align'    => 'full',
			'style'    => array( 'spacing' => $spacing ),
			'layout'   => array( 'type' => 'constrained' ),
		);

		$classes = array( 'wp-block-group', 'alignfull' );
		$css     = 'padding-top:var(--wp--preset--spacing--' . $padding . ');padding-bottom:var(--wp--preset--spacing--' . $padding . ')';

		if ( '' !== $this->gradient ) {
			// The design's own gradient, carried across whole.
			$attrs['style']['color']['gradient'] = $this->gradient;
			$classes[]                           = 'has-background';
			$css                                 = 'background:' . $this->gradient . ';' . $css;
		} else {
			$attrs['backgroundColor'] = $this->background;
			$classes[]                = 'has-' . $this->background . '-background-color';
			$classes[]                = 'has-background';
		}

		/*
		 * A dark band on a light site needs its text inverted, and saying so
		 * on the band itself means every paragraph inside inherits it instead
		 * of each one carrying a colour that would have to be corrected.
		 */
		if ( $this->on_dark ) {
			$attrs['textColor'] = 'base';
			$classes[]          = 'has-base-color';
			$classes[]          = 'has-text-color';
		}

		return '<!-- wp:group ' . wp_json_encode( $attrs ) . " -->\n"
			. '<section class="' . implode( ' ', $classes ) . '" style="' . $css . '">'
			. $inner
			. "</section>\n<!-- /wp:group -->";
	}

	/**
	 * Which palette slug this section's band should be painted with.
	 *
	 * @param array<string, mixed> $section Section record.
	 * @return string
	 */
	private function band_colour( array $section ): string {
		$this->gradient = '';
		$this->on_dark  = false;

		if ( array() === $this->section_colors || array() === $this->palette ) {
			return 'base';
		}

		foreach ( (array) ( $section['classes'] ?? array() ) as $class ) {
			$key = strtolower( (string) $class );

			if ( ! isset( $this->section_colors[ $key ] ) ) {
				continue;
			}

			$found = $this->section_colors[ $key ];

			if ( str_contains( strtolower( $found ), 'gradient' ) ) {
				$this->gradient = $found;
				$this->on_dark  = $this->gradient_is_dark( $found );

				return 'base';
			}

			$slug = $this->nearest_slug( $found );

			if ( '' !== $slug ) {
				$this->on_dark = $this->is_dark( $this->palette[ $slug ] )
					&& ! $this->is_dark( $this->palette['base'] ?? '#ffffff' );

				return $slug;
			}
		}

		return 'base';
	}

	/**
	 * Whether a colour is dark enough to need light text on it.
	 *
	 * @param string $hex Colour.
	 * @return bool
	 */
	private function is_dark( string $hex ): bool {
		list( $r, $g, $b ) = self::channels( $hex );

		return ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255 < 0.45;
	}

	/**
	 * Whether a gradient reads as dark overall.
	 *
	 * @param string $gradient Gradient declaration.
	 * @return bool
	 */
	private function gradient_is_dark( string $gradient ): bool {
		if ( ! preg_match_all( '/#[0-9a-f]{3,8}\b/i', $gradient, $found ) ) {
			return false;
		}

		$dark = 0;

		foreach ( $found[0] as $hex ) {
			if ( $this->is_dark( substr( $hex, 0, 7 ) ) ) {
				++$dark;
			}
		}

		return $dark > count( $found[0] ) / 2;
	}

	/**
	 * The palette entry closest to a given colour.
	 *
	 * Only background-ish slugs are candidates: a section painted with the
	 * brand colour is a rarity, and guessing it wrong turns a whole band
	 * unreadable.
	 *
	 * @param string $hex Colour from the design.
	 * @return string
	 */
	private function nearest_slug( string $hex ): string {
		$best     = '';
		$distance = PHP_INT_MAX;

		foreach ( array( 'base', 'surface', 'surface-2', 'contrast' ) as $slug ) {
			if ( ! isset( $this->palette[ $slug ] ) ) {
				continue;
			}

			$gap = self::colour_distance( $hex, $this->palette[ $slug ] );

			if ( $gap < $distance ) {
				$distance = $gap;
				$best     = $slug;
			}
		}

		// Too far from anything in the palette to be worth claiming.
		return $distance <= 9000 ? $best : '';
	}

	/**
	 * Squared distance between two colours.
	 *
	 * @param string $one Colour.
	 * @param string $two Colour.
	 * @return int
	 */
	private static function colour_distance( string $one, string $two ): int {
		$a = self::channels( $one );
		$b = self::channels( $two );

		return ( $a[0] - $b[0] ) ** 2 + ( $a[1] - $b[1] ) ** 2 + ( $a[2] - $b[2] ) ** 2;
	}

	/**
	 * A colour's channels.
	 *
	 * @param string $hex Colour.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function channels( string $hex ): array {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return array( 0, 0, 0 );
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Walk a node's children and concatenate the blocks they produce.
	 *
	 * @param DOMNode $node Parent node.
	 * @return string
	 */
	private function children( DOMNode $node ): string {
		$out = array();

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$block = $this->node( $child );

			if ( '' !== trim( $block ) ) {
				$out[] = $block;
			}
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Convert a single node.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function node( DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( (string) $node->nodeValue );

			return '' === $text ? '' : $this->paragraph( esc_html( $text ) );
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->tagName );

		if ( in_array( $tag, self::DROP, true ) ) {
			$this->note_dropped( $tag );

			return '';
		}

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $this->heading( $node, (int) substr( $tag, 1 ) );

			case 'p':
				return $this->text_element( $node );

			case 'ul':
			case 'ol':
				return $this->list( $node, 'ol' === $tag );

			case 'img':
				return $this->image( $node );

			case 'picture':
				return $this->picture( $node );

			case 'figure':
				return $this->figure( $node );

			case 'table':
				return $this->table( $node );

			case 'blockquote':
				return $this->quote( $node );

			case 'hr':
				return "<!-- wp:separator -->\n"
					. '<hr class="wp-block-separator has-alpha-channel-opacity"/>'
					. "\n<!-- /wp:separator -->";

			case 'br':
				return '';

			case 'a':
				return $this->is_button( $node ) ? $this->buttons( array( $node ) ) : $this->text_element( $node );

			default:
				return $this->container( $node );
		}
	}

	/**
	 * A block-level container: either a column set, a button row, or a pass-through.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private function container( DOMElement $node ): string {
		$buttons = $this->button_links( $node );

		if ( array() !== $buttons ) {
			return $this->buttons( $buttons );
		}

		/*
		 * Meaning before shape. A design builds a checklist out of divs and a
		 * statistic out of two spans; mapping those to paragraphs is faithful
		 * to the tags and useless to the person who has to edit them later.
		 */
		$quote = $this->as_quote( $node );

		if ( null !== $quote ) {
			return $quote;
		}

		$checklist = $this->as_list( $node );

		if ( null !== $checklist ) {
			return $checklist;
		}

		$metric = $this->as_metric( $node );

		if ( null !== $metric ) {
			return $metric;
		}

		// A run of similar siblings is a grid in the design; make it columns.
		$cards = $this->card_children( $node );

		/*
		 * Only at the top of a section. Columns inside columns inside columns
		 * is how a two-digit number ends up printed one character per line;
		 * below the first nesting the children simply stack, which is what
		 * the design does at narrow widths anyway.
		 */
		if ( count( $cards ) >= 2 && $this->depth < 1 ) {
			++$this->depth;
			$out = $this->columns( $cards );
			--$this->depth;

			return $out;
		}

		if ( $this->is_inline_only( $node ) ) {
			$stacked = $this->stacked_children( $node );

			if ( array() !== $stacked ) {
				return $this->stack( $stacked );
			}

			$text = $this->inline( $node );

			return '' === trim( wp_strip_all_tags( $text ) ) ? '' : $this->styled_text( $node, $text );
		}

		return $this->children( $node );
	}

	/**
	 * A run of ticked lines that a design built out of divs.
	 *
	 * @param DOMElement $node Container.
	 * @return string|null
	 */
	private function as_list( DOMElement $node ): ?string {
		$items = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( (string) $child->nodeValue ) ) {
					return null;
				}

				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $child->textContent ) );

			if ( '' === $text ) {
				continue;
			}

			/*
			 * A list item is a line, not a card. Without this a row of three
			 * feature cards — each of which happens to contain a checklist
			 * somewhere inside — collapses into three list items with every
			 * word of the card welded together.
			 */
			if ( mb_strlen( $text ) > 120 ) {
				return null;
			}

			// A tick either as a glyph or as the class the design gave it.
			$marked = 1 === preg_match( '/^[\x{2713}\x{2714}\x{2705}\x{2022}\x{25AA}\x{25CF}\x{2043}\x{2010}-\x{2015}\-\x{2192}\x{25B8}]\s*/u', $text );

			if ( ! $marked ) {
				// Only the marker the item itself opens with, not one nested deep.
				$first = null;

				foreach ( $child->childNodes as $candidate ) {
					if ( $candidate instanceof DOMElement ) {
						$first = $candidate;
						break;
					}
				}

				$marked = null !== $first
					&& str_contains( strtolower( $first->getAttribute( 'class' ) ), 'check' );
			}

			if ( ! $marked ) {
				return null;
			}

			$items[] = trim( (string) preg_replace( '/^[\x{2713}\x{2714}\x{2705}\x{2022}\x{25AA}\x{25CF}\x{2043}\x{2010}-\x{2015}\-\x{2192}\x{25B8}]\s*/u', '', $text ) );
		}

		if ( count( $items ) < 2 ) {
			return null;
		}

		$this->editable[] = __( 'List items', 'wow-signal' );

		$out = array();

		foreach ( $items as $item ) {
			$out[] = "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->";
		}

		return "<!-- wp:list {\"className\":\"is-style-checks\"} -->\n"
			. '<ul class="wp-block-list is-style-checks">'
			. implode( "\n\n", $out )
			. "</ul>\n<!-- /wp:list -->";
	}

	/**
	 * A figure with a caption under it, as the theme's Metric block.
	 *
	 * Gives the site owner a block with real settings — the number, what goes
	 * before and after it, whether it counts up — instead of two paragraphs
	 * they have to guess the relationship between.
	 *
	 * @param DOMElement $node Container.
	 * @return string|null
	 */
	private function as_metric( DOMElement $node ): ?string {
		$parts = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$loose = trim( (string) $child->nodeValue );

				if ( '' !== $loose ) {
					$parts[] = $loose;
				}

				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $child->textContent ) );

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		list( $value, $label ) = $parts;

		// The first half has to read as a figure, the second as its caption.
		if ( 1 !== preg_match( '/^[^\p{L}]*\d[\d\s.,]*\s*[%+kmKM€$£]*\+?$/u', $value ) ) {
			return null;
		}

		if ( mb_strlen( $value ) > 12 || mb_strlen( $label ) > 60 || mb_strlen( $label ) < 2 ) {
			return null;
		}

		$this->editable[] = __( 'Figure and its caption', 'wow-signal' );

		return '<!-- wp:wow/metric ' . wp_json_encode(
			array(
				'value' => $value,
				'label' => $label,
			)
		) . ' /-->';
	}

	/**
	 * A testimonial, as a real quotation.
	 *
	 * @param DOMElement $node Container.
	 * @return string|null
	 */
	private function as_quote( DOMElement $node ): ?string {
		$class = strtolower( $node->getAttribute( 'class' ) );
		$hint  = false;

		foreach ( array( 'quote', 'qtext', 'testimonial', 'review' ) as $word ) {
			if ( str_contains( $class, $word ) ) {
				$hint = true;
				break;
			}
		}

		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );

		// Curly quotes around a long passage say the same thing the class does.
		$quoted = 1 === preg_match( '/^[\x{201C}\x{00AB}"]/u', $text ) && mb_strlen( $text ) > 60;

		if ( ! $hint && ! $quoted ) {
			return null;
		}

		if ( '' === $text || ! $this->is_inline_only( $node ) ) {
			return null;
		}

		$this->editable[] = __( 'Quotation', 'wow-signal' );

		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">"
			. "<!-- wp:paragraph -->\n<p>" . $this->inline( $node ) . "</p>\n<!-- /wp:paragraph -->"
			. "</blockquote>\n<!-- /wp:quote -->";
	}

	/**
	 * Inline children a stylesheet was stacking as separate lines.
	 *
	 * `<span>Role</span><strong>CEO</strong><small>Aquaprole</small>` is one
	 * run of inline elements to HTML, but the design's CSS made each a block.
	 * Flattening it gives "RoleCEOAquaprole", so when a container holds only
	 * inline elements and no loose text of its own, each child becomes its own
	 * line — which is both what it looked like and easier to edit.
	 *
	 * @param DOMElement $node Container.
	 * @return array<int, DOMElement>
	 */
	private function stacked_children( DOMElement $node ): array {
		$children = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				// Loose text means this really is one sentence, not a stack.
				if ( '' !== trim( (string) $child->nodeValue ) ) {
					return array();
				}

				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			// A link belongs in the sentence, not on a line of its own.
			if ( 'a' === $tag || 'br' === $tag ) {
				return array();
			}

			if ( '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			$children[] = $child;
		}

		return count( $children ) >= 2 ? $children : array();
	}

	/**
	 * Emit stacked inline children as separate text blocks.
	 *
	 * @param array<int, DOMElement> $children Children.
	 * @return string
	 */
	private function stack( array $children ): string {
		$out = array();

		foreach ( $children as $child ) {
			$text = $this->inline( $child );

			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				continue;
			}

			$out[] = $this->styled_text( $child, $text );
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Direct children that look like repeated cards.
	 *
	 * @param DOMElement $node Parent.
	 * @return array<int, DOMElement>
	 */
	private function card_children( DOMElement $node ): array {
		$candidates = array();

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$tag = strtolower( $child->tagName );

				if ( in_array( $tag, array( 'div', 'article', 'li', 'section', 'aside' ), true ) ) {
					$candidates[] = $child;
					continue;
				}
			}

			if ( XML_TEXT_NODE === $child->nodeType && '' !== trim( (string) $child->nodeValue ) ) {
				return array();
			}

			if ( $child instanceof DOMElement ) {
				return array();
			}
		}

		if ( count( $candidates ) < 2 || count( $candidates ) > 6 ) {
			return array();
		}

		// Only a grid if the children actually carry comparable content.
		foreach ( $candidates as $candidate ) {
			if ( '' === trim( (string) $candidate->textContent ) && 0 === $candidate->getElementsByTagName( 'img' )->length ) {
				return array();
			}
		}

		return $candidates;
	}

	/**
	 * Wrap card children in a columns block.
	 *
	 * @param array<int, DOMElement> $cards Card elements.
	 * @return string
	 */
	private function columns( array $cards ): string {
		$columns = array();

		foreach ( $cards as $card ) {
			$inner = $this->children( $card );

			if ( '' === trim( $inner ) ) {
				continue;
			}

			$columns[] = "<!-- wp:column -->\n<div class=\"wp-block-column\">" . $inner . "</div>\n<!-- /wp:column -->";
		}

		if ( count( $columns ) < 2 ) {
			return implode( "\n\n", $columns );
		}

		/*
		 * Six columns across a page is not a layout, it is a squeeze: numbers
		 * end up one character per line. Wrap into rows instead, which is what
		 * the design's own grid did at every width below its widest.
		 */

		/*
		 * Card styling only where there are actually cards. Two columns are a
		 * layout — a hero split between copy and a portrait — and giving that
		 * a card background paints a white panel over a dark band, which is
		 * how white text ends up on white. On a dark band nothing gets the
		 * card treatment at all: the theme's card colour is chosen against the
		 * page, not against a band that inverts it.
		 */
		$as_cards = count( $columns ) >= 3 && ! $this->on_dark;
		$class    = $as_cards ? ' is-style-cards' : '';
		$attrs    = $as_cards ? ',"className":"is-style-cards"' : '';

		$rows = array();

		foreach ( array_chunk( $columns, count( $columns ) > 4 ? 3 : count( $columns ) ) as $row ) {
			$rows[] = '<!-- wp:columns {"align":"wide"' . $attrs . "} -->\n"
				. '<div class="wp-block-columns alignwide' . $class . '">'
				. implode( "\n\n", $row )
				. "</div>\n<!-- /wp:columns -->";
		}

		return implode( "\n\n", $rows );
	}

	/**
	 * Links inside this element that are styled as buttons.
	 *
	 * @param DOMElement $node Parent.
	 * @return array<int, DOMElement>
	 */
	private function button_links( DOMElement $node ): array {
		$links = array();

		foreach ( $node->getElementsByTagName( 'a' ) as $link ) {
			if ( $this->is_button( $link ) ) {
				$links[] = $link;
			}
		}

		// Only treat the element as a button row when that is all it holds.
		$text = trim( (string) $node->textContent );
		$sum  = '';

		foreach ( $links as $link ) {
			$sum .= trim( (string) $link->textContent );
		}

		return '' !== $sum && str_replace( ' ', '', $sum ) === str_replace( ' ', '', $text ) ? $links : array();
	}

	/**
	 * Whether a link is presented as a button.
	 *
	 * @param DOMElement $link Anchor.
	 * @return bool
	 */
	private function is_button( DOMElement $link ): bool {
		$class = strtolower( $link->getAttribute( 'class' ) );

		foreach ( self::BUTTON_HINTS as $hint ) {
			if ( str_contains( $class, $hint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A row of buttons.
	 *
	 * @param array<int, DOMElement> $links Anchors.
	 * @return string
	 */
	private function buttons( array $links ): string {
		$out = array();

		foreach ( $links as $index => $link ) {
			$label = trim( (string) $link->textContent );

			if ( '' === $label ) {
				continue;
			}

			$outline = $index > 0;
			$href    = $this->href( $link );

			/*
			 * The outline style draws itself in the body-text colour, so on an
			 * inverted band it has to be told to use the page colour instead
			 * or it comes out dark on dark.
			 */
			$attrs   = array();
			$classes = array( 'wp-block-button' );

			if ( $outline ) {
				$attrs['className'] = 'is-style-outline';
				$classes[]          = 'is-style-outline';
			}

			if ( $outline && $this->on_dark ) {
				$attrs['textColor'] = 'base';
				$classes[]          = 'has-base-color';
				$classes[]          = 'has-text-color';
			}

			$out[] = '<!-- wp:button' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . " -->\n"
				. '<div class="' . implode( ' ', $classes ) . '">'
				. '<a class="wp-block-button__link wp-element-button" href="' . $this->esc_ref( $href ) . '">'
				. esc_html( $label )
				. "</a></div>\n<!-- /wp:button -->";

			$this->editable[] = __( 'Button label and link', 'wow-signal' );
		}

		if ( array() === $out ) {
			return '';
		}

		return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">"
			. implode( "\n\n", $out )
			. "</div>\n<!-- /wp:buttons -->";
	}

	/**
	 * A heading, with its level kept sane for the page.
	 *
	 * @param DOMElement $node  Heading element.
	 * @param int        $level Source level.
	 * @return string
	 */
	private function heading( DOMElement $node, int $level ): string {
		$text = $this->inline( $node );

		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			return '';
		}

		if ( 1 === $level ) {
			if ( $this->allow_h1 ) {
				$this->allow_h1 = false;
			} else {
				$level = 2;
			}
		}

		$sizes = array(
			1 => 'display',
			2 => 'xx-large',
			3 => 'large',
			4 => 'medium',
			5 => 'medium',
			6 => 'small',
		);
		$size  = $sizes[ $level ] ?? 'medium';

		/*
		 * The display size is built for two or three words. A whole sentence
		 * at that size fills the screen before it finishes, so long headings
		 * step down a notch.
		 */
		$length = mb_strlen( trim( wp_strip_all_tags( $text ) ) );

		if ( 'display' === $size && $length > 38 ) {
			$size = 'xxx-large';
		} elseif ( 'xx-large' === $size && $length > 64 ) {
			$size = 'x-large';
		}

		$this->editable[] = __( 'Heading text', 'wow-signal' );

		$attrs = array(
			'level'    => $level,
			'fontSize' => $size,
		);

		/*
		 * The theme paints headings with the body-text colour, which is chosen
		 * against the page and disappears on an inverted band. Text inside the
		 * band inherits, but a heading carries its own colour and has to be
		 * told.
		 */
		if ( $this->on_dark ) {
			$attrs['textColor'] = 'base';
		}
		$class = 'wp-block-heading has-' . $size . '-font-size';

		if ( $this->on_dark ) {
			$class = 'wp-block-heading has-base-color has-text-color has-' . $size . '-font-size';
		}

		return '<!-- wp:heading ' . wp_json_encode( $attrs ) . " -->\n"
			. '<h' . $level . ' class="' . $class . '">' . $text . '</h' . $level . '>'
			. "\n<!-- /wp:heading -->";
	}

	/**
	 * A paragraph or paragraph-like element.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private function text_element( DOMElement $node ): string {
		$text = $this->inline( $node );

		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			return '';
		}

		return $this->styled_text( $node, $text );
	}

	/**
	 * Apply the theme's text presets based on what the element looks like.
	 *
	 * @param DOMElement $node Element.
	 * @param string     $text Inline HTML.
	 * @return string
	 */
	private function styled_text( DOMElement $node, string $text ): string {
		$plain = trim( wp_strip_all_tags( $text ) );

		if ( $this->is_eyebrow( $node, $plain ) ) {
			$this->editable[] = __( 'Small label above a heading', 'wow-signal' );

			/*
			 * accent-ink rather than accent: a brand colour is picked to be
			 * seen as a fill, and plenty of them are unreadable at label size.
			 * The readable sibling keeps the hue and clears the minimum.
			 */
			$slug = $this->on_dark ? 'accent' : 'accent-ink';

			return '<!-- wp:paragraph {"textColor":"' . $slug . "\",\"fontSize\":\"x-small\",\"style\":{\"typography\":{\"textTransform\":\"uppercase\",\"letterSpacing\":\"0.14em\",\"fontWeight\":\"700\"}}} -->\n"
				. '<p class="has-' . $slug . '-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.14em;text-transform:uppercase">'
				. $text
				. "</p>\n<!-- /wp:paragraph -->";
		}

		if ( $this->is_title( $node, $plain ) ) {
			return $this->heading( $node, 3 );
		}

		$this->editable[] = __( 'Body text', 'wow-signal' );

		return $this->paragraph( $text );
	}

	/**
	 * Whether an element is a card's title rather than its copy.
	 *
	 * Designs stop using h3 the moment a card gets its own stylesheet, so the
	 * headings that structure a page disappear into divs. Left alone, a page
	 * of twelve cards has one heading and thirty paragraphs — unusable with a
	 * screen reader and invisible to search engines.
	 *
	 * @param DOMElement $node  Element.
	 * @param string     $plain Its text.
	 * @return bool
	 */
	private function is_title( DOMElement $node, string $plain ): bool {
		if ( '' === $plain || mb_strlen( $plain ) > 90 ) {
			return false;
		}

		// A sentence is copy, however short.
		if ( 1 === preg_match( '/[.!?]\s+\p{Lu}/u', $plain ) ) {
			return false;
		}

		$class = strtolower( $node->getAttribute( 'class' ) );

		foreach ( array( 'title', 'heading', 'headline', 'name', 'subject', 'question' ) as $word ) {
			if ( str_contains( $class, $word ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A muted body paragraph.
	 *
	 * @param string $text Inline HTML.
	 * @return string
	 */
	private function paragraph( string $text ): string {
		/*
		 * On a dark band the paragraph says nothing about its colour and
		 * inherits the light text set on the band. Naming "muted" here would
		 * paint dark grey on dark navy — the palette's muted is chosen against
		 * the page background, not against every band on it.
		 */
		if ( $this->on_dark ) {
			return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
		}

		return "<!-- wp:paragraph {\"textColor\":\"muted\"} -->\n"
			. '<p class="has-muted-color has-text-color">' . $text . "</p>\n"
			. '<!-- /wp:paragraph -->';
	}

	/**
	 * Whether an element reads as a small label rather than body copy.
	 *
	 * @param DOMElement $node  Element.
	 * @param string     $plain Its text.
	 * @return bool
	 */
	private function is_eyebrow( DOMElement $node, string $plain ): bool {
		if ( mb_strlen( $plain ) > 48 || '' === $plain ) {
			return false;
		}

		$class = strtolower( $node->getAttribute( 'class' ) );

		foreach ( self::EYEBROW_HINTS as $hint ) {
			if ( str_contains( $class, $hint ) ) {
				return true;
			}
		}

		// All-caps short text is a label whatever it is called.
		return mb_strtoupper( $plain, 'UTF-8' ) === $plain && mb_strlen( $plain ) > 2;
	}

	/**
	 * A list.
	 *
	 * @param DOMElement $node    List element.
	 * @param bool       $ordered Whether it is ordered.
	 * @return string
	 */
	private function list( DOMElement $node, bool $ordered ): string {
		$items = array();

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$text = $this->inline( $child );

			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				continue;
			}

			$items[] = "<!-- wp:list-item -->\n<li>" . $text . "</li>\n<!-- /wp:list-item -->";
		}

		if ( array() === $items ) {
			return '';
		}

		$this->editable[] = __( 'List items', 'wow-signal' );

		$tag   = $ordered ? 'ol' : 'ul';
		$attrs = $ordered ? ' {"ordered":true}' : '';

		return '<!-- wp:list' . $attrs . " -->\n"
			. '<' . $tag . ' class="wp-block-list">'
			. implode( "\n\n", $items )
			. '</' . $tag . ">\n<!-- /wp:list -->";
	}

	/**
	 * An image.
	 *
	 * @param DOMElement $node Image element.
	 * @return string
	 */
	private function image( DOMElement $node ): string {
		$src = trim( $node->getAttribute( 'src' ) );

		// A lazy-loaded image keeps its real file in a data attribute.
		if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $attribute ) {
				$fallback = trim( $node->getAttribute( $attribute ) );

				if ( '' !== $fallback && ! str_starts_with( $fallback, 'data:' ) ) {
					$src = $fallback;
					break;
				}
			}
		}

		if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
			return '';
		}

		$alt = $node->getAttribute( 'alt' );

		$this->editable[] = __( 'Image and its alt text', 'wow-signal' );

		if ( '' === trim( $alt ) ) {
			$this->concerns[] = __( 'An image had no alt text in the design. Describe it in the editor, or mark it decorative.', 'wow-signal' );
		}

		return "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n"
			. '<figure class="wp-block-image size-large">'
			. '<img src="' . $this->esc_ref( $src ) . '" alt="' . esc_attr( $alt ) . '"/>'
			. "</figure>\n<!-- /wp:image -->";
	}

	/**
	 * A picture element: take the fallback img.
	 *
	 * @param DOMElement $node Picture element.
	 * @return string
	 */
	private function picture( DOMElement $node ): string {
		$img = $node->getElementsByTagName( 'img' )->item( 0 );

		return $img instanceof DOMElement ? $this->image( $img ) : '';
	}

	/**
	 * A figure: its image, plus a caption when there is one.
	 *
	 * @param DOMElement $node Figure element.
	 * @return string
	 */
	private function figure( DOMElement $node ): string {
		$img = $node->getElementsByTagName( 'img' )->item( 0 );

		if ( ! $img instanceof DOMElement ) {
			return $this->children( $node );
		}

		return $this->image( $img );
	}

	/**
	 * A quotation.
	 *
	 * @param DOMElement $node Blockquote.
	 * @return string
	 */
	private function quote( DOMElement $node ): string {
		$inner = $this->children( $node );

		if ( '' === trim( $inner ) ) {
			return '';
		}

		$this->editable[] = __( 'Quotation', 'wow-signal' );

		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $inner . "</blockquote>\n<!-- /wp:quote -->";
	}

	/**
	 * A table.
	 *
	 * @param DOMElement $node Table element.
	 * @return string
	 */
	private function table( DOMElement $node ): string {
		$rows = array(
			'head' => array(),
			'body' => array(),
		);

		foreach ( $node->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();
			$head  = false;

			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement ) {
					continue;
				}

				$tag = strtolower( $cell->tagName );

				if ( 'th' !== $tag && 'td' !== $tag ) {
					continue;
				}

				$head    = $head || 'th' === $tag;
				$cells[] = '<' . $tag . '>' . $this->inline( $cell ) . '</' . $tag . '>';
			}

			if ( array() === $cells ) {
				continue;
			}

			$rows[ $head ? 'head' : 'body' ][] = '<tr>' . implode( '', $cells ) . '</tr>';
		}

		if ( array() === $rows['head'] && array() === $rows['body'] ) {
			return '';
		}

		$this->editable[] = __( 'Table contents', 'wow-signal' );

		$html = '<figure class="wp-block-table"><table class="has-fixed-layout">';

		if ( array() !== $rows['head'] ) {
			$html .= '<thead>' . implode( '', $rows['head'] ) . '</thead>';
		}

		$html .= '<tbody>' . implode( '', $rows['body'] ) . '</tbody></table></figure>';

		return "<!-- wp:table {\"hasFixedLayout\":true} -->\n" . $html . "\n<!-- /wp:table -->";
	}

	/**
	 * Serialise a node's contents as safe inline HTML.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function inline( DOMNode $node ): string {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$out .= esc_html( (string) $child->nodeValue );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			if ( in_array( $tag, self::DROP, true ) ) {
				continue;
			}

			if ( 'br' === $tag ) {
				$out .= '<br>';
				continue;
			}

			if ( ! in_array( $tag, self::INLINE_KEEP, true ) ) {
				$out .= $this->inline( $child );
				continue;
			}

			if ( 'a' === $tag ) {
				$out .= '<a href="' . $this->esc_ref( $this->href( $child ) ) . '">' . $this->inline( $child ) . '</a>';
				continue;
			}

			// Keep the emphasis, drop every attribute that could carry styling.
			$keep = in_array( $tag, array( 'strong', 'b', 'em', 'i', 'code', 'sub', 'sup', 'mark' ), true ) ? $tag : null;

			$out .= null === $keep ? $this->inline( $child ) : '<' . $keep . '>' . $this->inline( $child ) . '</' . $keep . '>';
		}

		return trim( $out );
	}

	/**
	 * A link target that will not break when the page moves.
	 *
	 * @param DOMElement $link Anchor.
	 * @return string
	 */
	private function href( DOMElement $link ): string {
		$href = trim( $link->getAttribute( 'href' ) );

		if ( '' === $href ) {
			return '#';
		}

		if ( 1 === preg_match( '#^(javascript|vbscript|data):#i', $href ) ) {
			$this->concerns[] = __( 'A link ran a script instead of going somewhere; it now points nowhere.', 'wow-signal' );

			return '#';
		}

		return $href;
	}

	/**
	 * Escape a link or image target without inventing a protocol for it.
	 *
	 * WordPress turns "about.html" into "http://about.html" when it escapes a
	 * URL, which is not the same link — and it hides the relative path the
	 * assembler needs in order to repoint it at the real page later. Absolute
	 * URLs still go through esc_url(); relative ones are attribute-escaped,
	 * which is safe because href() has already refused every executable scheme
	 * and the block validator refuses them again independently.
	 *
	 * @param string $href Validated target.
	 * @return string
	 */
	private function esc_ref( string $href ): string {
		if ( 1 === preg_match( '#^(https?:)?//#i', $href ) || 1 === preg_match( '#^(mailto|tel):#i', $href ) ) {
			return esc_url( $href );
		}

		return esc_attr( $href );
	}

	/**
	 * Whether an element holds only inline content.
	 *
	 * @param DOMElement $node Element.
	 * @return bool
	 */
	private function is_inline_only( DOMElement $node ): bool {
		foreach ( $node->getElementsByTagName( '*' ) as $descendant ) {
			if ( ! in_array( strtolower( $descendant->tagName ), self::INLINE_KEEP, true ) ) {
				return false;
			}
		}

		return '' !== trim( (string) $node->textContent );
	}

	/**
	 * Record that something could not be carried over.
	 *
	 * @param string $tag Element name.
	 * @return void
	 */
	private function note_dropped( string $tag ): void {
		if ( 'form' === $tag || 'input' === $tag || 'select' === $tag || 'textarea' === $tag || 'button' === $tag ) {
			$this->concerns[] = __( 'This section had a form. Add the theme’s Contact form block where it belongs — a form copied as markup would not send anything.', 'wow-signal' );

			return;
		}

		if ( 'svg' === $tag || 'canvas' === $tag ) {
			$this->concerns[] = __( 'Decorative vector artwork was left out. The theme’s gradients stand in for it; add a real image if you need the original.', 'wow-signal' );

			return;
		}

		if ( 'iframe' === $tag || 'object' === $tag || 'embed' === $tag ) {
			$this->concerns[] = __( 'An embedded frame was left out. Use the matching embed block if you need it back.', 'wow-signal' );
		}
	}

	/**
	 * A one-line description of what the section turned out to be.
	 *
	 * @param array<string, mixed> $section Section record.
	 * @return string
	 */
	private function summary( array $section ): string {
		return sprintf(
			/* translators: 1: section label, 2: word count, 3: image count. */
			__( '"%1$s" — %2$d words and %3$d image(s), converted structurally into editable blocks.', 'wow-signal' ),
			(string) $section['label'],
			(int) $section['words'],
			(int) $section['images']
		);
	}

	/**
	 * Parse a fragment into a document.
	 *
	 * @param string $html Fragment.
	 * @return DOMDocument
	 */
	private static function load( string $html ): DOMDocument {
		$dom = new DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();

		return $dom;
	}
}
