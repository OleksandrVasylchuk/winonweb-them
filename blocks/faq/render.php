<?php
/**
 * Server render for qs/faq.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Rendered qs/faq-item children.
 * @var WP_Block             $block      Block instance.
 */

declare( strict_types = 1 );

use Qwerty\Soft\Support\BlockText;

defined( 'ABSPATH' ) || exit;

if ( '' === trim( $content ) ) {
	return;
}

$qsoft_emit_schema = ! isset( $attributes['emitSchema'] ) || (bool) $attributes['emitSchema'];
$qsoft_exclusive   = isset( $attributes['exclusive'] ) && (bool) $attributes['exclusive'];

$qsoft_classes = array( 'qs-faq' );

if ( $qsoft_exclusive ) {
	$qsoft_classes[] = 'is-exclusive';
}

$qsoft_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $qsoft_classes ),
	)
);

/*
 * Build the FAQPage graph from the parsed children rather than from the
 * rendered HTML: the parsed tree still has the question attribute and the
 * answer blocks separated, so no markup has to be scraped back apart.
 */
$qsoft_schema = array();

if ( $qsoft_emit_schema ) {
	$qsoft_children = isset( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] )
		? $block->parsed_block['innerBlocks']
		: array();

	foreach ( $qsoft_children as $qsoft_child ) {
		if ( ! is_array( $qsoft_child ) || 'qs/faq-item' !== ( $qsoft_child['blockName'] ?? '' ) ) {
			continue;
		}

		$qsoft_question = isset( $qsoft_child['attrs']['question'] )
			? trim( html_entity_decode( wp_strip_all_tags( (string) $qsoft_child['attrs']['question'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) )
			: '';

		$qsoft_answer = isset( $qsoft_child['innerBlocks'] ) && is_array( $qsoft_child['innerBlocks'] )
			? BlockText::from_blocks( $qsoft_child['innerBlocks'] )
			: '';

		if ( '' === $qsoft_question || '' === $qsoft_answer ) {
			continue;
		}

		$qsoft_schema[] = array(
			'@type'          => 'Question',
			'name'           => $qsoft_question,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $qsoft_answer,
			),
		);
	}
}
?>
<div <?php echo $qsoft_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner blocks are already rendered and escaped by the block API. ?>
</div>
<?php
if ( array() !== $qsoft_schema ) {
	/*
	 * wp_json_encode() escapes forward slashes by default, so an editor typing
	 * "</script>" into a question cannot break out of this element.
	 */
	printf(
		'<script type="application/ld+json">%s</script>',
		wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoding escapes the closing-tag sequence.
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $qsoft_schema,
			),
			JSON_UNESCAPED_UNICODE
		)
	);
}
