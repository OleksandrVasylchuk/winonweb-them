<?php
/**
 * WooCommerce integration.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Shop support, loaded only when WooCommerce is active.
 *
 * WooCommerce ships block templates of its own, and this theme overrides them
 * in /templates. The module's job is to declare support, keep the product
 * gallery working, and make sure shop CSS never loads on a page with no shop
 * on it.
 */
final class WooCommerce implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'after_setup_theme', array( $this, 'declare_support' ) );
		add_filter( 'woocommerce_enqueue_styles', array( $this, 'filter_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_off_shop' ), 99 );
		add_filter( 'woocommerce_product_get_rating_html', array( $this, 'accessible_rating' ), 10, 3 );
		add_filter( 'loop_shop_columns', array( $this, 'shop_columns' ) );
	}

	/**
	 * Declare the WooCommerce features the theme handles.
	 *
	 * @return void
	 */
	public function declare_support(): void {
		add_theme_support(
			'woocommerce',
			array(
				'thumbnail_image_width' => 720,
				'single_image_width'    => 1200,
				'product_grid'          => array(
					'default_columns' => 3,
					'min_columns'     => 1,
					'max_columns'     => 4,
				),
			)
		);

		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	}

	/**
	 * Keep WooCommerce's own stylesheets — the theme styles on top of them
	 * with tokens rather than replacing them, so plugin updates stay safe.
	 *
	 * @param array<string, array<string, mixed>> $styles Registered styles.
	 * @return array<string, array<string, mixed>>
	 */
	public function filter_styles( array $styles ): array {
		return $styles;
	}

	/**
	 * Drop shop assets on pages that contain no shop content.
	 *
	 * WooCommerce enqueues its bundle site-wide by default, which is dead
	 * weight on a landing page or a blog post.
	 *
	 * @return void
	 */
	public function dequeue_off_shop(): void {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		$needs_shop = is_woocommerce()
			|| is_cart()
			|| is_checkout()
			|| is_account_page()
			|| is_wc_endpoint_url();

		/**
		 * Filter whether WooCommerce assets are needed on this request.
		 *
		 * Set this to true on a page that renders shop blocks outside the
		 * normal shop templates, such as a "featured products" landing page.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $needs_shop Whether to keep WooCommerce assets.
		 */
		if ( (bool) apply_filters( 'wow_signal/needs_woocommerce_assets', $needs_shop ) ) {
			return;
		}

		foreach ( array( 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'wc-blocks-style' ) as $handle ) {
			wp_dequeue_style( $handle );
		}

		foreach ( array( 'woocommerce', 'wc-cart-fragments', 'wc-add-to-cart' ) as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Give star ratings a text alternative instead of decorative stars only.
	 *
	 * @param string $html   Default rating markup.
	 * @param float  $rating Average rating.
	 * @param int    $count  Number of ratings.
	 * @return string
	 */
	public function accessible_rating( string $html, $rating, $count ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$label = sprintf(
			/* translators: 1: average rating, 2: number of reviews. */
			_n( 'Rated %1$s out of 5 based on %2$s review', 'Rated %1$s out of 5 based on %2$s reviews', (int) $count, 'wow-signal' ),
			esc_html( (string) round( (float) $rating, 1 ) ),
			esc_html( number_format_i18n( (int) $count ) )
		);

		return '<span class="screen-reader-text">' . $label . '</span>' . $html;
	}

	/**
	 * Three products per row matches the theme's card grid rhythm.
	 *
	 * @return int
	 */
	public function shop_columns(): int {
		return 3;
	}
}
