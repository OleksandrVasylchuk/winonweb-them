<?php
/**
 * Builds the instructions that turn a design section into block markup.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use WP_Error;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the system prompt, the per-section message and the reply schema.
 *
 * The vocabulary in the system prompt — palette slugs, font sizes, spacing
 * steps, the theme's own blocks — is read from the live site rather than
 * written down here. A theme whose palette is edited in the Site Editor keeps
 * converting into that palette, with no prompt to update and no chance of the
 * instructions drifting away from what the site can actually render.
 */
final class ConversionPrompt {

	/**
	 * Core blocks the model is told it may use.
	 */
	private const VOCABULARY = array(
		'core/group'        => 'sections and cards; set tagName to "section" for a page section',
		'core/columns'      => 'side-by-side layout, with core/column children',
		'core/heading'      => 'headings, with the level attribute',
		'core/paragraph'    => 'body copy',
		'core/list'         => 'bulleted and numbered lists, with core/list-item children',
		'core/buttons'      => 'call-to-action rows, with core/button children',
		'core/image'        => 'images',
		'core/cover'        => 'a background image or colour with content on top',
		'core/media-text'   => 'an image beside text',
		'core/separator'    => 'a divider',
		'core/spacer'       => 'vertical space where a margin is not enough',
		'core/quote'        => 'a quotation',
		'core/table'        => 'tabular data',
		'core/details'      => 'a single expand-and-collapse disclosure',
		'core/search'       => 'a search field',
		'core/social-links' => 'social profile icons, with core/social-link children',
	);

	/**
	 * The JSON shape the reply must take.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'markup', 'summary', 'editable', 'concerns' ),
			'properties'           => array(
				'markup'   => array(
					'type'        => 'string',
					'description' => 'The complete Gutenberg block markup for this section, and nothing else.',
				),
				'summary'  => array(
					'type'        => 'string',
					'description' => 'One sentence describing what this section is, for the person reviewing it.',
				),
				'editable' => array(
					'type'        => 'array',
					'description' => 'Every piece of content the site owner can edit in this section, named the way they would describe it.',
					'items'       => array( 'type' => 'string' ),
				),
				'concerns' => array(
					'type'        => 'array',
					'description' => 'Anything that could not be converted faithfully, or that needs a human decision. Empty when there is nothing to report.',
					'items'       => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * The system prompt, built from the live theme.
	 *
	 * @return string
	 */
	public static function system(): string {
		$lines = array();

		$lines[] = 'You convert one section of a static HTML design into WordPress Gutenberg block markup for the WOW — Signal block theme.';
		$lines[] = '';
		$lines[] = 'The person who will use your output is not a developer. They must be able to change every word, image and link through the WordPress editor without touching code. That constraint outranks visual fidelity: a section that looks 95% right and is fully editable is a success, and a pixel-perfect section that is one frozen lump is a failure.';
		$lines[] = '';

		$lines[] = '## Output rules';
		$lines[] = '';
		$lines[] = '- Return Gutenberg block markup only: HTML comments of the form `<!-- wp:name {json} -->` around the saved HTML, exactly as the WordPress editor writes it. Getting the class names and attribute shapes wrong makes the editor report the block as corrupted, so match what core saves.';
		$lines[] = '- Never use `core/html`. Never emit `<script>`, `<style>`, `<iframe>`, `<form>`, inline `on...` handlers, or `javascript:` URLs. If the design has behaviour you cannot express with the blocks below, describe it in `concerns` instead of reproducing it.';
		$lines[] = '- Keep the design\'s real copy. Do not rewrite, shorten, translate or improve the wording, and do not substitute lorem ipsum.';
		$lines[] = '- Reference images by the exact `src` in the source markup, character for character, including any `../`. Do not rewrite the path, do not invent an ID, and do not drop the image because the file looks missing: the site copies every image out of the archive afterwards and re-points these paths at the real uploads, and it can only do that for paths it recognises. Keep every `alt`; if an image has none, write one that describes it, or use `alt=""` when it is purely decorative.';
		$lines[] = '';

		$lines[] = '## Use the theme\'s design tokens, never literal values';
		$lines[] = '';
		$lines[] = 'Colours, sizes and spacing must come from the presets below, so the site owner can re-theme everything from one screen. Do not write hex colours, pixel font sizes or pixel padding into the markup. If the design uses a colour with no close preset, pick the nearest one and note the difference in `concerns`.';
		$lines[] = '';
		$lines[] = self::presets();
		$lines[] = '';

		$lines[] = '## Blocks you may use';
		$lines[] = '';

		foreach ( self::VOCABULARY as $name => $purpose ) {
			$lines[] = sprintf( '- `%s` — %s', $name, $purpose );
		}

		$theme_blocks = self::theme_blocks();

		if ( array() !== $theme_blocks ) {
			$lines[] = '';
			$lines[] = 'This theme also provides these, and they are strongly preferred over rebuilding the same thing out of core blocks:';
			$lines[] = '';

			foreach ( $theme_blocks as $name => $description ) {
				$lines[] = sprintf( '- `%s` — %s', $name, $description );
			}
		}

		$lines[] = '';
		$lines[] = '## Structure and accessibility';
		$lines[] = '';
		$lines[] = '- Wrap the section in a `core/group` with `"tagName":"section"`. Use `"align":"full"` when the design runs edge to edge.';
		$lines[] = '- Keep the design\'s heading levels unless they are wrong. One `h1` per page — inside a section that is not the page opener, the top heading is `h2`, and cards below it are `h3`. Never skip a level.';
		$lines[] = '- Do not add `aria-label` or `aria-labelledby` to sections. Turning every section into a landmark makes a page harder to navigate with a screen reader, not easier.';
		$lines[] = '- A repeated card, tile or list item becomes a repeated block the owner can duplicate or delete — never one block containing all of them.';
		$lines[] = '- Text that is part of an image in the design becomes real text in the markup.';
		$lines[] = '';

		$lines[] = '## The reply';
		$lines[] = '';
		$lines[] = '`markup` is the block markup. `summary` is one sentence for the reviewer. `editable` lists what the owner can change, in their words — "the headline", "each of the three service cards", "the button text and link". `concerns` is for anything you could not carry across faithfully; leave it empty rather than padding it.';

		return implode( "\n", $lines );
	}

	/**
	 * A compact listing of the presets available on this site.
	 *
	 * @return string
	 */
	private static function presets(): string {
		$settings = array();

		if ( class_exists( WP_Theme_JSON_Resolver::class ) ) {
			$settings = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
		}

		$lines = array();

		$palette = $settings['color']['palette']['theme'] ?? array();

		if ( array() !== $palette ) {
			$parts = array();

			foreach ( $palette as $color ) {
				$parts[] = sprintf( '%s (%s)', $color['slug'], $color['color'] );
			}

			$lines[] = '**Colours** — as `"backgroundColor":"slug"` and `"textColor":"slug"`: ' . implode( ', ', $parts ) . '.';
		}

		$sizes = $settings['typography']['fontSizes']['theme'] ?? array();

		if ( array() !== $sizes ) {
			$lines[] = '**Font sizes** — as `"fontSize":"slug"`: ' . implode( ', ', array_column( $sizes, 'slug' ) ) . '. They are fluid already; do not add your own clamp().';
		}

		$spacing = $settings['spacing']['spacingSizes']['theme'] ?? array();

		if ( array() !== $spacing ) {
			$parts = array();

			foreach ( $spacing as $step ) {
				$parts[] = $step['slug'];
			}

			$lines[] = '**Spacing** — as `"style":{"spacing":{"padding":{"top":"var:preset|spacing|60"}}}`, using these steps: ' . implode( ', ', $parts ) . '.';
		}

		$gradients = $settings['color']['gradients']['theme'] ?? array();

		if ( array() !== $gradients ) {
			$lines[] = '**Gradients** — as `"gradient":"slug"`: ' . implode( ', ', array_column( $gradients, 'slug' ) ) . '.';
		}

		$lines[] = '**Block styles** — add via `"className"`: `is-style-card` and `is-style-panel` on a group, `is-style-cards` on columns, `is-style-checks` on a list, `is-style-glow` on an image, `is-style-gradient` on a heading, `is-style-glow-line` on a separator.';

		return implode( "\n", $lines );
	}

	/**
	 * The theme's own blocks and what each is for.
	 *
	 * @return array<string, string>
	 */
	private static function theme_blocks(): array {
		$blocks = array();

		/*
		 * Read block.json rather than the registry. The registry hands back
		 * descriptions in the site's language — on a Ukrainian site the prompt
		 * would otherwise describe English-language instructions using
		 * Ukrainian block summaries. block.json holds the untranslated source
		 * text, which is what this prompt should be written in.
		 */
		$files = glob( WOW_SIGNAL_DIR . '/blocks/*/block.json' );

		foreach ( $files ? $files : array() as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading the theme's own metadata.

			if ( ! is_array( $meta ) || ! isset( $meta['name'] ) ) {
				continue;
			}

			$name = (string) $meta['name'];

			// Child blocks are documented through their parent.
			if ( isset( $meta['parent'] ) ) {
				continue;
			}

			$blocks[ $name ] = isset( $meta['description'] )
				? (string) $meta['description']
				: (string) ( $meta['title'] ?? $name );
		}

		ksort( $blocks );

		return $blocks;
	}

	/**
	 * The message describing one section to convert.
	 *
	 * @param array<string, mixed> $section Section record from SectionSplitter.
	 * @param string               $css     The CSS slice that applies to it.
	 * @param array<string, mixed> $context Page-level context: title, lang, position.
	 * @return string
	 */
	public static function message( array $section, string $css, array $context = array() ): string {
		$lines = array();

		$page  = isset( $context['page'] ) ? (string) $context['page'] : '';
		$lang  = isset( $context['lang'] ) ? (string) $context['lang'] : '';
		$first = ! empty( $context['is_first'] );

		$lines[] = 'Convert the section below.';
		$lines[] = '';

		if ( '' !== $page ) {
			$lines[] = sprintf( 'It is section %d of the page "%s".', (int) $section['position'] + 1, $page );
		}

		if ( '' !== $lang ) {
			$lines[] = sprintf( 'The page language is "%s" — keep the copy in that language exactly as written.', $lang );
		}

		$lines[] = $first
			? 'This is the first section on the page, so its main heading is the page\'s only `h1`.'
			: 'This is not the first section on the page, so its top heading is an `h2` at most.';

		$lines[] = '';
		$lines[] = '### The section markup';
		$lines[] = '';
		$lines[] = '```html';
		$lines[] = (string) $section['html'];
		$lines[] = '```';

		if ( '' !== trim( $css ) ) {
			$lines[] = '';
			$lines[] = '### The CSS that applies to it';
			$lines[] = '';
			$lines[] = 'Use this to understand the intended layout, hierarchy and emphasis. Translate it into theme presets and block settings — do not copy any of these values literally.';
			$lines[] = '';
			$lines[] = '```css';
			$lines[] = $css;
			$lines[] = '```';
		}

		return implode( "\n", $lines );
	}

	/**
	 * One self-contained brief covering a whole page.
	 *
	 * Used by the subscription route, where the site owner pastes this into
	 * their own Claude conversation instead of the theme calling the API. That
	 * has no system/user split and no structured-output guarantee, so
	 * everything the model needs is inlined here and the reply format is
	 * spelled out rather than enforced by a schema.
	 *
	 * The whole page goes in one brief on purpose: one copy and one paste per
	 * page is something a non-developer will actually do, and section-by-section
	 * would be eight round trips.
	 *
	 * @param array<int, array<string, mixed>> $sections Section records, in order.
	 * @param array<int, string>               $css      CSS slice per section, keyed by position.
	 * @param array<string, mixed>             $context  Page-level context: page, lang.
	 * @return string
	 */
	public static function page_message( array $sections, array $css, array $context = array() ): string {
		$page  = isset( $context['page'] ) ? (string) $context['page'] : '';
		$lang  = isset( $context['lang'] ) ? (string) $context['lang'] : '';
		$total = count( $sections );

		$lines   = array();
		$lines[] = self::system();
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = sprintf( '## Your task: convert %d sections', $total );
		$lines[] = '';

		if ( '' !== $page ) {
			$lines[] = sprintf( 'They are the sections of the page "%s", in the order they appear.', $page );
		}

		if ( '' !== $lang ) {
			$lines[] = sprintf( 'The page language is "%s" — keep every word exactly as written, do not translate.', $lang );
		}

		$lines[] = 'Section 1 carries the page\'s only `h1`. Every later section starts at `h2` or lower.';
		$lines[] = '';
		$lines[] = '## Reply format';
		$lines[] = '';
		$lines[] = sprintf(
			'Reply with a JSON array of exactly %d objects, in the same order as the sections below, and nothing else — no commentary before or after. A fenced ```json block is fine.',
			$total
		);
		$lines[] = '';
		$lines[] = 'Each object has exactly these five keys:';
		$lines[] = '';
		$lines[] = '```json';
		$lines[] = '[';
		$lines[] = '  {';
		$lines[] = '    "section":  1,';
		$lines[] = '    "markup":   "<!-- wp:group ... --> ... <!-- /wp:group -->",';
		$lines[] = '    "summary":  "One sentence describing what this section is.",';
		$lines[] = '    "editable": ["Heading text", "Body copy", "Button label"],';
		$lines[] = '    "concerns": ["Anything that could not be converted faithfully."]';
		$lines[] = '  }';
		$lines[] = ']';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = '`section` is the number in the heading above each section below, counting from 1. Always include it: it is how the site places each result, and it is what lets a reply that ran out of room be continued.';
		$lines[] = '';
		$lines[] = 'Use `"concerns": []` when there is nothing to report. Never leave `markup` empty: if a section defeats you, return the closest honest approximation and say so in `concerns`.';
		$lines[] = '';
		$lines[] = sprintf(
			'If all %d will not fit in one reply, stop at a whole section and say which number you reached. Do not truncate a section\'s markup part-way.',
			$total
		);

		foreach ( $sections as $section ) {
			$position = (int) $section['position'];
			$slice    = isset( $css[ $position ] ) ? (string) $css[ $position ] : '';

			$lines[] = '';
			$lines[] = '---';
			$lines[] = '';
			$lines[] = sprintf(
				'## Section %d of %d — %s',
				$position + 1,
				$total,
				(string) $section['label']
			);
			$lines[] = '';
			$lines[] = '```html';
			$lines[] = (string) $section['html'];
			$lines[] = '```';

			if ( '' !== trim( $slice ) ) {
				$lines[] = '';
				$lines[] = 'CSS that applies to it — read it for layout and emphasis, never copy its values:';
				$lines[] = '';
				$lines[] = '```css';
				$lines[] = $slice;
				$lines[] = '```';
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Pull the section array out of whatever the site owner pasted back.
	 *
	 * People paste generously: a fenced block, a sentence of preamble, the
	 * whole chat turn. Rather than refuse, find the outermost JSON array and
	 * read that. Anything that still will not parse is reported plainly.
	 *
	 * @param string $reply Pasted text.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function parse_reply( string $reply ) {
		$text = trim( $reply );

		if ( '' === $text ) {
			return new WP_Error( 'wow_signal_empty_paste', __( 'Nothing was pasted.', 'wow-signal' ) );
		}

		// Strip a fenced block if the whole reply is wrapped in one.
		if ( 1 === preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $text, $fence ) ) {
			$text = $fence[1];
		}

		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );

		if ( false === $start || false === $end || $end < $start ) {
			return new WP_Error(
				'wow_signal_no_array',
				__( 'That does not look like the JSON list the brief asked for. Copy the whole reply, including the opening [ and closing ].', 'wow-signal' )
			);
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wow_signal_bad_paste',
				__( 'The pasted reply is not valid JSON. It may have been cut off — ask for it again and copy all of it.', 'wow-signal' )
			);
		}

		$sections = array();
		$index    = 0;

		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['markup'] ) ) {
				continue;
			}

			/*
			 * Trust the stated section number over the array index. A reply
			 * that ran out of room and was continued in a second paste starts
			 * its array at zero again, and placing that by index would drop
			 * the continuation on top of the sections already converted.
			 */
			$position = isset( $item['section'] ) && is_numeric( $item['section'] )
				? (int) $item['section'] - 1
				: $index;

			$sections[] = array(
				'position' => max( 0, $position ),
				'markup'   => (string) $item['markup'],
				'summary'  => isset( $item['summary'] ) ? (string) $item['summary'] : '',
				'editable' => isset( $item['editable'] ) && is_array( $item['editable'] ) ? array_map( 'strval', $item['editable'] ) : array(),
				'concerns' => isset( $item['concerns'] ) && is_array( $item['concerns'] ) ? array_map( 'strval', $item['concerns'] ) : array(),
			);

			++$index;
		}

		if ( array() === $sections ) {
			return new WP_Error(
				'wow_signal_no_sections',
				__( 'The pasted list has no sections with markup in it.', 'wow-signal' )
			);
		}

		return $sections;
	}
}
