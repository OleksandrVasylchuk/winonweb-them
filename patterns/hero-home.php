<?php
/**
 * Title: Hero — headline, promise and proof
 * Slug: wow-signal/hero-home
 * Categories: wow-hero
 * Description: A full-width opening section with an eyebrow, a large headline, a supporting line, two buttons and a row of metrics.
 * Keywords: hero, intro, headline, opening, banner
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Hero"},"align":"full","gradient":"signal-fade","style":{"spacing":{"padding":{"top":"var:preset|spacing|90","bottom":"var:preset|spacing|90"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull has-signal-fade-gradient-background has-background" style="padding-top:var(--wp--preset--spacing--90);padding-bottom:var(--wp--preset--spacing--90)"><!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"52rem"}} -->
<div class="wp-block-group alignwide"><!-- wp:paragraph {"textColor":"accent","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.14em","fontWeight":"700"}}} -->
<p class="has-accent-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.14em;text-transform:uppercase"><?php echo esc_html_x( 'Boutique studio · WordPress & Next.js', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"className":"is-style-gradient","fontSize":"display"} -->
<h1 class="wp-block-heading is-style-gradient has-display-font-size"><?php echo esc_html_x( 'Websites that win.', 'Pattern placeholder text', 'wow-signal' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'We build fast, accessible, measurable websites. Two senior developers, no account managers, no hand-offs — the people who scope your project are the ones who ship it.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"var:preset|spacing|50"} -->
<div style="height:var(--wp--preset--spacing--50)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#wow_signal_contact"><?php echo esc_html_x( 'Start a project', 'Pattern placeholder text', 'wow-signal' ); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#cases"><?php echo esc_html_x( 'See the numbers', 'Pattern placeholder text', 'wow-signal' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:spacer {"height":"var:preset|spacing|80"} -->
<div style="height:var(--wp--preset--spacing--80)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"98","suffix":"+","label":"<?php echo esc_attr_x( 'Average Lighthouse performance score on launch', 'Pattern placeholder text', 'wow-signal' ); ?>"} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"2.2","suffix":"s","label":"<?php echo esc_attr_x( 'Typical Largest Contentful Paint on 4G', 'Pattern placeholder text', 'wow-signal' ); ?>"} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"100","suffix":"%","label":"<?php echo esc_attr_x( 'Projects delivered to WCAG 2.2 AA', 'Pattern placeholder text', 'wow-signal' ); ?>"} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:wow/metric {"value":"2","label":"<?php echo esc_attr_x( 'Senior developers on every project, start to finish', 'Pattern placeholder text', 'wow-signal' ); ?>","animate":false} /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
