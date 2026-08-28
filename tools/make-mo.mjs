#!/usr/bin/env node
/**
 * Build a .po and a binary .mo from the POT plus a translation dictionary.
 *
 * The dictionaries live in tools/i18n/<locale>.json, grouped by gettext
 * context, so translators edit plain readable JSON instead of PO syntax and
 * the binary artefact is always regenerated rather than hand-maintained.
 *
 * Output is named <locale>.po / <locale>.mo, because that is what
 * load_theme_textdomain() looks for inside a theme's own languages directory.
 * The <domain>-<locale>.mo form only applies to wp-content/languages/themes/.
 *
 * Usage: npm run make:mo
 *
 * @package Qwerty\Soft
 */

import { readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join, basename } from 'node:path';
import { createHash } from 'node:crypto';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const DOMAIN = 'qwerty-soft-signal';

/** The theme version, read from the one place that owns it: style.css. */
const VERSION = ( () => {
	const header = readFileSync( resolve( root, 'style.css' ), 'utf8' ).slice( 0, 2048 );
	const match = header.match( /^\s*(?:\*\s*)?Version:\s*(.+)$/m );
	return match ? match[ 1 ].trim() : '0.0.0';
} )();

/*
 * gettext separators. A contextual string is keyed as
 *   context + EOT + msgid
 * and a plural as
 *   msgid + NUL + msgid_plural
 * with the translated forms themselves joined by NUL. Get either wrong and the
 * .mo still parses cleanly while translating nothing at all.
 */
const EOT = String.fromCharCode( 4 );
const NUL = String.fromCharCode( 0 );

/** Plural rules per locale, in the exact form gettext expects. */
const PLURALS = {
	uk: 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
};

const LANGUAGE_NAMES = { uk: 'Ukrainian' };

const unescapePo = ( s ) =>
	s
		.replace( /\\n/g, '\n' )
		.replace( /\\t/g, '\t' )
		.replace( /\\"/g, '"' )
		.replace( /\\\\/g, '\\' );

const escapePo = ( s ) =>
	s
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\n/g, '\\n' )
		.replace( /\t/g, '\\t' );

/** Parse the POT into entries. */
function readPot() {
	const src = readFileSync( join( root, 'languages', DOMAIN + '.pot' ), 'utf8' );
	const chunks = src.split( /\n\n+/ ).slice( 1 );
	const entries = [];

	for ( const chunk of chunks ) {
		const ctx = chunk.match( /^msgctxt "(.*)"$/m );
		const id = chunk.match( /^msgid "(.*)"$/m );
		const plural = chunk.match( /^msgid_plural "(.*)"$/m );
		const refs = [ ...chunk.matchAll( /^#: (.+)$/gm ) ].map( ( m ) => m[ 1 ] );
		const comments = [ ...chunk.matchAll( /^#\. (.+)$/gm ) ].map( ( m ) => m[ 1 ] );

		if ( ! id ) {
			continue;
		}

		entries.push( {
			context: ctx ? unescapePo( ctx[ 1 ] ) : null,
			msgid: unescapePo( id[ 1 ] ),
			plural: plural ? unescapePo( plural[ 1 ] ) : null,
			references: refs,
			comments,
		} );
	}

	return entries;
}

/** Build the binary MO payload. */
function buildMo( pairs ) {
	// gettext expects the table sorted by the original string.
	pairs.sort( ( a, b ) => ( a.key < b.key ? -1 : a.key > b.key ? 1 : 0 ) );

	const originals = pairs.map( ( p ) => Buffer.from( p.key, 'utf8' ) );
	const translations = pairs.map( ( p ) => Buffer.from( p.value, 'utf8' ) );
	const count = pairs.length;

	const headerSize = 28;
	const tableSize = count * 8;
	let offset = headerSize + tableSize * 2;

	const originalTable = Buffer.alloc( tableSize );
	const translationTable = Buffer.alloc( tableSize );
	const data = [];
	const terminator = Buffer.alloc( 1 );

	originals.forEach( ( buf, i ) => {
		originalTable.writeUInt32LE( buf.length, i * 8 );
		originalTable.writeUInt32LE( offset, i * 8 + 4 );
		data.push( buf, terminator );
		offset += buf.length + 1;
	} );

	translations.forEach( ( buf, i ) => {
		translationTable.writeUInt32LE( buf.length, i * 8 );
		translationTable.writeUInt32LE( offset, i * 8 + 4 );
		data.push( buf, terminator );
		offset += buf.length + 1;
	} );

	const header = Buffer.alloc( headerSize );
	header.writeUInt32LE( 0x950412de, 0 ); // magic, little-endian
	header.writeUInt32LE( 0, 4 ); // revision
	header.writeUInt32LE( count, 8 );
	header.writeUInt32LE( headerSize, 12 ); // originals table offset
	header.writeUInt32LE( headerSize + tableSize, 16 ); // translations table offset
	header.writeUInt32LE( 0, 20 ); // hash table size — unused
	header.writeUInt32LE( headerSize + tableSize * 2, 24 ); // hash offset

	return Buffer.concat( [ header, originalTable, translationTable, ...data ] );
}

const dictDir = join( root, 'tools', 'i18n' );

if ( ! existsSync( dictDir ) ) {
	console.error( 'No tools/i18n directory — nothing to build.' );
	process.exit( 1 );
}

const entries = readPot();
const locales = readdirSync( dictDir ).filter( ( f ) => f.endsWith( '.json' ) );

if ( ! existsSync( join( root, 'languages' ) ) ) {
	mkdirSync( join( root, 'languages' ), { recursive: true } );
}

/*
 * Taken from the POT's own timestamp rather than the clock, so rebuilding
 * without changing a string produces byte-identical catalogues and an empty
 * git diff.
 */
const revision = statSync( join( root, 'languages', DOMAIN + '.pot' ) )
	.mtime.toISOString()
	.replace( /\.\d+Z$/, '+0000' );

let exitCode = 0;

for ( const file of locales ) {
	const locale = basename( file, '.json' );
	const dict = JSON.parse( readFileSync( join( dictDir, file ), 'utf8' ) );
	const pluralRule = PLURALS[ locale ] ?? 'nplurals=2; plural=(n != 1);';
	const nplurals = Number( pluralRule.match( /nplurals=(\d+)/ )[ 1 ] );

	const lookup = ( context, msgid ) => {
		const group = dict[ context ?? '(code)' ];
		return group ? group[ msgid ] : undefined;
	};

	const poLines = [
		'# ' + ( LANGUAGE_NAMES[ locale ] ?? locale ) + ' translation for Qwerty Soft — Signal.',
		'# Copyright (C) 2026 Qwerty Soft',
		'# This file is distributed under the GNU General Public License v2 or later.',
		'msgid ""',
		'msgstr ""',
		`"Project-Id-Version: Qwerty Soft — Signal ${ VERSION }\\n"`,
		'"MIME-Version: 1.0\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		'"Language: ' + locale + '\\n"',
		'"Language-Team: ' + ( LANGUAGE_NAMES[ locale ] ?? locale ) + '\\n"',
		'"Plural-Forms: ' + pluralRule + '\\n"',
		'"X-Domain: ' + DOMAIN + '\\n"',
		'',
	];

	const moHeader = [
		`Project-Id-Version: Qwerty Soft — Signal ${ VERSION }`,
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: 8bit',
		'Language: ' + locale,
		'Plural-Forms: ' + pluralRule,
		'',
	].join( '\n' );

	const pairs = [ { key: '', value: moHeader } ];
	let translated = 0;
	const missing = [];

	/*
	 * Strings a script asks for at runtime cannot come from the .mo — the
	 * browser never sees it. WordPress loads a JSON catalogue per script
	 * instead, so collect each JS file's strings while walking the entries.
	 */
	const scripts = new Map();

	for ( const entry of entries ) {
		const value = lookup( entry.context, entry.msgid );

		for ( const comment of entry.comments ) {
			poLines.push( '#. ' + comment );
		}

		if ( entry.references.length ) {
			poLines.push( '#: ' + entry.references.join( ' ' ) );
		}

		if ( entry.context ) {
			poLines.push( 'msgctxt "' + escapePo( entry.context ) + '"' );
		}

		poLines.push( 'msgid "' + escapePo( entry.msgid ) + '"' );

		const key = entry.context ? entry.context + EOT + entry.msgid : entry.msgid;

		if ( entry.plural ) {
			poLines.push( 'msgid_plural "' + escapePo( entry.plural ) + '"' );

			const forms = [];

			for ( let i = 0; i < nplurals; i++ ) {
				const form = 0 === i ? value : lookup( entry.context, entry.msgid + '|plural|' + i );
				forms.push( form ?? '' );
				poLines.push( 'msgstr[' + i + '] "' + escapePo( form ?? '' ) + '"' );
			}

			if ( forms.every( ( f ) => '' !== f ) ) {
				translated++;
				pairs.push( { key: key + NUL + entry.plural, value: forms.join( NUL ) } );
			} else {
				missing.push( entry.msgid );
			}
		} else {
			poLines.push( 'msgstr "' + escapePo( value ?? '' ) + '"' );

			if ( undefined !== value && '' !== value ) {
				translated++;
				pairs.push( { key, value } );
			} else {
				missing.push( ( entry.context ? entry.context + ' / ' : '' ) + entry.msgid );
			}
		}

		// Record this string against every script that asks for it.
		if ( undefined !== value && '' !== value ) {
			const files = entry.references
				.flatMap( ( line ) => line.split( /\s+/ ) )
				.map( ( ref ) => ref.replace( /:\d+$/, '' ) )
				.filter( ( ref ) => ref.endsWith( '.js' ) );

			for ( const script of new Set( files ) ) {
				if ( ! scripts.has( script ) ) {
					scripts.set( script, {} );
				}

				const jed = scripts.get( script );
				const jedKey = entry.context ? entry.context + EOT + entry.msgid : entry.msgid;

				jed[ jedKey ] = entry.plural
					? Array.from( { length: nplurals }, ( _, i ) =>
							( 0 === i ? value : lookup( entry.context, entry.msgid + '|plural|' + i ) ) ?? ''
					  )
					: [ value ];
			}
		}

		poLines.push( '' );
	}

	writeFileSync( join( root, 'languages', locale + '.po' ), poLines.join( '\n' ), 'utf8' );
	writeFileSync( join( root, 'languages', locale + '.mo' ), buildMo( pairs ) );

	/*
	 * One catalogue per script, named the way load_script_textdomain() looks
	 * for it: <domain>-<locale>-<md5 of the path relative to the theme>.json.
	 * The payload keeps gettext's own "messages" domain, which is what
	 * wp.i18n expects on the other end.
	 */
	for ( const [ script, jed ] of scripts ) {
		const name = DOMAIN + '-' + locale + '-' + createHash( 'md5' ).update( script ).digest( 'hex' ) + '.json';

		writeFileSync(
			join( root, 'languages', name ),
			JSON.stringify(
				{
					domain: 'messages',
					'translation-revision-date': revision,
					generator: 'qwerty-soft-signal/make-mo.mjs',
					locale_data: {
						messages: {
							'': {
								domain: 'messages',
								lang: locale,
								'plural-forms': pluralRule,
							},
							...jed,
						},
					},
				},
				null,
				'\t'
			) + '\n',
			'utf8'
		);
	}

	console.log(
		'  ' + scripts.size + ' script catalogue(s) written for ' + locale + '.'
	);

	console.log(
		locale + ': ' + translated + '/' + entries.length + ' strings translated (' +
			Math.round( ( translated / entries.length ) * 100 ) + '%).'
	);

	if ( missing.length ) {
		exitCode = 1;
		console.log( '  ' + missing.length + ' untranslated:' );
		missing.slice( 0, 20 ).forEach( ( m ) => console.log( '    - ' + m.slice( 0, 90 ) ) );

		if ( missing.length > 20 ) {
			console.log( '    … and ' + ( missing.length - 20 ) + ' more' );
		}
	}
}

process.exit( exitCode );
