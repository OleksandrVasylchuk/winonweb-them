#!/usr/bin/env node
/**
 * Run PHPCS without depending on `composer` being on PATH.
 *
 * `composer lint` is the documented way and stays the documented way, but it
 * only works on a machine where Composer is installed globally. On a machine
 * where it is not — a Windows box running XAMPP, say — `npm run test` used to
 * fail at the PHP gate for a reason that has nothing to do with the code.
 *
 * Composer is still what installs PHPCS. All this does is find the binary it
 * already installed, and an interpreter to run it with.
 *
 * Usage: npm run lint:php
 *
 * @package Qwerty\Soft
 */

import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { requirePhp } from './php.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

const phpcs = resolve( root, 'vendor/bin/phpcs' );

if ( ! existsSync( phpcs ) ) {
	console.error( 'ERROR  vendor/bin/phpcs is missing. Run `composer install` once.' );
	process.exit( 1 );
}

const result = spawnSync( requirePhp(), [ phpcs, ...process.argv.slice( 2 ) ], {
	stdio: 'inherit',
	cwd: root,
	shell: false,
} );

process.exit( result.status ?? 1 );
