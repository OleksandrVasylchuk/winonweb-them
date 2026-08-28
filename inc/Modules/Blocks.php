<?php
/**
 * Block registration.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-registers every block in /blocks and adds the theme's block styles.
 *
 * Blocks are plain core-API blocks: a block.json, a render.php, a hand-written
 * editor script and a stylesheet. There is no bundler and no ACF dependency —
 * dropping the theme in wp-content/themes is enough to make them work.
 */
final class Blocks implements Module {

	/**
	 * Directory holding one sub-directory per block.
	 */
	private const BLOCKS_DIR = '/blocks';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'block_type_metadata', array( $this, 'settle_renderer' ) );
		add_action( 'init', array( $this, 'register_block_styles' ) );
		add_filter( 'block_categories_all', array( $this, 'register_category' ) );
	}

	/**
	 * Register every block found in /blocks.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		foreach ( $this->block_dirs() as $dir ) {
			register_block_type( $dir );
		}
	}

	/**
	 * Decide which of a generated block's two renderers actually draws it.
	 *
	 * A block the importer writes names both: `render` so WordPress can draw it
	 * on a site with no ACF, and `acf.renderTemplate` so ACF can draw it with
	 * its fields. Naming both was the intention — and naming both is what made
	 * every imported page come out blank.
	 *
	 * With ACF present, ACF takes the block over and calls whatever callback it
	 * finds. Core's `render` is not a callback that prints; it is a wrapper that
	 * *returns* the markup for core to echo. ACF calls it, ignores the return
	 * value, and renders nothing — every section of every page, silently, with
	 * the content sitting in the database the whole time.
	 *
	 * So exactly one renderer is left standing, chosen by what is installed.
	 *
	 * @param array<string, mixed> $metadata Block metadata as read from block.json.
	 * @return array<string, mixed>
	 */
	public function settle_renderer( array $metadata ): array {
		if ( ! isset( $metadata['acf'] ) ) {
			return $metadata;
		}

		if ( function_exists( 'acf_register_block_type' ) ) {
			unset( $metadata['render'] );

			return $metadata;
		}

		// No ACF: core draws it from the same file, and reads its values from options and meta.
		unset( $metadata['acf'] );

		return $metadata;
	}
	/**
	 * Locate block directories that contain a block.json.
	 *
	 * @return array<int, string>
	 */
	private function block_dirs(): array {
		/*
		 * Two levels, not one. The theme's own blocks sit directly under
		 * `blocks/`; the ones the importer generates sit under
		 * `blocks/design/` so that removing an import is a matter of deleting
		 * one directory and so that a theme update never touches them.
		 *
		 * A single-level glob found none of the second kind, which meant a
		 * generated block was written, was valid, and was invisible.
		 */
		$found = array();

		/*
		 * The generated half is asked for rather than assumed, because it can
		 * be moved: a test run is given a directory of its own so that it can
		 * never write into, or tidy away, a real site's import.
		 */
		foreach ( array( QSOFT_DIR . self::BLOCKS_DIR . '/*/block.json', \Qwerty\Soft\Support\BlockWriter::dir() . '/*/block.json' ) as $pattern ) {
			$matched = glob( $pattern );

			if ( is_array( $matched ) ) {
				$found = array_merge( $found, $matched );
			}
		}

		return array_map( 'dirname', $found );
	}

	/**
	 * Add the "Qwerty Soft" category to the top of the block inserter.
	 *
	 * @param array<int, array<string, mixed>> $categories Registered categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'qs',
				'title' => __( 'Qwerty Soft blocks', 'qwerty-soft-signal' ),
				'icon'  => null,
			)
		);

		return $categories;
	}

	/**
	 * Register block styles that patterns and editors can pick from.
	 *
	 * Each variant carries its own CSS as an inline style attached to that
	 * block's stylesheet. Combined with per-block asset loading, the CSS for a
	 * style ships only on pages where the block actually renders.
	 *
	 * @return void
	 */
	public function register_block_styles(): void {
		foreach ( $this->block_style_definitions() as $style ) {
			register_block_style(
				$style['block'],
				array(
					'name'         => $style['name'],
					'label'        => $style['label'],
					'inline_style' => $style['css'],
				)
			);
		}
	}

	/**
	 * The block style variants and their CSS.
	 *
	 * Every value below resolves to a theme.json token, so re-theming through
	 * the Site Editor updates these variants automatically.
	 *
	 * @return array<int, array{block:string, name:string, label:string, css:string}>
	 */
	private function block_style_definitions(): array {
		return array(
			array(
				'block' => 'core/group',
				'name'  => 'card',
				'label' => __( 'Card', 'qwerty-soft-signal' ),
				'css'   => '.wp-block-group.is-style-card{background:var(--wp--preset--color--surface);border:1px solid var(--wp--preset--color--border);border-radius:var(--wp--custom--radius--lg);padding:var(--wp--preset--spacing--60);box-shadow:var(--wp--preset--shadow--card);height:100%}',
			),
			array(
				'block' => 'core/group',
				'name'  => 'panel',
				'label' => __( 'Glass panel', 'qwerty-soft-signal' ),
				'css'   => '.wp-block-group.is-style-panel{background:color-mix(in srgb, var(--wp--preset--color--surface) 72%, transparent);border:1px solid var(--wp--preset--color--border);border-radius:var(--wp--custom--radius--lg);padding:var(--wp--preset--spacing--60);backdrop-filter:blur(10px);height:100%}',
			),
			array(
				'block' => 'core/columns',
				'name'  => 'cards',
				'label' => __( 'Card columns', 'qwerty-soft-signal' ),
				'css'   => '.wp-block-columns.is-style-cards>.wp-block-column{background:var(--wp--preset--color--surface);border:1px solid var(--wp--preset--color--border);border-radius:var(--wp--custom--radius--lg);padding:var(--wp--preset--spacing--60)}',
			),
			array(
				'block' => 'core/image',
				'name'  => 'glow',
				'label' => __( 'Signal glow', 'qwerty-soft-signal' ),
				'css'   => '.wp-block-image.is-style-glow img{border-radius:var(--wp--custom--radius--lg);box-shadow:var(--wp--preset--shadow--glow)}',
			),
			array(
				'block' => 'core/heading',
				'name'  => 'gradient',
				'label' => __( 'Aurora gradient', 'qwerty-soft-signal' ),

				/*
				 * background-clip:text needs a transparent fill, which would
				 * erase the text in Windows High Contrast Mode — the
				 * forced-colors query puts a real colour back.
				 */
				'css'   => '.wp-block-heading.is-style-gradient{background-image:var(--wp--preset--gradient--aurora);-webkit-background-clip:text;background-clip:text;color:transparent;-webkit-text-fill-color:transparent;padding-bottom:0.08em}@media (forced-colors:active){.wp-block-heading.is-style-gradient{background-image:none;color:CanvasText;-webkit-text-fill-color:CanvasText}}',
			),
			array(
				'block' => 'core/list',
				'name'  => 'checks',
				'label' => __( 'Check list', 'qwerty-soft-signal' ),

				/*
				 * The check glyph is a CSS ::before with empty content, so
				 * screen readers announce a plain list and never read a
				 * decorative character. Logical properties keep it on the
				 * correct side in RTL.
				 */
				'css'   => '.wp-block-list.is-style-checks{list-style:none;padding-inline-start:0}.wp-block-list.is-style-checks>li{position:relative;padding-inline-start:1.9em;margin-block-end:var(--wp--preset--spacing--30)}.wp-block-list.is-style-checks>li::before{content:"";position:absolute;inset-inline-start:0;inset-block-start:0.34em;width:1.05em;height:0.55em;border-left:2px solid var(--wp--preset--color--accent);border-bottom:2px solid var(--wp--preset--color--accent);transform:rotate(-45deg)}@media (forced-colors:active){.wp-block-list.is-style-checks>li::before{border-color:CanvasText}}',
			),
			array(
				'block' => 'core/separator',
				'name'  => 'glow-line',
				'label' => __( 'Signal line', 'qwerty-soft-signal' ),
				'css'   => '.wp-block-separator.is-style-glow-line{height:1px;border:0;background:linear-gradient(90deg,transparent 0%,var(--wp--preset--color--accent) 50%,transparent 100%);opacity:.7;max-width:100%}',
			),
		);
	}
}
