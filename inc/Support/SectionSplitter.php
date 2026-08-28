<?php
/**
 * Splits a design page into the sections a block theme is built from.
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
	private const STRIPPED_TAGS = array( 'script', 'noscript', 'template', 'iframe', 'object', 'embed', 'style', 'link', 'meta' );

	/**
	 * Wrapper elements design tools add that carry no meaning.
	 */
	private const UNWRAPPED_TAGS = array( 'x-dc', 'helmet', 'dc-root' );

	/**
	 * Template-engine elements whose contents are placeholders, not content.
	 */
	private const TEMPLATE_TAGS = array( 'sc-for', 'sc-if', 'sc-else', 'sc-switch', 'template' );

	/**
	 * How many levels of component inclusion are followed.
	 */
	private const IMPORT_DEPTH = 3;

	/**
	 * Where a page's header is looked for; the first match in document order wins.
	 */
	private const HEADER_QUERY = '//header[not(ancestor::section)][not(ancestor::article)][not(ancestor::footer)]'
		. ' | //nav[not(ancestor::header)][not(ancestor::section)][not(ancestor::article)][not(ancestor::footer)]'
		. ' | //*[@role="banner"]';

	/**
	 * Where a page's footer is looked for; the first match in document order wins.
	 */
	private const FOOTER_QUERY = '//footer[not(ancestor::section)][not(ancestor::article)] | //*[@role="contentinfo"]';

	/**
	 * Read a page and return its chrome plus its sections.
	 *
	 * @param string $file Absolute path of the HTML file.
	 * @param string $root Design root the file was unpacked into; defaults to the file's directory.
	 * @return array{title:string, lang:string, header:?array<string,mixed>, footer:?array<string,mixed>, sections:array<int,array<string,mixed>>, styles:array<int,string>, expanded:array{loops:int,items:int,unresolved:array<int,string>}, placeholders:array<string,string>, notes:array<int,string>}
	 */
	public static function split( string $file, string $root = '' ): array {
		$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

		$dir  = dirname( $file );
		$root = '' === $root ? $dir : $root;
		$dom  = self::load( $html );

		if ( $dom instanceof DOMDocument ) {
			self::inline_imports( $dom, $dir, 0, array( strtolower( basename( $file ) ) ) );
		}

		if ( null === $dom ) {
			return array(
				'title'        => basename( $file ),
				'lang'         => '',
				'header'       => null,
				'footer'       => null,
				'sections'     => array(),
				'styles'       => array(),
				'expanded'     => array(
					'loops'      => 0,
					'items'      => 0,
					'unresolved' => array(),
				),
				'placeholders' => array(),
				'notes'        => array(),
			);
		}

		// The data the page's loops are filled from lives in its scripts, which are stripped next.
		$data = TemplateData::from_page( $dom, $dir, $root );

		$xpath = new DOMXPath( $dom );

		$title = '';
		$node  = $xpath->query( '//title' )->item( 0 );

		if ( $node instanceof DOMNode ) {
			$title = trim( (string) $node->textContent );
		}

		$lang      = '';
		$html_root = $xpath->query( '//html' )->item( 0 );

		if ( $html_root instanceof DOMElement ) {
			$lang = (string) $html_root->getAttribute( 'lang' );
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

		$expanded = self::expand_templates( $dom, $xpath, $data );

		self::strip_templates( $xpath );

		$notes        = array();
		$placeholders = self::placeholders( $xpath, $dir, $root, (string) preg_replace( '/\.(dc\.)?html?$/i', '', basename( $file ) ), $notes );

		self::unwrap_custom_elements( $xpath );

		$header = self::first_of( $xpath, self::HEADER_QUERY );
		$footer = self::first_of( $xpath, self::FOOTER_QUERY );

		return array(
			'title'        => '' !== $title ? $title : basename( $file ),
			'lang'         => $lang,
			'header'       => $header,
			'footer'       => $footer,
			'sections'     => self::sections( $dom, $xpath ),
			'styles'       => $styles,
			'expanded'     => $expanded,
			'placeholders' => $placeholders,
			'notes'        => $notes,
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
	 * Replace <dc-import name="X"> placeholders with the component file X.
	 *
	 * Design tools export a shared header or footer once and reference it
	 * from every page. Left as a placeholder the page has no header at all,
	 * so the menu, the logo and the footer links all go missing. Components
	 * may include components; the depth limit and the chain of names being
	 * followed stop an export that references itself from looping.
	 *
	 * @param DOMDocument        $dom   Document being assembled.
	 * @param string             $dir   Directory of the file the document came from.
	 * @param int                $depth Current inclusion depth.
	 * @param array<int, string> $chain Lower-cased file names already being included.
	 * @return void
	 */
	private static function inline_imports( DOMDocument $dom, string $dir, int $depth, array $chain ): void {
		$xpath = new DOMXPath( $dom );
		$nodes = iterator_to_array( $xpath->query( '//dc-import' ) );

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || ! $node->parentNode instanceof DOMNode ) {
				continue;
			}

			$name = trim( $node->getAttribute( 'name' ) );

			/*
			 * A component the design keeps hidden — a newsletter pop-up, a
			 * cookie modal — is declared with a zero hint size or a telling
			 * name. It is not page content and would otherwise land as a
			 * section on every page that imports it.
			 */
			$hint   = preg_replace( '/\s+/', '', strtolower( $node->getAttribute( 'hint-size' ) ) );
			$hidden = '0px,0px' === $hint || '0,0' === $hint
				|| 1 === preg_match( '/popup|modal|dialog|overlay|toast|drawer/i', $name );

			if ( $hidden ) {
				$node->parentNode->removeChild( $node );
				continue;
			}

			$component = $depth < self::IMPORT_DEPTH ? self::component_file( $dir, $name ) : '';
			$key       = strtolower( basename( $component ) );

			if ( '' !== $component && ! in_array( $key, $chain, true ) ) {
				$part = self::load( (string) file_get_contents( $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

				if ( $part instanceof DOMDocument ) {
					$chain[] = $key;
					self::inline_imports( $part, dirname( $component ), $depth + 1, $chain );
					array_pop( $chain );

					$part_xpath = new DOMXPath( $part );

					// The component's own document chrome is not content.
					foreach ( array( 'head', 'helmet', 'title', 'meta', 'link', 'base' ) as $tag ) {
						foreach ( iterator_to_array( $part_xpath->query( '//' . $tag ) ) as $chrome ) {
							if ( $chrome->parentNode instanceof DOMNode ) {
								$chrome->parentNode->removeChild( $chrome );
							}
						}
					}

					$body = $part_xpath->query( '//body' )->item( 0 );

					if ( $body instanceof DOMNode ) {
						foreach ( iterator_to_array( $body->childNodes ) as $child ) {
							$node->parentNode->insertBefore( $dom->importNode( $child, true ), $node );
						}
					}
				}
			}

			$node->parentNode->removeChild( $node );
		}
	}

	/**
	 * Find the file a component name refers to.
	 *
	 * @param string $dir  Directory of the including page.
	 * @param string $name Component name from the placeholder.
	 * @return string Absolute path, or an empty string.
	 */
	private static function component_file( string $dir, string $name ): string {
		$name = basename( str_replace( '\\', '/', $name ) );

		if ( '' === $name || '.' === $name || '..' === $name ) {
			return '';
		}

		foreach ( array( $name . '.dc.html', $name . '.html', $name . '.htm', $name ) as $candidate ) {
			$path = $dir . '/' . $candidate;

			if ( 1 === preg_match( '/\.html?$/i', $candidate ) && is_file( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Most items one loop is filled with.
	 */
	private const LOOP_LIMIT = 24;

	/**
	 * Attributes a placeholder is never written into: an event handler is code.
	 */
	private const HANDLER_PREFIX = 'on';

	/**
	 * Image types a screenshot may be.
	 */
	private const SCREENSHOT_TYPES = array( 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'avif' );

	/**
	 * Directories a design keeps its screenshots in, in order of preference.
	 */
	private const SCREENSHOT_DIRS = array( 'screenshots', 'screenshot', 'previews' );

	/**
	 * Attributes that name the component an empty element stands for.
	 */
	private const NAMING_ATTRIBUTES = array( 'component-from-global-scope', 'component', 'name', 'is' );

	/**
	 * Root names that resolved at least once during the current expansion.
	 *
	 * @var array<string, bool>
	 */
	private static array $resolved = array();

	/**
	 * Fill the page's template loops and conditions from the design's own data.
	 *
	 * A blog page is a `<sc-for>` over posts; left to strip_templates() it is
	 * nine empty cards, which is no page at all. With the posts found beside
	 * the page, the loop body is cloned once per post and each `{{ p.title }}`
	 * becomes the title. Whatever cannot be resolved stays for strip_templates()
	 * to clean, so the worst case is exactly what happened before.
	 *
	 * @param DOMDocument  $dom   Parsed page.
	 * @param DOMXPath     $xpath Document query object.
	 * @param TemplateData $data  Data found in the page's scripts.
	 * @return array{loops:int, items:int, unresolved:array<int,string>}
	 */
	private static function expand_templates( DOMDocument $dom, DOMXPath $xpath, TemplateData $data ): array {
		$stats = array(
			'loops'      => 0,
			'items'      => 0,
			'unresolved' => array(),
		);

		$body = $xpath->query( '//body' )->item( 0 );

		if ( ! $body instanceof DOMElement ) {
			return $stats;
		}

		$fields         = TemplateData::fields( (string) $dom->saveHTML( $body ) );
		self::$resolved = array();

		self::fill( $body, array(), $data, $fields, $stats );

		/*
		 * Whatever still carries braces names data the design did not ship —
		 * unless the same name was filled elsewhere, in which case only a
		 * field computed at runtime is missing and the list itself was found.
		 */
		foreach ( $xpath->query( '//text()[contains(., "{{")] | //@*[contains(., "{{")]' ) as $node ) {
			if ( 1 <= preg_match_all( '/\{\{\s*([A-Za-z_$][\w$]*)/u', (string) $node->nodeValue, $names ) ) {
				foreach ( $names[1] as $name ) {
					if ( ! isset( self::$resolved[ $name ] ) && ! in_array( $name, $stats['unresolved'], true ) ) {
						$stats['unresolved'][] = $name;
					}
				}
			}
		}

		self::$resolved = array();

		return $stats;
	}

	/**
	 * Recursive half of expand_templates(): one subtree under one scope stack.
	 *
	 * @param DOMElement                        $node   Element whose children are processed.
	 * @param array<int, array<string, mixed>>  $scopes Loop scopes, outermost first.
	 * @param TemplateData                      $data   Data found in the page's scripts.
	 * @param array<string, array<int, string>> $fields Fields the page reads per root name.
	 * @param array<string, mixed>              $stats  Running counts, by reference.
	 * @return void
	 */
	private static function fill( DOMElement $node, array $scopes, TemplateData $data, array $fields, array &$stats ): void {
		self::substitute( $node, $scopes, $data, $fields );

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof \DOMText ) {
				self::substitute_text( $child, $scopes, $data, $fields );
				continue;
			}

			if ( ! $child instanceof DOMElement || ! $child->parentNode instanceof DOMNode ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			if ( 'sc-for' === $tag ) {
				self::fill_loop( $child, $scopes, $data, $fields, $stats );
				continue;
			}

			if ( 'sc-if' === $tag ) {
				self::fill_condition( $child, $scopes, $data, $fields, $stats );
				continue;
			}

			if ( 'sc-else' === $tag ) {
				// An else still here belongs to a condition that kept its own content.
				$child->parentNode->removeChild( $child );
				continue;
			}

			self::fill( $child, $scopes, $data, $fields, $stats );
		}
	}

	/**
	 * Clone a loop body once per item of its list.
	 *
	 * @param DOMElement                        $loop   The `<sc-for>` element.
	 * @param array<int, array<string, mixed>>  $scopes Loop scopes, outermost first.
	 * @param TemplateData                      $data   Data found in the page's scripts.
	 * @param array<string, array<int, string>> $fields Fields the page reads per root name.
	 * @param array<string, mixed>              $stats  Running counts, by reference.
	 * @return void
	 */
	private static function fill_loop( DOMElement $loop, array $scopes, TemplateData $data, array $fields, array &$stats ): void {
		$parent = $loop->parentNode;

		if ( ! $parent instanceof DOMNode ) {
			return;
		}

		$expr = self::unbrace( $loop->getAttribute( 'list' ) );

		if ( '' === $expr ) {
			$expr = self::unbrace( $loop->getAttribute( 'each' ) );
		}

		$name = trim( $loop->getAttribute( 'as' ) );
		$name = '' !== $name ? $name : 'item';

		/*
		 * The list is read by what the body does with each item: the fields of
		 * `p` are what a match for `grid` must carry when `grid` itself was
		 * computed at runtime and only the items exist as a literal.
		 */
		$shape = $fields;

		if ( 1 === preg_match( '/^[A-Za-z_$][\w$]*/', $expr, $root ) && isset( $fields[ $name ] ) ) {
			$shape[ $root[0] ] = array_values( array_unique( array_merge( $fields[ $root[0] ] ?? array(), $fields[ $name ] ) ) );
		}

		list( $found, $items ) = $data->resolve( $expr, $scopes, $shape, true );

		if ( ! $found || ! is_array( $items ) || array() === $items ) {
			// Left in place: strip_templates() removes it, as it always has.
			// The list is what goes unreported-on, not every item field under it.
			self::$resolved[ $name ] = true;
			return;
		}

		self::$resolved[ $name ] = true;

		$index = trim( $loop->getAttribute( 'index' ) );
		$count = 0;

		foreach ( array_slice( $items, 0, self::LOOP_LIMIT, true ) as $key => $item ) {
			$scope = array( $name => $item );

			if ( '' !== $index ) {
				$scope[ $index ] = $key;
			}

			$scope['$index'] = $count;
			$inner           = $scopes;
			$inner[]         = $scope;

			foreach ( $loop->childNodes as $child ) {
				$clone = $child->cloneNode( true );
				$parent->insertBefore( $clone, $loop );

				if ( $clone instanceof DOMElement ) {
					self::fill( $clone, $inner, $data, $fields, $stats );
				} elseif ( $clone instanceof \DOMText ) {
					self::substitute_text( $clone, $inner, $data, $fields );
				}
			}

			++$count;
		}

		$parent->removeChild( $loop );

		++$stats['loops'];
		$stats['items'] += $count;
	}

	/**
	 * Keep or drop a condition's content, and its `<sc-else>` the other way round.
	 *
	 * An unresolvable test keeps the content: a page with one "Featured" flag
	 * too many beats one with a hole in it.
	 *
	 * @param DOMElement                        $node   The `<sc-if>` element.
	 * @param array<int, array<string, mixed>>  $scopes Loop scopes, outermost first.
	 * @param TemplateData                      $data   Data found in the page's scripts.
	 * @param array<string, array<int, string>> $fields Fields the page reads per root name.
	 * @param array<string, mixed>              $stats  Running counts, by reference.
	 * @return void
	 */
	private static function fill_condition( DOMElement $node, array $scopes, TemplateData $data, array $fields, array &$stats ): void {
		$parent = $node->parentNode;

		if ( ! $parent instanceof DOMNode ) {
			return;
		}

		$expr = '';

		foreach ( array( 'test', 'value', 'if', 'condition' ) as $attribute ) {
			if ( $node->hasAttribute( $attribute ) ) {
				$expr = self::unbrace( $node->getAttribute( $attribute ) );
				break;
			}
		}

		$negate = str_starts_with( $expr, '!' );
		$expr   = ltrim( $expr, "! \t" );

		list( $found, $value ) = $data->resolve( $expr, $scopes, $fields );

		$keep = ! $found || ( TemplateData::truthy( $value ) xor $negate );

		// The else branch is the next element sibling, when it is one.
		$other = null;

		for ( $next = $node->nextSibling; $next instanceof DOMNode; $next = $next->nextSibling ) {
			if ( $next instanceof DOMElement ) {
				$other = 'sc-else' === strtolower( $next->tagName ) ? $next : null;
				break;
			}

			if ( ! ( $next instanceof \DOMText && '' === trim( (string) $next->nodeValue ) ) ) {
				break;
			}
		}

		$kept    = $keep ? $node : $other;
		$dropped = $keep ? $other : $node;

		if ( $dropped instanceof DOMElement && $dropped->parentNode instanceof DOMNode ) {
			$dropped->parentNode->removeChild( $dropped );
		}

		if ( ! $kept instanceof DOMElement || ! $kept->parentNode instanceof DOMNode ) {
			return;
		}

		self::fill( $kept, $scopes, $data, $fields, $stats );

		while ( $kept->firstChild instanceof DOMNode ) {
			$kept->parentNode->insertBefore( $kept->firstChild, $kept );
		}

		$kept->parentNode->removeChild( $kept );
	}

	/**
	 * Replace resolvable placeholders in one element's attributes.
	 *
	 * @param DOMElement                        $node   Element.
	 * @param array<int, array<string, mixed>>  $scopes Loop scopes, outermost first.
	 * @param TemplateData                      $data   Data found in the page's scripts.
	 * @param array<string, array<int, string>> $fields Fields the page reads per root name.
	 * @return void
	 */
	private static function substitute( DOMElement $node, array $scopes, TemplateData $data, array $fields ): void {
		if ( ! $node->hasAttributes() ) {
			return;
		}

		foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
			$value = (string) $attribute->nodeValue;

			if ( ! str_contains( $value, '{{' ) || str_starts_with( strtolower( $attribute->nodeName ), self::HANDLER_PREFIX ) ) {
				continue;
			}

			$node->setAttribute( $attribute->nodeName, self::replace( $value, $scopes, $data, $fields ) );
		}
	}

	/**
	 * Replace resolvable placeholders in one text node.
	 *
	 * @param \DOMText                          $text   Text node.
	 * @param array<int, array<string, mixed>>  $scopes Loop scopes, outermost first.
	 * @param TemplateData                      $data   Data found in the page's scripts.
	 * @param array<string, array<int, string>> $fields Fields the page reads per root name.
	 * @return void
	 */
	private static function substitute_text( \DOMText $text, array $scopes, TemplateData $data, array $fields ): void {
		$value = (string) $text->nodeValue;

		if ( str_contains( $value, '{{' ) ) {
			$text->nodeValue = self::replace( $value, $scopes, $data, $fields );
		}
	}

	/**
	 * Replace every resolvable `{{ }}` in a string; the rest are left as they were.
	 *
	 * Values are written as text, never parsed as markup, so a title with a
	 * tag in it prints the tag rather than running it.
	 *
	 * @param string                            $value  Text with placeholders.
	 * @param array<int, array<string, mixed>>  $scopes Loop scopes, outermost first.
	 * @param TemplateData                      $data   Data found in the page's scripts.
	 * @param array<string, array<int, string>> $fields Fields the page reads per root name.
	 * @return string
	 */
	private static function replace( string $value, array $scopes, TemplateData $data, array $fields ): string {
		return (string) preg_replace_callback(
			'/\{\{(.*?)\}\}/su',
			static function ( array $hit ) use ( $scopes, $data, $fields ): string {
				list( $found, $resolved ) = $data->resolve( $hit[1], $scopes, $fields );

				if ( ! $found ) {
					return $hit[0];
				}

				if ( 1 === preg_match( '/^\s*([A-Za-z_$][\w$]*)/', $hit[1], $root ) ) {
					self::$resolved[ $root[1] ] = true;
				}

				$text = TemplateData::text( $resolved );

				return null === $text ? $hit[0] : $text;
			},
			$value
		);
	}

	/**
	 * The expression inside `{{ }}`, or the raw attribute when it has no braces.
	 *
	 * @param string $value Attribute value.
	 * @return string
	 */
	private static function unbrace( string $value ): string {
		$value = trim( $value );

		if ( 1 === preg_match( '/^\{\{(.*)\}\}$/su', $value, $match ) ) {
			return trim( $match[1] );
		}

		return $value;
	}

	/**
	 * Stand a screenshot in for each visual the page's own scripts would have drawn.
	 *
	 * A `<hero-viz>` or a `<canvas>` is empty markup; what the designer saw was
	 * painted by JavaScript that does not travel. Designs usually ship a
	 * screenshot of it, named after the thing — so the closest name wins and
	 * the visual survives as a picture the editor can swap later. With no
	 * screenshot to use the element is left for the converter to report.
	 *
	 * @param DOMXPath           $xpath    Document query object.
	 * @param string             $page_dir Directory of the page.
	 * @param string             $root     Design root.
	 * @param string             $page     Page file name without its extension.
	 * @param array<int, string> $notes    Review notes, by reference.
	 * @return array<string, string> Element name to the screenshot's relative path.
	 */
	private static function placeholders( DOMXPath $xpath, string $page_dir, string $root, string $page, array &$notes ): array {
		$empty = array();

		foreach ( $xpath->query( '//*[contains(local-name(), "-")] | //canvas' ) as $node ) {
			if ( ! $node instanceof DOMElement || ! $node->parentNode instanceof DOMNode ) {
				continue;
			}

			if ( '' !== trim( (string) $node->textContent ) || $node->getElementsByTagName( 'img' )->length > 0 ) {
				continue;
			}

			// A visual layered under a real picture — a card's cover — is already covered.
			$covered = false;

			foreach ( $node->parentNode->childNodes as $sibling ) {
				if ( $sibling instanceof DOMElement && 'img' === strtolower( $sibling->tagName ) ) {
					$covered = true;
					break;
				}
			}

			if ( $covered ) {
				continue;
			}

			$empty[] = $node;
		}

		if ( array() === $empty ) {
			return array();
		}

		$shots = self::screenshots( $page_dir, $root );

		if ( array() === $shots ) {
			return array();
		}

		$map       = array();
		$page_used = false;

		foreach ( $empty as $node ) {
			$name = self::visual_name( $node );
			$file = self::best_screenshot( self::visual_tokens( $node ), $shots );

			if ( '' === $file && ! $page_used ) {
				$file      = self::best_screenshot( array( self::normalise_token( $page ) ), $shots, true );
				$page_used = '' !== $file;
			}

			if ( '' === $file ) {
				continue;
			}

			$figure = $node->ownerDocument->createElement( 'figure' );
			$figure->setAttribute( 'class', 'qs-import-placeholder' );

			$img = $node->ownerDocument->createElement( 'img' );
			$img->setAttribute( 'src', $file );
			$img->setAttribute( 'alt', '' );
			$img->setAttribute( 'data-qs-placeholder', $name );
			$figure->appendChild( $img );

			$node->parentNode->replaceChild( $figure, $node );

			$map[ $name ] = $file;
			$notes[]      = sprintf(
				/* translators: 1: name of the element the design's script would have rendered, 2: relative path of the screenshot used instead. */
				__( 'A JS-rendered visual (%1$s) was replaced with the design\'s screenshot %2$s. Swap it for a real image or embed when the site is live.', 'qwerty-soft-signal' ),
				$name,
				$file
			);
		}

		return $map;
	}

	/**
	 * The screenshots a design ships, as relative path to comparable stem.
	 *
	 * The page's own directory is tried first, then the design root; the path
	 * returned is relative to whichever held the directory, which is how the
	 * media import later looks files up.
	 *
	 * @param string $page_dir Directory of the page.
	 * @param string $root     Design root.
	 * @return array<string, string>
	 */
	private static function screenshots( string $page_dir, string $root ): array {
		foreach ( array_unique( array( $page_dir, $root ) ) as $base ) {
			foreach ( self::SCREENSHOT_DIRS as $dir ) {
				$path = $base . '/' . $dir;

				if ( ! is_dir( $path ) ) {
					continue;
				}

				$entries = scandir( $path );
				$shots   = array();

				foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
					$extension = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );

					if ( in_array( $extension, self::SCREENSHOT_TYPES, true ) && is_file( $path . '/' . $entry ) ) {
						$shots[ $dir . '/' . $entry ] = self::normalise_token( pathinfo( $entry, PATHINFO_FILENAME ) );
					}
				}

				if ( array() !== $shots ) {
					return $shots;
				}
			}
		}

		return array();
	}

	/**
	 * The name a JS-rendered visual is reported under.
	 *
	 * @param DOMElement $node Empty element.
	 * @return string
	 */
	private static function visual_name( DOMElement $node ): string {
		foreach ( self::NAMING_ATTRIBUTES as $attribute ) {
			$value = trim( $node->getAttribute( $attribute ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return strtolower( $node->tagName );
	}

	/**
	 * Words that describe a visual, longest first.
	 *
	 * The tag and naming attributes count whole and in parts — `hero-viz` is
	 * also `hero` and `viz` — and a variant is tried joined to the name, so
	 * `hero-viz variant="c"` can find `hero-viz-c.png` before `hero-viz.png`.
	 *
	 * @param DOMElement $node Empty element.
	 * @return array<int, string>
	 */
	private static function visual_tokens( DOMElement $node ): array {
		$raw  = array( strtolower( $node->tagName ) );
		$base = strtolower( $node->tagName );

		foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
			$key   = strtolower( $attribute->nodeName );
			$value = trim( (string) $attribute->nodeValue );

			if ( '' === $value || str_contains( $value, '{{' ) || in_array( $key, array( 'style', 'width', 'height', 'hint-size', 'from', 'src', 'href' ), true ) ) {
				continue;
			}

			if ( in_array( $key, self::NAMING_ATTRIBUTES, true ) ) {
				$base = $value;
			}

			$words = preg_split( '/[\s,\/]+/', $value );

			foreach ( is_array( $words ) ? $words : array() as $word ) {
				$raw[] = $word;

				if ( in_array( $key, array( 'variant', 'type', 'mode', 'kind' ), true ) ) {
					$raw[] = $base . '-' . $word;
				}
			}
		}

		$tokens = array();

		foreach ( $raw as $word ) {
			$tokens[] = self::normalise_token( $word );

			$parts = preg_split( '/[^a-z0-9]+/i', $word );

			foreach ( is_array( $parts ) ? $parts : array() as $part ) {
				$tokens[] = self::normalise_token( $part );
			}
		}

		$tokens = array_values( array_unique( array_filter( $tokens, static fn( string $token ): bool => strlen( $token ) >= 3 ) ) );

		usort( $tokens, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $tokens;
	}

	/**
	 * Lower-case letters and digits only, so `SecTeerExplainer` meets `secteer-explainer`.
	 *
	 * @param string $word Raw word.
	 * @return string
	 */
	private static function normalise_token( string $word ): string {
		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $word ) );
	}

	/**
	 * The screenshot whose name best matches a set of tokens.
	 *
	 * Exact beats prefix beats substring; within a tier the longest shared
	 * text wins. Substrings need four characters so `viz` alone does not claim
	 * every visual in the folder.
	 *
	 * @param array<int, string>    $tokens Candidate words, longest first.
	 * @param array<string, string> $shots  Relative path to normalised stem.
	 * @param bool                  $exact  Only accept an exact stem match.
	 * @return string Relative path, or an empty string.
	 */
	private static function best_screenshot( array $tokens, array $shots, bool $exact = false ): string {
		$best  = '';
		$score = 0;

		foreach ( $shots as $path => $stem ) {
			foreach ( $tokens as $token ) {
				$candidate = 0;

				if ( $token === $stem ) {
					$candidate = 3000 + strlen( $token );
				} elseif ( $exact ) {
					continue;
				} elseif ( str_starts_with( $stem, $token ) || str_starts_with( $token, $stem ) ) {
					$candidate = 2000 + min( strlen( $token ), strlen( $stem ) );
				} elseif ( strlen( $token ) >= 4 && ( str_contains( $stem, $token ) || str_contains( $token, $stem ) ) ) {
					$candidate = 1000 + min( strlen( $token ), strlen( $stem ) );
				}

				if ( $candidate > $score ) {
					$score = $candidate;
					$best  = $path;
				}
			}
		}

		return $best;
	}

	/**
	 * Remove template loops and every placeholder a data binding left behind.
	 *
	 * A `<sc-for>` loop or a `{{ post.title }}` is an instruction to a
	 * renderer that WordPress does not have. Carrying it into a page prints
	 * the braces to visitors; carrying an `href="{{ url }}"` makes a link to
	 * nowhere. Elements bound by attribute go entirely; text placeholders are
	 * cut out of their sentence, and an element left with nothing goes too.
	 *
	 * @param DOMXPath $xpath Document query object.
	 * @return void
	 */
	private static function strip_templates( DOMXPath $xpath ): void {
		foreach ( self::TEMPLATE_TAGS as $tag ) {
			foreach ( iterator_to_array( $xpath->query( '//' . $tag ) ) as $node ) {
				if ( $node->parentNode instanceof DOMNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		/*
		 * A placeholder in an attribute marks a templated element — but the
		 * page's own wrapper often carries one too (`class="page {{ theme }}"`),
		 * and dropping it would drop the whole page. Clean the attribute and
		 * keep any element that still has something to say; remove only the
		 * ones that were nothing but data binding.
		 */
		foreach ( iterator_to_array( $xpath->query( '//*[@*[contains(., "{{")]]' ) ) as $node ) {
			if ( ! $node instanceof DOMElement || ! $node->parentNode instanceof DOMNode ) {
				continue;
			}

			$has_content = '' !== trim( (string) preg_replace( '/\{\{.*?\}\}/su', '', (string) $node->textContent ) )
				|| $node->getElementsByTagName( 'img' )->length > 0;

			if ( ! $has_content ) {
				$node->parentNode->removeChild( $node );
				continue;
			}

			foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
				if ( str_contains( (string) $attribute->nodeValue, '{{' ) ) {
					$clean = trim( (string) preg_replace( array( '/\{\{.*?\}\}/su', '/\s+/' ), array( '', ' ' ), (string) $attribute->nodeValue ) );

					if ( '' === $clean ) {
						$node->removeAttribute( $attribute->nodeName );
					} else {
						$node->setAttribute( $attribute->nodeName, $clean );
					}
				}
			}
		}

		foreach ( iterator_to_array( $xpath->query( '//text()[contains(., "{{")]' ) ) as $text ) {
			$parent = $text->parentNode;

			if ( ! $parent instanceof DOMNode ) {
				continue;
			}

			$text->nodeValue = (string) preg_replace( '/\{\{.*?\}\}/su', '', (string) $text->nodeValue );

			$emptied = $parent instanceof DOMElement
				&& '' === trim( (string) $parent->textContent )
				&& 0 === $parent->getElementsByTagName( 'img' )->length
				&& $parent->parentNode instanceof DOMNode;

			if ( $emptied ) {
				$parent->parentNode->removeChild( $parent );
			}
		}
	}

	/**
	 * Take apart custom elements the page's own scripts would have rendered.
	 *
	 * One that wraps real text is just a wrapper and its children survive.
	 * One that is empty — a `<hero-viz>` canvas — is left in place for the
	 * converter, which knows how to say on the review screen that it was
	 * left out.
	 *
	 * @param DOMXPath $xpath Document query object.
	 * @return void
	 */
	private static function unwrap_custom_elements( DOMXPath $xpath ): void {
		foreach ( iterator_to_array( $xpath->query( '//*[contains(local-name(), "-")]' ) ) as $node ) {
			if ( ! $node instanceof DOMElement || ! $node->parentNode instanceof DOMNode ) {
				continue;
			}

			if ( '' === trim( (string) $node->textContent ) && 0 === $node->getElementsByTagName( 'img' )->length ) {
				continue;
			}

			while ( $node->firstChild instanceof DOMNode ) {
				$node->parentNode->insertBefore( $node->firstChild, $node );
			}

			$node->parentNode->removeChild( $node );
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
		} else {
			$nodes = self::with_orphans( $nodes, $xpath );
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
	 * Add content that sits outside every <section> to the section list.
	 *
	 * Design tools often leave the hero — the one part of the page nobody
	 * wants to lose — as a plain <div> above the first <section>. Trusting
	 * the explicit sections alone drops it silently. Any element outside the
	 * sections and the page chrome that carries a heading or an image is
	 * kept as a section of its own, outermost wrapper first, in document
	 * order with the rest.
	 *
	 * @param array<int, DOMNode> $sections Explicit sections.
	 * @param DOMXPath            $xpath    Document query object.
	 * @return array<int, DOMNode>
	 */
	private static function with_orphans( array $sections, DOMXPath $xpath ): array {
		$query = '//*[not(ancestor-or-self::section) and not(ancestor-or-self::header)'
			. ' and not(ancestor-or-self::footer) and not(ancestor-or-self::nav)'
			. ' and not(descendant::section) and not(self::html) and not(self::body) and not(self::main)'
			. ' and (descendant::h1 or descendant::h2 or descendant::h3 or descendant::img)]';

		$candidates = array();

		foreach ( $xpath->query( $query ) as $node ) {
			if ( $node instanceof DOMElement ) {
				$candidates[] = $node;
			}
		}

		if ( array() === $candidates ) {
			return $sections;
		}

		/*
		 * Keep only the outermost of nested candidates. One ancestor walk per
		 * node against a set — not a pairwise comparison, which a flat page
		 * of a few thousand cards turns into seconds.
		 */
		$set = new \SplObjectStorage();

		foreach ( $candidates as $node ) {
			$set->attach( $node );
		}

		$orphans = array();

		foreach ( $candidates as $node ) {
			$nested = false;

			for ( $parent = $node->parentNode; $parent instanceof DOMNode; $parent = $parent->parentNode ) {
				if ( $set->contains( $parent ) ) {
					$nested = true;
					break;
				}
			}

			if ( ! $nested ) {
				$orphans[] = $node;
			}
		}

		if ( array() === $orphans ) {
			return $sections;
		}

		$orphans = self::group_siblings( $orphans );

		// Merge in document order.
		$order = array();
		$index = 0;

		foreach ( $xpath->query( '//*' ) as $element ) {
			$order[ spl_object_id( $element ) ] = $index++;
		}

		$all = array_merge( $sections, $orphans );

		usort(
			$all,
			static function ( DOMNode $a, DOMNode $b ) use ( $order ): int {
				return ( $order[ spl_object_id( $a ) ] ?? 0 ) <=> ( $order[ spl_object_id( $b ) ] ?? 0 );
			}
		);

		return $all;
	}

	/**
	 * Fold runs of adjacent orphan siblings into one wrapper each.
	 *
	 * A row of cards sitting directly under <main> is one section of the
	 * page, not thirty. Consecutive orphans that share a parent — with nothing
	 * but whitespace between them — are moved into a single <div> so the
	 * converter sees them together, as it would have inside a <section>.
	 *
	 * @param array<int, DOMElement> $orphans Outermost orphan elements, in document order.
	 * @return array<int, DOMElement>
	 */
	private static function group_siblings( array $orphans ): array {
		$groups  = array();
		$current = array();

		$flush = static function () use ( &$groups, &$current ): void {
			if ( array() === $current ) {
				return;
			}

			if ( 1 === count( $current ) ) {
				$groups[] = $current[0];
			} else {
				$first   = $current[0];
				$wrapper = $first->ownerDocument->createElement( 'div' );
				$wrapper->setAttribute( 'class', 'qs-import-run' );
				$first->parentNode->insertBefore( $wrapper, $first );

				foreach ( $current as $node ) {
					$wrapper->appendChild( $node );
				}

				$groups[] = $wrapper;
			}

			$current = array();
		};

		foreach ( $orphans as $node ) {
			$previous = array() === $current ? null : $current[ count( $current ) - 1 ];

			if ( null !== $previous && self::adjacent( $previous, $node ) ) {
				$current[] = $node;
				continue;
			}

			$flush();
			$current[] = $node;
		}

		$flush();

		return $groups;
	}

	/**
	 * Whether two elements are siblings with only whitespace between them.
	 *
	 * @param DOMElement $first  Earlier element.
	 * @param DOMElement $second Later element.
	 * @return bool
	 */
	private static function adjacent( DOMElement $first, DOMElement $second ): bool {
		if ( $first->parentNode !== $second->parentNode ) {
			return false;
		}

		// A hero carries the page's h1 and always stands on its own.
		if ( $first->getElementsByTagName( 'h1' )->length > 0 || $second->getElementsByTagName( 'h1' )->length > 0 ) {
			return false;
		}

		for ( $next = $first->nextSibling; $next instanceof DOMNode; $next = $next->nextSibling ) {
			if ( $next === $second ) {
				return true;
			}

			if ( ! ( $next instanceof \DOMText && '' === trim( $next->nodeValue ) ) && ! $next instanceof \DOMComment ) {
				return false;
			}
		}

		return false;
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
			__( 'Section %d', 'qwerty-soft-signal' ),
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
