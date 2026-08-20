#!/usr/bin/env node
/**
 * POT generator for WOW — Signal.
 *
 * Scans the theme's PHP for gettext calls and its block.json files for the
 * strings WordPress translates from block metadata, then writes
 * languages/wow-signal.pot.
 *
 * Written in-house rather than pulled from wp-cli so the theme keeps a single
 * small dev dependency tree; the output follows the same POT conventions.
 *
 * Usage: npm run make:pot
 *
 * @package Wow\Signal
 */

import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join, relative } from 'node:path';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const DOMAIN = 'wow-signal';

/** The theme version, read from the one place that owns it: style.css. */
const VERSION = ( () => {
	const header = readFileSync( resolve( root, 'style.css' ), 'utf8' ).slice( 0, 2048 );
	const match = header.match( /^\s*(?:\*\s*)?Version:\s*(.+)$/m );
	return match ? match[ 1 ].trim() : '0.0.0';
} )();

/** Gettext functions, mapped to which argument holds what. */
const FUNCTIONS = {
	__: { text: 0, domain: 1 },
	_e: { text: 0, domain: 1 },
	esc_html__: { text: 0, domain: 1 },
	esc_html_e: { text: 0, domain: 1 },
	esc_attr__: { text: 0, domain: 1 },
	esc_attr_e: { text: 0, domain: 1 },
	_x: { text: 0, context: 1, domain: 2 },
	esc_html_x: { text: 0, context: 1, domain: 2 },
	esc_attr_x: { text: 0, context: 1, domain: 2 },
	_n: { text: 0, plural: 1, domain: 3 },
	_nx: { text: 0, plural: 1, context: 3, domain: 4 },
};

/** Recursively collect files with the given extension. */
function collect( dir, ext, found = [] ) {
	if ( ! existsSync( dir ) ) {
		return found;
	}

	for ( const entry of readdirSync( dir ) ) {
		if ( [ 'node_modules', 'vendor', 'artifacts', '.git' ].includes( entry ) ) {
			continue;
		}

		const full = join( dir, entry );

		if ( statSync( full ).isDirectory() ) {
			collect( full, ext, found );
		} else if ( entry.endsWith( ext ) ) {
			found.push( full );
		}
	}

	return found;
}

/**
 * Read a PHP string literal starting at `i` (which must be a quote).
 * Returns [value, indexAfterClosingQuote] or null.
 */
function readString( src, i ) {
	const quote = src[ i ];

	if ( "'" !== quote && '"' !== quote ) {
		return null;
	}

	let out = '';
	let j = i + 1;

	while ( j < src.length ) {
		const ch = src[ j ];

		if ( '\\' === ch ) {
			const next = src[ j + 1 ];

			if ( next === quote || '\\' === next ) {
				out += next;
				j += 2;
				continue;
			}

			if ( '"' === quote && 'n' === next ) {
				out += '\n';
				j += 2;
				continue;
			}

			out += ch;
			j++;
			continue;
		}

		if ( ch === quote ) {
			return [ out, j + 1 ];
		}

		out += ch;
		j++;
	}

	return null;
}

/** Skip whitespace and PHP comments. */
function skipGap( src, i ) {
	while ( i < src.length ) {
		if ( /\s/.test( src[ i ] ) ) {
			i++;
		} else if ( '/' === src[ i ] && '*' === src[ i + 1 ] ) {
			const end = src.indexOf( '*/', i );
			i = -1 === end ? src.length : end + 2;
		} else if ( '/' === src[ i ] && '/' === src[ i + 1 ] ) {
			const end = src.indexOf( '\n', i );
			i = -1 === end ? src.length : end + 1;
		} else {
			break;
		}
	}

	return i;
}

const entries = new Map();

function add( msgid, { context = null, plural = null, reference, comment = null } ) {
	const key = `${ context ?? '' }${ msgid }${ plural ?? '' }`;
	const existing = entries.get( key );

	if ( existing ) {
		existing.references.add( reference );

		if ( comment ) {
			existing.comments.add( comment );
		}

		return;
	}

	entries.set( key, {
		msgid,
		context,
		plural,
		references: new Set( [ reference ] ),
		comments: new Set( comment ? [ comment ] : [] ),
	} );
}

/** Pull a translator comment sitting immediately above the call. */
function translatorComment( src, callIndex ) {
	const before = src.slice( Math.max( 0, callIndex - 400 ), callIndex );
	const match = before.match( /\/\*\s*(translators:[\s\S]*?)\*\/\s*$/i );

	return match ? match[ 1 ].replace( /\s*\n\s*\*?\s*/g, ' ' ).trim() : null;
}

/**
 * Scan one source file for gettext calls.
 *
 * PHP and JavaScript are close enough here to share a reader: both use single
 * and double quoted literals with backslash escapes, and both use // and \/*
 * comments. The block editor scripts and the import screen call the same
 * wp.i18n functions by the same names, so leaving them out of this sweep is
 * what left half the admin untranslated.
 */
function scanSource( file ) {
	const src = readFileSync( file, 'utf8' );
	const rel = relative( root, file ).replace( /\\/g, '/' );
	const names = Object.keys( FUNCTIONS ).join( '|' );
	const callRe = new RegExp( `(?<![\\w$>])(${ names })\\s*\\(`, 'g' );
	let m;

	while ( ( m = callRe.exec( src ) ) !== null ) {
		const spec = FUNCTIONS[ m[ 1 ] ];
		let i = m.index + m[ 0 ].length;
		const args = [];

		for ( let a = 0; a <= Math.max( ...Object.values( spec ) ); a++ ) {
			i = skipGap( src, i );
			const read = readString( src, i );

			if ( ! read ) {
				args.push( null );
				// Argument is not a literal (a variable or expression): skip it.
				let depth = 0;

				while ( i < src.length ) {
					const ch = src[ i ];

					if ( '(' === ch ) {
						depth++;
					} else if ( ')' === ch ) {
						if ( 0 === depth ) {
							break;
						}
						depth--;
					} else if ( ',' === ch && 0 === depth ) {
						break;
					}

					i++;
				}
			} else {
				args.push( read[ 0 ] );
				i = read[ 1 ];
			}

			i = skipGap( src, i );

			if ( ',' === src[ i ] ) {
				i++;
			} else {
				break;
			}
		}

		if ( DOMAIN !== args[ spec.domain ] || 'string' !== typeof args[ spec.text ] ) {
			continue;
		}

		const line = src.slice( 0, m.index ).split( '\n' ).length;

		add( args[ spec.text ], {
			context: undefined !== spec.context ? args[ spec.context ] : null,
			plural: undefined !== spec.plural ? args[ spec.plural ] : null,
			reference: `${ rel }:${ line }`,
			comment: translatorComment( src, m.index ),
		} );
	}
}

for ( const file of collect( root, '.php' ) ) {
	scanSource( file );
}

/* The editor scripts and the import screen, which call the same functions
 * through wp.i18n. Their strings need JSON catalogues, not just the .mo —
 * make-mo.mjs writes those from the references recorded here. */
for ( const dir of [ join( root, 'assets', 'js' ), join( root, 'blocks' ) ] ) {
	for ( const file of collect( dir, '.js' ) ) {
		scanSource( file );
	}
}

/* Block metadata. WordPress translates these through the block.json i18n
 * schema, so they belong in the POT with the same contexts core uses. */
for ( const file of collect( join( root, 'blocks' ), 'block.json' ) ) {
	const meta = JSON.parse( readFileSync( file, 'utf8' ) );
	const rel = relative( root, file ).replace( /\\/g, '/' );

	if ( meta.title ) {
		add( meta.title, { context: 'block title', reference: rel } );
	}

	if ( meta.description ) {
		add( meta.description, { context: 'block description', reference: rel } );
	}

	for ( const keyword of meta.keywords ?? [] ) {
		add( keyword, { context: 'block keyword', reference: rel } );
	}
}

/* Style variation titles and descriptions. */
for ( const file of collect( join( root, 'styles' ), '.json' ) ) {
	const meta = JSON.parse( readFileSync( file, 'utf8' ) );
	const rel = relative( root, file ).replace( /\\/g, '/' );

	if ( meta.title ) {
		add( meta.title, { context: 'Style variation name', reference: rel } );
	}

	if ( meta.description ) {
		add( meta.description, { context: 'Style variation description', reference: rel } );
	}
}

/* Pattern titles, descriptions and keywords from the file headers. */
for ( const file of collect( join( root, 'patterns' ), '.php' ) ) {
	const src = readFileSync( file, 'utf8' );
	const rel = relative( root, file ).replace( /\\/g, '/' );

	for ( const [ header, context ] of [
		[ 'Title', 'Pattern title' ],
		[ 'Description', 'Pattern description' ],
		[ 'Keywords', 'Pattern keywords' ],
	] ) {
		const match = src.match( new RegExp( `^\\s*\\*\\s*${ header }:\\s*(.+)$`, 'm' ) );

		if ( match ) {
			add( match[ 1 ].trim(), { context, reference: rel } );
		}
	}
}

/** Escape a string for a POT msgid. */
const esc = ( s ) =>
	s
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\n/g, '\\n' )
		.replace( /\t/g, '\\t' );

const sorted = [ ...entries.values() ].sort( ( a, b ) => {
	const ra = [ ...a.references ][ 0 ];
	const rb = [ ...b.references ][ 0 ];
	return ra.localeCompare( rb ) || a.msgid.localeCompare( b.msgid );
} );

const lines = [
	'# Copyright (C) 2026 WOW — Win On Web',
	'# This file is distributed under the GNU General Public License v2 or later.',
	'msgid ""',
	'msgstr ""',
	`"Project-Id-Version: WOW — Signal ${ VERSION }\\n"`,
	'"Report-Msgid-Bugs-To: https://www.winonweb.dev/\\n"',
	'"MIME-Version: 1.0\\n"',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Content-Transfer-Encoding: 8bit\\n"',
	'"Language-Team: WOW — Win On Web\\n"',
	'"X-Domain: wow-signal\\n"',
	'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
	'',
];

for ( const entry of sorted ) {
	for ( const comment of entry.comments ) {
		lines.push( `#. ${ comment }` );
	}

	lines.push( `#: ${ [ ...entry.references ].join( ' ' ) }` );

	if ( entry.context ) {
		lines.push( `msgctxt "${ esc( entry.context ) }"` );
	}

	lines.push( `msgid "${ esc( entry.msgid ) }"` );

	if ( entry.plural ) {
		lines.push( `msgid_plural "${ esc( entry.plural ) }"` );
		lines.push( 'msgstr[0] ""' );
		lines.push( 'msgstr[1] ""' );
	} else {
		lines.push( 'msgstr ""' );
	}

	lines.push( '' );
}

const target = join( root, 'languages', 'wow-signal.pot' );
writeFileSync( target, lines.join( '\n' ), 'utf8' );

console.log( `Wrote ${ relative( root, target ) } — ${ sorted.length } strings.` );
