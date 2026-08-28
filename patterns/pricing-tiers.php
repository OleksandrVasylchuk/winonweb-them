<?php
/**
 * Title: Pricing — three tiers
 * Slug: qwerty-soft-signal/pricing-tiers
 * Categories: qs-conversion
 * Description: Three pricing cards with a highlighted middle tier, a feature list and a call to action on each.
 * Keywords: pricing, plans, packages, tiers, cost
 * Viewport Width: 1400
 *
 * @package Qwerty\Soft
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Pricing"},"anchor":"pricing","align":"full","backgroundColor":"surface","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull has-surface-background-color has-background" id="pricing" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)"><!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"44rem"}} -->
<div class="wp-block-group alignwide"><!-- wp:heading {"level":2,"fontSize":"xx-large"} -->
<h2 class="wp-block-heading has-xx-large-font-size"><?php echo esc_html_x( 'Straight pricing', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'Fixed scope, fixed price, written down before we start. If the scope changes we tell you what it costs before we do it.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:spacer {"height":"var:preset|spacing|70"} -->
<div style="height:var(--wp--preset--spacing--70)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"is-style-card","backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card has-base-background-color has-background"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html_x( 'Landing', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"xx-large","style":{"typography":{"fontWeight":"800"}}} -->
<p class="has-xx-large-font-size" style="font-weight:800"><?php echo esc_html_x( 'from €3,500', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
<p class="has-muted-color has-text-color has-small-font-size"><?php echo esc_html_x( 'One page that has to convert. Two to three weeks.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list {"className":"is-style-checks","textColor":"muted","fontSize":"small"} -->
<ul class="wp-block-list is-style-checks has-muted-color has-text-color has-small-font-size"><!-- wp:list-item -->
<li><?php echo esc_html_x( 'Custom block patterns', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Lighthouse 95+ on launch', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Analytics and conversion tracking', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline","fontSize":"small"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-small-font-size wp-element-button" href="#qwerty_soft_contact"><?php echo esc_html_x( 'Ask about Landing', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"is-style-card qs-tier-featured","backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card qs-tier-featured has-base-background-color has-background"><!-- wp:paragraph {"textColor":"accent","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em","fontWeight":"700"}}} -->
<p class="has-accent-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.12em;text-transform:uppercase"><?php echo esc_html_x( 'Most chosen', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html_x( 'Site', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"xx-large","style":{"typography":{"fontWeight":"800"}}} -->
<p class="has-xx-large-font-size" style="font-weight:800"><?php echo esc_html_x( 'from €9,000', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
<p class="has-muted-color has-text-color has-small-font-size"><?php echo esc_html_x( 'A complete block theme your team owns. Four to eight weeks.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list {"className":"is-style-checks","textColor":"muted","fontSize":"small"} -->
<ul class="wp-block-list is-style-checks has-muted-color has-text-color has-small-font-size"><!-- wp:list-item -->
<li><?php echo esc_html_x( 'Everything in Landing', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Full Site Editing setup and training', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'WCAG 2.2 AA audit and sign-off', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Content migration', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"fontSize":"small"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-small-font-size wp-element-button" href="#qwerty_soft_contact"><?php echo esc_html_x( 'Ask about Site', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"is-style-card","backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card has-base-background-color has-background"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html_x( 'Partner', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"xx-large","style":{"typography":{"fontWeight":"800"}}} -->
<p class="has-xx-large-font-size" style="font-weight:800"><?php echo esc_html_x( 'from €2,400/mo', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
<p class="has-muted-color has-text-color has-small-font-size"><?php echo esc_html_x( 'Ongoing development with a reserved share of our week.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list {"className":"is-style-checks","textColor":"muted","fontSize":"small"} -->
<ul class="wp-block-list is-style-checks has-muted-color has-text-color has-small-font-size"><!-- wp:list-item -->
<li><?php echo esc_html_x( 'Roadmap sessions every month', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Core Web Vitals monitoring', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php echo esc_html_x( 'Security patching within 24 hours', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline","fontSize":"small"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-small-font-size wp-element-button" href="#qwerty_soft_contact"><?php echo esc_html_x( 'Ask about Partner', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
