<?php
/**
 * Reads a design's stylesheets: its tokens, and the rules each section needs.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

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
	 * Build an index from one or more stylesheets.
	 *
	 * @param array<int, string> $sources Raw CSS strings.
	 */
	public function __construct( array $sources ) {
		foreach ( $sources as $css ) {
			$this->parse( self::strip_comments( (string) $css ) );
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
		if ( preg_match( '#(^|,)\s*(:root|html)\s*(,|$)#i', $selector ) ) {
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
