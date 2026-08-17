<?php
/**
 * Splits a design page into the sections a block theme is built from.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one HTML page into an ordered list of candidate sections.
 *
 * This is the step that decides what a "section" even is, and it runs before
 * any model is involved on purpose: splitting is a structural problem with a
 * right answer, so it should be done deterministically rather than paid for in
 * tokens and hoped for. The model's job starts once each section is isolated,
 * with only that section's markup and only the CSS that actually applies to
 * it.
 */
final class SectionSplitter {

	/**
	 * Elements that are never part of a section's content.
	 */
	private const STRIPPED_TAGS = array( 'script', 'noscript', 'template', 'iframe', 'object', 'embed' );

	/**
	 * Wrapper elements design tools add that carry no meaning.
	 */
	private const UNWRAPPED_TAGS = array( 'x-dc', 'helmet', 'dc-root' );

	/**
	 * Read a page and return its chrome plus its sections.
	 *
	 * @param string $file Absolute path of the HTML file.
	 * @return array{title:string, lang:string, header:?array<string,mixed>, footer:?array<string,mixed>, sections:array<int,array<string,mixed>>, styles:array<int,string>}
	 */
	public static function split( string $file ): array {
		$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

		$dom = self::load( $html );

		if ( null === $dom ) {
			return array(
				'title'    => basename( $file ),
				'lang'     => '',
				'header'   => null,
				'footer'   => null,
				'sections' => array(),
				'styles'   => array(),
			);
		}

		$xpath = new DOMXPath( $dom );

		$title = '';
		$node  = $xpath->query( '//title' )->item( 0 );

		if ( $node instanceof DOMNode ) {
			$title = trim( (string) $node->textContent );
		}

		$lang = '';
		$root = $xpath->query( '//html' )->item( 0 );

		if ( $root instanceof DOMElement ) {
			$lang = (string) $root->getAttribute( 'lang' );
		}

		// Inline <style> blocks are part of the design's styling surface.
		$styles = array();

		foreach ( $xpath->query( '//style' ) as $style ) {
			$css = trim( (string) $style->textContent );

			if ( '' !== $css ) {
				$styles[] = $css;
			}
		}

		self::strip( $xpath );
		self::unwrap( $xpath );

		$header = self::first_of( $xpath, '//header[not(ancestor::section)][not(ancestor::article)]' );
		$footer = self::first_of( $xpath, '//footer[not(ancestor::section)][not(ancestor::article)]' );

		return array(
			'title'    => '' !== $title ? $title : basename( $file ),
			'lang'     => $lang,
			'header'   => $header,
			'footer'   => $footer,
			'sections' => self::sections( $dom, $xpath ),
			'styles'   => $styles,
		);
	}

	/**
	 * Parse HTML into a DOM without letting libxml warnings escape.
	 *
	 * @param string $html Raw markup.
	 * @return DOMDocument|null
	 */
	private static function load( string $html ): ?DOMDocument {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument();

		/*
		 * libxml assumes ISO-8859-1 for HTML without a declared encoding,
		 * which mangles the Cyrillic and Chinese pages in a multilingual
		 * design. The explicit XML encoding hint forces UTF-8 without
		 * touching the markup itself.
		 */
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	/**
	 * Remove elements that must never reach the conversion step.
	 *
	 * Scripts especially: the model should never be asked to reason about
	 * them, and nothing downstream should be able to carry them into a page.
	 *
	 * @param DOMXPath $xpath Document query object.
	 * @return void
	 */
	private static function strip( DOMXPath $xpath ): void {
		foreach ( self::STRIPPED_TAGS as $tag ) {
			$nodes = iterator_to_array( $xpath->query( '//' . $tag ) );

			foreach ( $nodes as $node ) {
				if ( $node->parentNode instanceof DOMNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Replace design-tool wrapper elements with their children.
	 *
	 * Exports from design tools nest the real page inside custom elements such
	 * as <x-dc>. Left in place they hide every section from the structural
	 * queries below.
	 *
	 * @param DOMXPath $xpath Document query object.
	 * @return void
	 */
	private static function unwrap( DOMXPath $xpath ): void {
		foreach ( self::UNWRAPPED_TAGS as $tag ) {
			$nodes = iterator_to_array( $xpath->query( '//' . $tag ) );

			foreach ( $nodes as $node ) {
				if ( ! $node->parentNode instanceof DOMNode ) {
					continue;
				}

				while ( $node->firstChild instanceof DOMNode ) {
					$node->parentNode->insertBefore( $node->firstChild, $node );
				}

				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Describe the first node matching a query, if any.
	 *
	 * @param DOMXPath $xpath Document query object.
	 * @param string   $query XPath expression.
	 * @return array<string, mixed>|null
	 */
	private static function first_of( DOMXPath $xpath, string $query ): ?array {
		$node = $xpath->query( $query )->item( 0 );

		return $node instanceof DOMElement ? self::describe( $node, 0 ) : null;
	}

	/**
	 * Collect the page's sections in document order.
	 *
	 * @param DOMDocument $dom   Parsed document.
	 * @param DOMXPath    $xpath Document query object.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sections( DOMDocument $dom, DOMXPath $xpath ): array {
		/*
		 * Prefer explicit <section> elements — a designer who wrote them has
		 * already answered the question. Only when a page has none does the
		 * fallback guess from the top-level children of <main> or <body>.
		 */
		$nodes = iterator_to_array( $xpath->query( '//section[not(ancestor::section)]' ) );

		if ( array() === $nodes ) {
			$nodes = self::guess_sections( $dom, $xpath );
		}

		$sections = array();
		$position = 0;

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$described = self::describe( $node, $position );

			// A section with no text and no image is decoration, not content.
			if ( '' === trim( $described['text'] ) && 0 === $described['images'] ) {
				continue;
			}

			$sections[] = $described;
			++$position;
		}

		return $sections;
	}

	/**
	 * Fallback splitter for pages with no <section> elements.
	 *
	 * @param DOMDocument $dom   Parsed document.
	 * @param DOMXPath    $xpath Document query object.
	 * @return array<int, DOMNode>
	 */
	private static function guess_sections( DOMDocument $dom, DOMXPath $xpath ): array {
		unset( $dom );

		$container = $xpath->query( '//main' )->item( 0 );

		if ( ! $container instanceof DOMElement ) {
			$container = $xpath->query( '//body' )->item( 0 );
		}

		if ( ! $container instanceof DOMElement ) {
			return array();
		}

		$candidates = array();

		foreach ( $container->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( in_array( strtolower( $child->tagName ), array( 'header', 'footer', 'nav' ), true ) ) {
				continue;
			}

			$candidates[] = $child;
		}

		/*
		 * A single wrapper div holding everything is not a split — descend
		 * once more so the page does not come back as one giant section.
		 */
		if ( 1 === count( $candidates ) ) {
			$inner = array();

			foreach ( $candidates[0]->childNodes as $child ) {
				if ( $child instanceof DOMElement && ! in_array( strtolower( $child->tagName ), array( 'header', 'footer', 'nav' ), true ) ) {
					$inner[] = $child;
				}
			}

			if ( count( $inner ) > 1 ) {
				return $inner;
			}
		}

		return $candidates;
	}

	/**
	 * Build the record the conversion step works from.
	 *
	 * @param DOMElement $node     Section element.
	 * @param int        $position Zero-based order on the page.
	 * @return array<string, mixed>
	 */
	private static function describe( DOMElement $node, int $position ): array {
		$html = self::outer_html( $node );
		$text = self::readable_text( $node );

		$heading = '';

		foreach ( array( 'h1', 'h2', 'h3' ) as $level ) {
			$found = $node->getElementsByTagName( $level );

			if ( $found->length > 0 ) {
				$heading = self::readable_text( $found->item( 0 ) );
				break;
			}
		}

		$class_list = preg_split( '/\s+/', (string) $node->getAttribute( 'class' ) );

		$classes = array_values(
			array_filter( is_array( $class_list ) ? $class_list : array() )
		);

		return array(
			'position' => $position,
			'tag'      => strtolower( $node->tagName ),
			'id'       => (string) $node->getAttribute( 'id' ),
			'classes'  => $classes,
			'heading'  => $heading,
			'label'    => self::label( $heading, $node->getAttribute( 'id' ), $classes, $position ),
			'text'     => $text,
			'words'    => self::count_words( $text ),
			'images'   => $node->getElementsByTagName( 'img' )->length,
			'links'    => $node->getElementsByTagName( 'a' )->length,
			'forms'    => $node->getElementsByTagName( 'form' )->length,
			'lists'    => $node->getElementsByTagName( 'ul' )->length + $node->getElementsByTagName( 'ol' )->length,
			'html'     => $html,
			'bytes'    => strlen( $html ),
		);
	}

	/**
	 * Elements that do not force a word break when the text is flattened.
	 *
	 * Everything not listed here is treated as block level, so "…markets</p>
	 * <h2>Robert Khoubian</h2>" reads as two words rather than "marketsRobert".
	 *
	 * @var array<int, string>
	 */
	private const INLINE_TAGS = array(
		'a',
		'abbr',
		'b',
		'bdi',
		'bdo',
		'cite',
		'code',
		'data',
		'dfn',
		'em',
		'i',
		'kbd',
		'mark',
		'q',
		'rp',
		'rt',
		'ruby',
		's',
		'samp',
		'small',
		'span',
		'strong',
		'sub',
		'sup',
		'time',
		'u',
		'var',
		'wbr',
	);

	/**
	 * Flatten an element to text the way a reader would hear it.
	 *
	 * DOMNode::textContent simply concatenates, so a heading that follows a
	 * paragraph comes back welded to it. That is ugly on the review screen and
	 * worse in the prompt, where it invents words the design never contained.
	 * Block-level boundaries become spaces here instead.
	 *
	 * @param DOMNode|null $node Node to flatten.
	 * @return string
	 */
	private static function readable_text( ?DOMNode $node ): string {
		if ( ! $node instanceof DOMNode ) {
			return '';
		}

		$text = self::flatten( $node );

		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
	}

	/**
	 * Recursive half of readable_text().
	 *
	 * @param DOMNode $node Node to walk.
	 * @return string
	 */
	private static function flatten( DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return (string) $node->nodeValue;
		}

		if ( ! $node->hasChildNodes() ) {
			return $node instanceof DOMElement && 'br' === strtolower( $node->tagName ) ? ' ' : '';
		}

		$inline = $node instanceof DOMElement
			&& in_array( strtolower( $node->tagName ), self::INLINE_TAGS, true );

		$parts = array();

		foreach ( $node->childNodes as $child ) {
			$parts[] = self::flatten( $child );
		}

		$text = implode( '', $parts );

		return $inline ? $text : ' ' . $text . ' ';
	}

	/**
	 * Count words in a way that survives non-Latin scripts.
	 *
	 * The str_word_count() built-in reports zero for Chinese, Japanese and Korean, which
	 * makes a perfectly full section look empty on the review screen — and the
	 * emptiness check would then discard it. CJK is counted per character,
	 * which is the closest honest analogue of a word there.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	private static function count_words( string $text ): int {
		$cjk = preg_match_all( '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text );
		$cjk = false === $cjk ? 0 : $cjk;

		$latin = preg_split( '/[^\p{L}\p{N}\'"-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$latin = is_array( $latin ) ? count( $latin ) : 0;

		return $cjk > 0 ? $cjk : $latin;
	}

	/**
	 * A short human name for the section, for the review screen.
	 *
	 * @param string             $heading  First heading text.
	 * @param string             $id       Element id.
	 * @param array<int, string> $classes  Element classes.
	 * @param int                $position Order on the page.
	 * @return string
	 */
	private static function label( string $heading, string $id, array $classes, int $position ): string {
		if ( '' !== $heading ) {
			return mb_substr( $heading, 0, 60 );
		}

		if ( '' !== $id ) {
			return ucfirst( str_replace( array( '-', '_' ), ' ', $id ) );
		}

		foreach ( $classes as $class ) {
			// Utility classes describe styling; the first semantic one names it.
			if ( ! preg_match( '#^(is|has|u|col|row|flex|grid|p[trblxy]?-|m[trblxy]?-)#', $class ) ) {
				return ucfirst( str_replace( array( '-', '_' ), ' ', $class ) );
			}
		}

		return sprintf(
			/* translators: %d: section number on the page. */
			__( 'Section %d', 'wow-signal' ),
			$position + 1
		);
	}

	/**
	 * Serialise one element back to HTML.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private static function outer_html( DOMElement $node ): string {
		$doc = $node->ownerDocument;

		if ( ! $doc instanceof DOMDocument ) {
			return '';
		}

		return (string) $doc->saveHTML( $node );
	}
}
