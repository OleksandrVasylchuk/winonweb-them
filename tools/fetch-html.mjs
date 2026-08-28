#!/usr/bin/env node
/**
 * Pull rendered pages off a running site into artifacts/html.
 *
 * `npm run lint:html` validates whatever is in that folder. Until now filling
 * it was a curl loop pasted out of the README, which meant in practice it held
 * whichever pages somebody happened to fetch, on whichever day. This fetches a
 * consistent set — the front page, real pages taken from the site's own
 * sitemap, a search results page and a 404 — so the gate runs against what the
 * theme actually emits for real content rather than against a stale snapshot.
 *
 * Usage:
 *   npm run fetch:html                      # http://localhost
 *   npm run fetch:html -- http://site.test  # somewhere else
 *   npm run fetch:html -- http://site.test 12
 *
 * @package Qwerty\Soft
 */

import { mkdirSync, writeFileSync, readdirSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const outDir = resolve( root, 'artifacts/html' );

const base = ( process.argv[ 2 ] ?? 'http://localhost' ).replace( /\/+$/, '' );
const limit = Number.parseInt( process.argv[ 3 ] ?? '8', 10 );

/**
 * Fetch a URL, returning its body and status.
 *
 * @param {string} url Absolute URL.
 * @returns {Promise<{status:number,body:string}>}
 */
const get = async ( url ) => {
	const response = await fetch( url, {
		redirect: 'follow',
		headers: { 'User-Agent': 'qwerty-soft-signal-lint/1.0' },
	} );

	return { status: response.status, body: await response.text() };
};

/** Where the install lives, so a subdirectory does not end up in every name. */
const basePath = new URL( `${ base }/` ).pathname;

/** A file name that is safe and says which page it was. */
const nameFor = ( url ) => {
	let path = new URL( url ).pathname;

	if ( path.startsWith( basePath ) ) {
		path = path.slice( basePath.length );
	}

	path = path.replace( /^\/|\/$/g, '' );

	return ( '' === path ? 'front' : path.replace( /[^a-z0-9-]+/gi, '-' ) ).slice( 0, 60 );
};

/**
 * URLs listed in the site's own sitemap, newest first.
 *
 * WordPress emits wp-sitemap.xml itself since 5.5; a site whose SEO plugin has
 * replaced it will simply return nothing here and the fixed pages still run.
 *
 * @returns {Promise<string[]>}
 */
const fromSitemap = async () => {
	const found = [];

	try {
		const index = await get( `${ base }/wp-sitemap.xml` );

		if ( 200 !== index.status ) {
			return found;
		}

		const children = [ ...index.body.matchAll( /<loc>([^<]+)<\/loc>/g ) ].map( ( m ) => m[ 1 ] );

		for ( const child of children ) {
			if ( found.length >= limit ) {
				break;
			}

			// The index lists sub-sitemaps; the sub-sitemaps list pages.
			if ( ! child.includes( 'wp-sitemap' ) ) {
				found.push( child );
				continue;
			}

			const sub = await get( child );

			for ( const match of sub.body.matchAll( /<loc>([^<]+)<\/loc>/g ) ) {
				if ( found.length >= limit ) {
					break;
				}

				found.push( match[ 1 ] );
			}
		}
	} catch {
		return found;
	}

	return found;
};

/**
 * URLs from the REST API, for sites whose sitemap is off or rewritten away.
 *
 * Works whatever the permalink structure is, because the API hands back the
 * canonical link for each entry rather than a path this script has to guess.
 *
 * @returns {Promise<string[]>}
 */
const fromRest = async () => {
	const found = [];

	for ( const type of [ 'pages', 'posts' ] ) {
		if ( found.length >= limit ) {
			break;
		}

		try {
			const response = await get( `${ base }/wp-json/wp/v2/${ type }?per_page=${ limit }&_fields=link` );

			if ( 200 !== response.status ) {
				continue;
			}

			for ( const entry of JSON.parse( response.body ) ) {
				if ( found.length < limit && 'string' === typeof entry.link ) {
					found.push( entry.link );
				}
			}
		} catch {
			continue;
		}
	}

	return found;
};

/** Whichever source the site actually offers. */
const discover = async () => {
	const sitemap = await fromSitemap();

	return sitemap.length > 0 ? sitemap : fromRest();
};

const reachable = await get( `${ base }/` ).catch( () => null );

if ( null === reachable ) {
	console.error( `ERROR  nothing answered at ${ base }` );
	console.error( '       Start the site first — lint:html has nothing to check without it.' );
	process.exit( 1 );
}

/** Trailing slash and all, so the sitemap's home entry matches the fixed one. */
const canonical = ( url ) => url.replace( /\/+$/, '' ) + '/';

const seen = new Set();
const targets = [
	[ `${ base }/`, 'front' ],
	...( await discover() ).map( ( url ) => [ url, nameFor( url ) ] ),
	[ `${ base }/?s=design`, 'search' ],
	[ `${ base }/this-page-does-not-exist-${ Math.abs( base.length * 7717 ) }/`, '404' ],
].filter( ( [ url ] ) => {
	// The sitemap lists the home page too; without this "front" is fetched twice.
	const key = canonical( url );

	if ( seen.has( key ) ) {
		return false;
	}

	seen.add( key );
	return true;
} );

mkdirSync( outDir, { recursive: true } );

// Start clean so a page removed from the site stops being validated.
for ( const file of readdirSync( outDir ) ) {
	if ( file.endsWith( '.html' ) ) {
		rmSync( resolve( outDir, file ) );
	}
}

let written = 0;
let skipped = 0;

for ( const [ url, name ] of targets ) {
	let result;

	try {
		result = await get( url );
	} catch ( error ) {
		console.log( `  --  ${ name.padEnd( 28 ) } ${ error.message }` );
		skipped++;
		continue;
	}

	// A 404 page is expected to be a 404; everything else must be a 200.
	const wanted = '404' === name ? 404 : 200;

	if ( result.status !== wanted ) {
		console.log( `  --  ${ name.padEnd( 28 ) } HTTP ${ result.status }, expected ${ wanted }` );
		skipped++;
		continue;
	}

	if ( ! result.body.includes( '</html>' ) ) {
		console.log( `  --  ${ name.padEnd( 28 ) } body is not a complete document` );
		skipped++;
		continue;
	}

	writeFileSync( resolve( outDir, `${ name }.html` ), result.body );
	console.log( `  ok  ${ name.padEnd( 28 ) } ${ ( result.body.length / 1024 ).toFixed( 0 ) } KB` );
	written++;
}

console.log( `\n${ written } page(s) written to artifacts/html, ${ skipped } skipped.` );

if ( 0 === written ) {
	process.exit( 1 );
}
