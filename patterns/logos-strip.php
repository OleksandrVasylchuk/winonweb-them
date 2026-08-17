<?php
/**
 * Title: Client logos — quiet strip
 * Slug: wow-signal/logos-strip
 * Categories: wow-proof
 * Description: A low-key row of client names or logos under the hero. Replace the text with images once you have permission to use them.
 * Keywords: logos, clients, brands, trust, partners
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Client logos"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:heading {"level":2,"textColor":"muted","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.14em","fontWeight":"700"}}} -->
<h2 class="wp-block-heading has-muted-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.14em;text-transform:uppercase"><?php echo esc_html_x( 'Trusted by teams at', 'Pattern placeholder text', 'wow-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|40"} -->
<div style="height:var(--wp--preset--spacing--40)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:group {"align":"wide","className":"wow-logos","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group alignwide wow-logos"><!-- wp:paragraph {"textColor":"muted","fontSize":"large","style":{"typography":{"fontWeight":"700"}}} -->
<p class="has-muted-color has-text-color has-large-font-size" style="font-weight:700"><?php echo esc_html_x( 'Northwind', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large","style":{"typography":{"fontWeight":"700"}}} -->
<p class="has-muted-color has-text-color has-large-font-size" style="font-weight:700"><?php echo esc_html_x( 'Kraftverk', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large","style":{"typography":{"fontWeight":"700"}}} -->
<p class="has-muted-color has-text-color has-large-font-size" style="font-weight:700"><?php echo esc_html_x( 'Meridian', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large","style":{"typography":{"fontWeight":"700"}}} -->
<p class="has-muted-color has-text-color has-large-font-size" style="font-weight:700"><?php echo esc_html_x( 'Volta Labs', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large","style":{"typography":{"fontWeight":"700"}}} -->
<p class="has-muted-color has-text-color has-large-font-size" style="font-weight:700"><?php echo esc_html_x( 'Aurora Health', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></section>
<!-- /wp:group -->
