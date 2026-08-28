#!/usr/bin/env node
/**
 * Run the PHP unit tests.
 *
 * A thin wrapper so `npm run test:unit` works the same way the other gates do,
 * and so the tests find an interpreter on a machine where php is not on PATH.
 *
 * Usage: npm run test:unit
 *
 * @package Qwerty\Soft
 */

import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { requirePhp } from './php.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

const result = spawnSync( requirePhp(), [ resolve( root, 'tools/test-support.php' ) ], {
	stdio: 'inherit',
	cwd: root,
	shell: false,
} );

process.exit( result.status ?? 1 );
