<?php
/**
 * The second pass: compare what the blocks render as against the design.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the model to mark its own work, with the rendered result in front of it.
 *
 * A conversion is written blind. The model produces block markup and never
 * sees what WordPress makes of it — and the gap between the two is where the
 * remaining mistakes live: a `core/columns` whose `width` attributes do not
 * add up, a group whose padding lands twice, a heading that came out as body
 * copy because the `fontSize` slug was wrong.
 *
 * WordPress can answer that: `do_blocks()` renders the markup on the server,
 * which is the same HTML a visitor would get. Handing that back alongside the
 * design's own HTML turns an open-ended "convert this" into a closed question
 * — do these two describe the same thing, and if not, what is different —
 * which is a far easier question to answer well.
 *
 * The pass is optional and costs a second call per section. It is worth it on
 * an archive whose construction the structural converter has not seen before,
 * and worth skipping on one it handles cleanly.
 */
final class RefinePrompt {

	/**
	 * The JSON shape the review must take.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'verdict', 'changed', 'concerns' ),
			'properties'           => array(
				'verdict'  => array(
					'type'        => 'string',
					'enum'        => array( 'match', 'close', 'off' ),
					'description' => '"match" when the rendered page says the same thing as the design and no change is needed, "close" when small corrections are worth making, "off" when something structural is wrong.',
				),
				'markup'   => array(
					'type'        => 'string',
					'description' => 'The corrected block markup, complete and self-contained. Omit this entirely when the verdict is "match".',
				),
				'changed'  => array(
					'type'        => 'array',
					'description' => 'Each correction you made, in one short phrase. Empty when the verdict is "match".',
					'items'       => array( 'type' => 'string' ),
				),
				'concerns' => array(
					'type'        => 'array',
					'description' => 'Differences you could not fix with blocks, stated plainly for the person reviewing the import. Empty when there is nothing to report.',
					'items'       => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * The system prompt for the review pass.
	 *
	 * @return string
	 */
	public static function system(): string {
		$lines = array();

		$lines[] = 'You are checking one section of a WordPress page against the static design it was converted from, and correcting it where the two say different things.';
		$lines[] = '';
		$lines[] = 'You are given the design\'s own HTML, what its CSS resolves to, the Gutenberg block markup that was produced, and the HTML WordPress renders from that markup. The last one is what a visitor actually gets.';
		$lines[] = '';

		$lines[] = '## What counts as a difference';
		$lines[] = '';
		$lines[] = 'Judge structure and meaning, not pixels. WordPress adds its own wrappers and layout classes, and the theme applies its own spacing scale — none of that is a fault. These are:';
		$lines[] = '';
		$lines[] = '- Content that is missing, duplicated, or reordered.';
		$lines[] = '- A row of items that came out stacked, or a stack that came out as a row, against what the resolved CSS says.';
		$lines[] = '- Column widths in the wrong proportion to the design\'s grid tracks.';
		$lines[] = '- Heading levels that changed, or a heading that stopped being a heading.';
		$lines[] = '- A link or button that lost its destination, or whose label changed.';
		$lines[] = '- An image that lost its `src` or its `alt`.';
		$lines[] = '- A card, panel or band that lost its background, border or radius — or gained a second one around it.';
		$lines[] = '- Emphasis inverted: the subordinate heading now reads as the dominant one.';
		$lines[] = '';
		$lines[] = 'Not differences: extra `wp-block-*` classes, `is-layout-*` wrappers, the theme\'s own margins, an exact pixel value snapped to the nearest preset, or a colour named by a palette slug instead of a hex value.';
		$lines[] = '';

		$lines[] = '## Correcting';
		$lines[] = '';
		$lines[] = 'Return the whole section\'s corrected markup, not a fragment or a diff — it replaces what was there. Change only what you found wrong; keep every piece of copy, every link, every image path and every class name exactly as they are. Adding `core/html`, `<script>`, `<style>`, `<iframe>` or `<form>` is never a correction.';
		$lines[] = '';
		$lines[] = 'When the two already say the same thing, answer `"verdict":"match"` with no markup. That is the expected answer for most sections, and inventing a change to look useful makes the import worse.';

		return implode( "\n", $lines );
	}

	/**
	 * The message: the design, the markup, and what WordPress made of it.
	 *
	 * @param array<string, mixed> $section  Section record from SectionSplitter.
	 * @param string               $markup   The block markup under review.
	 * @param string               $rendered The HTML do_blocks() produced from it.
	 * @param array<string, mixed> $given    facts brief, shot flag, close-up count.
	 * @return string
	 */
	public static function message( array $section, string $markup, string $rendered, array $given = array() ): string {
		$facts = isset( $given['facts'] ) ? (string) $given['facts'] : '';

		$lines = array();

		$lines[] = 'Check this section and correct it if the two do not say the same thing.';

		$closeups = isset( $given['closeups'] ) ? (int) $given['closeups'] : 0;

		if ( $closeups > 0 ) {
			$lines[] = '';
			$lines[] = sprintf(
				'The first %s a close-up of a visual inside this section, which the design drew with JavaScript. Check that the picture is still in the block markup, in the same place, with an `alt` that describes it.',
				1 === $closeups ? 'attached image is' : 'two attached images are each'
			);
		}

		if ( ! empty( $given['shot'] ) ) {
			$lines[] = '';
			$lines[] = 'A screenshot of the whole finished page is attached; the section below is one part of it. Use it to judge emphasis and spacing, not to look for content that is not in the markup.';
		}

		$lines[] = '';
		$lines[] = '### 1. The design';
		$lines[] = '';
		$lines[] = '```html';
		$lines[] = (string) $section['html'];
		$lines[] = '```';

		if ( '' !== trim( $facts ) ) {
			$lines[] = '';
			$lines[] = '### 2. What the design\'s CSS resolves to';
			$lines[] = '';
			$lines[] = '```';
			$lines[] = $facts;
			$lines[] = '```';
		}

		$lines[] = '';
		$lines[] = '### 3. The block markup that was produced';
		$lines[] = '';
		$lines[] = '```html';
		$lines[] = $markup;
		$lines[] = '```';

		$lines[] = '';
		$lines[] = '### 4. What WordPress renders from it';
		$lines[] = '';
		$lines[] = 'This is the HTML a visitor receives. Compare it with the design above.';
		$lines[] = '';
		$lines[] = '```html';
		$lines[] = $rendered;
		$lines[] = '```';

		return implode( "\n", $lines );
	}

	/**
	 * Ask again, with what was wrong with the correction that came back.
	 *
	 * The review pass fails the same way the conversion does — a brace missing
	 * from one block's settings — and it is worth the same single retry. What
	 * is repeated here is the whole comparison, not just the complaint: a
	 * reviewer handed only its own broken output has nothing left to check
	 * against, and will happily "fix" the markup by cutting out the part it
	 * cannot make valid.
	 *
	 * @param string $review   The review message that was sent the first time.
	 * @param string $returned The correction that came back and failed.
	 * @param string $problem  What the validator objected to.
	 * @return string
	 */
	public static function correction( string $review, string $returned, string $problem ): string {
		$lines = array();

		$lines[] = 'The correction you sent could not be used. Fix it and send the whole section again.';
		$lines[] = '';
		$lines[] = '### What was wrong with it';
		$lines[] = '';
		$lines[] = $problem;
		$lines[] = '';
		$lines[] = 'Block settings are JSON inside the `<!-- wp:name { … } -->` comment: every brace and bracket paired, keys and string values in double quotes, no trailing commas. Keep the corrections you had already decided on — do not drop content to make the markup valid, and do not change your verdict to `match` to avoid the problem. If you genuinely cannot express the fix in valid block markup, say so in `concerns` and answer `"verdict":"match"`.';
		$lines[] = '';
		$lines[] = '### What you sent';
		$lines[] = '';
		$lines[] = '```html';
		$lines[] = '' !== trim( $returned ) ? $returned : '(nothing)';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '### The comparison again, unchanged';
		$lines[] = '';
		$lines[] = $review;

		return implode( "\n", $lines );
	}

	/**
	 * Render block markup the way the front end will, for the comparison.
	 *
	 * The rendered HTML is trimmed of the things that would only be noise in a
	 * comparison — comments, and any script or style a block emitted — and
	 * capped, because a long section rendered in full can outweigh everything
	 * else in the message and push the design itself out of view.
	 *
	 * @param string $markup    Block markup.
	 * @param int    $max_bytes Ceiling on the returned HTML.
	 * @return string
	 */
	public static function render( string $markup, int $max_bytes = 24000 ): string {
		if ( '' === trim( $markup ) || ! function_exists( 'do_blocks' ) ) {
			return '';
		}

		$html = do_blocks( $markup );

		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );

		// Collapse the blank lines block rendering leaves behind, without touching indentation.
		$html = (string) preg_replace( "/\n\s*\n+/", "\n", $html );
		$html = trim( $html );

		if ( strlen( $html ) > $max_bytes ) {
			$html = substr( $html, 0, $max_bytes ) . "\n… (rendering truncated)";
		}

		return $html;
	}
}
