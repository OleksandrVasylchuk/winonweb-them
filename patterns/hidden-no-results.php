<?php
/**
 * Title: No results
 * Slug: qwerty-soft-signal/hidden-no-results
 * Inserter: no
 *
 * @package Qwerty\Soft
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:heading {"level":2,"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size"><?php echo esc_html_x( 'Nothing matched', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html_x( 'Try a shorter phrase, or a different word for the same thing.', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:search {"label":"<?php echo esc_attr_x( 'Search this site', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr_x( 'Search this site…', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?>","buttonText":"<?php echo esc_attr_x( 'Search', 'Pattern placeholder text', 'qwerty-soft-signal' ); ?>","buttonPosition":"button-inside"} /--></div>
<!-- /wp:group -->
