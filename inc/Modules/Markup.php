<?php
/**
 * Corrections to markup produced by core blocks.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use WP_HTML_Tag_Processor;
use Wow\Signal\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Small, surgical fixes to core block output.
 *
 * Each one was found by validating the theme's rendered pages. They are done
 * with the HTML API rather than string replacement so malformed input can
 * never be produced, and every one is a no-op if core changes its markup.
 */
final class Markup implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'render_block', array( $this, 'refine_navigation' ), 10, 2 );
		add_filter( 'comment_form_defaults', array( $this, 'comment_form_defaults' ) );
		add_filter( 'comment_form_submit_button', array( $this, 'comment_submit_button' ) );
	}

	/**
	 * Tidy the navigation block's responsive markup.
	 *
	 * Two things core leaves behind:
	 *
	 * 1. The overlay open and close controls are <button> elements with no
	 *    `type`. Outside a form that defaults to submit harmlessly, but it
	 *    becomes a real bug the moment a navigation sits inside one.
	 * 2. The aria-label an editor sets on the navigation is written onto both
	 *    the <nav> landmark and the container <ul> inside it. The landmark is
	 *    the one that should carry the name; on the list it is a second,
	 *    duplicate accessible name for the same thing.
	 *
	 * @param string               $block_content Rendered block HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 * @return string
	 */
	public function refine_navigation( string $block_content, array $block ): string {
		if ( 'core/navigation' !== ( $block['blockName'] ?? '' ) || '' === trim( $block_content ) ) {
			return $block_content;
		}

		$processor = new WP_HTML_Tag_Processor( $block_content );

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( 'BUTTON' === $tag && null === $processor->get_attribute( 'type' ) ) {
				$processor->set_attribute( 'type', 'button' );
				continue;
			}

			if (
				'UL' === $tag
				&& $processor->has_class( 'wp-block-navigation__container' )
				&& null !== $processor->get_attribute( 'aria-label' )
			) {
				$processor->remove_attribute( 'aria-label' );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Submit comments with a <button> rather than <input type="submit">.
	 *
	 * A button can hold markup and inherits the theme's button element styles,
	 * so the comment form stops being the one control on the site that looks
	 * like it belongs to a different design.
	 *
	 * @param array<string, mixed> $defaults Comment form defaults.
	 * @return array<string, mixed>
	 */
	public function comment_form_defaults( array $defaults ): array {
		$defaults['submit_button'] = '<button name="%1$s" type="submit" id="%2$s" class="%3$s wp-element-button">%4$s</button>';

		return $defaults;
	}

	/**
	 * Convert the comment submit control from <input> to <button>.
	 *
	 * The core/post-comments-form block passes its own `submit_button` in the
	 * argument array, and arguments beat defaults — so filtering the defaults
	 * alone never reaches the block. This filter sees the finished markup,
	 * which is the only place both paths meet.
	 *
	 * @param string $button Rendered submit control.
	 * @return string
	 */
	public function comment_submit_button( string $button ): string {
		$processor = new WP_HTML_Tag_Processor( $button );

		if ( ! $processor->next_tag( array( 'tag_name' => 'INPUT' ) ) ) {
			// Already a <button>, or markup we do not recognise: leave it be.
			return $button;
		}

		return sprintf(
			'<button type="submit" name="%1$s" id="%2$s" class="%3$s">%4$s</button>',
			esc_attr( (string) $processor->get_attribute( 'name' ) ),
			esc_attr( (string) $processor->get_attribute( 'id' ) ),
			esc_attr( (string) $processor->get_attribute( 'class' ) ),
			esc_html( (string) $processor->get_attribute( 'value' ) )
		);
	}
}
