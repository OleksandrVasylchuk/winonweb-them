<?php
/**
 * The archive's product catalogue, imported as the shop's products.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the catalogue DesignNeeds found into WooCommerce products.
 *
 * A record becomes a published product: name and code in the title, the
 * description in the content with the performance table as a list, the
 * category as a product category, and the photograph matched by code against
 * the pictures the import already brought in. Each product also carries the
 * card-shaped meta the design's listing rows read by name — product_code,
 * product_name, product_category, product_summary, product_image — so a
 * listing block draws a full card without knowing WooCommerce exists.
 *
 * Catalogue items, not purchasable SKUs: no prices are invented. Re-running
 * is safe — a product is found again by its code and updated in place.
 */
final class CatalogImport {

	/**
	 * Import one catalogue file.
	 *
	 * @param string                                  $root  Design root directory.
	 * @param string                                  $file  Catalogue file, archive-relative.
	 * @param array<string, array{id:int,url:string}> $media Imported media map, archive path to attachment.
	 * @return array{made:int, updated:int, images:int} What happened.
	 */
	public static function run( string $root, string $file, array $media ): array {
		$report = array(
			'made'    => 0,
			'updated' => 0,
			'images'  => 0,
		);

		if ( ! post_type_exists( 'product' ) ) {
			return $report;
		}

		$root    = rtrim( str_replace( '\\', '/', $root ), '/' );
		$records = DesignNeeds::records_in( $root . '/' . ltrim( $file, '/' ) );

		if ( array() === $records ) {
			return $report;
		}

		$images = self::images_by_code( $media );

		foreach ( $records as $record ) {
			$code = trim( (string) ( $record['code'] ?? $record['sku'] ?? '' ) );
			$name = trim( (string) ( $record['name'] ?? $record['title'] ?? '' ) );

			if ( '' === $code || '' === $name ) {
				continue;
			}

			$description = trim( (string) ( $record['description'] ?? '' ) );
			$content     = $description;
			$specs       = array();

			foreach ( (array) ( $record['performance'] ?? $record['specs'] ?? array() ) as $line ) {
				if ( is_array( $line ) && isset( $line['label'], $line['value'] ) ) {
					$specs[] = (string) $line['label'] . ': ' . (string) $line['value'];
				}
			}

			if ( array() !== $specs ) {
				$content .= "\n\n<!-- wp:list --><ul class=\"wp-block-list\">";

				foreach ( $specs as $spec ) {
					$content .= '<li>' . esc_html( $spec ) . '</li>';
				}

				$content .= '</ul><!-- /wp:list -->';
			}

			$existing = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => 'product_code', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The code is the record's identity; one indexed lookup per record.
					'meta_value'     => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See meta_key above.
				)
			);

			$args = array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => $name . ' (' . $code . ')',
				'post_name'    => (string) ( $record['slug'] ?? '' ),
				'post_content' => $content,
				'post_excerpt' => $description,
			);

			if ( array() !== $existing ) {
				$args['ID'] = (int) $existing[0];
				$id         = wp_update_post( $args );

				++$report['updated'];
			} else {
				$id = wp_insert_post( $args );

				++$report['made'];
			}

			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}

			$summary = $description;

			if ( mb_strlen( $summary ) > 160 ) {
				$cut     = mb_substr( $summary, 0, 160 );
				$space   = mb_strrpos( $cut, ' ' );
				$summary = mb_substr( $cut, 0, false === $space ? 160 : $space ) . '…';
			}

			// The names the design's listing rows ask for.
			update_post_meta( (int) $id, 'product_code', $code );
			update_post_meta( (int) $id, 'product_name', $name );
			update_post_meta( (int) $id, 'product_category', (string) ( $record['category'] ?? '' ) );
			update_post_meta( (int) $id, 'product_summary', $summary );
			update_post_meta( (int) $id, '_qwerty_soft_imported', 'record:product' );

			$image = (int) ( $images[ strtoupper( $code ) ] ?? 0 );

			if ( $image > 0 ) {
				set_post_thumbnail( (int) $id, $image );
				update_post_meta( (int) $id, 'product_image', $image );

				++$report['images'];
			}

			$category = trim( (string) ( $record['category'] ?? '' ) );

			if ( '' !== $category && taxonomy_exists( 'product_cat' ) ) {
				wp_set_object_terms( (int) $id, $category, 'product_cat', false );
			}
		}

		return $report;
	}

	/**
	 * The imported pictures, keyed by the product code their name starts with.
	 *
	 * Catalogue photography is named after the SKU — `RVE-1015__RVE-1015.png`
	 * — and the media import already brought every referenced file in. The
	 * first picture per code is the product's; the variants stay in the
	 * library.
	 *
	 * @param array<string, array{id:int,url:string}> $media Imported media map.
	 * @return array<string, int> Upper-cased code to attachment ID.
	 */
	private static function images_by_code( array $media ): array {
		$images = array();

		foreach ( $media as $path => $row ) {
			$name = basename( (string) $path );

			if ( 1 !== preg_match( '/^([A-Za-z0-9][A-Za-z0-9_.-]*?)(?:__|\.)/', $name, $found ) ) {
				continue;
			}

			$code = strtoupper( $found[1] );

			if ( ! isset( $images[ $code ] ) ) {
				$images[ $code ] = (int) ( $row['id'] ?? 0 );
			}
		}

		return $images;
	}
}
