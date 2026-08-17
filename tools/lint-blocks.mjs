#!/usr/bin/env node
/**
 * Block markup linter for WOW — Signal.
 *
 * Templates, template parts and patterns are hand-written block markup. A
 * stray comment or a malformed attribute object shows up in the Site Editor
 * as "this block contains unexpected content", which is a miserable thing to
 * discover in production. This walks every file and checks:
 *
 *   1. block comments open and close in the right order;
 *   2. every attribute object is valid JSON;
 *   3. every referenced block is one WordPress core ships or one this theme
 *      registers in /blocks;
 *   4. every wp:pattern reference points at a pattern that exists;
 *   5. every wp:template-part reference points at a file in /parts.
 *
 * Usage: npm run lint:blocks
 *
 * @package Wow\Signal
 */

import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join, basename } from 'node:path';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

/* Core blocks used anywhere in this theme. Kept explicit so a typo in a
 * block name is caught rather than silently rendering an empty comment. */
const CORE_BLOCKS = new Set( [
	'avatar', 'button', 'buttons', 'column', 'columns', 'comment-author-name',
	'comment-content', 'comment-date', 'comment-reply-link', 'comment-template',
	'comments', 'comments-pagination', 'comments-pagination-next',
	'comments-pagination-numbers', 'comments-pagination-previous',
	'comments-title', 'group', 'heading', 'latest-posts', 'list', 'list-item',
	'navigation', 'paragraph', 'pattern', 'post-author-name', 'post-comments-form',
	'post-content', 'post-date', 'post-excerpt', 'post-featured-image',
	'post-navigation-link', 'post-template', 'post-terms', 'post-title', 'query',
	'query-no-results', 'query-pagination', 'query-pagination-next',
	'query-pagination-numbers', 'query-pagination-previous', 'query-title',
	'search', 'separator', 'site-logo', 'site-title', 'social-link',
	'social-links', 'spacer', 'template-part', 'term-description',
] );

/* WooCommerce blocks. Only reachable when the plugin is active, which is why
 * they live in a separate set and are reported as informational. */
const WOO_BLOCKS = new Set( [
	'woocommerce/classic-template',
	'woocommerce/classic-shortcode',
] );

const themeBlocks = new Set(
	existsSync( join( root, 'blocks' ) )
		? readdirSync( join( root, 'blocks' ), { withFileTypes: true } )
				.filter( ( entry ) => entry.isDirectory() )
				.filter( ( entry ) => existsSync( join( root, 'blocks', entry.name, 'block.json' ) ) )
				.map( ( entry ) => {
					const meta = JSON.parse(
						readFileSync( join( root, 'blocks', entry.name, 'block.json' ), 'utf8' )
					);
					return meta.name;
				} )
		: []
);

const patternSlugs = new Set(
	existsSync( join( root, 'patterns' ) )
		? readdirSync( join( root, 'patterns' ) )
				.filter( ( file ) => file.endsWith( '.php' ) )
				.map( ( file ) => {
					const source = readFileSync( join( root, 'patterns', file ), 'utf8' );
					const match = source.match( /^\s*\*\s*Slug:\s*(\S+)\s*$/m );
					return match ? match[ 1 ] : null;
				} )
				.filter( Boolean )
		: []
);

const partNames = new Set(
	existsSync( join( root, 'parts' ) )
		? readdirSync( join( root, 'parts' ) )
				.filter( ( file ) => file.endsWith( '.html' ) )
				.map( ( file ) => basename( file, '.html' ) )
		: []
);

const problems = [];

function report( file, message ) {
	problems.push( `${ file }: ${ message }` );
}

/**
 * PHP is evaluated before WordPress ever parses the block markup, so for
 * linting purposes it is replaced with a harmless literal. That keeps
 * attribute objects such as {"label":"<?php echo ... ?>"} valid JSON.
 */
function stripPhp( source ) {
	return source.replace( /<\?php[\s\S]*?\?>/g, 'PHP' ).replace( /<\?php[\s\S]*$/g, 'PHP' );
}

const TOKEN = /<!--\s+(\/?)wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)\s*(\{[\s\S]*?\})?\s*(\/)?-->/g;

function lintFile( relativePath, source ) {
	const text = stripPhp( source );
	const stack = [];
	let match;

	TOKEN.lastIndex = 0;

	while ( ( match = TOKEN.exec( text ) ) !== null ) {
		const [ raw, closing, name, attrs, selfClosing ] = match;
		const qualified = name.includes( '/' ) ? name : `core/${ name }`;

		// 2. attributes must be valid JSON.
		if ( attrs ) {
			try {
				JSON.parse( attrs );
			} catch ( error ) {
				report( relativePath, `invalid JSON attributes on ${ raw.slice( 0, 70 ) }… — ${ error.message }` );
				continue;
			}
		}

		// 3. the block has to exist somewhere.
		const known =
			CORE_BLOCKS.has( name ) ||
			themeBlocks.has( qualified ) ||
			WOO_BLOCKS.has( qualified );

		if ( ! known && ! closing ) {
			report( relativePath, `unknown block "${ qualified }"` );
		}

		// 4 and 5. cross-references must resolve.
		if ( attrs && ! closing ) {
			const parsed = JSON.parse( attrs );

			if ( 'pattern' === name && parsed.slug && ! patternSlugs.has( parsed.slug ) ) {
				report( relativePath, `references missing pattern "${ parsed.slug }"` );
			}

			if ( 'template-part' === name && parsed.slug && ! partNames.has( parsed.slug ) ) {
				report( relativePath, `references missing template part "${ parsed.slug }"` );
			}
		}

		// 1. balance.
		if ( selfClosing ) {
			continue;
		}

		if ( closing ) {
			const open = stack.pop();

			if ( open !== name ) {
				report(
					relativePath,
					`closing "/wp:${ name }" does not match open "wp:${ open ?? '(nothing)' }"`
				);
			}
		} else {
			stack.push( name );
		}
	}

	if ( stack.length ) {
		report( relativePath, `unclosed block(s): ${ stack.join( ', ' ) }` );
	}
}

function walk( directory, extension ) {
	const full = join( root, directory );

	if ( ! existsSync( full ) ) {
		return [];
	}

	return readdirSync( full )
		.filter( ( file ) => file.endsWith( extension ) )
		.map( ( file ) => [ `${ directory }/${ file }`, readFileSync( join( full, file ), 'utf8' ) ] );
}

const files = [
	...walk( 'templates', '.html' ),
	...walk( 'parts', '.html' ),
	...walk( 'patterns', '.php' ),
];

for ( const [ relativePath, source ] of files ) {
	lintFile( relativePath, source );
}

console.log(
	`Checked ${ files.length } files — ` +
		`${ themeBlocks.size } theme blocks, ${ patternSlugs.size } patterns, ${ partNames.size } template parts.`
);

if ( problems.length ) {
	console.log( `\n${ problems.length } problem(s):\n` );
	problems.forEach( ( problem ) => console.log( `  ${ problem }` ) );
	process.exit( 1 );
}

console.log( 'No problems found.' );
