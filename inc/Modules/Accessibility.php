<?php
/**
 * Accessibility behaviour that markup alone cannot provide.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Skip link, focus management and accessible read-more links.
 */
final class Accessibility implements Module {

	/**
	 * The id of the <main> landmark rendered by every template.
	 *
	 * Kept in sync with the `anchor` on the main group in /templates/*.html.
	 */
	public const MAIN_ID = 'qs-main';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->replace_core_skip_link();

		add_action( 'wp_body_open', array( $this, 'skip_link' ), 1 );
		add_filter( 'excerpt_more', array( $this, 'excerpt_more' ) );
		add_filter( 'the_content_more_link', array( $this, 'content_more_link' ), 10, 2 );
	}

	/**
	 * Print a real, server-rendered skip link as the first focusable element.
	 *
	 * WCAG 2.4.1 (Bypass Blocks). It is plain HTML on purpose — a keyboard user
	 * gets it before any JavaScript has parsed, and it still works if scripts
	 * fail entirely.
	 *
	 * @return void
	 */
	public function skip_link(): void {
		printf(
			'<a class="skip-link" href="#%1$s">%2$s</a>',
			esc_attr( self::MAIN_ID ),
			esc_html__( 'Skip to main content', 'qwerty-soft-signal' )
		);
	}

	/**
	 * Drop the core block-theme skip link in favour of the one above.
	 *
	 * Without this a keyboard user meets two consecutive links to the same
	 * landmark, which is a WCAG 2.4.4 problem rather than a cosmetic one.
	 *
	 * Core decides whether to inject its own link inside
	 * get_the_block_template_html(), which runs before wp_enqueue_scripts, so
	 * the hooks have to come off at theme load rather than on a later action.
	 * Unhooking either one is enough for core to stand down — see
	 * wp-includes/block-template.php.
	 *
	 * @return void
	 */
	public function replace_core_skip_link(): void {
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_block_template_skip_link' );
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
	}

	/**
	 * Replace the bare "…" excerpt ellipsis with a labelled link.
	 *
	 * The visible text stays short while screen readers get the post title, so
	 * a list of links is never a wall of identical "Read more" (WCAG 2.4.4).
	 *
	 * @return string
	 */
	public function excerpt_more(): string {
		return sprintf(
			'&hellip; <a class="qs-read-more" href="%1$s">%2$s<span class="screen-reader-text">: %3$s</span></a>',
			esc_url( (string) get_permalink() ),
			esc_html__( 'Read more', 'qwerty-soft-signal' ),
			esc_html( (string) get_the_title() )
		);
	}

	/**
	 * Same treatment for the <!--more--> tag inside full content.
	 *
	 * @param string $link    Default markup.
	 * @param string $more    Link text.
	 * @return string
	 */
	public function content_more_link( string $link, string $more ): string {
		unset( $link );

		return sprintf(
			'<a class="qs-read-more" href="%1$s">%2$s<span class="screen-reader-text">: %3$s</span></a>',
			esc_url( (string) get_permalink() ),
			esc_html( wp_strip_all_tags( $more ) ),
			esc_html( (string) get_the_title() )
		);
	}
}
