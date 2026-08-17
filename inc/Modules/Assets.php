<?php
/**
 * Asset loading strategy.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the critical path as short as possible.
 *
 * The theme ships no global stylesheet, and there is nothing to load
 * asynchronously because nothing render-blocking is ever added:
 *
 * - everything above the fold is a theme.json token or a rule in theme.json's
 *   `styles.css` key, and WordPress inlines both into the single global-styles
 *   <style> element the page already carries;
 * - per-block CSS is declared in block.json, so a block that never renders
 *   never costs a byte, and core's wp_maybe_inline_styles() inlines what is
 *   left because each handle carries a `path`;
 * - the only external asset in the critical path is one 25 KB woff2 subset,
 *   and that is preloaded rather than discovered late.
 *
 * The one thing PHP still has to do is issue that preload.
 */
final class Assets implements Module {

	/**
	 * Font file preloaded for the first paint.
	 *
	 * Only the Latin subset is preloaded: it covers digits, punctuation and the
	 * Latin alphabet, so it is needed on every page. The Latin-Extended and
	 * Cyrillic subsets are fetched lazily by the browser via unicode-range,
	 * only when a glyph in that range is actually used.
	 */
	private const PRELOAD_FONT = '/assets/fonts/manrope-latin.woff2';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		// One stylesheet per rendered block instead of one giant core bundle.
		add_filter( 'should_load_separate_core_block_assets', '__return_true' );

		// Ship only the block JS a page actually needs.
		add_filter( 'should_load_block_assets_on_demand', '__return_true' );

		add_action( 'wp_head', array( $this, 'preload_font' ), 2 );
	}

	/**
	 * Preload the Latin font subset so the first paint is not a swap.
	 *
	 * @return void
	 */
	public function preload_font(): void {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin="anonymous">' . "\n",
			esc_url( WOW_SIGNAL_URI . self::PRELOAD_FONT )
		);
	}
}
