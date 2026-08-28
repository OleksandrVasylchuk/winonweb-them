<?php
/**
 * Title: Hero — inner page header
 * Slug: qwerty-soft-signal/hero-page
 * Categories: qs-hero
 * Description: A compact page opener with an eyebrow, a heading and one supporting paragraph.
 * Keywords: hero, page header, intro, title
 * Viewport Width: 1400
 *
 * @package Qwerty\Soft
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"tagName":"section","metadata":{"name":"Page hero"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--70)"><!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"48rem"}} -->
<div class="wp-block-group alignwide"><!-- wp:paragraph {"textColor":"accent","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.14em","fontWeight":"700"}}} -->
<p class="has-accent-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.14em;text-transform:uppercase"><?php echo esc_html_x( 'Services', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"fontSize":"xxx-large"} -->
<h1 class="wp-block-heading has-xxx-large-font-size"><?php echo esc_html_x( 'What we do, and what it costs', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'Short version: we build and rescue WordPress and Next.js sites, we quote a fixed number, and we can show you the measurements afterwards.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></section>
<!-- /wp:group -->
