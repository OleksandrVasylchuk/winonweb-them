<?php
/**
 * Module contract.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Every theme module implements this and does all of its hooking in register().
 *
 * Constructors stay side-effect free so modules can be instantiated in tests
 * without touching WordPress globals.
 */
interface Module {

	/**
	 * Attach the module's hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
