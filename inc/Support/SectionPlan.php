<?php
/**
 * Works out what is editable in one section of a design.
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
 * The reading of a section that decides what its block will hold.
 *
 * The importer no longer translates a design into the theme's own blocks — it
 * wraps each section in a block of its own, keeping the markup and the
 * stylesheet the designer wrote. What that block needs is not a conversion but
 * a plan: which parts of this markup are content somebody will edit, which are
 * the frame around it, and which repeat.
 *
 * That is a structural question, and structure is worth answering with code
 * rather than with a model. A heading is editable because it is a heading; six
 * siblings with the same tag and the same classes are a list because they are
 * six siblings with the same tag and the same classes. The model's remaining
 * job is judgement — what to call a field, and whether a list is the section's
 * own furniture or the site's records — which is a much smaller question to be
 * wrong about.
 *
 * Three shapes come out of here, and the difference decides what gets built:
 *
 * - `single`  one-off content: a hero, a call to action. Fields and inner
 *             blocks, one instance.
 * - `repeat`  a short fixed list the design owns: four fact tiles, five chips.
 *             A repeater on the block.
 * - `listing` a list of the site's own records rendered through the design's
 *             card: six reports, a grid of cases. A post type, and a block that
 *             chooses which posts rather than holding their text.
 */
final class SectionPlan {

	/**
	 * Elements whose text a person edits.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'li', 'figcaption' );

	/**
	 * Inline elements that carry content of their own in these designs.
	 *
	 * A fact tile is `<div><span>Since</span><strong>2004</strong></div>` far
	 * more often than it is two paragraphs, and a plan that ignored inline tags
	 * offered no fields at all for the commonest thing in a handoff — the
	 * label-and-value pair. They only count outside a block of text: the
	 * `<strong>` in "Led by **Robert Khoubian**" is emphasis within a sentence,
	 * not a field, and is left to the paragraph that owns it.
	 *
	 * @var array<int, string>
	 */
	private const INLINE_TAGS = array( 'span', 'strong', 'b', 'em', 'small', 'time' );

	/**
	 * Markup a text field is allowed to keep inside it.
	 *
	 * A heading is rarely one run of text. `<h1><span class="gradient-text">Your
	 * Security Program,</span><br>Built and Managed.</h1>` is one heading with
	 * a highlighted half and a deliberate line break, and the stylesheet has a
	 * rule aimed at that span. Wiping the children to write a text field lost
	 * the break, the highlight and the rule's target in one move — the heading
	 * read "Program,Built" on the live site. A field that holds these tags
	 * holds the heading as the designer wrote it; anything not on this list is
	 * still stripped, which is what keeps the field a line of copy and not a
	 * place to put a script.
	 *
	 * @var array<int, string>
	 */
	public const RICH_TAGS = array( 'br', 'span', 'strong', 'b', 'em', 'i', 'small', 'sup', 'sub', 'mark', 'u', 's', 'code', 'a', 'time', 'abbr' );

	/**
	 * Elements whose text is never copy, whatever they hold.
	 *
	 * Everything else with words of its own is offered as a field.
	 *
	 * @var array<int, string>
	 */
	private const NOT_TEXT_TAGS = array(
		'script',
		'style',
		'noscript',
		'template',
		'svg',
		'path',
		'g',
		'defs',
		'symbol',
		'use',
		'text',
		'tspan',
		'title',
		'desc',
		'math',
		'iframe',
		'object',
		'embed',
		'video',
		'audio',
		'source',
		'track',
		'canvas',
		'select',
		'option',
		'optgroup',
		'textarea',
		'input',
		'datalist',
		'form',
		'pre',
		'html',
		'head',
		'body',
	);

	/**
	 * How many identical siblings make a list rather than a coincidence.
	 *
	 * Two is a coincidence often enough — a two-column band, a pair of buttons —
	 * and calling it a list would put a repeater around furniture. Three is
	 * where a design starts meaning "these are the same kind of thing".
	 *
	 * @var int
	 */
	private const LIST_THRESHOLD = 3;

	/**
	 * Longest a field name may get before it is cut.
	 *
	 * @var int
	 */
	private const NAME_LIMIT = 32;

	/**
	 * Read one section and say what its block should hold.
	 *
	 * @param string $html Section markup, as the splitter cut it.
	 * @return array{kind:string,fields:array<int,array<string,string>>,item:array<string,mixed>|null}
	 */
	public static function of( string $html ): array {
		$dom = self::parse( $html );

		if ( null === $dom ) {
			return array(
				'kind'   => 'single',
				'fields' => array(),
				'item'   => null,
			);
		}

		$xpath = new DOMXPath( $dom );
		$body  = $xpath->query( '//body' )->item( 0 );

		if ( ! $body instanceof DOMNode ) {
			return array(
				'kind'   => 'single',
				'fields' => array(),
				'item'   => null,
			);
		}

		$repeat = self::repeating_group( $xpath );

		/*
		 * The fields of the section itself are everything editable that is not
		 * inside the repeat — its heading, its intro, its button. Taking the
		 * repeat out first is what stops the first card's title being offered
		 * as a section field as well as a row field.
		 */
		$skip   = null === $repeat ? array() : $repeat['nodes'];
		$fields = self::fields_under( $xpath, $body, $skip );

		if ( null === $repeat ) {
			return array(
				'kind'   => 'single',
				'fields' => $fields,
				'item'   => null,
			);
		}

		$rows = self::fields_under( $xpath, $repeat['nodes'][0], array() );

		/*
		 * A row that is itself a link — a strip of five category anchors, a
		 * footer's ten nav links, a bare `<li>` of plain text — has nothing
		 * *under* it, because the row is the field rather than holding one.
		 * `fields_under()` only looks at descendants, so it came back empty and
		 * this used to be read as N separate fields rather than a repeat of
		 * one — ten fixed slots called "Link", "Link 2" … "Link 10" that
		 * nobody could add an eleventh to or remove a stale one from.
		 *
		 * `field_for()` is what decides whether a single element is a field at
		 * all, so asking it about the row itself tells the two cases apart
		 * without a second rule: an anchor or a heading with real text answers
		 * yes and becomes the row's one field, an empty `<span>` — the bars of
		 * a hamburger button, three of them, identical — answers no exactly as
		 * it already did, and nothing below changes for that case.
		 */
		$leaf = self::field_for( $repeat['nodes'][0] );

		if ( null !== $leaf ) {
			$leaf['path'] = '';

			/*
			 * The row's own words, too. `<div class="logo-name">BUYME<small>
			 * Digital commerce</small></div>` is a row whose name is its own
			 * text and whose caption is inside it; reading only what was
			 * *under* the row made the caption a field and froze every
			 * logo's name to the first row's. A rich row claims its whole
			 * subtree as one field; words beside decoration, or a link that
			 * is a card, keep what is inside as fields of their own and add
			 * the row's own field in front.
			 */
			if ( ! empty( $leaf['rich'] ) || array() === $rows ) {
				$rows = array( $leaf );
			} else {
				$leaf['name'] = self::unique( $leaf['name'], array_flip( array_column( $rows, 'name' ) ) );

				array_unshift( $rows, $leaf );
			}
		}

		/*
		 * A repetition of nothing is not a list.
		 *
		 * Three identical empty `<span>`s are the bars of a hamburger button,
		 * and four empty `<i>`s are a decoration; there is nothing in any of
		 * them for an editor to change, row or field. Offering them as a
		 * repeater put an "Items" field on the block that governed how many
		 * bars the button had — and, because a fresh block has no rows, drew a
		 * button with no bars at all.
		 */
		if ( array() === $rows ) {
			return array(
				'kind'   => 'single',
				'fields' => self::fields_under( $xpath, $body, array() ),
				'item'   => null,
			);
		}

		/*
		 * What a row says in its attributes. A card numbered by
		 * `data-no="02"` and drawn by `content:attr(data-no)` has no text
		 * to become a field, so every card came out wearing the first
		 * card's number. An attribute whose value differs from row to row
		 * is content, and is offered as a field of the row like any other.
		 */
		foreach ( self::varying_attributes( $repeat['nodes'] ) as $varying ) {
			$name = self::unique( self::name_for_attribute( $varying['attr'], $varying['owner'] ), array_flip( array_column( $rows, 'name' ) ) );

			$rows[] = array(
				'name'  => $name,
				'type'  => 'text',
				'label' => ucfirst( str_replace( '_', ' ', $name ) ),
				'path'  => $varying['path'],
				'attr'  => $varying['attr'],
			);
		}

		$item = array(
			'selector' => $repeat['signature'],
			'count'    => count( $repeat['nodes'] ),
			'fields'   => $rows,

			/*
			 * Two addresses, both needed. `path` is one row, which the writer
			 * lifts out as the template for every row. `parent` is what holds
			 * the rows, which is where the loop is written and where the other
			 * rows are deleted from — the design drew six cards, the block
			 * ships one and repeats it.
			 */
			'path'     => self::path_of( $repeat['nodes'][0], $body ),
			'parent'   => self::path_of( $repeat['nodes'][0]->parentNode, $body ),
		);

		$kind = 1 === count( $item['fields'] ) || $item['count'] < 4 ? 'repeat' : 'listing';

		/*
		 * Rows that only look alike are not one row repeated.
		 *
		 * Three support tiers share a card's shape, but each card carries its
		 * own bullet list — text the planned fields never reach. The wrap
		 * ships the first row as the template for all of them, so every card
		 * came out wearing the first card's bullets, three times over. When
		 * the text left after the planned fields differs between rows, the
		 * section is kept whole instead: every element planned as its own
		 * field, nothing repeated, nothing invented.
		 *
		 * Only a would-be repeat is judged. A listing's rows are records, and
		 * records differing from one another is what records are — their
		 * variance is absorbed by the type's own fields, not frozen into a
		 * template.
		 */
		if ( 'repeat' === $kind && self::rows_diverge( $repeat['nodes'], $rows ) ) {
			Lessons::note( 'divergent_single' );

			return array(
				'kind'   => 'single',
				'fields' => self::fields_under( $xpath, $body, array() ),
				'item'   => null,
			);
		}

		/*
		 * Whether this is the section's own furniture or the site's records is
		 * the one thing here a model should settle, because it is a judgement
		 * about the business and not about the markup. Until it is asked, the
		 * count is the best available guess: a design that drew six of
		 * something is usually showing a list that will grow.
		 *
		 * Except when a row is one field. A record worth its own post type has
		 * shape — a title and a body, a title and a picture — and a row that
		 * is only ever "Link" or only ever "Text" has none: a nav strip, a row
		 * of tag chips, a footer's worth of addresses, read one field at a
		 * time because that is genuinely all any of them hold. Guessing
		 * "listing" here does not cost a wrong label the way it would for a
		 * card — it costs the content: a fresh site has no posts of a type
		 * nobody made yet, so the block that queries them draws nothing,
		 * silently, and a design that shipped ten working links ships zero.
		 * "repeat" is never that wrong; worst case a person reviews rows a
		 * model would have called records anyway.
		 */
		return array(
			'kind'   => $kind,
			'fields' => $fields,
			'item'   => $item,
		);
	}

	/**
	 * Whether the rows say different things where no field is planned.
	 *
	 * Each row's text, minus what its planned fields already cover, is what
	 * the repeat template would freeze from the first row and stamp onto all
	 * of them. Equal residuals — static labels, an arrow, "Learn more" — are
	 * exactly what a template should carry. Different ones are content that
	 * repetition would corrupt.
	 *
	 * @param array<int, DOMElement>           $nodes  The repeated rows.
	 * @param array<int, array<string, mixed>> $fields The fields planned for one row.
	 * @return bool
	 */
	private static function rows_diverge( array $nodes, array $fields ): bool {
		$residuals = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$residual = trim( (string) preg_replace( '#\s+#u', ' ', $node->textContent ) );

			foreach ( $fields as $field ) {
				$found = self::at( $node, (string) ( $field['path'] ?? '' ) );

				if ( ! $found instanceof DOMElement ) {
					continue;
				}

				$text = trim( (string) preg_replace( '#\s+#u', ' ', $found->textContent ) );

				if ( '' === $text ) {
					continue;
				}

				$position = mb_strpos( $residual, $text );

				if ( false !== $position ) {
					$residual = mb_substr( $residual, 0, $position ) . mb_substr( $residual, $position + mb_strlen( $text ) );
				}
			}

			$residuals[] = trim( (string) preg_replace( '#\s+#u', ' ', $residual ) );
		}

		$first = $residuals[0] ?? '';

		foreach ( $residuals as $residual ) {
			/*
			 * Only a difference with something to say. A step number, a badge,
			 * an ordinal — "1", "2", "3" — differs between rows and means
			 * nothing lost; the bullet lists of three support tiers are whole
			 * sentences. The bar is the length of what actually differs.
			 */
			if ( $residual !== $first && max( mb_strlen( $residual ), mb_strlen( $first ) ) > 30 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The largest run of identical siblings in the section, if there is one.
	 *
	 * @param DOMXPath $xpath Document index.
	 * @return array{signature:string,nodes:array<int,DOMElement>}|null
	 */
	private static function repeating_group( DOMXPath $xpath ): ?array {
		$groups = array();

		foreach ( $xpath->query( '//body//*' ) as $node ) {
			if ( ! $node instanceof DOMElement || ! $node->parentNode instanceof DOMElement ) {
				continue;
			}

			$signature = self::signature( $node );

			if ( '' === $signature ) {
				continue;
			}

			/*
			 * Keyed by parent as well as by shape: two grids of cards in one
			 * section are two lists, and merging them would produce a repeater
			 * that spans a heading.
			 */
			$key = spl_object_id( $node->parentNode ) . '|' . $signature;

			$groups[ $key ][] = $node;
		}

		$groups = array_filter( $groups, static fn( array $nodes ): bool => count( $nodes ) >= self::LIST_THRESHOLD );

		/*
		 * The outermost list is the list. Three service cards each holding a
		 * four-point `<ul>` used to lose to the first card's four `<li>`s —
		 * the bigger run won — so the first card got a repeater for its
		 * bullets and the other two got flat fields, and the section was two
		 * different things in one block. A list inside a row of a bigger list
		 * is that row's content, not the section's repeat; it is dropped
		 * before the largest is chosen.
		 */
		foreach ( $groups as $key => $nodes ) {
			foreach ( $groups as $other_key => $others ) {
				if ( $other_key !== $key && self::inside_any( $nodes[0], $others ) ) {
					unset( $groups[ $key ] );

					continue 2;
				}
			}
		}

		$best = null;

		foreach ( $groups as $key => $nodes ) {
			if ( null !== $best && count( $nodes ) <= count( $best['nodes'] ) ) {
				continue;
			}

			$best = array(
				'signature' => (string) substr( (string) $key, (int) strpos( (string) $key, '|' ) + 1 ),
				'nodes'     => $nodes,
			);
		}

		return $best;
	}

	/**
	 * What makes two siblings the same kind of thing.
	 *
	 * Tag and classes, and nothing else. Text differs between cards by
	 * definition, and so do image sources and links — matching on those would
	 * mean no two cards were ever the same.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private static function signature( DOMElement $node ): string {
		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		$classes = array_values( array_filter( is_array( $classes ) ? $classes : array() ) );

		sort( $classes );

		return strtolower( $node->tagName ) . ( array() === $classes ? '' : '.' . implode( '.', $classes ) );
	}

	/**
	 * Everything editable under one node, skipping whole subtrees.
	 *
	 * @param DOMXPath          $xpath The document.
	 * @param DOMNode           $root  Where to look.
	 * @param array<int, mixed> $skip  Subtrees to leave alone.
	 * @return array<int, array<string, string>>
	 */
	private static function fields_under( DOMXPath $xpath, DOMNode $root, array $skip ): array {
		/*
		 * A field claims its whole subtree. Once a paragraph is a field, the
		 * `<strong>` inside it is that paragraph's emphasis rather than a
		 * second field, and the editor gets one box holding the sentence
		 * instead of three holding its pieces.
		 */
		$claimed = $skip;
		$taken   = array();
		$fields  = array();

		foreach ( $xpath->query( './/*', $root ) as $node ) {
			if ( ! $node instanceof DOMElement || self::inside_any( $node, $claimed ) ) {
				continue;
			}

			$field = self::field_for( $node );

			if ( null === $field ) {
				continue;
			}

			// A link that is a card keeps its address as a field and leaves its contents to be fields too.
			if ( empty( $field['open'] ) ) {
				$claimed[] = $node;
			}

			$name = self::unique( $field['name'], $taken );

			$taken[ $name ] = true;
			$field['name']  = $name;
			$field['path']  = self::path_of( $node, $root );
			$fields[]       = $field;
		}

		return $fields;
	}

	/**
	 * Where a node sits, written so the block writer can find it again.
	 *
	 * A name is not an address. Two cards in a row both hold an `h3` called
	 * "heading", and a plan that says only "heading" cannot tell the writer
	 * which `h3` to put the field into — so nothing could be generated from a
	 * plan at all. This is the address: the index of each element among its
	 * element siblings, from the root down, joined by slashes.
	 *
	 * Element indices rather than a CSS selector on purpose. The design's own
	 * classes are kept, but they are not unique — `.stat` appears six times —
	 * and a selector that matches six nodes is not an address either.
	 *
	 * @param DOMNode $node Element to locate.
	 * @param DOMNode $root Where the path starts, exclusive.
	 * @return string Slash-joined indices, empty when the node is the root.
	 */
	private static function path_of( DOMNode $node, DOMNode $root ): string {
		$steps = array();

		for ( $at = $node; $at instanceof DOMElement && $at !== $root; $at = $at->parentNode ) {
			$index  = 0;
			$parent = $at->parentNode;

			if ( ! $parent instanceof DOMNode ) {
				break;
			}

			foreach ( $parent->childNodes as $sibling ) {
				if ( $sibling === $at ) {
					break;
				}

				if ( $sibling instanceof DOMElement ) {
					++$index;
				}
			}

			array_unshift( $steps, (string) $index );

			if ( $parent === $root ) {
				break;
			}
		}

		return implode( '/', $steps );
	}

	/**
	 * Walk a path made by path_of() back to the element it names.
	 *
	 * @param DOMNode $root Where the path starts.
	 * @param string  $path Slash-joined indices.
	 * @return DOMElement|null The element, or null when the path does not fit.
	 */
	public static function at( DOMNode $root, string $path ): ?DOMElement {
		$at = $root;

		if ( '' === trim( $path ) ) {
			return $at instanceof DOMElement ? $at : null;
		}

		foreach ( explode( '/', $path ) as $step ) {
			$index = 0;
			$found = null;

			foreach ( $at->childNodes as $child ) {
				if ( ! $child instanceof DOMElement ) {
					continue;
				}

				if ( $index === (int) $step ) {
					$found = $child;

					break;
				}

				++$index;
			}

			if ( null === $found ) {
				return null;
			}

			$at = $found;
		}

		return $at instanceof DOMElement ? $at : null;
	}

	/**
	 * The field one element becomes, or null when it is frame rather than content.
	 *
	 * @param DOMElement $node Element.
	 * @return array<string, string>|null
	 */
	private static function field_for( DOMElement $node ): ?array {
		$tag = strtolower( $node->tagName );

		if ( 'img' === $tag ) {
			return array(
				'name'  => self::name_for( $node, 'image' ),
				'type'  => 'image',
				'label' => self::label_for( $node, 'Image' ),
			);
		}

		if ( 'a' === $tag && '' !== trim( $node->textContent ) ) {
			$field = array(
				'name'  => self::name_for( $node, 'link' ),
				'type'  => 'link',
				'label' => self::label_for( $node, 'Link' ),
			);

			/*
			 * A link that is a card. `<a class="card"><h3>…</h3><p>…</p></a>`
			 * is a link whose words live in its own elements, and claiming
			 * its subtree as one link field left the heading and the
			 * paragraph frozen in the template. The address stays a field;
			 * the elements inside go on to become fields of their own.
			 */
			if ( '' === trim( self::own_text( $node ) ) ) {
				$field['open'] = true;
			}

			return $field;
		}

		$inline = in_array( $tag, self::INLINE_TAGS, true );

		if ( ! $inline && ! in_array( $tag, self::TEXT_TAGS, true ) && in_array( $tag, self::NOT_TEXT_TAGS, true ) ) {
			return null;
		}

		// An element that only wraps other elements is a container, not a field.
		if ( '' === trim( self::own_text( $node ) ) ) {
			return null;
		}

		/*
		 * What sits beside the words decides what kind of field they are.
		 *
		 * Inline markup — a highlighted span, a line break — travels with the
		 * words as a rich field. Decoration with no words of its own — an
		 * icon, a picture — stays in the template and the words are planted
		 * around it. A block with words of its own, `<li>Plan<ul>…</ul></li>`,
		 * makes the element a frame: a field that wiped the list to hold the
		 * one word lost more than it made editable, so the frame is left and
		 * what it holds is looked at on its own.
		 */
		$rich  = false;
		$decor = false;

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( in_array( strtolower( $child->tagName ), self::RICH_TAGS, true ) ) {
				$rich = true;
			} elseif ( '' === trim( $child->textContent ) ) {
				$decor = true;
			} else {
				return null;
			}
		}

		if ( $inline ) {
			$field = array(
				'name'  => self::name_for( $node, 'label' ),
				'type'  => 'text',
				'label' => self::label_for( $node, 'Label' ),
			);
		} elseif ( in_array( $tag, self::TEXT_TAGS, true ) ) {
			$field = array(
				'name'  => self::name_for( $node, self::text_name( $tag ) ),
				'type'  => in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? 'text' : 'textarea',
				'label' => self::label_for( $node, ucfirst( self::text_name( $tag ) ) ),
			);
		} else {
			/*
			 * Any other element with words of its own: an eyebrow in a
			 * `<div>`, an icon glyph, a table cell, a button. Left out, they
			 * were frozen into the template — and a row of cards whose only
			 * difference was the glyph in each one's icon box came out
			 * wearing the first card's glyph three times.
			 */
			$field = array(
				'name'  => self::name_for( $node, 'text' ),
				'type'  => mb_strlen( trim( self::own_text( $node ) ) ) > 80 ? 'textarea' : 'text',
				'label' => self::label_for( $node, 'Text' ),
			);
		}

		/*
		 * Marked here, once, so the writer, the values and the field group
		 * all agree on which fields hold markup and which keep decoration.
		 */
		if ( $decor ) {
			$field['words'] = true;
		} elseif ( $rich ) {
			$field['rich'] = true;
		}

		return $field;
	}

	/**
	 * The `data-*` attributes on a repeat's rows whose values differ between rows.
	 *
	 * @param array<int, DOMElement> $nodes The rows.
	 * @return array<int, string> Attribute names, in the first row's order.
	 */
	private static function varying_attributes( array $nodes ): array {
		$first = $nodes[0] ?? null;

		if ( ! $first instanceof DOMElement || count( $nodes ) < 2 ) {
			return array();
		}

		$found    = array();
		$elements = array( $first );

		foreach ( $first->getElementsByTagName( '*' ) as $inner ) {
			$elements[] = $inner;
		}

		foreach ( $elements as $element ) {
			if ( ! $element instanceof DOMElement ) {
				continue;
			}

			$path = self::path_of( $element, $first );

			foreach ( $element->attributes as $attribute ) {
				$name = strtolower( (string) $attribute->name );

				// `data-*` for what the stylesheet draws; `style` for a bar's width or a tile's own picture.
				if ( ( ! str_starts_with( $name, 'data-' ) && 'style' !== $name ) || str_starts_with( $name, 'data-qs-' ) ) {
					continue;
				}

				$value = (string) $attribute->value;

				foreach ( $nodes as $node ) {
					$twin = $node instanceof DOMElement ? self::at( $node, $path ) : null;

					if ( $twin instanceof DOMElement && $twin->getAttribute( $name ) !== $value ) {
						$found[] = array(
							'attr'  => $name,
							'path'  => $path,
							'owner' => $element,
						);

						break;
					}
				}
			}
		}

		return $found;
	}

	/**
	 * A field name for an attribute: `data-no` becomes `no`, a `style` on
	 * `.mini-bar span` becomes `mini_bar_style`.
	 *
	 * @param string     $attribute Attribute name.
	 * @param DOMElement $owner     The element carrying it.
	 * @return string
	 */
	private static function name_for_attribute( string $attribute, DOMElement $owner ): string {
		$name = (string) preg_replace( '/^data-/', '', $attribute );

		if ( 'style' === $attribute ) {
			$class  = self::name_for( $owner, '' );
			$holder = $owner->parentNode instanceof DOMElement ? self::name_for( $owner->parentNode, '' ) : '';
			$name   = trim( ( '' !== $class ? $class : $holder ) . '_style', '_' );
		}

		$name = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $name ) );
		$name = trim( $name, '_' );

		return '' === $name ? 'attribute' : substr( $name, 0, self::NAME_LIMIT );
	}

	/**
	 * Whether an element is one of the rows a repeat was planned over.
	 *
	 * The rows' holder is not always only rows. A grid whose parent also
	 * holds its heading or a "view all" link used to lose both — the writer
	 * kept the first element child as the row and deleted the rest, and the
	 * values read every child as a row. Both now ask this instead.
	 *
	 * @param DOMElement $node     Element.
	 * @param string     $selector The signature the plan recorded for the rows.
	 * @return bool
	 */
	public static function is_row( DOMElement $node, string $selector ): bool {
		return '' === $selector || self::signature( $node ) === $selector;
	}

	/**
	 * The text a node owns, ignoring the text of elements inside it.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private static function own_text( DOMElement $node ): string {
		$text = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= $child->nodeValue;
			}
		}

		return $text;
	}

	/**
	 * What to call the field for this element.
	 *
	 * The design's own class name where there is one, because the designer
	 * already named the thing — `.eyebrow` becomes `eyebrow`, and a field
	 * called `eyebrow` is one somebody can find. The tag is the fallback.
	 *
	 * @param DOMElement $node     Element.
	 * @param string     $fallback Name to use when the element has no class.
	 * @return string
	 */
	private static function name_for( DOMElement $node, string $fallback ): string {
		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		$classes = is_array( $classes ) ? array_values( array_filter( $classes ) ) : array();

		$name = array() === $classes ? $fallback : (string) $classes[0];
		$name = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $name ) );
		$name = trim( $name, '_' );

		return '' === $name ? $fallback : substr( $name, 0, self::NAME_LIMIT );
	}

	/**
	 * The label an editor reads beside the field.
	 *
	 * @param DOMElement $node     Element.
	 * @param string     $fallback Label to use when the element has no class.
	 * @return string
	 */
	private static function label_for( DOMElement $node, string $fallback ): string {
		$name = self::name_for( $node, '' );

		if ( '' === $name ) {
			return $fallback;
		}

		return ucfirst( str_replace( '_', ' ', $name ) );
	}

	/**
	 * A sensible name for a text element with no class of its own.
	 *
	 * @param string $tag Tag name.
	 * @return string
	 */
	private static function text_name( string $tag ): string {
		if ( 'h1' === $tag ) {
			return 'heading';
		}

		if ( in_array( $tag, array( 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return 'subheading';
		}

		return 'text';
	}

	/**
	 * Keep field names unique within their group.
	 *
	 * @param string             $name  Wanted name.
	 * @param array<string,bool> $taken Names already used.
	 * @return string
	 */
	private static function unique( string $name, array $taken ): string {
		if ( ! isset( $taken[ $name ] ) ) {
			return $name;
		}

		$suffix = 2;

		while ( isset( $taken[ $name . '_' . $suffix ] ) ) {
			++$suffix;
		}

		return $name . '_' . $suffix;
	}

	/**
	 * Whether a node sits inside any of the given subtrees.
	 *
	 * @param DOMElement            $node  Element.
	 * @param array<int,DOMElement> $roots Subtrees.
	 * @return bool
	 */
	private static function inside_any( DOMElement $node, array $roots ): bool {
		foreach ( $roots as $root ) {
			$walk = $node;

			while ( $walk instanceof DOMNode ) {
				if ( $walk === $root ) {
					return true;
				}

				$walk = $walk->parentNode;
			}
		}

		return false;
	}

	/**
	 * Parse a fragment without letting libxml complain about a design's markup.
	 *
	 * @param string $html Markup.
	 * @return DOMDocument|null
	 */
	private static function parse( string $html ): ?DOMDocument {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$dom = new DOMDocument();

		libxml_use_internal_errors( true );
		$ok = $dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();

		return $ok ? $dom : null;
	}
}
