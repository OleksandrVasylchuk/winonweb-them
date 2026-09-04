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
	 * Most markup files read while looking for a shop or a form.
	 *
	 * @var int
	 */
	private const MAX_FILES = 400;

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

		$files   = self::markup_files( $root );
		$catalog = self::catalog( $root );
		$shop    = self::shop_signs( $files );

		if ( null !== $catalog || array() !== $shop ) {
			$needs[] = self::shop_need( $catalog, $shop );
		}

		$forms = self::forms( $files );

		if ( array() !== $forms ) {
			$needs[] = self::form_need( $forms );
		}

		return $needs;
	}

	/**
	 * The shop row: what was found, and what the button will do about it.
	 *
	 * Two things reach this. A catalogue is the archive saying it has stock;
	 * a cart in the markup is the design saying it has a shop. Either one on
	 * its own is enough — the handoff that started all this had the catalogue
	 * and no cart, and the one after it had an add-to-cart button on every
	 * card and no data file anywhere.
	 *
	 * @param array{file:string, records:int}|null        $catalog The catalogue, when there is one.
	 * @param array<int, array{says:string, file:string}> $shop    What the markup gave away.
	 * @return array<string, mixed>
	 */
	private static function shop_need( ?array $catalog, array $shop ): array {
		$plugin = 'woocommerce/woocommerce.php';
		$active = self::active( $plugin );
		$kit    = ShopKit::installed();
		$why    = array();

		if ( null !== $catalog ) {
			$why[] = sprintf(
				/* translators: 1: number of catalogue records, 2: the data file they sit in. */
				__( 'The archive carries a product catalogue: %1$d records in %2$s. With WooCommerce installed, the build imports them as products — pictures matched by code — and the design’s product listings draw from them.', 'qwerty-soft-signal' ),
				(int) $catalog['records'],
				basename( (string) $catalog['file'] )
			);
		}

		if ( array() !== $shop ) {
			$seen = array();

			foreach ( $shop as $sign ) {
				$seen[] = sprintf(
					/* translators: 1: what was found, e.g. "an add-to-cart button", 2: the file it was found in. */
					__( '%1$s in %2$s', 'qwerty-soft-signal' ),
					$sign['says'],
					$sign['file']
				);
			}

			$why[] = sprintf(
				/* translators: %s: a list of what the design draws and where. */
				__( 'The design draws a shop: %s.', 'qwerty-soft-signal' ),
				implode( ', ', $seen )
			);
		}

		$why[] = $kit
			? __( 'The theme’s shop half — cart, checkout, product and product-archive templates — is already in place.', 'qwerty-soft-signal' )
			: __( 'One button does the rest: it installs WooCommerce and unfolds the theme’s own shop half with it — the cart, checkout, product and product-archive templates, which the theme ships folded away so that a site with nothing to sell never carries them.', 'qwerty-soft-signal' );

		if ( ! $active ) {
			$button = self::installed( $plugin )
				? __( 'Activate WooCommerce and add the shop templates', 'qwerty-soft-signal' )
				: __( 'Install WooCommerce and the shop templates', 'qwerty-soft-signal' );
		} else {
			$button = __( 'Add the shop templates', 'qwerty-soft-signal' );
		}

		return array(
			'key'       => 'woocommerce',
			'name'      => 'WooCommerce',
			'plugin'    => $plugin,
			'installed' => self::installed( $plugin ),
			'active'    => $active,
			'kit'       => $kit,

			/*
			 * Ready is not the same as active. A site that already had
			 * WooCommerce on it before the import has the plugin and none of
			 * the templates, and reading "installed and active" off the
			 * plugin alone hid the button that would have added them.
			 */
			'ready'     => $active && $kit,
			'button'    => $button,
			'done'      => null === $catalog
				? __( 'Installed and active — the shop templates are in place.', 'qwerty-soft-signal' )
				: __( 'Installed and active — the shop templates are in place and the build will import the catalogue.', 'qwerty-soft-signal' ),
			'catalog'   => null === $catalog ? '' : (string) $catalog['file'],
			'records'   => null === $catalog ? 0 : (int) $catalog['records'],
			'why'       => implode( ' ', $why ),
		);
	}

	/**
	 * The form row: found, and already answered.
	 *
	 * The only recommendation here is not to install anything. A design's
	 * contact form is markup — a few inputs and a button — and the theme
	 * already ships the server half it needs: `qs/contact-form`, a plain POST
	 * with a honeypot, a time trap and a per-IP rate limit. BlockWriter wires
	 * the design's own form to it, so the section keeps every class the
	 * design gave it and starts working the moment the page is built.
	 *
	 * A forms plugin would put a second form, drawn by somebody else's
	 * markup, where the design had drawn its own. That is the whole reason
	 * this row has no button.
	 *
	 * @param array<int, array{file:string}> $forms The forms the archive draws.
	 * @return array<string, mixed>
	 */
	private static function form_need( array $forms ): array {
		$files = array();

		foreach ( $forms as $form ) {
			$files[ $form['file'] ] = true;
		}

		$where = array_slice( array_keys( $files ), 0, 3 );

		return array(
			'key'       => 'contact-form',
			'name'      => __( 'The design’s forms', 'qwerty-soft-signal' ),
			'plugin'    => '',
			'installed' => true,
			'active'    => true,
			'ready'     => true,
			'button'    => '',
			'done'      => __( 'Nothing to install — the theme answers this one.', 'qwerty-soft-signal' ),
			'forms'     => count( $forms ),
			'why'       => sprintf(
				/* translators: 1: how many forms, 2: the files they were drawn in. */
				_n(
					'The design draws %1$d form (%2$s). No plugin is needed: the build keeps the design’s own markup and wires it to the theme’s contact form, which emails the site address, drops bots on a honeypot and a time trap, and works with JavaScript switched off. Set who receives it under Settings → General, or filter qwerty_soft/contact_recipient.',
					'The design draws %1$d forms (%2$s). No plugin is needed: the build keeps the design’s own markup and wires them to the theme’s contact form, which emails the site address, drops bots on a honeypot and a time trap, and works with JavaScript switched off. Set who receives it under Settings → General, or filter qwerty_soft/contact_recipient.',
					count( $forms ),
					'qwerty-soft-signal'
				),
				count( $forms ),
				implode( ', ', $where )
			),
		);
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
	 * The archive's markup, as a map of absolute path => archive-relative path.
	 *
	 * Markup rather than every file: what is being looked for is what the
	 * design draws, and a design draws in HTML and in the component languages
	 * that compile to it. Minified bundles are skipped — they say the same
	 * thing the source next to them says, at a hundred times the cost.
	 *
	 * @param string $root Design root directory.
	 * @return array<string, string>
	 */
	private static function markup_files( string $root ): array {
		$files = array();

		try {
			$walk = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $walk as $file ) {
				if ( ! $file->isFile() || $file->getSize() > self::MAX_FILE ) {
					continue;
				}

				$path = str_replace( '\\', '/', $file->getPathname() );
				$name = strtolower( $file->getFilename() );

				if ( str_contains( $path, '/node_modules/' ) || str_contains( $name, '.min.' ) ) {
					continue;
				}

				if ( 1 !== preg_match( '/\.(html?|jsx|tsx|vue|svelte|js|ts)$/i', $name ) ) {
					continue;
				}

				$files[ $path ] = ltrim( substr( $path, strlen( $root ) ), '/' );

				// A handoff can hold thousands of modules; this is a hint, not an audit.
				if ( count( $files ) >= self::MAX_FILES ) {
					break;
				}
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		ksort( $files );

		return $files;
	}

	/**
	 * What the markup gives away about a shop, with the file that gave it.
	 *
	 * Each sign has to be something only a shop draws. "Price" is not on the
	 * list and never will be: an agency site with a pricing table is not a
	 * shop, and a screen that recommends WooCommerce to it teaches whoever
	 * reads it to stop reading it.
	 *
	 * @param array<string, string> $files Markup files, absolute path => relative.
	 * @return array<int, array{says:string, file:string}> At most three, one per kind.
	 */
	private static function shop_signs( array $files ): array {
		$signs = array(
			array(
				'pattern' => '/add[\s_-]?to[\s_-]?(cart|bag|basket)/i',
				'says'    => __( 'an add-to-cart button', 'qwerty-soft-signal' ),
			),
			array(
				'pattern' => '/\bwoocommerce\b/i',
				'says'    => __( 'WooCommerce markup', 'qwerty-soft-signal' ),
			),
			array(
				'pattern' => '/href=["\'][^"\']*\/(cart|checkout|basket)(["\'\/?#]|$)/i',
				'says'    => __( 'a link to a cart or a checkout', 'qwerty-soft-signal' ),
			),
			array(
				'pattern' => '/\bshopping[\s-]?(cart|bag|basket)\b/i',
				'says'    => __( 'a shopping cart', 'qwerty-soft-signal' ),
			),
			array(
				'pattern' => '/class=["\'][^"\']*\b(mini-cart|cart-count|cart-drawer|cart-icon|checkout-button)\b/i',
				'says'    => __( 'cart markup', 'qwerty-soft-signal' ),
			),
		);

		$found = array();

		foreach ( $files as $path => $rel ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			foreach ( $signs as $index => $sign ) {
				if ( isset( $found[ $index ] ) || 1 !== preg_match( $sign['pattern'], $body ) ) {
					continue;
				}

				$found[ $index ] = array(
					'says' => (string) $sign['says'],
					'file' => $rel,
				);
			}

			if ( count( $found ) === count( $signs ) ) {
				break;
			}
		}

		ksort( $found );

		return array_slice( array_values( $found ), 0, 3 );
	}

	/**
	 * Every form the design draws, minus the ones that are not forms to fill in.
	 *
	 * A search box is a form and is nobody's contact form: the theme's search
	 * block already draws one, and offering to wire it to an inbox would be
	 * an offer to email every search. Same for a form whose only control is a
	 * button — a log-out link drawn as a POST, an add-to-cart.
	 *
	 * @param array<string, string> $files Markup files, absolute path => relative.
	 * @return array<int, array{file:string}>
	 */
	private static function forms( array $files ): array {
		$forms = array();

		foreach ( $files as $path => $rel ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			if ( ! str_contains( strtolower( $body ), '<form' ) ) {
				continue;
			}

			$count = preg_match_all( '/<form\b[\s\S]*?<\/form\s*>/i', $body, $matches );

			if ( ! $count ) {
				continue;
			}

			foreach ( (array) $matches[0] as $chunk ) {
				if ( self::is_search_form( (string) $chunk ) ) {
					continue;
				}

				// A form with nothing to type in is a button in disguise.
				if ( 1 !== preg_match( '/<(input|textarea|select)\b/i', (string) $chunk ) ) {
					continue;
				}

				$forms[] = array( 'file' => $rel );
			}
		}

		return $forms;
	}

	/**
	 * Whether one form is the site's search.
	 *
	 * @param string $chunk The form's markup.
	 * @return bool
	 */
	private static function is_search_form( string $chunk ): bool {
		if ( 1 === preg_match( '/role=["\']search["\']/i', $chunk ) ) {
			return true;
		}

		if ( 1 === preg_match( '/type=["\']search["\']/i', $chunk ) ) {
			return true;
		}

		return 1 === preg_match( '/<form\b[^>]*\bclass=["\'][^"\']*\bsearch\b/i', $chunk );
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
