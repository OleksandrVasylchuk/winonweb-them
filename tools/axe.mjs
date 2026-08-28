#!/usr/bin/env node
/**
 * Accessibility gate: axe-core, in a real browser, against a running site.
 *
 * Structure rules (lint:html) tell half the story. Whether a focus ring is
 * visible, whether a control has an accessible name once the block's view.js
 * has run, whether a landmark is duplicated by a plugin — those only show up
 * in a rendered page. This loads the front page, a search results page, a 404
 * and up to N pages from the site's own sitemap, injects axe-core into each
 * and runs the WCAG 2.2 AA rule set.
 *
 * Any violation fails the run. `color-contrast` results axe marks as
 * *incomplete* — it cannot measure text over a gradient or an image, which
 * the hero uses — are printed as INFO, not failures: docs/ACCESSIBILITY.md
 * records the mathematical check of those pairs, and audit:contrast enforces
 * the flat-colour ones.
 *
 * Setup, once:  npm install && npx playwright install chromium
 * Usage:        npm run audit:a11y -- http://your-site.test [pages=10]
 *
 * @package Qwerty\Soft
 */

import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire( import.meta.url );

const [ , , baseArg, limitArg ] = process.argv;

if ( ! baseArg ) {
	console.error( 'usage: npm run audit:a11y -- http://your-site.test [pages]' );
	process.exit( 2 );
}

const base = baseArg.replace( /\/+$/, '' );
const limit = Math.max( 0, Number.parseInt( limitArg ?? '10', 10 ) || 10 );

/** The rule tags that make up WCAG 2.2 AA, plus axe's own best practices off. */
const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

// ---------------------------------------------------------------- discovery

/**
 * Fetch text, or null on any failure — discovery must never fail the gate.
 *
 * @param {string} url Absolute URL.
 * @returns {Promise<{status:number,body:string}|null>}
 */
const fetchText = async ( url ) => {
	try {
		const response = await fetch( url, { redirect: 'follow', headers: { 'user-agent': 'qwerty-soft-signal-axe/1.0' } } );
		return { status: response.status, body: await response.text() };
	} catch {
		return null;
	}
};

/**
 * Pages listed in the site's sitemap (WordPress core's, or a plugin's).
 *
 * Follows one level of sitemap index. Falls back to the REST API when there
 * is no sitemap, which is what happens with plain permalinks.
 *
 * @returns {Promise<string[]>}
 */
const discover = async () => {
	const found = new Set();
	const locs = ( xml ) => [ ...xml.matchAll( /<loc>\s*([^<\s]+)\s*<\/loc>/g ) ].map( ( m ) => m[ 1 ] );

	for ( const candidate of [ '/wp-sitemap.xml', '/sitemap.xml', '/sitemap_index.xml' ] ) {
		const index = await fetchText( base + candidate );

		if ( ! index || 200 !== index.status || ! index.body.includes( '<loc>' ) ) {
			continue;
		}

		for ( const loc of locs( index.body ) ) {
			if ( /\.xml(\?|$)/.test( loc ) ) {
				const child = await fetchText( loc );
				if ( child && 200 === child.status ) {
					locs( child.body ).filter( ( l ) => ! /\.xml(\?|$)/.test( l ) ).forEach( ( l ) => found.add( l ) );
				}
			} else {
				found.add( loc );
			}

			if ( found.size >= limit * 3 ) {
				break;
			}
		}

		if ( found.size > 0 ) {
			break;
		}
	}

	if ( 0 === found.size ) {
		for ( const type of [ 'pages', 'posts' ] ) {
			const rest = await fetchText( `${ base }/wp-json/wp/v2/${ type }?per_page=${ limit }&_fields=link` );

			if ( rest && 200 === rest.status ) {
				try {
					JSON.parse( rest.body ).forEach( ( item ) => item.link && found.add( item.link ) );
				} catch {
					// Not JSON; move on.
				}
			}
		}
	}

	// Same-origin only, front page excluded (it is always first anyway).
	return [ ...found ]
		.filter( ( url ) => url.startsWith( base ) && url.replace( /\/+$/, '' ) !== base )
		.slice( 0, limit );
};

// ------------------------------------------------------------------- audit

let chromium;

try {
	( { chromium } = require( 'playwright' ) );
} catch {
	console.error( 'ERROR  playwright is not installed. Run: npm install && npx playwright install chromium' );
	process.exit( 2 );
}

const axeSource = readFileSync( require.resolve( 'axe-core/axe.min.js' ), 'utf8' );

const targets = [
	{ label: 'front page', url: `${ base }/`, expect: 200 },
	{ label: 'search', url: `${ base }/?s=signal`, expect: 200 },
	{ label: '404', url: `${ base }/qwerty-soft-signal-this-page-does-not-exist-${ Date.now() }/`, expect: 404 },
	...( await discover() ).map( ( url ) => ( { label: url.slice( base.length ) || '/', url, expect: 200 } ) ),
];

let browser;

try {
	browser = await chromium.launch();
} catch ( error ) {
	console.error( 'ERROR  could not launch Chromium. Run: npx playwright install chromium' );
	console.error( `       ${ error.message.split( '\n' )[ 0 ] }` );
	process.exit( 2 );
}

const context = await browser.newContext( {
	viewport: { width: 1280, height: 900 },
	reducedMotion: 'reduce',
	userAgent: 'qwerty-soft-signal-axe/1.0 (Playwright)',
} );

let violations = 0;
let incomplete = 0;
let skipped = 0;

console.log( `axe-core ${ JSON.parse( readFileSync( require.resolve( 'axe-core/package.json' ), 'utf8' ) ).version } · WCAG 2.2 AA · ${ targets.length } page(s) on ${ base }\n` );

for ( const target of targets ) {
	const page = await context.newPage();

	try {
		const response = await page.goto( target.url, { waitUntil: 'networkidle', timeout: 30000 } );
		const status = response?.status() ?? 0;

		if ( status !== target.expect ) {
			skipped += 1;
			console.log( `SKIP  ${ target.label }  (HTTP ${ status }, expected ${ target.expect })` );
			continue;
		}

		// Let view.js reveal the slider arrows and settle the counters.
		await page.waitForTimeout( 500 );

		await page.addScriptTag( { content: axeSource } );

		const results = await page.evaluate(
			( tags ) => window.axe.run( document, {
				runOnly: { type: 'tag', values: tags },
				resultTypes: [ 'violations', 'incomplete' ],
			} ),
			TAGS
		);

		const contrastIncomplete = results.incomplete.filter( ( r ) => 'color-contrast' === r.id );
		const otherIncomplete = results.incomplete.filter( ( r ) => 'color-contrast' !== r.id );

		const verdict = results.violations.length ? 'FAIL' : ' ok ';
		console.log( `${ verdict }  ${ target.label }  ${ results.violations.length } violation(s), ${ results.incomplete.length } incomplete` );

		for ( const violation of results.violations ) {
			violations += 1;
			console.log( `      ✗ ${ violation.id } [${ violation.impact }] — ${ violation.help }` );
			console.log( `        ${ violation.helpUrl }` );

			for ( const node of violation.nodes.slice( 0, 5 ) ) {
				console.log( `        · ${ node.target.join( ' ' ) }` );
				console.log( `          ${ node.html.replace( /\s+/g, ' ' ).slice( 0, 160 ) }` );
			}

			if ( violation.nodes.length > 5 ) {
				console.log( `        · …and ${ violation.nodes.length - 5 } more` );
			}
		}

		for ( const item of contrastIncomplete ) {
			incomplete += item.nodes.length;
			console.log( `      INFO  color-contrast could not be measured on ${ item.nodes.length } element(s) — gradient or image background; see docs/ACCESSIBILITY.md for the mathematical check` );

			for ( const node of item.nodes.slice( 0, 3 ) ) {
				console.log( `            · ${ node.target.join( ' ' ) }` );
			}
		}

		for ( const item of otherIncomplete ) {
			console.log( `      INFO  ${ item.id } needs a manual check on ${ item.nodes.length } element(s) — ${ item.help }` );
		}
	} catch ( error ) {
		skipped += 1;
		console.log( `SKIP  ${ target.label }  (${ error.message.split( '\n' )[ 0 ] })` );
	} finally {
		await page.close();
	}
}

await browser.close();

console.log( `\n${ targets.length - skipped } page(s) audited, ${ violations } violation(s), ${ incomplete } contrast check(s) left to the maths${ skipped ? `, ${ skipped } skipped` : '' }` );

if ( targets.length - skipped < 1 ) {
	console.error( 'ERROR  no page could be audited — is the site up?' );
	process.exit( 1 );
}

process.exit( violations > 0 ? 1 : 0 );
