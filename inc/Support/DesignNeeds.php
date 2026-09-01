<?php
/**
 * What a design archive needs from the site, read before anything is built.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an unpacked archive and says which plugins the site will want.
 *
 * A handoff that ships a product catalogue — hundreds of records in a data
 * file, a folder of SKU-named photographs — is asking for a shop, and the
 * person importing it found that out the hard way: pages built, listings
 * empty, and a WooCommerce install plus a hand-written import afterwards.
 * The archive said "catalogue" from the start; nothing was listening.
 *
 * This listens. It names what it found, in a sentence, with the evidence —
 * and the import screen turns each need into one button. Deliberately short
 * of magic: it recommends and installs on a click, it never installs on its
 * own, and it only ever names plugins from its own short list.
 */
final class DesignNeeds {

	/**
	 * Largest data file that is read while scanning, in bytes.
	 *
	 * @var int
	 */
	private const MAX_FILE = 4194304;

	/**
	 * What one archive needs, each row ready for the screen.
	 *
	 * @param string $root Design root directory.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan( string $root ): array {
		$root  = rtrim( str_replace( '\\', '/', $root ), '/' );
		$needs = array();

		if ( ! is_dir( $root ) ) {
			return $needs;
		}

		$catalog = self::catalog( $root );

		if ( null !== $catalog ) {
			$needs[] = array(
				'key'       => 'woocommerce',
				'name'      => 'WooCommerce',
				'plugin'    => 'woocommerce/woocommerce.php',
				'installed' => self::installed( 'woocommerce/woocommerce.php' ),
				'active'    => self::active( 'woocommerce/woocommerce.php' ),
				'catalog'   => (string) $catalog['file'],
				'records'   => (int) $catalog['records'],
				'why'       => sprintf(
					/* translators: 1: number of catalogue records, 2: the data file they sit in. */
					__( 'The archive carries a product catalogue: %1$d records in %2$s. With WooCommerce installed, the build imports them as products — pictures matched by code — and the design’s product listings draw from them.', 'qwerty-soft-signal' ),
					(int) $catalog['records'],
					basename( (string) $catalog['file'] )
				),
			);
		}

		return $needs;
	}

	/**
	 * The archive's product catalogue, when it ships one.
	 *
	 * A catalogue is a data file — JSON, or a TS module that is JSON behind
	 * an export — holding an array of records that look like products: a
	 * code or SKU, a name, a description. Named files are tried first;
	 * anything else would mean reading every byte of a 300 MB handoff.
	 *
	 * @param string $root Design root directory.
	 * @return array{file:string, records:int}|null
	 */
	public static function catalog( string $root ): ?array {
		$candidates = array();

		try {
			$walk = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $walk as $file ) {
				if ( ! $file->isFile() || $file->getSize() > self::MAX_FILE ) {
					continue;
				}

				$name = strtolower( $file->getFilename() );
				$path = str_replace( '\\', '/', $file->getPathname() );

				if ( str_contains( $path, '/node_modules/' ) ) {
					continue;
				}

				if ( 1 === preg_match( '/(catalog|products?)[a-z0-9_-]*\.(json|ts)$/i', $name ) ) {
					$candidates[] = $path;
				}
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		$best = null;

		foreach ( $candidates as $path ) {
			$records = self::records_in( $path );

			if ( count( $records ) >= 5 && ( null === $best || count( $records ) > $best['records'] ) ) {
				$best = array(
					'file'    => ltrim( substr( $path, strlen( rtrim( str_replace( '\\', '/', $root ), '/' ) ) ), '/' ),
					'records' => count( $records ),
				);
			}
		}

		return $best;
	}

	/**
	 * The product records inside one data file, if that is what it holds.
	 *
	 * @param string $path Absolute file path.
	 * @return array<int, array<string, mixed>>
	 */
	public static function records_in( string $path ): array {
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

		/*
		 * A TS module is JSON behind an export: slice from the first `= [` to
		 * the closing bracket, minding the `] as SomeType[]` assertion that
		 * follows it in generated files.
		 */
		if ( 1 === preg_match( '/\.ts$/i', $path ) ) {
			$start = strpos( $raw, '= [' );

			if ( false === $start ) {
				return array();
			}

			$end = strpos( $raw, '] as ', $start );
			$end = false === $end ? strrpos( $raw, ']' ) : $end;

			if ( false === $end ) {
				return array();
			}

			$raw = substr( $raw, $start + 2, $end - $start - 1 );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || array() === $data || ! isset( $data[0] ) || ! is_array( $data[0] ) ) {
			return array();
		}

		/*
		 * Product-shaped or not ours: a code or SKU, and a name. Anything
		 * without both is some other list — routes, translations, menus.
		 */
		$first = $data[0];
		$code  = isset( $first['code'] ) || isset( $first['sku'] );
		$name  = isset( $first['name'] ) || isset( $first['title'] );

		return $code && $name ? $data : array();
	}

	/**
	 * Whether a plugin is on disk.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function installed( string $plugin ): bool {
		return is_file( WP_PLUGIN_DIR . '/' . $plugin );
	}

	/**
	 * Whether a plugin is active.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function active( string $plugin ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin );
	}
}
