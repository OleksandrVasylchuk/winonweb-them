<?php
/**
 * Server render for wow/colophon.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block markup (unused).
 * @var WP_Block             $block      Block instance (unused).
 */

declare( strict_types = 1 );

use Wow\Signal\Modules\Credit;

defined( 'ABSPATH' ) || exit;

$wow_prefix    = isset( $attributes['prefix'] ) ? (string) $attributes['prefix'] : '©';
$wow_show_name = ! isset( $attributes['showSiteName'] ) || (bool) $attributes['showSiteName'];
$wow_extra     = isset( $attributes['extraText'] ) ? (string) $attributes['extraText'] : '';

// wp_date() respects the site's timezone; gmdate() would roll over early.
$wow_year = (string) wp_date( 'Y' );

$wow_parts = array( trim( $wow_prefix . ' ' . $wow_year ) );

if ( $wow_show_name ) {
	$wow_parts[] = (string) get_bloginfo( 'name' );
}

if ( '' !== trim( $wow_extra ) ) {
	$wow_parts[] = $wow_extra;
}

$wow_line = implode(
	' · ',
	array_filter(
		$wow_parts,
		static function ( $part ): bool {
			return '' !== trim( (string) $part );
		}
	)
);

$wow_html = '<span class="wow-colophon__line">' . esc_html( $wow_line ) . '</span>';

if ( Credit::is_enabled() ) {
	$wow_link = sprintf(
		'<a href="%1$s" rel="noopener noreferrer nofollow" target="_blank">%2$s<span class="screen-reader-text"> %3$s</span></a>',
		esc_url( 'https://www.winonweb.dev/' ),
		esc_html__( 'WOW — Win On Web', 'wow-signal' ),
		esc_html__( '(opens in a new tab)', 'wow-signal' )
	);

	$wow_html .= '<span class="wow-colophon__credit">' . sprintf(
		/* translators: %s: linked studio name. */
		esc_html__( 'Design by %s', 'wow-signal' ),
		$wow_link
	) . '</span>';
}

$wow_allowed = array(
	'span' => array( 'class' => array() ),
	'a'    => array(
		'href'   => array(),
		'rel'    => array(),
		'target' => array(),
	),
);

/*
 * get_block_wrapper_attributes() escapes every value it emits, so it is
 * printed as-is; running it through an escaper again would corrupt the
 * attribute string.
 */
printf(
	'<p %1$s>%2$s</p>',
	get_block_wrapper_attributes( array( 'class' => 'wow-colophon' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	wp_kses( $wow_html, $wow_allowed )
);
