<?php
/**
 * Title: No results
 * Slug: wow-signal/hidden-no-results
 * Inserter: no
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"is-style-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-card"><!-- wp:heading {"level":2,"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size"><?php echo esc_html_x( 'Nothing matched', 'Pattern placeholder text', 'wow-signal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html_x( 'Try a shorter phrase, or a different word for the same thing.', 'Pattern placeholder text', 'wow-signal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:search {"label":"<?php echo esc_attr_x( 'Search this site', 'Pattern placeholder text', 'wow-signal' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr_x( 'Search this site…', 'Pattern placeholder text', 'wow-signal' ); ?>","buttonText":"<?php echo esc_attr_x( 'Search', 'Pattern placeholder text', 'wow-signal' ); ?>","buttonPosition":"button-inside"} /--></div>
<!-- /wp:group -->
