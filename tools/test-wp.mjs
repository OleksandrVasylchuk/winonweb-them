#!/usr/bin/env node
/**
 * Run the integration tests against a real WordPress install.
 *
 * Each file in tests/wp/ (except bootstrap.php) is one PHP process, so a
 * fatal error in one cannot hide the results of the others. The install is
 * found the way the bootstrap finds it — WOW_WP_PATH, else a wp-load.php above
 * the theme — and when there is none the whole thing is a skip, not a failure:
 * `npm test` does not depend on this, `npm run test:all` does.
 *
 * Usage:
 *   npm run test:wp                       # every test
 *   npm run test:wp -- contact-form seo   # only these
 *   WOW_WP_PATH=/var/www/html npm run test:wp
 *
 * @package Wow\Signal
 */

import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { requirePhp } from './php.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const dir = resolve( root, 'tests/wp' );

/** Where WordPress is, or null. Mirrors wow_locate_wordpress() in bootstrap.php. */
const locateWordPress = () => {
	const configured = process.env.WOW_WP_PATH;

	if ( configured ) {
		return existsSync( resolve( configured, 'wp-load.php' ) ) ? configured : null;
	}

	let current = root;

	for ( let i = 0; i < 6; i++ ) {
		if ( existsSync( resolve( current, 'wp-load.php' ) ) ) {
			return current;
		}

		const parent = dirname( current );

		if ( parent === current ) {
			break;
		}

		current = parent;
	}

	return null;
};

const wordpress = locateWordPress();

if ( null === wordpress ) {
	console.log( 'skipped: no WordPress found (set WOW_WP_PATH)' );
	process.exit( 0 );
}

const php = requirePhp();
const only = process.argv.slice( 2 ).map( ( name ) => name.replace( /\.php$/, '' ) );

const files = readdirSync( dir )
	.filter( ( name ) => name.endsWith( '.php' ) && 'bootstrap.php' !== name )
	.filter( ( name ) => 0 === only.length || only.includes( name.replace( /\.php$/, '' ) ) )
	// The harness proves its own isolation before anything relies on it.
	.sort( ( a, b ) => ( 'isolation.php' === a ? -1 : 'isolation.php' === b ? 1 : a.localeCompare( b ) ) );

if ( 0 === files.length ) {
	console.error( `ERROR  no test files matched ${ only.join( ', ' ) } in tests/wp/` );
	process.exit( 1 );
}

console.log( `WordPress: ${ wordpress }` );
console.log( `PHP:       ${ php }\n` );

let checks = 0;
let failures = 0;
let broken = 0;
const summary = [];

for ( const file of files ) {
	const started = Date.now();
	const result = spawnSync( php, [ '-d', 'display_errors=stderr', resolve( dir, file ) ], {
		cwd: root,
		shell: false,
		encoding: 'utf8',
		env: { ...process.env, WOW_WP_PATH: wordpress },
		maxBuffer: 64 * 1024 * 1024,
	} );

	const seconds = ( ( Date.now() - started ) / 1000 ).toFixed( 1 );
	const stdout = result.stdout ?? '';
	const stderr = ( result.stderr ?? '' ).trim();

	console.log( `──── ${ file } (${ seconds }s)` );
	process.stdout.write( stdout.endsWith( '\n' ) ? stdout : `${ stdout }\n` );

	if ( stderr ) {
		console.log( '  stderr:' );
		console.log( stderr.split( '\n' ).map( ( line ) => `    ${ line }` ).join( '\n' ) );
	}

	const tally = stdout.match( /^(\d+) checks, (\d+) failure\(s\)$/m );

	if ( tally ) {
		checks += Number( tally[ 1 ] );
		failures += Number( tally[ 2 ] );
		summary.push( `  ${ Number( tally[ 2 ] ) > 0 ? 'FAIL' : ' ok ' }  ${ file }  ${ tally[ 1 ] } checks, ${ tally[ 2 ] } failure(s)` );
	} else if ( /^skipped:/m.test( stdout ) ) {
		summary.push( `  skip  ${ file }` );
	} else {
		broken += 1;
		summary.push( `  DIED  ${ file }  exit ${ result.status ?? result.error?.message ?? '?' } — no tally printed` );
	}

	console.log( '' );
}

console.log( '════ integration tests' );
summary.forEach( ( line ) => console.log( line ) );
console.log( `\n${ checks } checks, ${ failures } failure(s)${ broken ? `, ${ broken } file(s) did not finish` : '' }` );

process.exit( failures > 0 || broken > 0 ? 1 : 0 );
