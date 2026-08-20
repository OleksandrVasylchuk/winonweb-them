#!/usr/bin/env node
/**
 * WCAG 2.2 contrast auditor for WOW — Signal.
 *
 * Reads the colour palettes straight out of theme.json and styles/light.json,
 * then checks every pair the theme actually renders against its required
 * minimum. Exits non-zero on any failure so it can gate CI.
 *
 * Usage: npm run audit:contrast
 *
 * @package Wow\Signal
 */

import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

/** Relative luminance of one sRGB channel (WCAG 2.2, §relative luminance). */
const channel = ( value ) => {
	const v = value / 255;
	return v <= 0.04045 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
};

/** Relative luminance of a #rrggbb colour. */
const luminance = ( hex ) => {
	const h = hex.replace( '#', '' );
	return (
		0.2126 * channel( parseInt( h.slice( 0, 2 ), 16 ) ) +
		0.7152 * channel( parseInt( h.slice( 2, 4 ), 16 ) ) +
		0.0722 * channel( parseInt( h.slice( 4, 6 ), 16 ) )
	);
};

/** Contrast ratio between two #rrggbb colours. */
const contrast = ( a, b ) => {
	const x = luminance( a );
	const y = luminance( b );
	return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
};

/** Pull { slug: hex } out of a theme.json-shaped file. */
const paletteOf = ( relativePath ) => {
	const json = JSON.parse( readFileSync( resolve( root, relativePath ), 'utf8' ) );
	const entries = json?.settings?.color?.palette ?? [];
	return Object.fromEntries( entries.map( ( c ) => [ c.slug, c.color ] ) );
};

/*
 * The design contract.
 *
 * 4.5 — WCAG 1.4.3 AA, normal body text.
 * 3.0 — WCAG 1.4.3 AA large text (>=24px, or >=18.66px bold) and
 *       WCAG 1.4.11 non-text contrast for user-interface components
 *       (form control borders, focus indicators).
 *
 * Purely decorative fills (card backgrounds, hairline dividers) are exempt
 * from 1.4.11 and are reported as INFO so regressions stay visible without
 * failing the build.
 */
const CONTRACT = [
	// --- Body and secondary text -------------------------------------- //
	[ 'contrast', 'base', 4.5, 'Body text on page background' ],
	[ 'contrast', 'surface', 4.5, 'Body text on card' ],
	[ 'contrast', 'surface-2', 4.5, 'Body text on raised card' ],
	[ 'muted', 'base', 4.5, 'Secondary text on page background' ],
	[ 'muted', 'surface', 4.5, 'Secondary text on card' ],
	[ 'muted', 'surface-2', 4.5, 'Secondary text on raised card' ],

	// --- Accent text and links ----------------------------------------- //
	[ 'accent-ink', 'base', 4.5, 'Link on page background' ],
	[ 'accent-ink', 'surface', 4.5, 'Link on card' ],
	[ 'accent-ink', 'surface-2', 4.5, 'Link on raised card' ],
	[ 'accent-2', 'base', 4.5, 'Accent-2 text on page background' ],
	[ 'accent-2', 'surface', 4.5, 'Accent-2 text on card' ],
	[ 'accent-3', 'base', 4.5, 'Accent-3 text on page background' ],
	[ 'accent-3', 'surface', 4.5, 'Accent-3 text on card' ],
	[ 'success', 'base', 4.5, 'Success message on page background' ],
	[ 'success', 'surface', 4.5, 'Success message on card' ],
	[ 'warning', 'base', 4.5, 'Error/warning message on page background' ],
	[ 'warning', 'surface', 4.5, 'Error/warning message on card' ],

	// --- Button labels -------------------------------------------------- //
	// Rule: labels on an accent fill are always `base`, never `contrast`.
	[ 'base', 'accent', 4.5, 'Primary button label on accent fill' ],
	[ 'base', 'accent-2', 4.5, 'Button label on accent-2 fill' ],
	[ 'base', 'accent-3', 4.5, 'Button label on accent-3 fill' ],
	[ 'base', 'contrast', 4.5, 'Inverted button label' ],
	[ 'contrast', 'surface-2', 4.5, 'Secondary button label on raised fill' ],

	// --- Non-text UI components (WCAG 1.4.11) --------------------------- //
	[ 'border-strong', 'base', 3.0, 'Form control border on page background' ],
	[ 'border-strong', 'surface', 3.0, 'Form control border on card' ],
	[ 'border-strong', 'surface-2', 3.0, 'Form control border on raised card' ],
	[ 'accent', 'base', 3.0, 'Focus ring on page background' ],
	[ 'accent', 'surface', 3.0, 'Focus ring on card' ],
	[ 'accent', 'surface-2', 3.0, 'Focus ring on raised card' ],
	/*
	 * The focus indicator is a two-tone ring: an accent outline separated from
	 * the element by a `base`-coloured gap (see styles.css in theme.json). On a
	 * light inverted fill the accent alone would only reach 1.65:1, so what has
	 * to clear 3:1 is the ring against its gap, and the gap against the fill.
	 */
	[ 'accent', 'base', 3.0, 'Focus ring against its separator gap' ],
	[ 'base', 'contrast', 3.0, 'Focus ring separator gap on inverted fill' ],
	[ 'base', 'accent', 3.0, 'Focus ring separator gap on accent fill' ],
];

/** Decorative pairs — reported, never enforced. */
const INFO = [
	[ 'border', 'base', 'Hairline divider on page background (decorative)' ],
	[ 'border', 'surface', 'Hairline divider on card (decorative)' ],
	[ 'surface', 'base', 'Card fill vs page background (decorative)' ],
	[ 'surface-2', 'surface', 'Raised card vs card (decorative)' ],
];

/**
 * Every palette the theme can render.
 *
 * The default is theme.json; the rest are discovered, so a style variation
 * cannot be added without the contract being enforced on it. A variation that
 * ships a partial palette is a failure, not a skip: WordPress replaces the
 * theme palette wholesale, so the slugs it leaves out simply disappear.
 */
const THEMES = [
	[ 'DEFAULT (theme.json)', 'theme.json' ],
	...readdirSync( resolve( root, 'styles' ) )
		.filter( ( name ) => name.endsWith( '.json' ) )
		.sort()
		.map( ( name ) => {
			const path = `styles/${ name }`;
			const title = JSON.parse( readFileSync( resolve( root, path ), 'utf8' ) )?.title ?? name;

			return [ `VARIATION — ${ title } (${ path })`, path ];
		} ),
];

let failures = 0;
let checks = 0;
let audited = 0;

for ( const [ label, file ] of THEMES ) {
	const palette = paletteOf( file );
	console.log( `\n=== ${ label } ===` );

	/*
	 * A variation is allowed to change only typography or layout. One that
	 * defines no palette inherits the theme's, which has already been audited
	 * above, so auditing it again would report the same numbers twice — and
	 * treating its absent slugs as missing tokens would fail the build for a
	 * file that is doing nothing wrong.
	 */
	if ( 0 === Object.keys( palette ).length ) {
		console.log( '  no palette of its own — inherits the audited default' );
		continue;
	}

	audited++;

	for ( const [ fg, bg, min, purpose ] of CONTRACT ) {
		if ( ! palette[ fg ] || ! palette[ bg ] ) {
			console.log( `  ????  MISSING TOKEN  ${ fg } / ${ bg }` );
			failures++;
			continue;
		}
		const ratio = contrast( palette[ fg ], palette[ bg ] );
		const pass = ratio >= min;
		checks++;
		if ( ! pass ) {
			failures++;
		}
		console.log(
			`  ${ ratio.toFixed( 2 ).padStart( 5 ) }:1  min ${ min.toFixed( 1 ) }  ` +
				`${ pass ? 'PASS' : 'FAIL' }  ${ purpose } ` +
				`[${ fg } ${ palette[ fg ] } on ${ bg } ${ palette[ bg ] }]`
		);
	}

	for ( const [ fg, bg, purpose ] of INFO ) {
		if ( ! palette[ fg ] || ! palette[ bg ] ) {
			continue;
		}
		const ratio = contrast( palette[ fg ], palette[ bg ] );
		console.log( `  ${ ratio.toFixed( 2 ).padStart( 5 ) }:1   ----  INFO  ${ purpose }` );
	}
}

console.log(
	`\n${ checks } enforced checks across ${ audited } palettes — ` +
		`${ failures } failure(s).`
);

process.exit( failures > 0 ? 1 : 0 );
