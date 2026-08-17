<?php
/**
 * Server render for wow/metric.
 *
 * The finished number is always present in the HTML. The count-up animation
 * is layered on top by view.js and is skipped entirely for visitors who ask
 * for reduced motion, so nobody ever sees a stuck zero.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks (unused).
 * @var WP_Block             $block      Block instance (unused).
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$wow_value  = isset( $attributes['value'] ) ? trim( (string) $attributes['value'] ) : '';
$wow_prefix = isset( $attributes['prefix'] ) ? (string) $attributes['prefix'] : '';
$wow_suffix = isset( $attributes['suffix'] ) ? (string) $attributes['suffix'] : '';
$wow_label  = isset( $attributes['label'] ) ? (string) $attributes['label'] : '';
$wow_anim   = ! isset( $attributes['animate'] ) || (bool) $attributes['animate'];

if ( '' === $wow_value && '' === trim( wp_strip_all_tags( $wow_label ) ) ) {
	return;
}

/*
 * Only a plain number can be counted up. "4.9" or "150" animate; "24/7" or
 * "A+" are shown as-is, which is why the numeric test drives the data
 * attribute rather than the editor's toggle alone.
 */
$wow_numeric   = is_numeric( str_replace( array( ' ', ',' ), array( '', '.' ), $wow_value ) );
$wow_countable = $wow_anim && $wow_numeric && '' !== $wow_value;

// The full string a screen reader should hear, in reading order.
$wow_spoken = trim( $wow_prefix . $wow_value . $wow_suffix );

$wow_wrapper = get_block_wrapper_attributes( array( 'class' => 'wow-metric' ) );
?>
<div <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<p class="wow-metric__value">
		<?php if ( '' !== $wow_spoken ) : ?>
			<span class="screen-reader-text"><?php echo esc_html( $wow_spoken ); ?></span>
			<span
				class="wow-metric__figure"
				aria-hidden="true"
				<?php if ( $wow_countable ) : ?>
					data-wow-count-to="<?php echo esc_attr( $wow_value ); ?>"
				<?php endif; ?>
			>
				<?php if ( '' !== $wow_prefix ) : ?>
					<span class="wow-metric__affix"><?php echo esc_html( $wow_prefix ); ?></span>
				<?php endif; ?>
				<span class="wow-metric__number"><?php echo esc_html( $wow_value ); ?></span>
				<?php if ( '' !== $wow_suffix ) : ?>
					<span class="wow-metric__affix"><?php echo esc_html( $wow_suffix ); ?></span>
				<?php endif; ?>
			</span>
		<?php endif; ?>
	</p>
	<?php if ( '' !== trim( wp_strip_all_tags( $wow_label ) ) ) : ?>
		<p class="wow-metric__label">
			<?php
			echo wp_kses(
				$wow_label,
				array(
					'strong' => array(),
					'em'     => array(),
					'br'     => array(),
				)
			);
			?>
		</p>
	<?php endif; ?>
</div>
