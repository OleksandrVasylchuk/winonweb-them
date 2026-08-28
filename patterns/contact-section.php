<?php
/**
 * Title: Contact — form with direct details
 * Slug: qwerty-soft-signal/contact-section
 * Categories: qs-conversion
 * Description: A two-column contact section: an accessible enquiry form next to direct contact details.
 * Keywords: contact, form, enquiry, email, get in touch
 * Viewport Width: 1400
 *
 * @package Qwerty\Soft
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Contact"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)"><!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|70","left":"var:preset|spacing|70"}}}} -->
<div class="wp-block-columns alignwide"><!-- wp:column {"width":"42%"} -->
<div class="wp-block-column" style="flex-basis:42%"><!-- wp:heading {"level":2,"fontSize":"xx-large"} -->
<h2 class="wp-block-heading has-xx-large-font-size"><?php echo esc_html_x( 'Tell us what you are building', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'You will hear back from one of the two people who would actually do the work, within one working day.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"var:preset|spacing|50"} -->
<div style="height:var(--wp--preset--spacing--50)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:list {"className":"is-style-checks","textColor":"muted","fontSize":"small"} -->
<ul class="wp-block-list is-style-checks has-muted-color has-text-color has-small-font-size"><!-- wp:list-item -->
<li><?php echo esc_html_x( 'A written scope and a fixed number before anything starts', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'No sales call, no discovery workshop invoice', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'We will tell you if your project is not a fit for us', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"58%"} -->
<div class="wp-block-column" style="flex-basis:58%"><!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:qs/contact-form {"consentText":"<?php echo esc_attr_x( 'We use your details only to answer this enquiry and delete them after twelve months.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?>"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
