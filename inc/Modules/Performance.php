<?php
/**
 * Core Web Vitals defaults.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Image, navigation and rendering defaults tuned for LCP and CLS.
 */
final class Performance implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'upload_mimes', array( $this, 'modern_formats' ) );
		add_filter( 'jpeg_quality', array( $this, 'image_quality' ) );
		add_filter( 'wp_editor_set_quality', array( $this, 'image_quality' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'image_attributes' ), 10, 3 );
		add_filter( 'wp_omit_loading_attr_threshold', array( $this, 'lcp_threshold' ) );
		add_filter( 'wp_lazy_loading_enabled', array( $this, 'lazy_loading' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'speculation_rules' ), 1 );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
	}

	/**
	 * Allow modern image formats to be uploaded.
	 *
	 * @param array<string, string> $mimes Allowed MIME types.
	 * @return array<string, string>
	 */
	public function modern_formats( array $mimes ): array {
		$mimes['webp'] = 'image/webp';
		$mimes['avif'] = 'image/avif';

		return $mimes;
	}

	/**
	 * Slightly higher than the WordPress default, still well under the
	 * point where file size grows faster than perceived quality.
	 *
	 * @return int
	 */
	public function image_quality(): int {
		return 86;
	}

	/**
	 * Decode images off the main thread.
	 *
	 * `loading` is intentionally left to core, which already omits it for the
	 * first images on the page (see lcp_threshold below).
	 *
	 * @param array<string, string> $attr       Image attributes.
	 * @param \WP_Post              $attachment Attachment post.
	 * @param string|int[]          $size       Requested size.
	 * @return array<string, string>
	 */
	public function image_attributes( array $attr, $attachment, $size ): array {
		unset( $attachment, $size );

		if ( ! isset( $attr['decoding'] ) ) {
			$attr['decoding'] = 'async';
		}

		return $attr;
	}

	/**
	 * Never lazy-load the first image on a page.
	 *
	 * A lazy-loaded LCP image is the single most common cause of a poor LCP
	 * score on WordPress. Core's default threshold is 3; 1 is stricter.
	 *
	 * @return int
	 */
	public function lcp_threshold(): int {
		return 1;
	}

	/**
	 * Keep lazy-loading off template parts that are always above the fold.
	 *
	 * @param bool   $enabled  Whether lazy loading applies.
	 * @param string $tag_name Tag being filtered.
	 * @return bool
	 */
	public function lazy_loading( bool $enabled, string $tag_name ): bool {
		// The site logo sits in the header on every single page view.
		if ( 'img' === $tag_name && doing_action( 'wp_body_open' ) ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Prerender same-origin links the user is likely to open next.
	 *
	 * Skipped for logged-in users so an editor never prerenders an admin
	 * action, and skipped when the visitor asked to save data.
	 *
	 * @return void
	 */
	public function speculation_rules(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$rules = array(
			'prerender' => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => '/*' ),
							array(
								'not' => array( 'href_matches' => '/wp-*.php' ),
							),
							array(
								'not' => array( 'href_matches' => '/wp-admin/*' ),
							),
							array(
								'not' => array( 'href_matches' => '/*\\?*(^|&)_wpnonce=*' ),
							),
							array(
								'not' => array( 'selector_matches' => '[rel~="nofollow"]' ),
							),
							array(
								'not' => array( 'selector_matches' => '.no-prerender' ),
							),
						),
					),
					'eagerness' => 'moderate',
				),
			),
		);

		printf(
			'<script type="speculationrules">%s</script>' . "\n",
			wp_json_encode( $rules ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output of a static array is safe inside a script element.
		);
	}

	/**
	 * Preconnect nothing by default; the theme loads no third-party origins.
	 *
	 * The filter is implemented so the studio has one obvious place to add a
	 * CDN or analytics origin per project instead of scattering link tags.
	 *
	 * @param array<int, mixed> $hints         Existing hints.
	 * @param string            $relation_type Hint relation.
	 * @return array<int, mixed>
	 */
	public function resource_hints( array $hints, string $relation_type ): array {
		if ( 'preconnect' !== $relation_type ) {
			return $hints;
		}

		/**
		 * Filter extra origins to preconnect to.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $origins Absolute origins, e.g. https://cdn.example.com.
		 */
		$origins = (array) apply_filters( 'wow_signal/preconnect_origins', array() );

		foreach ( $origins as $origin ) {
			if ( is_string( $origin ) && '' !== $origin ) {
				$hints[] = esc_url_raw( $origin );
			}
		}

		return $hints;
	}
}
