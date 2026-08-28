<?php
/**
 * Server render for qs/slider.
 *
 * The slider is a scroll-snapping list first and a carousel second. With no
 * JavaScript it is still a fully usable horizontal scroller that keyboard and
 * screen-reader users can reach; view.js only reveals the arrow buttons,
 * which would otherwise be controls that do nothing.
 *
 * @package Qwerty\Soft
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

$qsoft_label = isset( $attributes['label'] ) ? trim( (string) $attributes['label'] ) : '';
$qsoft_label = '' !== $qsoft_label ? $qsoft_label : __( 'Case studies', 'qwerty-soft-signal' );

$qsoft_width = isset( $attributes['slideWidth'] ) ? (string) $attributes['slideWidth'] : 'var(--wp--custom--slider--slide-width)';

/*
 * Only a bare CSS length or one of the theme.json slider width tokens may
 * reach the style attribute. Anything else falls back to the default token.
 */
if (
	1 !== preg_match( '/^\d+(\.\d+)?(rem|em|px|%|vw|ch)$/', $qsoft_width )
	&& 1 !== preg_match( '/^var\(--wp--custom--slider--slide-width(-narrow|-wide)?\)$/', $qsoft_width )
) {
	$qsoft_width = 'var(--wp--custom--slider--slide-width)';
}

$qsoft_uid = wp_unique_id( 'qs-slider-' );

$qsoft_wrapper = get_block_wrapper_attributes( array( 'class' => 'qs-slider' ) );
?>
<div
	<?php echo $qsoft_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	style="--qs-slide-width:<?php echo esc_attr( $qsoft_width ); ?>"
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
		class="qs-slider__viewport"
		id="<?php echo esc_attr( $qsoft_uid ); ?>"
		role="group"
		aria-roledescription="<?php esc_attr_e( 'carousel', 'qwerty-soft-signal' ); ?>"
		aria-label="<?php echo esc_attr( $qsoft_label ); ?>"
		tabindex="0"
	>
		<ul class="qs-slider__track" role="list">
			<?php foreach ( $block->inner_blocks as $qsoft_slide ) : ?>
				<li class="qs-slider__slide">
					<?php echo $qsoft_slide->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner blocks are rendered and escaped by the block API. ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<?php // Hidden until view.js confirms it can drive them. ?>
	<div class="qs-slider__controls" data-qs-slider-controls hidden>
		<button
			type="button"
			class="qs-slider__button"
			data-qs-slider-prev
			aria-controls="<?php echo esc_attr( $qsoft_uid ); ?>"
		>
			<span class="screen-reader-text"><?php esc_html_e( 'Previous cases', 'qwerty-soft-signal' ); ?></span>
			<span class="qs-slider__arrow qs-slider__arrow--prev" aria-hidden="true"></span>
		</button>
		<button
			type="button"
			class="qs-slider__button"
			data-qs-slider-next
			aria-controls="<?php echo esc_attr( $qsoft_uid ); ?>"
		>
			<span class="screen-reader-text"><?php esc_html_e( 'Next cases', 'qwerty-soft-signal' ); ?></span>
			<span class="qs-slider__arrow qs-slider__arrow--next" aria-hidden="true"></span>
		</button>
	</div>
</div>
