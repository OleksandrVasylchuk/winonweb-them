<?php
/**
 * Builds the brief that turns component source into the page it renders.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The instructions for reading an application and writing down what it shows.
 *
 * This is deliberately *not* the conversion prompt. Nothing here mentions
 * blocks, the palette or the theme, and that separation is the point: asking
 * for source-to-blocks in one step would mean the model doing two hard jobs at
 * once and the importer losing every check it has. What comes back here is
 * plain HTML, which then goes through exactly the same machinery every other
 * design goes through — the splitter, the structural converter, the CSS index,
 * the validator, the preview. One new step, and the rest of the import is
 * unchanged and still checkable.
 *
 * The other rule worth stating: **nothing may be invented**. A model asked to
 * render a page from its components will happily improve the copy on the way
 * past, and the result is a beautiful site that says things the client never
 * wrote. Every string on the page has to come from the source it was given.
 */
final class SourcePrompt {

	/**
	 * The JSON shape the reply must take.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'html', 'title', 'notes' ),
			'properties'           => array(
				'html'  => array(
					'type'        => 'string',
					'description' => 'The complete <body> content this route renders, as static HTML. No <html>, <head>, <script> or <style> tags.',
				),
				'title' => array(
					'type'        => 'string',
					'description' => 'The page title, taken from the source rather than invented.',
				),
				'notes' => array(
					'type'        => 'array',
					'description' => 'Anything that could not be rendered faithfully: content that only exists after data is fetched, a section whose source was truncated, a decision that had to be made. Empty when there is nothing to report.',
					'items'       => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * The standing instructions.
	 *
	 * @param string $framework react or vue.
	 * @return string
	 */
	public static function system( string $framework ): string {
		$kind = 'vue' === $framework ? 'Vue' : 'React';

		return implode(
			"\n",
			array(
				'You are reading the source of a ' . $kind . ' application and writing down, as static HTML, exactly what one of its routes renders in a browser.',
				'',
				'This HTML is then converted into a WordPress site by a separate step, so it must be the page as a person would see it — not a template, not a description of it, and not an improvement on it.',
				'',
				'Rules, in order of importance:',
				'',
				'1. Invent nothing. Every heading, paragraph, label, button, list item, price, statistic and caption must be copied from the source you are given. Where the source fills text from a data module, use the real records from that module. If a value genuinely is not in the source, leave the element out rather than writing a plausible one.',
				'2. Render the page as it first appears: the default state of every tab, accordion and dialog, with nothing open that opens on a click, and the first item selected where something must be.',
				'3. Where a component maps over a list, render the real items — up to twelve of them, then stop. Say in notes how many there were.',
				'4. Keep the class attributes the source puts on each element, exactly as written. They are how the design\'s stylesheet is read later; a page with the classes rewritten loses its styling.',
				'5. Use semantic, sectioned markup: one <section> per band of the page, <header> for the site header, <footer> for the site footer, <nav> for navigation, real <h1>–<h4>, <ul>/<li> for lists, <table> for tables, <form> with real <label>s for forms. One <h1> only.',
				'6. Images: use the paths from the list of files that exist in this design, written exactly as listed and relative to the design root. Never use a path that is not on that list, never use an external URL, and never use a data: URI. Give every image a real alt attribute. Leave the image out if nothing on the list is the right picture.',
				'7. Links: keep the route paths the source links to (href="/products"), and drop javascript-only handlers. No onclick attributes.',
				'8. No <script>, no <style>, no <html>, no <head>, no framework attributes ({...}, v-if, className as JSX). Write plain HTML with class="…".',
				'9. Text that a browser would show wrapped in an icon-only button, and interface furniture that carries no content (drag handles, spinners, empty states behind a fetch), can be left out. Say so in notes.',
				'',
				'Return the body content of the page and nothing else.',
			)
		);
	}

	/**
	 * The message for one route.
	 *
	 * @param array<string, mixed> $route  Route row from {@see SourceProject::routes()}.
	 * @param array<string, mixed> $bundle Bundle from {@see SourceProject::bundle()}.
	 * @param string               $root   Where the design is unpacked, for a reader that can open files.
	 * @return string
	 */
	public static function message( array $route, array $bundle, string $root = '' ): string {
		$lines = array(
			'# The page to render',
			'',
			'Route: ' . (string) $route['path'],
			'Component: ' . (string) $route['component'] . ' — exported from ' . (string) $route['file'],
			'',
			'Render what that component puts on the screen at that URL, including the layout, header and footer that wrap it.',
			'',
		);

		if ( '' !== $root ) {
			/*
			 * Where the quoted files actually are. Without this the excerpts
			 * are all there is: a reader that wanted the rest of a truncated
			 * catalogue tried the path as written, found nothing relative to
			 * wherever it was started, and reported the data as unavailable.
			 * Paths in this brief are relative to that directory.
			 */
			$lines[] = 'The design is unpacked on this machine at ' . $root . ' and every path below is relative to it.';
			$lines[] = 'If you have a file-reading tool, open the files you need there — especially where an excerpt below says it was truncated. If you have no such tool, work from the excerpts alone and say in notes what that cost.';
			$lines[] = '';
		}

		/*
		 * What the handoff says about itself, before a line of its source is
		 * quoted. A design system states which colours and radii are the
		 * brand's; a route inventory states which pages exist and why; a
		 * content note states which copy is final and which is a placeholder.
		 * Reading the components without any of that is reading a script with
		 * no stage directions.
		 */
		if ( isset( $bundle['docs'] ) && '' !== (string) $bundle['docs'] ) {
			$lines[] = '# What this handoff says about itself';
			$lines[] = '';
			$lines[] = 'Written by whoever prepared the design. Where it contradicts the source, the source is what renders — but these pages tell you what was meant, which copy is final, and how the design is supposed to look.';
			$lines[] = '';
			$lines[] = (string) $bundle['docs'];
			$lines[] = '';
		}

		if ( array() !== $bundle['ui'] ) {
			$lines[] = '# Interface primitives';
			$lines[] = '';
			$lines[] = 'These are the project\'s own copies of standard shadcn/ui primitives and are not quoted here. Render them as the elements they are known to render (Card as a div, Button as a button or an anchor, Accordion as a details/summary pair, Tabs as a set of panels with the first one shown):';
			$lines[] = '';

			foreach ( $bundle['ui'] as $path ) {
				$lines[] = '- ' . $path;
			}

			$lines[] = '';
		}

		$lines[] = '# Source';
		$lines[] = '';

		foreach ( $bundle['files'] as $file ) {
			$lines[] = '## ' . $file['path'] . ( $file['truncated'] ? ' (truncated — only the beginning is shown)' : '' );
			$lines[] = '';
			$lines[] = '```';
			$lines[] = $file['code'];
			$lines[] = '```';
			$lines[] = '';
		}

		if ( array() !== $bundle['styles'] ) {
			$lines[] = '# Stylesheets';
			$lines[] = '';
			$lines[] = 'For reference only — do not copy any of it into the answer. It tells you what the class names mean.';
			$lines[] = '';

			foreach ( $bundle['styles'] as $style ) {
				$lines[] = '## ' . $style['path'];
				$lines[] = '';
				$lines[] = '```css';
				$lines[] = $style['code'];
				$lines[] = '```';
				$lines[] = '';
			}
		}

		if ( array() !== $bundle['images'] ) {
			$lines[] = '# Pictures that exist in this design';
			$lines[] = '';
			$lines[] = 'Use these paths verbatim in src attributes. Anything not on this list does not exist.';
			$lines[] = '';

			foreach ( $bundle['images'] as $path ) {
				$lines[] = '- ' . $path;
			}

			$lines[] = '';
		}

		if ( array() !== $bundle['notes'] ) {
			$lines[] = '# About this brief';
			$lines[] = '';

			foreach ( $bundle['notes'] as $note ) {
				$lines[] = '- ' . $note;
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
