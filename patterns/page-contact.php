<?php
/**
 * Title: Page — contact
 * Slug: wow-signal/page-contact
 * Categories: wow-page
 * Block Types: core/post-content
 * Post Types: page
 * Description: A contact page with an enquiry form, direct details and the questions people ask before getting in touch.
 * Keywords: contact, enquiry, form, full page
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Contact hero"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--40)"><!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"48rem"}} -->
<div class="wp-block-group alignwide"><!-- wp:paragraph {"textColor":"accent","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.14em","fontWeight":"700"}}} -->
<p class="has-accent-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.14em;text-transform:uppercase"><?php echo esc_html_x( 'Contact', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"fontSize":"xxx-large"} -->
<h1 class="wp-block-heading has-xxx-large-font-size"><?php echo esc_html_x( 'Let us look at your project', 'Pattern placeholder text', 'wow-signal' ); ?></h1>
<!-- /wp:heading --></div>
<!-- /wp:group --></section>
<!-- /wp:group -->

<!-- wp:pattern {"slug":"wow-signal/contact-section"} /-->

<!-- wp:pattern {"slug":"wow-signal/faq-section"} /-->
