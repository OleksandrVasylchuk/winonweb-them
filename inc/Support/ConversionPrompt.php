<?php
/**
 * Builds the instructions that turn a design section into block markup.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

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
	 * The JSON shape a guided conversion must take.
	 *
	 * Same four keys as {@see self::schema()}, plus the one thing only a
	 * guided conversion can report: what it changed about the structural
	 * conversion it was given, and why. That list is what the import report
	 * shows when somebody asks what the model actually did for the money.
	 *
	 * @return array<string, mixed>
	 */
	public static function guided_schema(): array {
		$schema = self::schema();

		$schema['required'][] = 'changed';

		$schema['properties']['changed'] = array(
			'type'        => 'array',
			'description' => 'Each fix you made to the structural conversion you were given, in one short phrase — "made the five cards a five-column grid instead of a stack". Empty when the structural conversion was already right and you returned it unchanged.',
			'items'       => array( 'type' => 'string' ),
		);

		return $schema;
	}

	/**
	 * The system prompt, built from the live theme.
	 *
	 * @param bool $guided Whether the model is being handed a structural
	 *                     conversion, a resolved-CSS brief and screenshots to
	 *                     work from, rather than the raw section alone.
	 * @return string
	 */
	public static function system( bool $guided = false ): string {
		$lines = array();

		$lines[] = 'You convert one section of a static HTML design into WordPress Gutenberg block markup for the Qwerty Soft — Signal block theme.';
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

		if ( $guided ) {
			$lines   = array_merge( $lines, self::guidance() );
			$lines[] = '';
		}

		$lines[] = '## The reply';
		$lines[] = '';
		$lines[] = '`markup` is the block markup. `summary` is one sentence for the reviewer. `editable` lists what the owner can change, in their words — "the headline", "each of the three service cards", "the button text and link". `concerns` is for anything you could not carry across faithfully; leave it empty rather than padding it.';

		if ( $guided ) {
			$lines[] = '';
			$lines[] = '`changed` lists what you fixed about the structural conversion, one short phrase each. An empty list means you judged it already correct and returned it as it was — which is a perfectly good answer and happens often.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * How to use the material a guided conversion is handed.
	 *
	 * The structural converter in this theme is good at the mechanical part —
	 * headings, lists, buttons, images, the design's own class names and
	 * colours — and blind in exactly one place: it decides what a container
	 * *means*. Its known failure is reading a wrapper as a stack when the
	 * design's CSS made it a grid, and the reverse. That is what the resolved
	 * CSS and the screenshot are for, and why the instruction below is to fix
	 * that class of thing rather than to rewrite from scratch: a rewrite loses
	 * the parts that were already right and costs several times as much.
	 *
	 * @return array<int, string>
	 */
	private static function guidance(): array {
		$lines = array();

		$lines[] = '## What you are given, and what to do with it';
		$lines[] = '';
		$lines[] = 'You are not converting from nothing. Along with the section\'s HTML you get:';
		$lines[] = '';
		$lines[] = '1. **A structural conversion** — block markup this theme produced from the same section mechanically. It is usually close. Its headings, copy, links, image paths, class names and colours are reliable and were taken from the design itself.';
		$lines[] = '2. **A resolved-CSS brief** — what the design\'s stylesheets actually compute to for each element, with the cascade already run and every `var()` substituted. `grid: 1.06fr .72fr` in that brief is a fact about the design, not a guess.';
		$lines[] = '3. **A screenshot**, when the archive had one — the finished design as a person sees it.';
		$lines[] = '';
		$lines[] = '**Start from the structural conversion and correct it.** Return it unchanged when it is right. Do not rebuild a section that needs one attribute moved, and never replace its copy, links or image paths with your own reading of the HTML — those are already correct, and re-typing them is how a name or a URL quietly changes.';
		$lines[] = '';
		$lines[] = 'What actually goes wrong, in the order it is worth checking:';
		$lines[] = '';
		$lines[] = '- **Columns read as a stack, or a stack read as columns.** The brief settles it. `display:grid` with three tracks is `core/columns` with three `core/column` children, whatever the HTML nesting suggests; `display:block` is a stack even when the children look like cards. Uneven tracks (`1.06fr .72fr`) become column widths in the same proportion — roughly 60% and 40% — not two equal halves.';
		$lines[] = '- **A container that carries the design\'s look.** When the brief gives an element a background, a radius, a border or a shadow, that treatment belongs on the block it became — not on a fresh wrapper group around it, which doubles the padding and draws the card twice.';
		$lines[] = '- **Emphasis lost.** The brief\'s font sizes and weights say which heading dominates. Map them onto the theme\'s preset scale, keeping the design\'s order of emphasis even when the exact size falls between two presets.';
		$lines[] = '- **Something the blocks cannot hold.** An element the brief marks `absolute-positioned`, or `driven by JavaScript`, has no block equivalent. Put its content in the flow where it belongs and say what was lost in `concerns`. Do not approximate it with spacers and negative margins.';
		$lines[] = '';
		$lines[] = 'The structural conversion is not authoritative about any of that. Where it and the brief disagree, the brief is right.';

		return $lines;
	}

	/**
	 * The message for a guided conversion of one section.
	 *
	 * @param array<string, mixed> $section Section record from SectionSplitter.
	 * @param array<string, mixed> $given   baseline markup, facts brief, css slice, shot flag.
	 * @param array<string, mixed> $context Page-level context: page, lang, is_first.
	 * @return string
	 */
	public static function brief( array $section, array $given, array $context = array() ): string {
		$page     = isset( $context['page'] ) ? (string) $context['page'] : '';
		$lang     = isset( $context['lang'] ) ? (string) $context['lang'] : '';
		$first    = ! empty( $context['is_first'] );
		$baseline = isset( $given['baseline'] ) ? (string) $given['baseline'] : '';
		$facts    = isset( $given['facts'] ) ? (string) $given['facts'] : '';
		$css      = isset( $given['css'] ) ? (string) $given['css'] : '';

		$lines = array();

		$lines[] = 'Convert the section below, starting from the structural conversion further down.';
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

		$docs = isset( $context['docs'] ) ? trim( (string) $context['docs'] ) : '';

		if ( '' !== $docs ) {
			$lines[] = '';
			$lines[] = '### What the design says about itself';
			$lines[] = '';
			$lines[] = 'Its own documentation, quoted in part. Where it contradicts the markup, the markup is what the page shows — but this is where the brand colours, the intent of a page and the status of its copy are written down.';
			$lines[] = '';
			$lines[] = $docs;
		}

		$lines = array_merge( $lines, self::pictures( $given, (int) $section['position'] + 1 ) );

		$lines[] = '';
		$lines[] = '### The section markup';
		$lines[] = '';
		$lines[] = '```html';
		$lines[] = (string) $section['html'];
		$lines[] = '```';

		if ( '' !== trim( $facts ) ) {
			$lines[] = '';
			$lines[] = '### What the design\'s CSS resolves to';
			$lines[] = '';
			$lines[] = 'The cascade has already been run for you. Every value here is what that element computes to at desktop width, with `var()` substituted. Indentation follows the nesting of the section.';
			$lines[] = '';
			$lines[] = '```';
			$lines[] = $facts;
			$lines[] = '```';
		}

		if ( '' !== trim( $baseline ) ) {
			$lines[] = '';
			$lines[] = '### The structural conversion to correct';
			$lines[] = '';
			$lines[] = '```html';
			$lines[] = $baseline;
			$lines[] = '```';
		} else {
			$lines[] = '';
			$lines[] = '### The structural conversion';
			$lines[] = '';
			$lines[] = 'It produced nothing usable for this section, so build the markup yourself from the section and the brief above.';
		}

		/*
		 * The raw stylesheet is a fallback for a section no brief could be
		 * made for — not a companion to one. Sending both spends the context
		 * window twice on the same facts, and invites the model to re-run a
		 * cascade that has already been run for it.
		 */
		if ( '' === trim( $facts ) && '' !== trim( $css ) ) {
			$lines[] = '';
			$lines[] = '### The CSS that applies to it';
			$lines[] = '';
			$lines[] = 'Read it for layout, hierarchy and emphasis, and translate it into theme presets — never copy its values literally.';
			$lines[] = '';
			$lines[] = '```css';
			$lines[] = $css;
			$lines[] = '```';
		}

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

		/*
		 * The rule that was missing, and its absence was measurable.
		 *
		 * `BlockConverter::faithful()` is on by default and keeps the design's
		 * own values, but the flag never reached this brief — so the offline
		 * pass preserved the archive's class names and the model, told only
		 * about the theme's presets, dropped them again. On the Robert
		 * Khoubian handoff that cost 190 of the 253 class names the design's
		 * stylesheet targets: the rules shipped, the classes did not, and the
		 * header and hero rendered as neither the design nor the theme.
		 *
		 * It also explains the cost. The review pass compares the result with
		 * the original, sees a section that no longer matches, corrects it,
		 * and still does not match — so it runs every round it is allowed
		 * instead of agreeing on the first. The brief was arguing with the
		 * reviewer, and the build paid for the argument.
		 */
		if ( BlockConverter::faithful() ) {
			$lines[] = '**Keep the design\'s own class names.** Every `class` attribute in the source belongs on the block you make from it, spelled exactly as it appears, in addition to anything above. The archive\'s stylesheet ships with the page and targets those names; a class you drop is a rule that stops applying, and the section renders unstyled. Never rename, shorten, merge or tidy them.';
		}

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
		$files = glob( QSOFT_DIR . '/blocks/*/block.json' );

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

		/*
		 * What the handoff wrote about itself, in a few hundred words. The
		 * whole documentation would be most of the window and this is one
		 * section of one page — but a design system that names the brand
		 * colours, or a content note that says which copy is a placeholder,
		 * changes the answer for every section and is worth its room.
		 */
		$docs = isset( $context['docs'] ) ? trim( (string) $context['docs'] ) : '';

		if ( '' !== $docs ) {
			$lines[] = '';
			$lines[] = '### What the design says about itself';
			$lines[] = '';
			$lines[] = 'Its own documentation, quoted in part. Where it contradicts the markup, the markup is what the page shows.';
			$lines[] = '';
			$lines[] = $docs;
		}

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
	 * Say what the attached pictures are, when there are any.
	 *
	 * Two different things arrive as images and they are read completely
	 * differently. A close-up is the section's own visual — a chart the design
	 * drew with JavaScript, which the markup cannot describe at all — and it
	 * is the authority on what that element looks like. A page screenshot is
	 * context: the section is somewhere inside it, and the model has to find
	 * it rather than describe the whole page. Left unlabelled, a model handed
	 * both will happily convert the wrong one.
	 *
	 * @param array<string, mixed> $given    Brief material: shot, closeups.
	 * @param int                  $position Human-facing section number.
	 * @return array<int, string>
	 */
	private static function pictures( array $given, int $position ): array {
		$closeups = isset( $given['closeups'] ) ? (int) $given['closeups'] : 0;
		$shot     = ! empty( $given['shot'] );

		if ( 0 === $closeups && ! $shot ) {
			return array();
		}

		$lines   = array();
		$lines[] = '';

		if ( $closeups > 0 ) {
			$lines[] = sprintf(
				'The first %s a close-up of a visual inside this section — something the design drew with JavaScript, which the markup below cannot describe. It already appears in the markup as an `<img>`: keep that image exactly where it is, and use the picture to write an `alt` that says what it actually shows.',
				1 === $closeups ? 'attached image is' : 'two attached images are each'
			);
		}

		if ( $shot ) {
			$lines[] = sprintf(
				'A screenshot of the whole finished page is attached%s. Section %d is one part of it — find that part by its heading and copy, and read its spacing and emphasis against the rest of the page. Do not convert anything that is not in the markup below.',
				$closeups > 0 ? ' after those' : '',
				$position
			);
		}

		return $lines;
	}

	/**
	 * Ask again, with what was wrong with the last answer.
	 *
	 * The whole original brief is repeated rather than referred back to. It
	 * costs prompt tokens that are almost entirely cache hits by now, and it
	 * removes the failure mode where a model, handed only its own broken
	 * output and a complaint, fixes the syntax while quietly losing half the
	 * copy — because the section it was converting is no longer in front of it.
	 *
	 * @param string $brief    The brief that was sent the first time.
	 * @param string $returned The markup that came back and failed.
	 * @param string $problem  What the validator objected to.
	 * @return string
	 */
	public static function correction( string $brief, string $returned, string $problem ): string {
		$lines = array();

		$lines[] = 'Your last answer to this could not be used. Fix it and return the whole section again.';
		$lines[] = '';
		$lines[] = '### What was wrong';
		$lines[] = '';
		$lines[] = $problem;
		$lines[] = '';
		$lines[] = 'Change only what that complaint is about. The layout decisions in your answer were accepted — do not reconsider them, do not rewrite the copy, and do not drop anything to make the problem go away. Block settings are JSON inside the `<!-- wp:name { … } -->` comment: every brace and bracket paired, keys and string values in double quotes, no trailing commas, and no comment or ellipsis anywhere inside them.';
		$lines[] = '';
		$lines[] = '### What you returned';
		$lines[] = '';
		$lines[] = '```html';
		$lines[] = '' !== trim( $returned ) ? $returned : '(nothing)';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '### The original task, unchanged';
		$lines[] = '';
		$lines[] = $brief;

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
			return new WP_Error( 'qwerty_soft_empty_paste', __( 'Nothing was pasted.', 'qwerty-soft-signal' ) );
		}

		// Strip a fenced block if the whole reply is wrapped in one.
		if ( 1 === preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $text, $fence ) ) {
			$text = $fence[1];
		}

		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );

		if ( false === $start || false === $end || $end < $start ) {
			return new WP_Error(
				'qwerty_soft_no_array',
				__( 'That does not look like the JSON list the brief asked for. Copy the whole reply, including the opening [ and closing ].', 'qwerty-soft-signal' )
			);
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'qwerty_soft_bad_paste',
				__( 'The pasted reply is not valid JSON. It may have been cut off — ask for it again and copy all of it.', 'qwerty-soft-signal' )
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
				'qwerty_soft_no_sections',
				__( 'The pasted list has no sections with markup in it.', 'qwerty-soft-signal' )
			);
		}

		return $sections;
	}
}
