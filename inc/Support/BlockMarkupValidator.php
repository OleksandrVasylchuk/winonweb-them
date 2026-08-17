<?php
/**
 * Gatekeeper for generated block markup.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Validates block markup produced by a language model before it is used.
 *
 * Nothing generated reaches a preview, a pattern or a page without passing
 * through here. The model is a remote service returning a string; that string
 * is treated exactly like any other untrusted input, regardless of how well it
 * usually behaves.
 *
 * What this refuses:
 *
 * - **Executable content** — `<script>`, `<iframe>`, `<object>`, event-handler
 *   attributes and `javascript:` URLs. An administrator can normally post
 *   these, but an administrator did not write this markup.
 * - **PHP** — `<?php` in content that may be written to a pattern file.
 * - **Unknown blocks** — a block comment naming something neither WordPress
 *   nor this theme registers renders as nothing and quietly loses content.
 * - **Malformed structure** — unbalanced or mismatched block comments, which
 *   the editor reports to the client as corrupted content.
 * - **Broken attributes** — a block comment whose JSON does not parse.
 */
final class BlockMarkupValidator {

	/**
	 * Core blocks the converter is allowed to emit.
	 *
	 * Deliberately a subset. A conversion that reaches for something outside
	 * this list is usually mapping a design onto the wrong primitive, and a
	 * small vocabulary is what keeps the output editable and consistent.
	 */
	private const CORE_BLOCKS = array(
		'buttons',
		'button',
		'column',
		'columns',
		'cover',
		'details',
		'embed',
		'gallery',
		'group',
		'heading',
		'html',
		'image',
		'list',
		'list-item',
		'media-text',
		'paragraph',
		'pattern',
		'preformatted',
		'pullquote',
		'quote',
		'search',
		'separator',
		'social-link',
		'social-links',
		'spacer',
		'table',
		'template-part',
		'video',
		'site-logo',
		'site-title',
		'site-tagline',
		'navigation',
		'navigation-link',
		'post-content',
		'post-title',
		'post-date',
		'post-excerpt',
		'post-featured-image',
		'post-terms',
		'post-author-name',
		'post-template',
		'query',
		'query-pagination',
		'query-pagination-next',
		'query-pagination-numbers',
		'query-pagination-previous',
		'query-no-results',
		'query-title',
		'term-description',
		'latest-posts',
	);

	/**
	 * Elements never permitted in generated markup.
	 */
	private const FORBIDDEN_TAGS = array( 'script', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'base', 'style' );

	/**
	 * Findings from the last check.
	 *
	 * @var array<int, string>
	 */
	private array $errors = array();

	/**
	 * Non-fatal observations from the last check.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = array();

	/**
	 * Block names this theme registers, resolved once per request.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $registered = null;

	/**
	 * Errors from the last call to check().
	 *
	 * @return array<int, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Warnings from the last call to check().
	 *
	 * @return array<int, string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Validate a block markup string.
	 *
	 * @param string $markup Generated block markup.
	 * @return bool True when the markup is safe to preview and save.
	 */
	public function check( string $markup ): bool {
		$this->errors   = array();
		$this->warnings = array();

		if ( '' === trim( $markup ) ) {
			$this->errors[] = __( 'The conversion produced nothing.', 'wow-signal' );

			return false;
		}

		$this->check_dangerous_content( $markup );
		$this->check_block_structure( $markup );

		return array() === $this->errors;
	}

	/**
	 * Refuse anything executable.
	 *
	 * @param string $markup Generated block markup.
	 * @return void
	 */
	private function check_dangerous_content( string $markup ): void {
		if ( str_contains( $markup, '<?php' ) || str_contains( $markup, '<?=' ) ) {
			$this->errors[] = __( 'The result contains PHP, which is never allowed in page content.', 'wow-signal' );
		}

		foreach ( self::FORBIDDEN_TAGS as $tag ) {
			if ( 1 === preg_match( '#<' . $tag . '\b#i', $markup ) ) {
				$this->errors[] = sprintf(
					/* translators: %s: HTML tag name. */
					__( 'The result contains a <%s> element, which generated content may not include.', 'wow-signal' ),
					$tag
				);
			}
		}

		/*
		 * Event-handler attributes. The word boundary before "on" keeps this
		 * from firing on legitimate attributes that merely end in one, and the
		 * = is required so prose mentioning "onclick" does not trip it.
		 */
		if ( 1 === preg_match( '#\s+on[a-z]+\s*=#i', $markup ) ) {
			$this->errors[] = __( 'The result contains an inline event handler attribute.', 'wow-signal' );
		}

		if ( 1 === preg_match( '#(href|src|action|formaction)\s*=\s*["\']?\s*(javascript|vbscript|data:text/html)#i', $markup ) ) {
			$this->errors[] = __( 'The result contains a script-carrying link or source.', 'wow-signal' );
		}

		// core/html would let anything above through on a later edit.
		if ( str_contains( $markup, '<!-- wp:html' ) ) {
			$this->errors[] = __( 'The result uses the Custom HTML block. Sections must be built from real blocks so they stay editable.', 'wow-signal' );
		}
	}

	/**
	 * Walk the block comments: balance, names and attribute JSON.
	 *
	 * @param string $markup Generated block markup.
	 * @return void
	 */
	private function check_block_structure( string $markup ): void {
		$pattern = '#<!--\s+(/?)wp:([a-z][a-z0-9-]*(?:/[a-z][a-z0-9-]*)?)\s*(\{.*?\})?\s*(/)?-->#s';

		if ( ! preg_match_all( $pattern, $markup, $matches, PREG_SET_ORDER ) ) {
			$this->errors[] = __( 'The result contains no blocks at all.', 'wow-signal' );

			return;
		}

		$stack   = array();
		$counted = 0;

		foreach ( $matches as $match ) {
			$closing      = '' !== $match[1];
			$name         = $match[2];
			$attributes   = $match[3] ?? '';
			$self_closing = isset( $match[4] ) && '/' === $match[4];

			if ( ! $closing ) {
				++$counted;
				$this->check_block_name( $name );

				if ( '' !== $attributes && null === json_decode( $attributes, true ) ) {
					$this->errors[] = sprintf(
						/* translators: %s: block name. */
						__( 'The settings on the "%s" block are not valid JSON.', 'wow-signal' ),
						$name
					);
				}
			}

			if ( $self_closing ) {
				continue;
			}

			if ( $closing ) {
				$open = array_pop( $stack );

				if ( null === $open ) {
					$this->errors[] = sprintf(
						/* translators: %s: block name. */
						__( 'A "%s" block is closed but was never opened.', 'wow-signal' ),
						$name
					);
					continue;
				}

				if ( $open !== $name ) {
					$this->errors[] = sprintf(
						/* translators: 1: closing block name, 2: block that was open. */
						__( 'A "%1$s" block closes while "%2$s" is still open.', 'wow-signal' ),
						$name,
						$open
					);
				}

				continue;
			}

			$stack[] = $name;
		}

		if ( array() !== $stack ) {
			$this->errors[] = sprintf(
				/* translators: %s: comma-separated block names. */
				__( 'These blocks are never closed: %s.', 'wow-signal' ),
				implode( ', ', array_unique( $stack ) )
			);
		}

		if ( 0 === $counted ) {
			$this->errors[] = __( 'The result contains no blocks at all.', 'wow-signal' );
		}
	}

	/**
	 * Is this a block the site can actually render?
	 *
	 * @param string $name Block name from the comment.
	 * @return void
	 */
	private function check_block_name( string $name ): void {
		if ( ! str_contains( $name, '/' ) ) {
			if ( in_array( $name, self::CORE_BLOCKS, true ) ) {
				return;
			}

			$this->errors[] = sprintf(
				/* translators: %s: block name. */
				__( '"%s" is not a block the converter is allowed to use.', 'wow-signal' ),
				'core/' . $name
			);

			return;
		}

		if ( null === $this->registered ) {
			$this->registered = array();

			foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $registered_name => $type ) {
				unset( $type );
				$this->registered[ $registered_name ] = true;
			}
		}

		if ( isset( $this->registered[ $name ] ) ) {
			return;
		}

		$this->errors[] = sprintf(
			/* translators: %s: block name. */
			__( '"%s" is not installed on this site, so that part of the section would render as nothing.', 'wow-signal' ),
			$name
		);
	}

	/**
	 * Report quality issues that do not make the markup unsafe.
	 *
	 * Run separately from check() because these should inform the reviewer
	 * rather than block the import.
	 *
	 * @param string $markup Generated block markup.
	 * @return array<int, string>
	 */
	public function review( string $markup ): array {
		$notes = array();

		if ( 1 === preg_match( '#<img\b(?![^>]*\balt\s*=)#i', $markup ) ) {
			$notes[] = __( 'An image has no alt text. Add one, or mark it decorative, before publishing.', 'wow-signal' );
		}

		/*
		 * Delimited with ~ rather than # — a hex colour starts with the very
		 * character # would end the pattern on, which silently disabled this
		 * check in an earlier version.
		 */
		if ( 1 === preg_match( '~style\s*=\s*"[^"]*(\#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\()~', $markup ) ) {
			$notes[] = __( 'A colour is written directly into the markup instead of using a theme colour, so it will not follow the site palette.', 'wow-signal' );
		}

		if ( 1 === preg_match( '#style\s*=\s*"[^"]*font-size\s*:\s*\d#i', $markup ) ) {
			$notes[] = __( 'A font size is written directly into the markup instead of using a theme size.', 'wow-signal' );
		}

		preg_match_all( '#<h([1-6])\b#i', $markup, $headings );

		if ( ! empty( $headings[1] ) ) {
			$levels = array_map( 'intval', $headings[1] );

			if ( count( array_filter( $levels, static fn( int $l ): bool => 1 === $l ) ) > 1 ) {
				$notes[] = __( 'This section contains more than one level-1 heading. A page should have exactly one.', 'wow-signal' );
			}

			$previous = null;

			foreach ( $levels as $level ) {
				if ( null !== $previous && $level > $previous + 1 ) {
					$notes[] = __( 'The heading levels skip a step, which is confusing for screen reader users.', 'wow-signal' );
					break;
				}

				$previous = $level;
			}
		}

		return $notes;
	}
}
