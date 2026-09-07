<?php
/**
 * The shop half of the theme, kept folded until a design asks for it.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Copies shop-kit/ into place, once, when the site turns out to need a shop.
 *
 * The theme a client receives is the theme from zero: ten templates, no
 * WooCommerce module, nothing in the Site Editor about carts or checkouts.
 * Most sites the studio builds never sell anything, and seven shop templates
 * that only exist to be hidden are seven things to explain.
 *
 * The shop half still ships — folded, under shop-kit/, where WordPress does
 * not look. When an import finds a shop in the design, the import screen's
 * one button installs WooCommerce and calls install() here, which unfolds the
 * kit: shop-kit/templates/* become templates/*, shop-kit/inc/* become inc/*,
 * and the next request boots a theme that has a shop in it.
 *
 * Deliberately one-way. A file that already exists is never overwritten —
 * once it is on the site it belongs to the site, and the person who edited
 * the checkout template did not ask for it back the way we wrote it.
 */
final class ShopKit {

	/**
	 * Where the folded copy lives, inside the theme.
	 *
	 * @return string Absolute path, no trailing slash.
	 */
	public static function dir(): string {
		return rtrim( wp_normalize_path( get_template_directory() ), '/' ) . '/shop-kit';
	}

	/**
	 * Whether this copy of the theme carries the kit at all.
	 *
	 * A studio checkout always does. A site whose kit has been unfolded and
	 * then updated still does, because a theme update ships the kit again —
	 * install() is what refuses to undo the site's own edits, not this.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return is_dir( self::dir() );
	}

	/**
	 * Every file the kit holds: source path => where it belongs in the theme.
	 *
	 * Both sides are relative to the theme root, so the map reads as what it
	 * is — shop-kit/templates/cart.html becomes templates/cart.html.
	 *
	 * @return array<string, string>
	 */
	public static function files(): array {
		$kit   = self::dir();
		$files = array();

		if ( ! is_dir( $kit ) ) {
			return $files;
		}

		try {
			$walk = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $kit, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $walk as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$path = wp_normalize_path( $file->getPathname() );
				$rel  = ltrim( substr( $path, strlen( $kit ) ), '/' );

				// The kit's own notes are for whoever opens the folder, not for the theme.
				if ( '' === $rel || str_starts_with( $rel, 'README' ) ) {
					continue;
				}

				$files[ 'shop-kit/' . $rel ] = $rel;
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		ksort( $files );

		return $files;
	}

	/**
	 * Recorded when this site unfolds the kit.
	 *
	 * The filesystem says whether the shop half is here; only a record says
	 * whether it is *supposed* to be. A theme update deletes the unfolded
	 * copies along with everything else the release ZIP does not carry, and
	 * without this a recovery could not tell a shop site that just lost its
	 * templates from a site running WooCommerce that never imported a shop —
	 * and would hand the second one seven templates it never asked for.
	 *
	 * @var string
	 */
	public const INSTALLED = 'qwerty_soft_shop_kit_installed';

	/**
	 * Whether the kit is already unfolded on this site.
	 *
	 * True only when every file of it is in place: a half-copied kit is a
	 * theme with a checkout template and no module to declare shop support,
	 * and reporting that as installed would hide the one thing worth fixing.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		$files = self::files();

		if ( array() === $files ) {
			return false;
		}

		$root = rtrim( wp_normalize_path( get_template_directory() ), '/' );

		foreach ( $files as $destination ) {
			if ( ! is_file( $root . '/' . $destination ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Unfold the kit into the theme.
	 *
	 * @return array{copied:int, kept:int, files:array<int, string>}|WP_Error What moved, or why nothing did.
	 */
	public static function install() {
		$files = self::files();

		if ( array() === $files ) {
			return new WP_Error(
				'qwerty_soft_no_shop_kit',
				__( 'This copy of the theme has no shop-kit folder, so there is no shop half to add. Re-upload the theme ZIP and try again.', 'qwerty-soft-signal' ),
				array( 'status' => 500 )
			);
		}

		$root   = rtrim( wp_normalize_path( get_template_directory() ), '/' );
		$copied = array();
		$kept   = 0;

		foreach ( $files as $source => $destination ) {
			$to = $root . '/' . $destination;

			// Already on the site: leave it exactly as the site has it.
			if ( is_file( $to ) ) {
				++$kept;
				continue;
			}

			$dir = dirname( $to );

			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'qwerty_soft_shop_kit_dir',
					sprintf(
						/* translators: %s: a directory inside the theme. */
						__( 'The shop half could not be added: %s cannot be created. The theme folder is not writable by the web server.', 'qwerty-soft-signal' ),
						$dir
					),
					array( 'status' => 500 )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Theme-local copy; WP_Filesystem would ask for FTP credentials to move a file the theme already owns.
			if ( ! copy( $root . '/' . $source, $to ) ) {
				return new WP_Error(
					'qwerty_soft_shop_kit_copy',
					sprintf(
						/* translators: %s: a file inside the theme. */
						__( 'The shop half could not be added: %s could not be written. The theme folder is not writable by the web server.', 'qwerty-soft-signal' ),
						$destination
					),
					array( 'status' => 500 )
				);
			}

			$copied[] = $destination;
		}

		/*
		 * The templates are new files in a folder WordPress has already read
		 * this request. Nothing on this page load will see them; the redirect
		 * after the button will.
		 */
		if ( array() !== $copied ) {
			wp_clean_themes_cache();
		}

		// This site is a shop site now, whatever a later update does to the files.
		update_option( self::INSTALLED, 1, true );

		return array(
			'copied' => count( $copied ),
			'kept'   => $kept,
			'files'  => $copied,
		);
	}
}
