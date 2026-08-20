/**
 * Finding a PHP interpreter, once, for every tool that needs one.
 *
 * Composer installs PHPCS but is not always on PATH, and neither is php. Rather
 * than each tool re-inventing the search — and failing for a reason that has
 * nothing to do with the code under test — they all ask here.
 *
 * @package Wow\Signal
 */

import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';

/**
 * Places a PHP binary tends to be, in order of preference.
 *
 * PATH first, so a machine with PHP configured properly uses that one.
 */
const CANDIDATES = [
	'php',
	'C:/xampp/php/php.exe',
	'D:/Work/XAMPP/php/php.exe',
	'C:/laragon/bin/php/php.exe',
	'/usr/local/bin/php',
	'/usr/bin/php',
];

/** Does this command answer to `-v`? */
const works = ( command ) => {
	if ( command.includes( '/' ) && ! existsSync( command ) ) {
		return false;
	}

	const probe = spawnSync( command, [ '-v' ], { stdio: 'ignore', shell: false } );

	return ! probe.error && 0 === probe.status;
};

/**
 * A PHP interpreter, or null if there is none.
 *
 * @returns {string|null}
 */
export const findPhp = () => CANDIDATES.find( works ) ?? null;

/**
 * A PHP interpreter, or a clear exit explaining what to install.
 *
 * @returns {string}
 */
export const requirePhp = () => {
	const php = findPhp();

	if ( null === php ) {
		console.error( 'ERROR  no PHP interpreter found.' );
		console.error( '       Add php to PATH — the PHP gates cannot run without one.' );
		process.exit( 1 );
	}

	return php;
};
