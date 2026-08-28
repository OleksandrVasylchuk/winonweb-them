#!/usr/bin/env node
/**
 * Rebuild the binary members of the design fixture and pack it into design.zip.
 *
 * The fixture under tests/fixtures/design/ is a small Claude-Design-style
 * export: three pages wrapped in <x-dc>, shared components pulled in with
 * <dc-import>, a stylesheet, a data.js and a handful of images. The text files
 * are committed as they are; the images are generated here so nobody has to
 * commit a picture to test an importer. Each one is the smallest valid file of
 * its type — a 1×1 JPEG is 125 bytes, a 1×1 PNG is 68.
 *
 * design.zip is what DesignArchive::unpack() receives in the integration
 * tests, so it is regenerated from the folder every time this runs: the
 * folder is the source of truth, the archive is derived.
 *
 * Usage: npm run fixture:zip
 *
 * @package Qwerty\Soft
 */

import { deflateRawSync } from 'node:zlib';
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const design = resolve( here, 'design' );
const archive = resolve( here, 'design.zip' );

// ------------------------------------------------------------------ binaries

/**
 * The smallest JPEG that decoders agree is a JPEG: 1×1, greyscale, baseline.
 *
 * Hand-assembled from the standard markers — SOI, JFIF APP0, a quantisation
 * table, a frame header, two minimal Huffman tables, a one-byte scan, EOI.
 * getimagesize() reads it as 1×1 image/jpeg without GD.
 */
const JPEG_1X1 = Buffer.from(
	'ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc40014000100000000000000000000000000000009ffc40014100100000000000000000000000000000000ffda0008010100003f00d2cf20ffd9',
	'hex'
);

/** A 1×1 transparent PNG — the same bytes Home.dc.html carries as a data: URI. */
const PNG_1X1 = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
	'base64'
);

const binaries = {
	'img/band.jpg': JPEG_1X1,
	'img/one.jpg': JPEG_1X1,
	'img/two.jpg': JPEG_1X1,
	'screenshots/hero-viz.png': PNG_1X1,
};

for ( const [ rel, body ] of Object.entries( binaries ) ) {
	const target = resolve( design, rel );
	mkdirSync( dirname( target ), { recursive: true } );
	writeFileSync( target, body );
}

// ---------------------------------------------------------------- ZIP writer
// The same hand-rolled writer tools/build-zip.mjs uses, for the same reason:
// the repository stays installable without a single runtime dependency.

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

const crc32 = ( buffer ) => {
	let c = -1;

	for ( let i = 0; i < buffer.length; i++ ) {
		c = CRC_TABLE[ ( c ^ buffer[ i ] ) & 0xff ] ^ ( c >>> 8 );
	}

	return ( c ^ -1 ) >>> 0;
};

/**
 * A fixed timestamp so the archive is byte-identical on every machine.
 * 2026-01-01 00:00:00 in MS-DOS form.
 */
const STAMP = { time: 0, date: ( ( 2026 - 1980 ) << 9 ) | ( 1 << 5 ) | 1 };

const zip = ( entries ) => {
	const chunks = [];
	const central = [];
	let offset = 0;

	for ( const { name, body } of entries ) {
		const nameBytes = Buffer.from( name, 'utf8' );
		const sum = crc32( body );
		const deflated = deflateRawSync( body, { level: 9 } );
		const store = deflated.length >= body.length;
		const payload = store ? body : deflated;
		const method = store ? 0 : 8;

		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( 0x04034b50, 0 );
		local.writeUInt16LE( 20, 4 );
		local.writeUInt16LE( 0x0800, 6 );
		local.writeUInt16LE( method, 8 );
		local.writeUInt16LE( STAMP.time, 10 );
		local.writeUInt16LE( STAMP.date, 12 );
		local.writeUInt32LE( sum, 14 );
		local.writeUInt32LE( payload.length, 18 );
		local.writeUInt32LE( body.length, 22 );
		local.writeUInt16LE( nameBytes.length, 26 );
		local.writeUInt16LE( 0, 28 );

		chunks.push( local, nameBytes, payload );

		const header = Buffer.alloc( 46 );
		header.writeUInt32LE( 0x02014b50, 0 );
		header.writeUInt16LE( 0x031e, 4 );
		header.writeUInt16LE( 20, 6 );
		header.writeUInt16LE( 0x0800, 8 );
		header.writeUInt16LE( method, 10 );
		header.writeUInt16LE( STAMP.time, 12 );
		header.writeUInt16LE( STAMP.date, 14 );
		header.writeUInt32LE( sum, 16 );
		header.writeUInt32LE( payload.length, 20 );
		header.writeUInt32LE( body.length, 24 );
		header.writeUInt16LE( nameBytes.length, 28 );
		header.writeUInt16LE( 0, 30 );
		header.writeUInt16LE( 0, 32 );
		header.writeUInt16LE( 0, 34 );
		header.writeUInt16LE( 0, 36 );
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

// --------------------------------------------------------------------- pack

const collect = ( dir, found = [] ) => {
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const absolute = resolve( dir, entry.name );

		if ( entry.isDirectory() ) {
			collect( absolute, found );
		} else if ( entry.isFile() ) {
			found.push( relative( design, absolute ).split( sep ).join( '/' ) );
		}
	}

	return found.sort();
};

const files = collect( design );

// Packed under a wrapper folder, the way a downloaded export arrives.
const entries = files.map( ( rel ) => ( {
	name: `fixture-design/${ rel }`,
	body: readFileSync( resolve( design, rel ) ),
} ) );

const packed = zip( entries );
writeFileSync( archive, packed );

const raw = entries.reduce( ( total, e ) => total + e.body.length, 0 );

if ( raw > 100 * 1024 ) {
	console.error( `ERROR  the fixture is ${ raw } bytes; keep it under 100 KB.` );
	process.exit( 1 );
}

console.log( `design.zip  ${ entries.length } files · ${ raw } bytes raw · ${ packed.length } bytes packed` );
files.forEach( ( f ) => console.log( `  ${ f }  ${ statSync( resolve( design, f ) ).size }` ) );
