<?php
/**
 * Title: 404 content
 * Slug: qwerty-soft-signal/hidden-404
 * Inserter: no
 *
 * @package Qwerty\Soft
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"layout":{"type":"constrained","contentSize":"40rem"}} -->
<div class="wp-block-group"><!-- wp:paragraph {"textColor":"accent","fontSize":"x-small","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.14em","fontWeight":"700"}}} -->
<p class="has-accent-color has-text-color has-x-small-font-size" style="font-weight:700;letter-spacing:0.14em;text-transform:uppercase"><?php echo esc_html_x( 'Error 404', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"fontSize":"xxx-large"} -->
<h1 class="wp-block-heading has-xxx-large-font-size"><?php echo esc_html_x( 'That page is not here', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
<p class="has-muted-color has-text-color has-large-font-size"><?php echo esc_html_x( 'It may have moved, or the link that brought you here may be out of date. Try a search, or head back to the homepage.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"var:preset|spacing|50"} -->
<div style="height:var(--wp--preset--spacing--50)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:search {"label":"<?php echo esc_attr_x( 'Search this site', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr_x( 'Search this site…', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?>","buttonText":"<?php echo esc_attr_x( 'Search', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?>","buttonPosition":"button-inside"} /-->

<!-- wp:spacer {"height":"var:preset|spacing|50"} -->
<div style="height:var(--wp--preset--spacing--50)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/"><?php echo esc_html_x( 'Back to the homepage', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
