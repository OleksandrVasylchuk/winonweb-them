<?php
/**
 * Reading and applying the theme's style variations.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Lists what is in styles/ and switches the site onto one of them.
 *
 * The Site Editor can already do this, three panels deep, for somebody who
 * knows the word "variation". The setup screen does it in one press for
 * somebody who does not.
 *
 * Applying a variation writes it into the site's user global styles — the
 * record the Site Editor itself writes — so the choice survives a theme update
 * and can be changed or undone from either place afterwards.
 */
final class StyleVariations {

	/**
	 * Every variation in styles/, plus the theme's own default.
	 *
	 * Read from the files rather than from WP_Theme_JSON_Resolver so the list
	 * carries the descriptions and the palette needed to draw a swatch, and so
	 * a variation added to the folder appears here with no other change.
	 *
	 * @return array<string, array{title:string,description:string,swatch:array<int,string>}>
	 */
	public static function all(): array {
		$found = array(
			'' => array(
				'title'       => __( 'Signal — the theme default', 'qwerty-soft-signal' ),
				'description' => __( 'Cyan on near-black. The palette the theme ships with.', 'qwerty-soft-signal' ),
				'swatch'      => self::swatch( self::decode( QSOFT_DIR . '/theme.json' ) ),
			),
		);

		foreach ( (array) glob( QSOFT_DIR . '/styles/*.json' ) as $path ) {
			$data = self::decode( (string) $path );

			if ( array() === $data ) {
				continue;
			}

			$slug = basename( (string) $path, '.json' );

			/*
			 * Reading the file directly means WordPress's own theme.json
			 * translation pass never runs on these two strings, so they arrive
			 * in English on a translated site even though tools/make-pot.mjs
			 * has already put them in the catalogue under these contexts.
			 * Translating them here is the same lookup _x() would do; the text
			 * cannot be a literal because it comes out of a file.
			 */
			$title       = (string) ( $data['title'] ?? $slug );
			$description = (string) ( $data['description'] ?? '' );

			// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.LowLevelTranslationFunction -- The text comes out of a JSON file; make-pot.mjs is what put it in the catalogue, and the low-level call is the same lookup _x() would perform.
			$title = translate_with_gettext_context( $title, 'Style variation name', 'qwerty-soft-signal' );

			if ( '' !== $description ) {
				$description = translate_with_gettext_context( $description, 'Style variation description', 'qwerty-soft-signal' );
			}
			// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.LowLevelTranslationFunction

			$found[ $slug ] = array(
				'title'       => $title,
				'description' => $description,
				'swatch'      => self::swatch( $data ),
			);
		}

		return $found;
	}

	/**
	 * Switch the site onto a variation, or back to the theme default.
	 *
	 * @param string $slug Variation file name without .json; empty for the default.
	 * @return bool Whether anything was applied.
	 */
	public static function apply( string $slug ): bool {
		$data = self::user_data();

		// Clear whatever a previous choice left behind before writing the new one.
		unset( $data['settings']['color']['palette']['theme'] );
		unset( $data['settings']['color']['gradients']['theme'] );
		unset( $data['settings']['shadow']['presets']['theme'] );

		if ( '' === $slug ) {
			self::save( $data );

			return true;
		}

		$path = QSOFT_DIR . '/styles/' . $slug . '.json';

		// The slug comes off a form; keep it inside styles/ whatever it says.
		$real = realpath( $path );
		$root = realpath( QSOFT_DIR . '/styles' );

		if ( false === $real || false === $root || ! str_starts_with( $real, $root ) ) {
			return false;
		}

		$variation = self::decode( $real );

		if ( array() === $variation ) {
			return false;
		}

		$palette   = $variation['settings']['color']['palette'] ?? null;
		$gradients = $variation['settings']['color']['gradients'] ?? null;
		$shadows   = $variation['settings']['shadow']['presets'] ?? null;

		if ( is_array( $palette ) ) {
			$data['settings']['color']['palette']['theme'] = $palette;
		}

		if ( is_array( $gradients ) ) {
			$data['settings']['color']['gradients']['theme'] = $gradients;
		}

		if ( is_array( $shadows ) ) {
			$data['settings']['shadow']['presets']['theme'] = $shadows;
		}

		self::save( $data );

		return true;
	}

	/**
	 * Replace only the gradient and shadow presets, leaving the palette alone.
	 *
	 * The brand kit derives its own palette but inherits whatever gradients and
	 * shadows are already stored. Without this, choosing Signal Ember and then
	 * entering a pink brand colour leaves amber gradients sitting on a pink
	 * palette — every colour individually correct, the page visibly wrong.
	 *
	 * Passing null for either clears the stored override, so the theme's own
	 * presets come back.
	 *
	 * @param array<int, array<string, string>>|null $gradients Gradient presets.
	 * @param array<int, array<string, string>>|null $shadows   Shadow presets.
	 * @return void
	 */
	public static function set_presets( ?array $gradients, ?array $shadows ): void {
		$data = self::user_data();

		if ( null === $gradients ) {
			unset( $data['settings']['color']['gradients']['theme'] );
		} else {
			$data['settings']['color']['gradients']['theme'] = $gradients;
		}

		if ( null === $shadows ) {
			unset( $data['settings']['shadow']['presets']['theme'] );
		} else {
			$data['settings']['shadow']['presets']['theme'] = $shadows;
		}

		self::save( $data );
	}

	/**
	 * Three colours that say what a variation looks like.
	 *
	 * @param array<string, mixed> $data Decoded theme.json-shaped file.
	 * @return array<int, string> Background, text and accent.
	 */
	private static function swatch( array $data ): array {
		$palette = $data['settings']['color']['palette'] ?? array();
		$by_slug = array();

		foreach ( (array) $palette as $entry ) {
			if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) ) {
				$by_slug[ (string) $entry['slug'] ] = (string) $entry['color'];
			}
		}

		return array(
			$by_slug['base'] ?? '#ffffff',
			$by_slug['contrast'] ?? '#000000',
			$by_slug['accent'] ?? '#000000',
		);
	}

	/**
	 * Decode a theme.json-shaped file.
	 *
	 * @param string $path Absolute path.
	 * @return array<string, mixed> Decoded data, or an empty array.
	 */
	private static function decode( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$data = wp_json_file_decode( $path, array( 'associative' => true ) );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The site's current user global styles, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private static function user_data(): array {
		$post = get_post( WP_Theme_JSON_Resolver::get_user_global_styles_post_id() );
		$data = null === $post ? array() : json_decode( (string) $post->post_content, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['settings'] = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
		$data['styles']   = isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array();

		return $data;
	}

	/**
	 * Store global styles and drop the cached copy.
	 *
	 * @param array<string, mixed> $data Global styles.
	 * @return void
	 */
	private static function save( array $data ): void {
		$data['version']                     = 3;
		$data['isGlobalStylesUserThemeJSON'] = true;

		wp_update_post(
			array(
				'ID'           => WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);

		WP_Theme_JSON_Resolver::clean_cached_data();
	}
}
