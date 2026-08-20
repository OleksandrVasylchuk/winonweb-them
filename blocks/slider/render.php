<?php
/**
 * Server render for wow/slider.
 *
 * The slider is a scroll-snapping list first and a carousel second. With no
 * JavaScript it is still a fully usable horizontal scroller that keyboard and
 * screen-reader users can reach; view.js only reveals the arrow buttons,
 * which would otherwise be controls that do nothing.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Rendered inner blocks (unused — see below).
 * @var WP_Block             $block      Block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( 0 === count( $block->inner_blocks ) ) {
	return;
}

$wow_label = isset( $attributes['label'] ) ? trim( (string) $attributes['label'] ) : '';
$wow_label = '' !== $wow_label ? $wow_label : __( 'Case studies', 'wow-signal' );

$wow_width = isset( $attributes['slideWidth'] ) ? (string) $attributes['slideWidth'] : 'var(--wp--custom--slider--slide-width)';

/*
 * Only a bare CSS length or one of the theme.json slider width tokens may
 * reach the style attribute. Anything else falls back to the default token.
 */
if (
	1 !== preg_match( '/^\d+(\.\d+)?(rem|em|px|%|vw|ch)$/', $wow_width )
	&& 1 !== preg_match( '/^var\(--wp--custom--slider--slide-width(-narrow|-wide)?\)$/', $wow_width )
) {
	$wow_width = 'var(--wp--custom--slider--slide-width)';
}

$wow_uid = wp_unique_id( 'wow-slider-' );

$wow_wrapper = get_block_wrapper_attributes( array( 'class' => 'wow-slider' ) );
?>
<div
	<?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	style="--wow-slide-width:<?php echo esc_attr( $wow_width ); ?>"
>
	<?php
	/*
	 * aria-roledescription tells assistive technology this is a carousel, and
	 * the region needs a name for that to be meaningful. tabindex="0" makes
	 * the scroll container itself keyboard-scrollable, which browsers do not
	 * do for overflow containers that hold no focusable element.
	 */
	?>
	<div
		class="wow-slider__viewport"
		id="<?php echo esc_attr( $wow_uid ); ?>"
		role="group"
		aria-roledescription="<?php esc_attr_e( 'carousel', 'wow-signal' ); ?>"
		aria-label="<?php echo esc_attr( $wow_label ); ?>"
		tabindex="0"
	>
		<ul class="wow-slider__track" role="list">
			<?php foreach ( $block->inner_blocks as $wow_slide ) : ?>
				<li class="wow-slider__slide">
					<?php echo $wow_slide->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner blocks are rendered and escaped by the block API. ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<?php // Hidden until view.js confirms it can drive them. ?>
	<div class="wow-slider__controls" data-wow-slider-controls hidden>
		<button
			type="button"
			class="wow-slider__button"
			data-wow-slider-prev
			aria-controls="<?php echo esc_attr( $wow_uid ); ?>"
		>
			<span class="screen-reader-text"><?php esc_html_e( 'Previous cases', 'wow-signal' ); ?></span>
			<span class="wow-slider__arrow wow-slider__arrow--prev" aria-hidden="true"></span>
		</button>
		<button
			type="button"
			class="wow-slider__button"
			data-wow-slider-next
			aria-controls="<?php echo esc_attr( $wow_uid ); ?>"
		>
			<span class="screen-reader-text"><?php esc_html_e( 'Next cases', 'wow-signal' ); ?></span>
			<span class="wow-slider__arrow wow-slider__arrow--next" aria-hidden="true"></span>
		</button>
	</div>
</div>
