<?php
/**
 * Title: Services — three card grid
 * Slug: qwerty-soft-signal/services-grid
 * Categories: qs-content
 * Description: A heading with a short intro and three service cards, each with a title, description and link.
 * Keywords: services, features, cards, offering, grid
 * Viewport Width: 1400
 *
 * @package Qwerty\Soft
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Services"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)"><!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"44rem"}} -->
<div class="wp-block-group alignwide"><!-- wp:heading {"level":2,"fontSize":"xx-large"} -->
<h2 class="wp-block-heading has-xx-large-font-size"><?php echo esc_html_x( 'What we build', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'Three ways we work. Every one of them ends with a site you can measure, not a folder of files you cannot maintain.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:spacer {"height":"var:preset|spacing|70"} -->
<div style="height:var(--wp--preset--spacing--70)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:paragraph {"textColor":"accent","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em","fontWeight":"700"}}} -->
<p class="has-accent-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.12em;text-transform:uppercase"><?php echo esc_html_x( '01', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
<h3 class="wp-block-heading has-x-large-font-size"><?php echo esc_html_x( 'WordPress builds', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html_x( 'Block themes your team can actually edit. No page builder, no plugin sprawl, no locked-in licence renewals.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list {"className":"is-style-checks","textColor":"muted","fontSize":"small"} -->
<ul class="wp-block-list is-style-checks has-muted-color has-text-color has-small-font-size"><!-- wp:list-item -->
<li><?php echo esc_html_x( 'Full Site Editing from day one', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Content model built around your editors', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Handover docs and a training session', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:paragraph {"textColor":"accent-2","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em","fontWeight":"700"}}} -->
<p class="has-accent-2-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.12em;text-transform:uppercase"><?php echo esc_html_x( '02', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
<h3 class="wp-block-heading has-x-large-font-size"><?php echo esc_html_x( 'React and Next.js', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html_x( 'Product interfaces and headless front ends where a template alone will not do the job.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list {"className":"is-style-checks","textColor":"muted","fontSize":"small"} -->
<ul class="wp-block-list is-style-checks has-muted-color has-text-color has-small-font-size"><!-- wp:list-item -->
<li><?php echo esc_html_x( 'Server rendering and edge caching', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Typed APIs and a real test suite', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'WordPress as the editing layer, if you want it', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:paragraph {"textColor":"accent-3","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em","fontWeight":"700"}}} -->
<p class="has-accent-3-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.12em;text-transform:uppercase"><?php echo esc_html_x( '03', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
<h3 class="wp-block-heading has-x-large-font-size"><?php echo esc_html_x( 'Rescue and rebuild', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html_x( 'A site that is slow, broken or unusable on a screen reader. We audit it, fix it, and show you the before and after.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list {"className":"is-style-checks","textColor":"muted","fontSize":"small"} -->
<ul class="wp-block-list is-style-checks has-muted-color has-text-color has-small-font-size"><!-- wp:list-item -->
<li><?php echo esc_html_x( 'Core Web Vitals back into the green', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'WCAG 2.2 AA remediation with a report', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Security and update hygiene, sorted', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
