<?php
/**
 * Title: FAQ — accordion with structured data
 * Slug: wow-signal/faq-section
 * Categories: wow-conversion
 * Description: A heading and a set of questions that expand and collapse, ready to be picked up by search engines.
 * Keywords: faq, questions, accordion, answers, support
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"FAQ"},"anchor":"faq","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull" id="faq" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)"><!-- wp:heading {"level":2,"fontSize":"xx-large"} -->
<h2 class="wp-block-heading has-xx-large-font-size"><?php echo esc_html_x( 'Questions we get asked', 'Pattern placeholder text', 'wow-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|60"} -->
<div style="height:var(--wp--preset--spacing--60)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:wow/faq -->
<!-- wp:wow/faq-item {"question":"<?php echo esc_attr_x( 'How long does a site take?', 'Pattern placeholder text', 'wow-signal' ); ?>","startOpen":true} -->
<!-- wp:paragraph -->
<p><?php echo esc_html_x( 'A marketing site is usually four to eight weeks from the scope call. A rebuild with content migration runs longer, and we tell you which one you are in before you commit to anything.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:wow/faq-item -->

<!-- wp:wow/faq-item {"question":"<?php echo esc_attr_x( 'Do you use page builders?', 'Pattern placeholder text', 'wow-signal' ); ?>"} -->
<!-- wp:paragraph -->
<p><?php echo esc_html_x( 'No. We build block themes on the editor that ships with WordPress. It is faster, it has no licence to renew, and it will still open in five years.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:wow/faq-item -->

<!-- wp:wow/faq-item {"question":"<?php echo esc_attr_x( 'What does accessible actually mean here?', 'Pattern placeholder text', 'wow-signal' ); ?>"} -->
<!-- wp:paragraph -->
<p><?php echo esc_html_x( 'WCAG 2.2 level AA, checked with automated tooling and by hand with a keyboard and a screen reader. You get the report, including anything we could not fix and why.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:wow/faq-item -->

<!-- wp:wow/faq-item {"question":"<?php echo esc_attr_x( 'Who owns the code?', 'Pattern placeholder text', 'wow-signal' ); ?>"} -->
<!-- wp:paragraph -->
<p><?php echo esc_html_x( 'You do, from the first commit. The repository is yours, the hosting is in your name, and nothing we write depends on us staying involved.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:wow/faq-item -->

<!-- wp:wow/faq-item {"question":"<?php echo esc_attr_x( 'Can you work with our existing team?', 'Pattern placeholder text', 'wow-signal' ); ?>"} -->
<!-- wp:paragraph -->
<p><?php echo esc_html_x( 'Often, yes. We review pull requests, pair with in-house developers and leave the conventions documented so the work outlives the engagement.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:wow/faq-item -->
<!-- /wp:wow/faq --></section>
<!-- /wp:group -->
