#!/usr/bin/env node
/**
 * Release packager for Qwerty Soft — Signal.
 *
 * Writes artifacts/release/qwerty-soft-signal-<version>.zip containing exactly the
 * files that belong on a client's server — the theme, and nothing that only
 * exists to develop it.
 *
 * The theme has no build step, so this is not a build: it is a filter. Every
 * file that goes in is already the file that runs. What this guards against is
 * the one mistake that cannot be taken back once a customer has the download —
 * shipping the repository (node_modules, the reference design, the API-key
 * notes) instead of the product.
 *
 * ZIP is written by hand rather than with a dependency, for the same reason
 * the theme has no bundler: `npm install` should stay optional.
 *
 * It also writes artifacts/release/update.json — the manifest the theme's
 * update checker (inc/Modules/Updates.php) reads — with the archive's SHA-256
 * already filled in. The download URL in it is built from --download-base,
 * which must point at wherever the ZIP will actually be served from.
 *
 * Usage: npm run build:zip -- --download-base https://qwerty-soft.com/downloads/
 *        DOWNLOAD_BASE=https://… npm run build:zip
 *        npm run build:zip -- --details-url https://…/changelog/
 *
 * @package Qwerty\Soft
 */

import { createHash } from 'node:crypto';
import { deflateRawSync } from 'node:zlib';
import { readdirSync, readFileSync, mkdirSync, writeFileSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, relative, sep } from 'node:path';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

/** The folder name the archive unpacks into — must match the text domain. */
const SLUG = 'qwerty-soft-signal';

/**
 * Everything that must never reach a customer.
 *
 * Matched against the path relative to the theme root, with forward slashes.
 * A name without a slash matches at any depth; a name with one is anchored to
 * the root.
 */
const EXCLUDE = [
	// Dependencies and generated output.
	'node_modules',
	'vendor',
	'artifacts',

	// Source material and working notes — never part of the product.
	'designs',
	'design-reference',
	'tools',

	// Blocks the importer generated from somebody's design. They belong to the
	// site they were built for, not to the theme: shipping them would put one
	// client's markup and copy into the next client's install, and a theme
	// update would overwrite the very files an editor had been editing.
	'blocks/design',
	// The fixture design and the integration tests: development only.
	'tests',
	// docs/ ships: GUIDE.md is the client manual and ACCESSIBILITY.md is the
	// conformance report — both are handed over with the site. The rest stay
	// inside the studio: BLOCK_SPEC.md for whoever writes a new block,
	// TECHNICAL.md for whoever inherits the codebase, and MANAGEMENT.md, which
	// carries our hours and our margins.
	'docs/BLOCK_SPEC.md',
	'docs/IMPORT_SPEC.md',
	'docs/TECHNICAL.md',
	'docs/MANAGEMENT.md',

	// Version control and editor state.
	'.git',
	'.github',
	'.claude',
	'.idea',
	'.vscode',

	// Development configuration: useless without npm and composer.
	'package.json',
	'package-lock.json',
	'composer.json',
	'composer.lock',
	'phpcs.xml.dist',
	'.htmlvalidate.json',
	'.editorconfig',
	'.gitattributes',
	'.gitignore',

	// Instructions for contributors, not for buyers.
	'CLAUDE.md',
	'README.md',

	// Noise.
	'.DS_Store',
	'Thumbs.db',
];

/**
 * Files without which the archive is not a theme.
 *
 * A block theme needs templates/index.html where a classic theme needs
 * index.php; WordPress 5.9 and later will not activate one without it.
 */
const REQUIRED = [
	'style.css',
	'functions.php',
	'theme.json',
	'readme.txt',
	'screenshot.png',
	'templates/index.html',
	'parts/header.html',
	'parts/footer.html',
];

/** Suffixes that are always excluded, wherever they appear. */
const EXCLUDE_SUFFIX = [ '.log', '.zip', '.map' ];

/**
 * Should this relative path be left out?
 *
 * @param {string} rel Path relative to the theme root, forward slashes.
 * @returns {boolean}
 */
const excluded = ( rel ) => {
	if ( EXCLUDE_SUFFIX.some( ( s ) => rel.endsWith( s ) ) ) {
		return true;
	}

	return EXCLUDE.some( ( pattern ) => {
		if ( pattern.includes( '/' ) ) {
			return rel === pattern || rel.startsWith( `${ pattern }/` );
		}

		const segments = rel.split( '/' );
		return segments.includes( pattern );
	} );
};

/** Every shippable file, sorted so two runs of the same tree agree byte for byte. */
const collect = ( dir = root, found = [] ) => {
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const absolute = resolve( dir, entry.name );
		const rel = relative( root, absolute ).split( sep ).join( '/' );

		if ( excluded( rel ) ) {
			continue;
		}

		if ( entry.isDirectory() ) {
			collect( absolute, found );
		} else if ( entry.isFile() ) {
			found.push( rel );
		}
	}

	return found.sort();
};

// ---------------------------------------------------------------- ZIP writer

/** CRC-32 table, built once. */
const CRC_TABLE = ( () => {
	const table = new Int32Array( 256 );

	for ( let n = 0; n < 256; n++ ) {
		let c = n;
		for ( let k = 0; k < 8; k++ ) {
			c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
		}
		table[ n ] = c;
	}

	return table;
} )();

/**
 * CRC-32 of a buffer, as an unsigned 32-bit integer.
 *
 * @param {Buffer} buffer Bytes to sum.
 * @returns {number}
 */
const crc32 = ( buffer ) => {
	let c = -1;

	for ( let i = 0; i < buffer.length; i++ ) {
		c = CRC_TABLE[ ( c ^ buffer[ i ] ) & 0xff ] ^ ( c >>> 8 );
	}

	return ( c ^ -1 ) >>> 0;
};

/**
 * A file's modification time in MS-DOS date/time form.
 *
 * @param {Date} date Modification time.
 * @returns {{time:number,date:number}}
 */
const dosStamp = ( date ) => {
	const year = Math.max( 1980, date.getFullYear() );

	return {
		time: ( date.getHours() << 11 ) | ( date.getMinutes() << 5 ) | ( date.getSeconds() >> 1 ),
		date: ( ( year - 1980 ) << 9 ) | ( ( date.getMonth() + 1 ) << 5 ) | date.getDate(),
	};
};

/**
 * Build a ZIP archive from a list of { name, body, mtime } entries.
 *
 * Entries are deflated unless deflating makes them bigger, in which case they
 * are stored. Names are written with the UTF-8 flag set (bit 11), which is
 * what every unzipper made this century reads.
 *
 * @param {Array<{name:string,body:Buffer,mtime:Date}>} entries Archive members.
 * @returns {Buffer}
 */
const zip = ( entries ) => {
	const chunks = [];
	const central = [];
	let offset = 0;

	for ( const { name, body, mtime } of entries ) {
		const nameBytes = Buffer.from( name, 'utf8' );
		const stamp = dosStamp( mtime );
		const sum = crc32( body );

		const deflated = deflateRawSync( body, { level: 9 } );
		const store = deflated.length >= body.length;
		const payload = store ? body : deflated;
		const method = store ? 0 : 8;

		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( 0x04034b50, 0 );
		local.writeUInt16LE( 20, 4 ); // Version needed.
		local.writeUInt16LE( 0x0800, 6 ); // UTF-8 names.
		local.writeUInt16LE( method, 8 );
		local.writeUInt16LE( stamp.time, 10 );
		local.writeUInt16LE( stamp.date, 12 );
		local.writeUInt32LE( sum, 14 );
		local.writeUInt32LE( payload.length, 18 );
		local.writeUInt32LE( body.length, 22 );
		local.writeUInt16LE( nameBytes.length, 26 );
		local.writeUInt16LE( 0, 28 ); // No extra field.

		chunks.push( local, nameBytes, payload );

		const header = Buffer.alloc( 46 );
		header.writeUInt32LE( 0x02014b50, 0 );
		header.writeUInt16LE( 0x031e, 4 ); // Made by: UNIX, spec 3.0 — carries the mode below.
		header.writeUInt16LE( 20, 6 );
		header.writeUInt16LE( 0x0800, 8 );
		header.writeUInt16LE( method, 10 );
		header.writeUInt16LE( stamp.time, 12 );
		header.writeUInt16LE( stamp.date, 14 );
		header.writeUInt32LE( sum, 16 );
		header.writeUInt32LE( payload.length, 20 );
		header.writeUInt32LE( body.length, 24 );
		header.writeUInt16LE( nameBytes.length, 28 );
		header.writeUInt16LE( 0, 30 ); // Extra.
		header.writeUInt16LE( 0, 32 ); // Comment.
		header.writeUInt16LE( 0, 34 ); // Disk.
		header.writeUInt16LE( 0, 36 ); // Internal attributes.
		// Regular file, rw-r--r--. Shifted into the high half, which overflows
		// a signed 32-bit integer in JavaScript, so force it unsigned.
		header.writeUInt32LE( ( 0o100644 << 16 ) >>> 0, 38 );
		header.writeUInt32LE( offset, 42 );

		central.push( header, nameBytes );
		offset += local.length + nameBytes.length + payload.length;
	}

	const directory = Buffer.concat( central );

	const end = Buffer.alloc( 22 );
	end.writeUInt32LE( 0x06054b50, 0 );
	end.writeUInt16LE( 0, 4 );
	end.writeUInt16LE( 0, 6 );
	end.writeUInt16LE( entries.length, 8 );
	end.writeUInt16LE( entries.length, 10 );
	end.writeUInt32LE( directory.length, 12 );
	end.writeUInt32LE( offset, 16 );
	end.writeUInt16LE( 0, 20 );

	return Buffer.concat( [ ...chunks, directory, end ] );
};

// --------------------------------------------------------------------- main

/** The style.css header, which is the one place a release is described. */
const styleHeader = readFileSync( resolve( root, 'style.css' ), 'utf8' ).slice( 0, 2048 );

/**
 * One field of the style.css header.
 *
 * @param {string} name Header name, e.g. "Requires PHP".
 * @returns {string} Trimmed value, or an empty string.
 */
const headerField = ( name ) => {
	const match = styleHeader.match( new RegExp( `^\\s*(?:\\*\\s*)?${ name }:\\s*(.+)$`, 'm' ) );
	return match ? match[ 1 ].trim() : '';
};

/** The one place a release number is declared. */
const version = ( () => {
	const found = headerField( 'Version' );

	if ( ! found ) {
		console.error( 'ERROR  style.css has no Version header — nothing to name the archive after.' );
		process.exit( 1 );
	}

	return found;
} )();

/**
 * A --flag value from argv, falling back to an environment variable.
 *
 * Accepts both `--flag value` and `--flag=value`.
 *
 * @param {string} flag Flag name without dashes.
 * @param {string} env  Environment variable name.
 * @returns {string}
 */
const option = ( flag, env ) => {
	const argv = process.argv.slice( 2 );
	const index = argv.indexOf( `--${ flag }` );

	if ( index > -1 && argv[ index + 1 ] && ! argv[ index + 1 ].startsWith( '--' ) ) {
		return argv[ index + 1 ].trim();
	}

	const inline = argv.find( ( a ) => a.startsWith( `--${ flag }=` ) );
	if ( inline ) {
		return inline.slice( flag.length + 3 ).trim();
	}

	return ( process.env[ env ] ?? '' ).trim();
};

/**
 * Where the ZIP will be served from. The placeholder is deliberately not a
 * real host: a manifest pointing at it is refused by the theme's own host
 * allow-list, so forgetting to set this cannot ship a working-looking but
 * broken update.
 */
const PLACEHOLDER_BASE = 'https://downloads.example.invalid/';
const downloadBase = option( 'download-base', 'DOWNLOAD_BASE' ) || PLACEHOLDER_BASE;
const detailsUrl = option( 'details-url', 'DETAILS_URL' ) || `${ headerField( 'Theme URI' ) || 'https://qwerty-soft.com/themes/signal/' }changelog/`;

if ( ! /^https:\/\/[^\s/]+\/.*$/.test( downloadBase ) ) {
	console.error( `ERROR  --download-base must be an https:// URL with a trailing path, got "${ downloadBase }".` );
	process.exit( 1 );
}

/** Everything that claims to know the version has to agree with style.css. */
const agree = () => {
	const problems = [];

	const stable = readFileSync( resolve( root, 'readme.txt' ), 'utf8' ).match( /^Stable tag:\s*(.+)$/m );
	if ( ! stable || stable[ 1 ].trim() !== version ) {
		problems.push( `readme.txt "Stable tag" is ${ stable ? stable[ 1 ].trim() : 'missing' }, style.css says ${ version }` );
	}

	const pkg = JSON.parse( readFileSync( resolve( root, 'package.json' ), 'utf8' ) );
	if ( pkg.version !== version ) {
		problems.push( `package.json version is ${ pkg.version }, style.css says ${ version }` );
	}

	return problems;
};

const disagreements = agree();

if ( disagreements.length > 0 ) {
	console.error( 'ERROR  the release number does not agree with itself:\n' );
	disagreements.forEach( ( p ) => console.error( `  · ${ p }` ) );
	console.error( '\nFix the mismatch before packaging — a client who reports a bug against' );
	console.error( 'the wrong version costs more than the minute this takes.' );
	process.exit( 1 );
}

const files = collect();
const missing = REQUIRED.filter( ( f ) => ! files.includes( f ) );

if ( missing.length > 0 ) {
	console.error( `ERROR  the archive would not be a theme — missing: ${ missing.join( ', ' ) }` );
	process.exit( 1 );
}

const entries = files.map( ( rel ) => ( {
	name: `${ SLUG }/${ rel }`,
	body: readFileSync( resolve( root, rel ) ),
	mtime: statSync( resolve( root, rel ) ).mtime,
} ) );

const archive = zip( entries );
const outDir = resolve( root, 'artifacts/release' );
const outFile = resolve( outDir, `${ SLUG }-${ version }.zip` );

mkdirSync( outDir, { recursive: true } );
writeFileSync( outFile, archive );

const sha256 = createHash( 'sha256' ).update( archive ).digest( 'hex' );

// The manifest inc/Modules/Updates.php fetches. Field names are the contract.
const manifest = {
	version,
	download_url: `${ downloadBase.replace( /\/?$/, '/' ) }${ SLUG }-${ version }.zip`,
	requires: headerField( 'Requires at least' ),
	requires_php: headerField( 'Requires PHP' ),
	tested: headerField( 'Tested up to' ),
	sha256,
	details_url: detailsUrl,
};

const manifestFile = resolve( outDir, 'update.json' );
writeFileSync( manifestFile, `${ JSON.stringify( manifest, null, 2 ) }\n` );
writeFileSync( `${ outFile }.sha256`, `${ sha256 }  ${ SLUG }-${ version }.zip\n` );

const raw = entries.reduce( ( total, e ) => total + e.body.length, 0 );
const kb = ( n ) => `${ ( n / 1024 ).toFixed( 0 ) } KB`;

console.log( `\nqwerty-soft-signal ${ version }` );
console.log( `  ${ entries.length } files · ${ kb( raw ) } raw · ${ kb( archive.length ) } packed` );
console.log( `  ${ relative( root, outFile ).split( sep ).join( '/' ) }` );
console.log( `  sha256 ${ sha256 }` );
console.log( `  ${ relative( root, manifestFile ).split( sep ).join( '/' ) } → ${ manifest.download_url }` );

if ( downloadBase === PLACEHOLDER_BASE ) {
	console.log( '  NOTE   download_url is a placeholder. Pass --download-base (or set DOWNLOAD_BASE)' );
	console.log( '         to the URL the ZIP will be served from before publishing update.json.' );
}

console.log( '' );

// A tree of directories, so the operator can see at a glance what shipped.
const byDir = new Map();

for ( const rel of files ) {
	const key = rel.includes( '/' ) ? `${ rel.split( '/' )[ 0 ] }/` : '(root)';
	byDir.set( key, ( byDir.get( key ) ?? 0 ) + 1 );
}

for ( const [ dir, count ] of [ ...byDir ].sort() ) {
	console.log( `  ${ String( count ).padStart( 4 ) }  ${ dir }` );
}

console.log( '' );
