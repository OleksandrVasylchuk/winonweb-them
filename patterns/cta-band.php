<?php
/**
 * Title: Call to action — full width band
 * Slug: wow-signal/cta-band
 * Categories: wow-conversion
 * Description: A wide, high-contrast band with one headline and one button.
 * Keywords: cta, call to action, banner, conversion, contact
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Call to action"},"align":"full","backgroundColor":"surface-2","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull has-surface-2-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)"><!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"46rem"}} -->
<div class="wp-block-group alignwide"><!-- wp:heading {"level":2,"fontSize":"xxx-large"} -->
<h2 class="wp-block-heading has-xxx-large-font-size"><?php echo esc_html_x( 'Ready to stop losing visitors to a slow site?', 'Pattern placeholder text', 'wow-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'Send us the URL. We will tell you what is wrong with it, for free, before you decide anything.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"var:preset|spacing|50"} -->
<div style="height:var(--wp--preset--spacing--50)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/#wow_signal_contact"><?php echo esc_html_x( 'Get a free review', 'Pattern placeholder text', 'wow-signal' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></section>
<!-- /wp:group -->
