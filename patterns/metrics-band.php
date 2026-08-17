<?php
/**
 * Title: Metrics — four figures in a row
 * Slug: wow-signal/metrics-band
 * Categories: wow-proof
 * Description: A row of four numbers that count up as the visitor reaches them.
 * Keywords: metrics, numbers, stats, results, kpi, counters
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Metrics"},"align":"full","backgroundColor":"surface","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)"><!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"1.4","suffix":"s","label":"<?php echo esc_attr_x( 'Median Largest Contentful Paint across live client sites', 'Pattern placeholder text', 'wow-signal' ); ?>"} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"0","label":"<?php echo esc_attr_x( 'Cumulative Layout Shift on every template we ship', 'Pattern placeholder text', 'wow-signal' ); ?>","animate":false} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"120","suffix":"+","label":"<?php echo esc_attr_x( 'Projects delivered since 2016', 'Pattern placeholder text', 'wow-signal' ); ?>"} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"24","suffix":"h","label":"<?php echo esc_attr_x( 'Maximum time to a reply from a real developer', 'Pattern placeholder text', 'wow-signal' ); ?>"} /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
