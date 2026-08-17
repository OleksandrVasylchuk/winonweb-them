<?php
/**
 * Title: Case studies — swipeable cards
 * Slug: wow-signal/cases-slider
 * Categories: wow-proof
 * Description: A horizontal slider of case study cards, each with a result, a client and a short summary.
 * Keywords: cases, portfolio, work, slider, carousel, results
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Case studies"},"anchor":"cases","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull" id="cases" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)"><!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"44rem"}} -->
<div class="wp-block-group alignwide"><!-- wp:heading {"level":2,"fontSize":"xx-large"} -->
<h2 class="wp-block-heading has-xx-large-font-size"><?php echo esc_html_x( 'Selected work', 'Pattern placeholder text', 'wow-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'Every project below is measured against what it was before we touched it.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:spacer {"height":"var:preset|spacing|60"} -->
<div style="height:var(--wp--preset--spacing--60)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:wow/slider {"label":"<?php echo esc_attr_x( 'Client case studies', 'Pattern placeholder text', 'wow-signal' ); ?>","align":"wide"} -->
<!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:wow/metric {"value":"41","suffix":"%","label":"<?php echo esc_attr_x( 'more enquiries in the first quarter', 'Pattern placeholder text', 'wow-signal' ); ?>"} /-->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html_x( 'B2B manufacturer, rebuilt', 'Pattern placeholder text', 'wow-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
<p class="has-muted-color has-text-color has-small-font-size"><?php echo esc_html_x( 'Replaced a page builder with a block theme. The site went from 4.8 seconds to 1.6 and the sales team stopped filing tickets to change a phone number.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:wow/metric {"value":"100","label":"<?php echo esc_attr_x( 'accessibility score, up from 61', 'Pattern placeholder text', 'wow-signal' ); ?>"} /-->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html_x( 'Public sector portal', 'Pattern placeholder text', 'wow-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
<p class="has-muted-color has-text-color has-small-font-size"><?php echo esc_html_x( 'A full WCAG 2.2 AA remediation with a written audit trail, delivered ahead of a statutory compliance deadline.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:wow/metric {"value":"3.9","suffix":"x","label":"<?php echo esc_attr_x( 'faster checkout on mobile', 'Pattern placeholder text', 'wow-signal' ); ?>"} /-->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html_x( 'WooCommerce performance rescue', 'Pattern placeholder text', 'wow-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
<p class="has-muted-color has-text-color has-small-font-size"><?php echo esc_html_x( 'Cut eleven plugins, moved the cart to blocks and put the shop assets behind a condition. Same features, a third of the JavaScript.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:wow/metric {"value":"6","label":"<?php echo esc_attr_x( 'weeks from first call to launch', 'Pattern placeholder text', 'wow-signal' ); ?>"} /-->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html_x( 'SaaS marketing site', 'Pattern placeholder text', 'wow-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
<p class="has-muted-color has-text-color has-small-font-size"><?php echo esc_html_x( 'Next.js front end, WordPress editing behind it. Marketing publishes without a deploy, engineering keeps its type safety.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- /wp:wow/slider --></section>
<!-- /wp:group -->
