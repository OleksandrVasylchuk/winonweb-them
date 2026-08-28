<?php
/**
 * Reads a design's stylesheets: its tokens, and the rules each section needs.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A small, deliberately un-clever CSS reader.
 *
 * Two jobs:
 *
 * 1. **Tokens.** Custom properties on `:root` are the design's own vocabulary.
 *    Lifting them out is what makes the imported site re-themeable afterwards
 *    instead of a pile of hard-coded hexes.
 * 2. **Relevant rules.** A section is converted with only the CSS that could
 *    apply to it. Sending a whole 30 KB stylesheet with every section would
 *    cost more than the markup itself and bury the rules that matter.
 *
 * This is not a spec-complete parser and does not try to be. It reads the
 * shapes real stylesheets are written in, and when it is unsure it errs
 * towards including a rule — a slightly larger prompt is a cheaper mistake
 * than a section converted without its styling.
 */
final class CssIndex {

	/**
	 * Parsed rules: selector, declarations and any at-rule wrapper.
	 *
	 * @var array<int, array{selector:string, body:string, at:string, tokens:array<int,string>}>
	 */
	private array $rules = array();

	/**
	 * Custom properties declared on :root.
	 *
	 * @var array<string, string>
	 */
	private array $tokens = array();

	/**
	 * Comment-free source text, kept so the design-wide custom property map
	 * can be built on demand by DesignTokens::custom_properties().
	 *
	 * @var array<int, string>
	 */
	private array $sources = array();

	/**
	 * Selectors compiled for element matching, built on first use.
	 *
	 * @var array<int, array{chain:array<int,array<string,mixed>>, specificity:int, order:int, declarations:array<int,array{0:string,1:string,2:bool}>}>|null
	 */
	private ?array $matchers = null;

	/**
	 * Design-wide custom properties, resolved on first use.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $vars = null;

	/**
	 * Per-element cache of the declarations that won the cascade.
	 *
	 * @var \WeakMap<\DOMElement, array<string, string>>
	 */
	private \WeakMap $declared;

	/**
	 * Per-element cache of the custom properties in scope.
	 *
	 * @var \WeakMap<\DOMElement, array<string, string>>
	 */
	private \WeakMap $scopes;

	/**
	 * Build an index from one or more stylesheets.
	 *
	 * @param array<int, string> $sources Raw CSS strings.
	 */
	public function __construct( array $sources ) {
		$this->declared = new \WeakMap();
		$this->scopes   = new \WeakMap();

		foreach ( $sources as $css ) {
			$clean           = self::strip_comments( (string) $css );
			$this->sources[] = $clean;
			$this->parse( $clean );
		}
	}

	/**
	 * Create an index from a design directory.
	 *
	 * @param string             $root   Absolute path of the unpacked design.
	 * @param array<int, string> $inline Inline <style> contents already found.
	 * @return self
	 */
	public static function from_directory( string $root, array $inline = array() ): self {
		$sources = $inline;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'css' === strtolower( $file->getExtension() ) ) {
				$sources[] = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
			}
		}

		return new self( $sources );
	}

	/**
	 * The design's custom properties.
	 *
	 * @return array<string, string>
	 */
	public function tokens(): array {
		return $this->tokens;
	}

	/**
	 * How many rules were understood.
	 *
	 * @return int
	 */
	public function rule_count(): int {
		return count( $this->rules );
	}

	/**
	 * Remove comments without disturbing the rest of the source.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	private static function strip_comments( string $css ): string {
		$clean = preg_replace( '#/\*.*?\*/#s', '', $css );

		return is_string( $clean ) ? $clean : $css;
	}

	/**
	 * Walk the stylesheet, recording every declaration block.
	 *
	 * @param string $css   Comment-free CSS.
	 * @param string $at    Enclosing at-rule prelude, if any.
	 * @return void
	 */
	private function parse( string $css, string $at = '' ): void {
		$length = strlen( $css );
		$buffer = '';
		$i      = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '{' !== $char ) {
				$buffer .= $char;
				++$i;
				continue;
			}

			$prelude = trim( $buffer );
			$buffer  = '';
			$block   = self::read_block( $css, $i );
			$i       = $block['end'];

			if ( str_starts_with( $prelude, '@' ) ) {
				/*
				 * Conditional groups (@media, @supports, @layer) wrap ordinary
				 * rules, so recurse and remember the condition. Descriptor
				 * at-rules (@font-face, @keyframes) hold declarations rather
				 * than rules and are not indexed — nothing in a section
				 * selector can match them.
				 */
				if ( preg_match( '#^@(media|supports|layer|container)\b#i', $prelude ) ) {
					$this->parse( $block['body'], '' !== $at ? $at : $prelude );
				}

				continue;
			}

			if ( '' === $prelude ) {
				continue;
			}

			$this->record( $prelude, $block['body'], $at );
		}
	}

	/**
	 * Read a balanced { ... } block starting at an opening brace.
	 *
	 * @param string $css   Source.
	 * @param int    $start Index of the opening brace.
	 * @return array{body:string, end:int}
	 */
	private static function read_block( string $css, int $start ): array {
		$depth  = 0;
		$length = strlen( $css );

		for ( $i = $start; $i < $length; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
				continue;
			}

			if ( '}' === $css[ $i ] ) {
				--$depth;

				if ( 0 === $depth ) {
					return array(
						'body' => substr( $css, $start + 1, $i - $start - 1 ),
						'end'  => $i + 1,
					);
				}
			}
		}

		// Unbalanced source: treat the remainder as the block.
		return array(
			'body' => substr( $css, $start + 1 ),
			'end'  => $length,
		);
	}

	/**
	 * Store one rule and pull custom properties out of :root.
	 *
	 * @param string $selector Selector list.
	 * @param string $body     Declarations.
	 * @param string $at       Enclosing at-rule prelude.
	 * @return void
	 */
	private function record( string $selector, string $body, string $at ): void {
		/*
		 * :root and html are where tokens live by convention; body and a lone
		 * class (`.page{--bg:#fff}`) are where exports scope them. Variant
		 * selectors such as `.page.dark` or `html.theme-dark` do not qualify,
		 * which — together with first-declaration-wins — keeps the default
		 * theme rather than its dark override.
		 */
		if ( preg_match( '#(^|,)\s*(:root|html|body|\.[A-Za-z_][\w-]*)\s*(,|$)#i', $selector ) ) {
			preg_match_all( '#(--[A-Za-z0-9_-]+)\s*:\s*([^;]+)#', $body, $matches, PREG_SET_ORDER );

			foreach ( $matches as $match ) {
				$name = $match[1];

				// First declaration wins: later ones are usually overrides in
				// a media query or a theme variant.
				if ( ! isset( $this->tokens[ $name ] ) ) {
					$this->tokens[ $name ] = trim( $match[2] );
				}
			}
		}

		$this->rules[] = array(
			'selector' => trim( preg_replace( '/\s+/', ' ', $selector ) ?? $selector ),
			'body'     => trim( preg_replace( '/\s+/', ' ', $body ) ?? $body ),
			'at'       => $at,
			'tokens'   => self::selector_tokens( $selector ),
		);
	}

	/**
	 * The class, id and tag names a selector depends on.
	 *
	 * @param string $selector Selector list.
	 * @return array<int, string>
	 */
	private static function selector_tokens( string $selector ): array {
		$tokens = array();

		if ( preg_match_all( '#\.(-?[_a-zA-Z][\w-]*)#', $selector, $classes ) ) {
			foreach ( $classes[1] as $class ) {
				$tokens[] = '.' . $class;
			}
		}

		if ( preg_match_all( '#\#(-?[_a-zA-Z][\w-]*)#', $selector, $ids ) ) {
			foreach ( $ids[1] as $id ) {
				$tokens[] = '#' . $id;
			}
		}

		if ( preg_match_all( '#(^|[\s>+~,(])([a-zA-Z][a-zA-Z0-9]*)#', $selector, $tags ) ) {
			foreach ( $tags[2] as $tag ) {
				$tokens[] = strtolower( $tag );
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * The CSS a given fragment of markup might actually be styled by.
	 *
	 * @param string $html      Section markup.
	 * @param int    $max_bytes Cap on the returned stylesheet.
	 * @return string
	 */
	public function rules_for( string $html, int $max_bytes = 14000 ): string {
		$present = self::markup_tokens( $html );
		$out     = array();
		$bytes   = 0;
		$groups  = array();

		foreach ( $this->rules as $rule ) {
			if ( ! self::applies( $rule['tokens'], $present ) ) {
				continue;
			}

			$line   = $rule['selector'] . '{' . $rule['body'] . '}';
			$bytes += strlen( $line );

			if ( $bytes > $max_bytes ) {
				break;
			}

			if ( '' === $rule['at'] ) {
				$out[] = $line;
				continue;
			}

			$groups[ $rule['at'] ][] = $line;
		}

		foreach ( $groups as $at => $lines ) {
			$out[] = $at . '{' . implode( '', $lines ) . '}';
		}

		return implode( "\n", $out );
	}

	/**
	 * Does a rule reference anything the markup contains?
	 *
	 * A rule with no class, id or tag token — `*`, or a bare pseudo-element —
	 * is treated as applying, because excluding it is the riskier guess.
	 *
	 * @param array<int, string> $needed  Tokens the selector depends on.
	 * @param array<string,bool> $present Tokens found in the markup.
	 * @return bool
	 */
	private static function applies( array $needed, array $present ): bool {
		if ( array() === $needed ) {
			return true;
		}

		foreach ( $needed as $token ) {
			if ( isset( $present[ $token ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Class, id and tag names appearing in a fragment of markup.
	 *
	 * @param string $html Markup.
	 * @return array<string, bool>
	 */
	private static function markup_tokens( string $html ): array {
		$tokens = array();

		if ( preg_match_all( '#<([a-zA-Z][a-zA-Z0-9]*)#', $html, $tags ) ) {
			foreach ( $tags[1] as $tag ) {
				$tokens[ strtolower( $tag ) ] = true;
			}
		}

		if ( preg_match_all( '#\sclass\s*=\s*"([^"]*)"#i', $html, $classes ) ) {
			foreach ( $classes[1] as $list ) {
				$parts = preg_split( '/\s+/', $list );

				foreach ( is_array( $parts ) ? $parts : array() as $class ) {
					if ( '' !== $class ) {
						$tokens[ '.' . $class ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '#\sid\s*=\s*"([^"]*)"#i', $html, $ids ) ) {
			foreach ( $ids[1] as $id ) {
				if ( '' !== trim( $id ) ) {
					$tokens[ '#' . trim( $id ) ] = true;
				}
			}
		}

		return $tokens;
	}

	/**
	 * Properties the element resolver reports.
	 *
	 * A deliberately short list: what a block can carry as an attribute, plus
	 * the few layout facts (display, grid tracks, max-width) the converter
	 * needs to decide between columns and a stack.
	 *
	 * @var array<int, string>
	 */
	private const RESOLVED = array(
		'padding-top',
		'padding-right',
		'padding-bottom',
		'padding-left',
		'margin-top',
		'margin-right',
		'margin-bottom',
		'margin-left',
		'border-radius',
		'border-width',
		'border-style',
		'border-color',
		'row-gap',
		'column-gap',
		'font-size',
		'font-weight',
		'font-family',
		'line-height',
		'letter-spacing',
		'text-transform',
		'text-align',
		'color',
		'background-color',
		'background-image',
		'max-width',
		'grid-template-columns',
		'flex-direction',
		'flex-wrap',
		'align-items',
		'justify-content',
		'box-shadow',
		'display',

		/*
		 * Not read by the converter, which has no block to put them on.
		 * They are here for the brief the model is given: an element the
		 * design took out of the flow, or sized by ratio, is exactly the kind
		 * of construction a structural conversion cannot express, and the
		 * model can only say so if it is told.
		 */
		'position',
		'width',
		'min-height',
		'aspect-ratio',
		'opacity',
	);

	/**
	 * Properties a child takes from its parent when it sets none of its own.
	 *
	 * @var array<int, string>
	 */
	private const INHERITED = array( 'color', 'font-size', 'font-weight', 'font-family', 'line-height', 'letter-spacing', 'text-transform', 'text-align' );

	/**
	 * The styles an element ends up with: its own, plus what it inherits.
	 *
	 * "Computed-ish": the cascade is honoured for the selector shapes real
	 * stylesheets use — type, class, id, descendant and child combinators,
	 * comma lists, inline `style=""` — in specificity and source order, with
	 * `var()` resolved through the design's custom properties, including ones
	 * scoped on an ancestor. Pseudo-classes, attribute selectors and sibling
	 * combinators are ignored, as are `max-width` media blocks: the result is
	 * the design at desktop width in its resting state. No browser defaults
	 * are added; a property the design never set is simply absent.
	 *
	 * Inheritance stops at the fragment root, so a body-level rule does not
	 * put a colour on every paragraph.
	 *
	 * @param \DOMElement $node Element inside a parsed section.
	 * @return array<string, string> Longhand property to resolved value.
	 */
	public function styles_for( \DOMElement $node ): array {
		$styles = $this->declared_for( $node );
		$parent = $node->parentNode;

		while ( $parent instanceof \DOMElement && ! in_array( strtolower( $parent->tagName ), array( 'body', 'html' ), true ) ) {
			$above = $this->declared_for( $parent );

			foreach ( self::INHERITED as $property ) {
				if ( ! isset( $styles[ $property ] ) && isset( $above[ $property ] ) ) {
					$styles[ $property ] = $above[ $property ];
				}
			}

			$parent = $parent->parentNode;
		}

		return $styles;
	}

	/**
	 * Only what the design declared on this element itself.
	 *
	 * Headings reset font-size and font-weight in every browser, so for them
	 * an inherited size is not what the design showed; this is the view that
	 * answers "did the design say so about this element".
	 *
	 * @param \DOMElement $node Element inside a parsed section.
	 * @return array<string, string> Longhand property to resolved value.
	 */
	public function declared_for( \DOMElement $node ): array {
		if ( isset( $this->declared[ $node ] ) ) {
			return $this->declared[ $node ];
		}

		$declarations = array();

		foreach ( $this->matchers() as $matcher ) {
			if ( ! self::matches( $matcher['chain'], $node ) ) {
				continue;
			}

			foreach ( $matcher['declarations'] as $declaration ) {
				$declarations[] = array( $declaration[0], $declaration[1], $declaration[2], $matcher['specificity'], $matcher['order'] );
			}
		}

		// The style attribute beats every stylesheet rule.
		foreach ( self::declarations( $node->getAttribute( 'style' ) ) as $index => $declaration ) {
			$declarations[] = array( $declaration[0], $declaration[1], $declaration[2], 10000, $index );
		}

		usort(
			$declarations,
			static function ( array $a, array $b ): int {
				return array( $a[2], $a[3], $a[4] ) <=> array( $b[2], $b[3], $b[4] );
			}
		);

		// Custom properties first, so a value can use one declared alongside it.
		$vars = $this->scope_above( $node );

		foreach ( $declarations as $declaration ) {
			if ( str_starts_with( $declaration[0], '--' ) ) {
				$vars[ $declaration[0] ] = $declaration[1];
			}
		}

		$styles = array();

		foreach ( $declarations as $declaration ) {
			if ( str_starts_with( $declaration[0], '--' ) ) {
				continue;
			}

			$value = trim( DesignTokens::substitute_vars( $declaration[1], $vars ) );

			// An unresolvable reference or a keyword that defers elsewhere says nothing usable.
			if ( str_contains( $value, 'var(' ) || in_array( strtolower( $value ), array( 'inherit', 'initial', 'unset', 'revert', 'currentcolor' ), true ) ) {
				foreach ( array_keys( self::expand( $declaration[0], '0' ) ) as $longhand ) {
					unset( $styles[ $longhand ] );
				}

				continue;
			}

			foreach ( self::expand( $declaration[0], $value ) as $longhand => $resolved ) {
				$styles[ $longhand ] = $resolved;
			}
		}

		$this->scopes[ $node ]   = $vars;
		$this->declared[ $node ] = array_intersect_key( $styles, array_flip( self::RESOLVED ) );

		return $this->declared[ $node ];
	}

	/**
	 * Custom properties visible to an element before its own declarations.
	 *
	 * @param \DOMElement $node Element.
	 * @return array<string, string>
	 */
	private function scope_above( \DOMElement $node ): array {
		$parent = $node->parentNode;

		if ( $parent instanceof \DOMElement && ! in_array( strtolower( $parent->tagName ), array( 'body', 'html' ), true ) ) {
			$this->declared_for( $parent );

			return $this->scopes[ $parent ] ?? $this->global_vars();
		}

		return $this->global_vars();
	}

	/**
	 * Every custom property the design declares, default theme first.
	 *
	 * The :root-style tokens gathered while parsing take precedence because
	 * they are first-declaration-wins; DesignTokens::custom_properties() fills
	 * in whatever else is declared anywhere, so a `--wc` set on a card still
	 * resolves when the card is met out of context.
	 *
	 * @return array<string, string>
	 */
	private function global_vars(): array {
		if ( null === $this->vars ) {
			$everything = DesignTokens::custom_properties( implode( "\n", $this->sources ) );
			$preferred  = array();

			foreach ( $this->tokens as $name => $value ) {
				$preferred[ strtolower( $name ) ] = $value;
			}

			$this->vars = $preferred + $everything;
		}

		return $this->vars;
	}

	/**
	 * Rules compiled into something an element can be tested against.
	 *
	 * @return array<int, array{chain:array<int,array<string,mixed>>, specificity:int, order:int, declarations:array<int,array{0:string,1:string,2:bool}>}>
	 */
	private function matchers(): array {
		if ( null !== $this->matchers ) {
			return $this->matchers;
		}

		$this->matchers = array();

		foreach ( $this->rules as $order => $rule ) {
			if ( ! self::at_applies( $rule['at'] ) ) {
				continue;
			}

			$declarations = self::declarations( $rule['body'] );

			if ( array() === $declarations ) {
				continue;
			}

			foreach ( self::split_top( $rule['selector'], ',' ) as $selector ) {
				$compiled = self::compile( $selector );

				if ( null === $compiled ) {
					continue;
				}

				$this->matchers[] = array(
					'chain'        => $compiled['chain'],
					'specificity'  => $compiled['specificity'],
					'order'        => $order,
					'declarations' => $declarations,
				);
			}
		}

		return $this->matchers;
	}

	/**
	 * Whether rules under an at-rule describe the desktop resting state.
	 *
	 * `min-width` blocks up to 1024px are the desktop defaults written
	 * mobile-first; `max-width` blocks are the narrow-screen adjustments and
	 * are skipped, as are print, dark-scheme and container queries.
	 *
	 * @param string $at At-rule prelude.
	 * @return bool
	 */
	private static function at_applies( string $at ): bool {
		if ( '' === $at ) {
			return true;
		}

		$at = strtolower( $at );

		if ( str_starts_with( $at, '@container' ) ) {
			return false;
		}

		if ( ! str_starts_with( $at, '@media' ) ) {
			return true;
		}

		if ( preg_match( '/max-width|print|prefers-color-scheme|orientation/', $at ) ) {
			return false;
		}

		if ( preg_match( '/min-width\s*:\s*([\d.]+)\s*(px|em|rem)/', $at, $width ) ) {
			$pixels = (float) $width[1] * ( 'px' === $width[2] ? 1 : 16 );

			return $pixels <= 1024;
		}

		return true;
	}

	/**
	 * Parse a declaration block into name, value and importance.
	 *
	 * @param string $body Declarations.
	 * @return array<int, array{0:string,1:string,2:bool}>
	 */
	private static function declarations( string $body ): array {
		$out = array();

		foreach ( self::split_top( $body, ';' ) as $declaration ) {
			$colon = strpos( $declaration, ':' );

			if ( false === $colon ) {
				continue;
			}

			$name  = strtolower( trim( substr( $declaration, 0, $colon ) ) );
			$value = trim( substr( $declaration, $colon + 1 ) );

			if ( '' === $name || '' === $value || 1 !== preg_match( '/^-{0,2}[a-z][a-z0-9-]*$/', $name ) ) {
				continue;
			}

			$important = false;

			if ( 1 === preg_match( '/^(.*?)\s*!\s*important$/i', $value, $flag ) ) {
				$value     = trim( $flag[1] );
				$important = true;
			}

			$out[] = array( $name, $value, $important );
		}

		return $out;
	}

	/**
	 * Split on a character, ignoring occurrences inside parentheses or quotes.
	 *
	 * @param string $text      Text.
	 * @param string $separator Single character.
	 * @return array<int, string>
	 */
	private static function split_top( string $text, string $separator ): array {
		$parts  = array();
		$buffer = '';
		$depth  = 0;
		$quote  = '';
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( '' !== $quote ) {
				$buffer .= $char;

				if ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			}

			if ( $char === $separator && 0 === $depth ) {
				$parts[] = trim( $buffer );
				$buffer  = '';
				continue;
			}

			$buffer .= $char;
		}

		$parts[] = trim( $buffer );

		return array_values( array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Compile one selector into a chain of compounds, right-most last.
	 *
	 * Anything with a pseudo-class, pseudo-element, attribute selector,
	 * sibling combinator or universal selector is declined: those describe
	 * states and structure this resolver does not model.
	 *
	 * @param string $selector One selector, no commas.
	 * @return array{chain:array<int,array<string,mixed>>, specificity:int}|null
	 */
	private static function compile( string $selector ): ?array {
		$selector = trim( $selector );

		if ( '' === $selector || 1 === preg_match( '/[:\[\]+~*]/', $selector ) ) {
			return null;
		}

		$parts = preg_split( '/\s*(>)\s*|\s+/', $selector, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) || array() === $parts ) {
			return null;
		}

		$chain       = array();
		$combinator  = ' ';
		$specificity = 0;

		foreach ( $parts as $part ) {
			if ( '>' === $part ) {
				$combinator = '>';
				continue;
			}

			if ( 1 !== preg_match( '/^([a-zA-Z][\w-]*)?((?:[.#]-?[_a-zA-Z][\w-]*)*)$/', $part, $found ) ) {
				return null;
			}

			$compound = array(
				'tag'        => strtolower( $found[1] ),
				'id'         => '',
				'classes'    => array(),
				'combinator' => $combinator,
			);

			if ( preg_match_all( '/([.#])(-?[_a-zA-Z][\w-]*)/', $found[2] ?? '', $qualifiers, PREG_SET_ORDER ) ) {
				foreach ( $qualifiers as $qualifier ) {
					if ( '#' === $qualifier[1] ) {
						$compound['id'] = $qualifier[2];
						$specificity   += 100;
					} else {
						$compound['classes'][] = $qualifier[2];
						$specificity          += 10;
					}
				}
			}

			if ( '' !== $compound['tag'] ) {
				++$specificity;
			}

			$chain[]    = $compound;
			$combinator = ' ';
		}

		return array(
			'chain'       => $chain,
			'specificity' => $specificity,
		);
	}

	/**
	 * Test a compiled selector against an element, right to left.
	 *
	 * @param array<int, array<string, mixed>> $chain Compounds.
	 * @param \DOMElement                      $node  Element.
	 * @return bool
	 */
	private static function matches( array $chain, \DOMElement $node ): bool {
		$last = count( $chain ) - 1;

		if ( ! self::compound_matches( $chain[ $last ], $node ) ) {
			return false;
		}

		return self::ancestors_match( $chain, $last - 1, $node, (string) $chain[ $last ]['combinator'] );
	}

	/**
	 * Match the remaining compounds against the ancestors of a node.
	 *
	 * @param array<int, array<string, mixed>> $chain      Compounds.
	 * @param int                              $index      Compound to match next.
	 * @param \DOMElement                      $node       Element whose ancestors are searched.
	 * @param string                           $combinator How the compound relates to the node: ' ' or '>'.
	 * @return bool
	 */
	private static function ancestors_match( array $chain, int $index, \DOMElement $node, string $combinator ): bool {
		if ( $index < 0 ) {
			return true;
		}

		$parent = $node->parentNode;

		while ( $parent instanceof \DOMElement ) {
			if ( self::compound_matches( $chain[ $index ], $parent )
				&& self::ancestors_match( $chain, $index - 1, $parent, (string) $chain[ $index ]['combinator'] ) ) {
				return true;
			}

			if ( '>' === $combinator ) {
				return false;
			}

			$parent = $parent->parentNode;
		}

		return false;
	}

	/**
	 * Does one compound (tag, id, classes) describe an element?
	 *
	 * @param array<string, mixed> $compound Compound.
	 * @param \DOMElement          $node     Element.
	 * @return bool
	 */
	private static function compound_matches( array $compound, \DOMElement $node ): bool {
		if ( '' !== $compound['tag'] && strtolower( $node->tagName ) !== $compound['tag'] ) {
			return false;
		}

		if ( '' !== $compound['id'] && $node->getAttribute( 'id' ) !== $compound['id'] ) {
			return false;
		}

		if ( array() === $compound['classes'] ) {
			return true;
		}

		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		$classes = is_array( $classes ) ? $classes : array();

		foreach ( $compound['classes'] as $wanted ) {
			if ( ! in_array( $wanted, $classes, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Break a shorthand into the longhands the converter reads.
	 *
	 * @param string $name  Property.
	 * @param string $value Resolved value.
	 * @return array<string, string>
	 */
	private static function expand( string $name, string $value ): array {
		switch ( $name ) {
			case 'padding':
			case 'margin':
				$sides = self::four_sides( $value );

				return null === $sides ? array() : array(
					$name . '-top'    => $sides[0],
					$name . '-right'  => $sides[1],
					$name . '-bottom' => $sides[2],
					$name . '-left'   => $sides[3],
				);

			case 'border-radius':
				$slash = strpos( $value, '/' );
				$sides = self::four_sides( false === $slash ? $value : substr( $value, 0, $slash ) );

				// Only a uniform radius is carried: a block holds one value.
				if ( null === $sides || count( array_unique( $sides ) ) > 1 ) {
					return array();
				}

				return array( 'border-radius' => $sides[0] );

			case 'gap':
			case 'grid-gap':
				$parts = self::split_top( $value, ' ' );

				return array(
					'row-gap'    => $parts[0] ?? $value,
					'column-gap' => $parts[1] ?? $parts[0] ?? $value,
				);

			case 'font':
				return self::font_shorthand( $value );

			case 'background':
				return self::background_shorthand( $value );

			case 'border':
				return self::border_shorthand( $value );

			default:
				return in_array( $name, self::RESOLVED, true ) ? array( $name => $value ) : array();
		}
	}

	/**
	 * One to four values, in top/right/bottom/left order.
	 *
	 * @param string $value Shorthand value.
	 * @return array{0:string,1:string,2:string,3:string}|null
	 */
	private static function four_sides( string $value ): ?array {
		$parts = self::split_top( trim( $value ), ' ' );

		switch ( count( $parts ) ) {
			case 1:
				return array( $parts[0], $parts[0], $parts[0], $parts[0] );
			case 2:
				return array( $parts[0], $parts[1], $parts[0], $parts[1] );
			case 3:
				return array( $parts[0], $parts[1], $parts[2], $parts[1] );
			case 4:
				return array( $parts[0], $parts[1], $parts[2], $parts[3] );
			default:
				return null;
		}
	}

	/**
	 * The `font` shorthand: optional style/weight, size, optional line-height, family.
	 *
	 * @param string $value Shorthand value.
	 * @return array<string, string>
	 */
	private static function font_shorthand( string $value ): array {
		$pattern = '/^(?:(italic|oblique|normal|small-caps|bold|bolder|lighter|[1-9]00)\s+)*'
			. '([\d.]+(?:px|rem|em|%|pt)|(?:x+-)?(?:small|large)|medium)'
			. '(?:\s*\/\s*([\d.]+(?:px|rem|em|%)?))?\s+(.+)$/i';

		if ( 1 !== preg_match( $pattern, trim( $value ), $found ) ) {
			return array();
		}

		$out = array(
			'font-size'   => $found[2],
			'font-family' => trim( $found[4] ),
		);

		if ( isset( $found[3] ) && '' !== $found[3] ) {
			$out['line-height'] = $found[3];
		}

		$size_at = strpos( $value, $found[2] );
		$before  = false === $size_at ? '' : substr( $value, 0, $size_at );

		if ( preg_match( '/\b(bold|bolder|lighter|[1-9]00)\b/i', $before, $weight ) ) {
			$out['font-weight'] = strtolower( $weight[1] );
		}

		return $out;
	}

	/**
	 * The `background` shorthand: a colour, a gradient, a picture, or a stack.
	 *
	 * @param string $value Shorthand value.
	 * @return array<string, string>
	 */
	private static function background_shorthand( string $value ): array {
		// A shorthand resets both layers, so a gradient written later really does replace an earlier flat colour.
		$out = array(
			'background-color' => 'transparent',
			'background-image' => 'none',
		);

		if ( preg_match( '/url\((?:[^()]|\([^()]*\))*\)/i', $value, $picture ) ) {
			$out['background-image'] = $picture[0];
		} elseif ( preg_match( '/(?:repeating-)?(?:linear|radial|conic)-gradient\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/i', $value ) ) {
			$out['background-image'] = $value;
		}

		$rest = (string) preg_replace( '/(?:url|(?:repeating-)?(?:linear|radial|conic)-gradient)\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/i', ' ', $value );

		if ( preg_match( '/#[0-9a-f]{3,8}\b|(?:rgba?|hsla?|oklch|color-mix)\((?:[^()]|\([^()]*\))*\)|\b(?:white|black|transparent)\b/i', $rest, $color ) ) {
			$out['background-color'] = $color[0];
		}

		return $out;
	}

	/**
	 * The `border` shorthand into width, style and colour.
	 *
	 * @param string $value Shorthand value.
	 * @return array<string, string>
	 */
	private static function border_shorthand( string $value ): array {
		$out = array();

		if ( 'none' === strtolower( trim( $value ) ) || '0' === trim( $value ) ) {
			return array(
				'border-width' => '0',
				'border-style' => 'none',
			);
		}

		foreach ( self::split_top( $value, ' ' ) as $part ) {
			$lower = strtolower( $part );

			if ( 1 === preg_match( '/^(?:[\d.]+(?:px|rem|em|pt)?|thin|medium|thick)$/', $lower ) ) {
				$out['border-width'] = $lower;
			} elseif ( in_array( $lower, array( 'none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset' ), true ) ) {
				$out['border-style'] = $lower;
			} else {
				$out['border-color'] = $part;
			}
		}

		return $out;
	}

	/**
	 * A length in CSS pixels, or null when it cannot be known statically.
	 *
	 * An em is read against the 16px default, which is right for padding on
	 * a body-sized element and close enough elsewhere; percentages, viewport
	 * units and calc() are declined rather than guessed.
	 *
	 * @param string $value Length.
	 * @return float|null
	 */
	public static function px( string $value ): ?float {
		$value = strtolower( trim( $value ) );

		if ( 1 !== preg_match( '/^(-?\d*\.?\d+)(px|rem|em|pt)?$/', $value, $found ) ) {
			return null;
		}

		$number = (float) $found[1];
		$unit   = $found[2] ?? '';

		if ( '' === $unit ) {
			return 0.0 === $number ? 0.0 : null;
		}

		switch ( $unit ) {
			case 'rem':
			case 'em':
				return $number * 16;
			case 'pt':
				return $number * 4 / 3;
			default:
				return $number;
		}
	}

	/**
	 * Turn the design's custom properties into a theme.json starting point.
	 *
	 * Only colours are proposed automatically. Spacing and type scales are
	 * reported but not converted, because a design's `--gap-3` rarely maps
	 * one-to-one onto a WordPress spacing preset and a wrong guess there is
	 * harder to notice than a wrong colour.
	 *
	 * @return array{colors:array<int,array{slug:string,name:string,color:string}>, other:array<string,string>}
	 */
	public function palette_proposal(): array {
		$colors = array();
		$other  = array();

		foreach ( $this->tokens as $name => $value ) {
			$slug = sanitize_title( ltrim( $name, '-' ) );

			if ( self::is_color( $value ) ) {
				$colors[] = array(
					'slug'  => $slug,
					'name'  => ucfirst( str_replace( '-', ' ', $slug ) ),
					'color' => self::normalise_color( $value ),
				);
				continue;
			}

			$other[ $name ] = $value;
		}

		return array(
			'colors' => $colors,
			'other'  => $other,
		);
	}

	/**
	 * Is this value a single flat colour?
	 *
	 * Gradients and shadows are excluded deliberately — they belong in
	 * theme.json gradients or shadows, not the palette.
	 *
	 * @param string $value Declaration value.
	 * @return bool
	 */
	private static function is_color( string $value ): bool {
		$value = trim( $value );

		if ( 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return true;
		}

		return 1 === preg_match( '/^(rgb|rgba|hsl|hsla|oklch|lab)\(\s*[^()]*\)$/i', $value );
	}

	/**
	 * Expand shorthand hex so theme.json always stores six digits.
	 *
	 * @param string $value Colour value.
	 * @return string
	 */
	private static function normalise_color( string $value ): string {
		$value = trim( $value );

		if ( 1 === preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $value, $m ) ) {
			return strtolower( '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3] );
		}

		return 1 === preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? strtolower( $value ) : $value;
	}
}
