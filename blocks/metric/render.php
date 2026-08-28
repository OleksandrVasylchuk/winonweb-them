<?php
/**
 * Server render for qs/metric.
 *
 * The finished number is always present in the HTML. The count-up animation
 * is layered on top by view.js and is skipped entirely for visitors who ask
 * for reduced motion, so nobody ever sees a stuck zero.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks (unused).
 * @var WP_Block             $block      Block instance (unused).
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$qsoft_value  = isset( $attributes['value'] ) ? trim( (string) $attributes['value'] ) : '';
$qsoft_prefix = isset( $attributes['prefix'] ) ? (string) $attributes['prefix'] : '';
$qsoft_suffix = isset( $attributes['suffix'] ) ? (string) $attributes['suffix'] : '';
$qsoft_label  = isset( $attributes['label'] ) ? (string) $attributes['label'] : '';
$qsoft_anim   = ! isset( $attributes['animate'] ) || (bool) $attributes['animate'];

if ( '' === $qsoft_value && '' === trim( wp_strip_all_tags( $qsoft_label ) ) ) {
	return;
}

/*
 * Only a plain number can be counted up: digits, optional thousands spaces
 * and an optional dot decimal ("4.9", "150", "12 500"). Anything else —
 * "24/7", "A+", "4,9" — is shown as-is. The same regex lives in view.js and
 * edit.js so PHP, the animation and the editor never disagree about what is
 * countable.
 */
$qsoft_numeric   = 1 === preg_match( '/^\d[\d\s]*(\.\d+)?$/D', $qsoft_value );
$qsoft_countable = $qsoft_anim && $qsoft_numeric && '' !== $qsoft_value;

// The full string a screen reader should hear, in reading order.
$qsoft_spoken = trim( $qsoft_prefix . $qsoft_value . $qsoft_suffix );

$qsoft_wrapper = get_block_wrapper_attributes( array( 'class' => 'qs-metric' ) );
?>
<div <?php echo $qsoft_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<p class="qs-metric__value">
		<?php if ( '' !== $qsoft_spoken ) : ?>
			<span class="screen-reader-text"><?php echo esc_html( $qsoft_spoken ); ?></span>
			<span
				class="qs-metric__figure"
				aria-hidden="true"
				<?php if ( $qsoft_countable ) : ?>
					data-qs-count-to="<?php echo esc_attr( $qsoft_value ); ?>"
				<?php endif; ?>
			>
				<?php if ( '' !== $qsoft_prefix ) : ?>
					<span class="qs-metric__affix"><?php echo esc_html( $qsoft_prefix ); ?></span>
				<?php endif; ?>
				<span class="qs-metric__number"><?php echo esc_html( $qsoft_value ); ?></span>
				<?php if ( '' !== $qsoft_suffix ) : ?>
					<span class="qs-metric__affix"><?php echo esc_html( $qsoft_suffix ); ?></span>
				<?php endif; ?>
			</span>
		<?php endif; ?>
	</p>
	<?php if ( '' !== trim( wp_strip_all_tags( $qsoft_label ) ) ) : ?>
		<p class="qs-metric__label">
			<?php
			echo wp_kses(
				$qsoft_label,
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
