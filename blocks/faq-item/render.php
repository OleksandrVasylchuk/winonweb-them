<?php
/**
 * Server render for qs/faq-item.
 *
 * Uses the native <details>/<summary> pair rather than a scripted accordion.
 * That gives keyboard operation, screen-reader state announcements and
 * in-page find-on-page expansion for free, and it still works if the
 * theme's JavaScript never loads.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Rendered answer blocks.
 * @var WP_Block             $block      Block instance (unused).
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$qsoft_question = isset( $attributes['question'] ) ? (string) $attributes['question'] : '';
$qsoft_open     = isset( $attributes['startOpen'] ) && (bool) $attributes['startOpen'];

if ( '' === trim( wp_strip_all_tags( $qsoft_question ) ) ) {
	return;
}

$qsoft_wrapper = get_block_wrapper_attributes( array( 'class' => 'qs-faq__item' ) );

// Inline formatting an editor can apply to the question with the toolbar.
$qsoft_allowed_inline = array(
	'strong' => array(),
	'em'     => array(),
	'code'   => array(),
	'span'   => array( 'class' => array() ),
);
?>
<details <?php echo $qsoft_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?><?php echo $qsoft_open ? ' open' : ''; ?>>
	<summary class="qs-faq__question">
		<span class="qs-faq__question-text"><?php echo wp_kses( $qsoft_question, $qsoft_allowed_inline ); ?></span>
		<span class="qs-faq__marker" aria-hidden="true"></span>
	</summary>
	<div class="qs-faq__answer">
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner blocks are rendered and escaped by the block API. ?>
	</div>
</details>
