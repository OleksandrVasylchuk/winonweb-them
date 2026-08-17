<?php
/**
 * Server render for wow/faq.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Rendered wow/faq-item children.
 * @var WP_Block             $block      Block instance.
 */

declare( strict_types = 1 );

use Wow\Signal\Support\BlockText;

defined( 'ABSPATH' ) || exit;

if ( '' === trim( $content ) ) {
	return;
}

$wow_emit_schema = ! isset( $attributes['emitSchema'] ) || (bool) $attributes['emitSchema'];
$wow_exclusive   = isset( $attributes['exclusive'] ) && (bool) $attributes['exclusive'];

$wow_classes = array( 'wow-faq' );

if ( $wow_exclusive ) {
	$wow_classes[] = 'is-exclusive';
}

$wow_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wow_classes ),
	)
);

/*
 * Build the FAQPage graph from the parsed children rather than from the
 * rendered HTML: the parsed tree still has the question attribute and the
 * answer blocks separated, so no markup has to be scraped back apart.
 */
$wow_schema = array();

if ( $wow_emit_schema ) {
	$wow_children = isset( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] )
		? $block->parsed_block['innerBlocks']
		: array();

	foreach ( $wow_children as $wow_child ) {
		if ( ! is_array( $wow_child ) || 'wow/faq-item' !== ( $wow_child['blockName'] ?? '' ) ) {
			continue;
		}

		$wow_question = isset( $wow_child['attrs']['question'] )
			? trim( wp_strip_all_tags( (string) $wow_child['attrs']['question'] ) )
			: '';

		$wow_answer = isset( $wow_child['innerBlocks'] ) && is_array( $wow_child['innerBlocks'] )
			? BlockText::from_blocks( $wow_child['innerBlocks'] )
			: '';

		if ( '' === $wow_question || '' === $wow_answer ) {
			continue;
		}

		$wow_schema[] = array(
			'@type'          => 'Question',
			'name'           => $wow_question,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $wow_answer,
			),
		);
	}
}
?>
<div <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner blocks are already rendered and escaped by the block API. ?>
</div>
<?php
if ( array() !== $wow_schema ) {
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
				'mainEntity' => $wow_schema,
			),
			JSON_UNESCAPED_UNICODE
		)
	);
}
