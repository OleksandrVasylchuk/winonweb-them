<?php
/**
 * Core Web Vitals defaults.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

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
		add_filter( 'wp_lazy_loading_enabled', array( $this, 'lazy_loading' ), 10, 3 );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );

		if ( function_exists( 'wp_get_speculation_rules' ) ) {
			// WordPress 6.8+ prints its own speculation rules; only tune them.
			add_filter( 'wp_speculation_rules_configuration', array( $this, 'speculation_configuration' ) );
		} else {
			add_action( 'wp_head', array( $this, 'speculation_rules' ), 1 );
		}
	}

	/*
	 * A note on `styles_inline_size_limit`, so nobody spends the afternoon
	 * rediscovering this:
	 *
	 * With separate core block assets on, WordPress inlines block stylesheets
	 * rather than linking them. Core's navigation stylesheet is 20,709 bytes,
	 * which looks like it sits 709 bytes over the 20,000-byte default — so the
	 * obvious fix is to nudge the limit up by a kilobyte.
	 *
	 * That does not work. The limit is a *cumulative* budget across every
	 * stylesheet inlined on the page, not a per-file ceiling, and core sorts
	 * smallest-first. Making the navigation sheet fit means raising the budget
	 * past the sum of everything else plus 20 KB — inlining roughly 40 KB of
	 * uncacheable CSS into every page to save one cacheable request. That is a
	 * worse trade for anyone who visits more than one page, so the theme leaves
	 * core's default alone and says so in the README instead.
	 */

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
	 * first three content images on the page (`wp_omit_loading_attr_threshold`
	 * at its default). The theme used to force that threshold down to 1, but
	 * the header logo counts toward it, so a logo wide enough to qualify ate
	 * the single slot and the real LCP image was lazy-loaded. Core's default
	 * plus the header exemption in lazy_loading() covers both cases.
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
	 * Never lazy-load images inside the header template part.
	 *
	 * The header is above the fold on every page view, so the logo and any
	 * image next to it should be fetched eagerly. Core passes the template
	 * part area as the filter context (`template_part_header`); in a block
	 * theme that is the only reliable signal, because `wp_body_open` has
	 * already finished by the time the template renders.
	 *
	 * @param bool   $enabled  Whether lazy loading applies.
	 * @param string $tag_name Tag being filtered.
	 * @param string $context  Rendering context, e.g. `template_part_header`.
	 * @return bool
	 */
	public function lazy_loading( bool $enabled, string $tag_name, string $context ): bool {
		if ( 'img' === $tag_name && 'template_part_' . WP_TEMPLATE_PART_AREA_HEADER === $context ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Whether this visitor should get prerendering at all.
	 *
	 * Logged-in users are skipped so an editor never prerenders an admin
	 * action; visitors who sent `Save-Data: on` asked not to spend bandwidth
	 * on pages they may never open.
	 *
	 * @return bool
	 */
	private function wants_prerender(): bool {
		if ( is_user_logged_in() ) {
			return false;
		}

		$save_data = isset( $_SERVER['HTTP_SAVE_DATA'] )
			? sanitize_key( (string) wp_unslash( $_SERVER['HTTP_SAVE_DATA'] ) )
			: '';

		return 'on' !== $save_data;
	}

	/**
	 * Tune core's speculation rules (WordPress 6.8+) to prerender moderately.
	 *
	 * Core's own exclusions already cover wp-admin, wp-*.php, nonced links and
	 * `rel="nofollow"`. Returning the configuration unchanged for users who
	 * should not prerender falls back to core's default, which is prefetch
	 * conservatively for logged-out visitors and nothing for logged-in ones.
	 *
	 * @param array<string, string>|null $config Core's configuration, or null to disable.
	 * @return array<string, string>|null
	 */
	public function speculation_configuration( $config ) {
		if ( ! $this->wants_prerender() ) {
			return $config;
		}

		return array(
			'mode'      => 'prerender',
			'eagerness' => 'moderate',
		);
	}

	/**
	 * Prerender same-origin links the user is likely to open next.
	 *
	 * Fallback for WordPress 6.7, which has no speculation rules API of its
	 * own. On 6.8+ this is never hooked; see register().
	 *
	 * @return void
	 */
	public function speculation_rules(): void {
		if ( ! $this->wants_prerender() ) {
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
		$origins = (array) apply_filters( 'qwerty_soft/preconnect_origins', array() );

		foreach ( $origins as $origin ) {
			if ( is_string( $origin ) && '' !== $origin ) {
				$hints[] = esc_url_raw( $origin );
			}
		}

		return $hints;
	}
}
