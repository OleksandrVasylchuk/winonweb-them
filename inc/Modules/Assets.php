<?php
/**
 * Asset loading strategy.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

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
	 * Font subsets that exist on disk, keyed by slug.
	 *
	 * The Latin subset covers digits, punctuation and the Latin alphabet, so
	 * it is needed on every page. The others are fetched by the browser via
	 * unicode-range only when a glyph in that range is used — which for a
	 * Cyrillic-locale site is the very first word, so those sites preload it
	 * too rather than discover it after layout.
	 */
	private const FONT_SUBSETS = array(
		'latin'     => '/assets/fonts/manrope-latin.woff2',
		'latin-ext' => '/assets/fonts/manrope-latin-ext.woff2',
		'cyrillic'  => '/assets/fonts/manrope-cyrillic.woff2',
	);

	/**
	 * Locale prefixes whose body copy is set in Cyrillic.
	 */
	private const CYRILLIC_LOCALES = array( 'uk', 'ru', 'bg', 'sr', 'be', 'mk', 'kk' );

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
	 * Preload the font subsets the locale needs so the first paint is not a swap.
	 *
	 * @return void
	 */
	public function preload_font(): void {
		foreach ( $this->preload_subsets() as $slug ) {
			printf(
				'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin="anonymous">' . "\n",
				esc_url( QSOFT_URI . self::FONT_SUBSETS[ $slug ] )
			);
		}
	}

	/**
	 * Which subsets to preload: Latin always, Cyrillic for Cyrillic locales.
	 *
	 * @return array<int, string> Subset slugs, each a key of FONT_SUBSETS.
	 */
	private function preload_subsets(): array {
		$subsets = array( 'latin' );
		$lang    = strtolower( substr( (string) get_locale(), 0, 2 ) );

		if ( in_array( $lang, self::CYRILLIC_LOCALES, true ) ) {
			$subsets[] = 'cyrillic';
		}

		/**
		 * Filter the font subsets preloaded in the head.
		 *
		 * Slugs: 'latin', 'latin-ext', 'cyrillic'. Return an empty array to
		 * preload nothing.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $subsets Subset slugs, in output order.
		 */
		$subsets = (array) apply_filters( 'qwerty_soft/preload_fonts', $subsets );

		$known = array_filter(
			$subsets,
			static fn( $slug ): bool => is_string( $slug ) && isset( self::FONT_SUBSETS[ $slug ] )
		);

		return array_values( array_unique( $known ) );
	}
}
