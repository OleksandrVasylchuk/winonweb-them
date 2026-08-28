<?php
/**
 * Server render for qs/colophon.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block markup (unused).
 * @var WP_Block             $block      Block instance (unused).
 */

declare( strict_types = 1 );

use Qwerty\Soft\Modules\Credit;

defined( 'ABSPATH' ) || exit;

$qsoft_prefix    = isset( $attributes['prefix'] ) ? (string) $attributes['prefix'] : '©';
$qsoft_show_name = ! isset( $attributes['showSiteName'] ) || (bool) $attributes['showSiteName'];
$qsoft_extra     = isset( $attributes['extraText'] ) ? (string) $attributes['extraText'] : '';

// wp_date() respects the site's timezone; gmdate() would roll over early.
$qsoft_year = (string) wp_date( 'Y' );

$qsoft_parts = array( trim( $qsoft_prefix . ' ' . $qsoft_year ) );

if ( $qsoft_show_name ) {
	$qsoft_parts[] = (string) get_bloginfo( 'name' );
}

if ( '' !== trim( $qsoft_extra ) ) {
	$qsoft_parts[] = $qsoft_extra;
}

$qsoft_line = implode(
	' · ',
	array_filter(
		$qsoft_parts,
		static function ( $part ): bool {
			return '' !== trim( (string) $part );
		}
	)
);

$qsoft_html = '<span class="qs-colophon__line">' . esc_html( $qsoft_line ) . '</span>';

if ( Credit::is_enabled() ) {
	$qsoft_link = sprintf(
		'<a href="%1$s" rel="noopener noreferrer nofollow" target="_blank">%2$s<span class="screen-reader-text"> %3$s</span></a>',
		esc_url( 'https://qwerty-soft.com/' ),
		esc_html__( 'Qwerty Soft', 'qwerty-soft-signal' ),
		esc_html__( '(opens in a new tab)', 'qwerty-soft-signal' )
	);

	$qsoft_html .= '<span class="qs-colophon__credit">' . sprintf(
		/* translators: %s: linked studio name. */
		esc_html__( 'Design by %s', 'qwerty-soft-signal' ),
		$qsoft_link
	) . '</span>';
}

$qsoft_allowed = array(
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
	get_block_wrapper_attributes( array( 'class' => 'qs-colophon' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	wp_kses( $qsoft_html, $qsoft_allowed )
);
