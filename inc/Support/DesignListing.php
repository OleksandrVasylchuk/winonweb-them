<?php
/**
 * Turns the posts a listing block points at into rows its markup can draw.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * What a listing section shows.
 *
 * Six report cards in a design are not content of that section — they are the
 * site's own records, and the card is the template for one of them. A block
 * that held them as content would mean adding a seventh report by editing a
 * page, which is the difference between handing over a site and handing over a
 * mockup.
 *
 * So the block holds a choice — which posts, how many — and this turns that
 * choice into rows shaped like the row the design drew. The mapping from a
 * post to those fields is by position and by type, because the design's field
 * names are the designer's words and no post has a column called "subheading":
 * the first text field gets the title, the first long field the excerpt, the
 * first image the featured image, the first link the permalink.
 */
final class DesignListing {

	/**
	 * How many rows a listing draws when nothing says otherwise.
	 */
	private const FALLBACK = 3;

	/**
	 * The rows a listing block should draw.
	 *
	 * @param mixed                 $source Chosen posts: ids, WP_Post objects, or empty.
	 * @param int                   $limit  How many to show.
	 * @param array<string, string> $shape  Field name => field type, in the order drawn.
	 * @param string                $type   The record type this listing draws, when it has one.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( $source, int $limit, array $shape, string $type = '' ): array {
		$posts = self::posts( $source, $limit, $type );
		$rows  = array();

		foreach ( $posts as $post ) {
			$rows[] = self::row( $post, $shape );
		}

		return $rows;
	}

	/**
	 * The posts a block points at, or the most recent when it points at none.
	 *
	 * @param mixed  $source Chosen posts.
	 * @param int    $limit  How many.
	 * @param string $type   The record type this listing draws, when it has one.
	 * @return array<int, WP_Post>
	 */
	private static function posts( $source, int $limit, string $type = '' ): array {
		$limit = $limit > 0 ? $limit : self::FALLBACK;

		if ( ! empty( $source ) ) {
			$chosen = array();

			foreach ( (array) $source as $one ) {
				$post = $one instanceof WP_Post ? $one : get_post( (int) $one );

				if ( $post instanceof WP_Post ) {
					$chosen[] = $post;
				}
			}

			return array_slice( $chosen, 0, $limit );
		}

		/*
		 * Nothing chosen is the normal state of a freshly imported block, and
		 * it should still draw something: an empty grid reads as a broken
		 * import rather than as an unconfigured one.
		 */
		$query = new WP_Query(
			array(
				'post_type'           => '' !== $type && post_type_exists( $type ) ? $type : 'post',
				'posts_per_page'      => $limit,
				'post_status'         => 'publish',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		return $query->posts;
	}

	/**
	 * One post, in the shape of the row the design drew.
	 *
	 * @param WP_Post               $post  The record.
	 * @param array<string, string> $shape Field name => field type.
	 * @return array<string, mixed>
	 */
	private static function row( WP_Post $post, array $shape ): array {
		$row   = array();
		$taken = array(
			'text'     => false,
			'textarea' => false,
			'image'    => false,
			'link'     => false,
		);

		foreach ( $shape as $name => $type ) {
			$row[ $name ] = '';

			/*
			 * The record's own field, asked for by name and answered first.
			 *
			 * A type made from this design has a field for every part of the
			 * card, named exactly as the card names it — so a report's
			 * `report_code` is a report's `report_code`, not the third thing
			 * this loop happened to reach. Everything below is the fallback
			 * for an ordinary post, which has no such fields and has to be
			 * mapped by shape.
			 */
			$own = self::own_field( $post, $name );

			if ( null !== $own ) {
				$row[ $name ]   = $own;
				$taken[ $type ] = true;

				continue;
			}

			if ( 'text' === $type && ! $taken['text'] ) {
				$row[ $name ]  = get_the_title( $post );
				$taken['text'] = true;

				continue;
			}

			if ( 'textarea' === $type && ! $taken['textarea'] ) {
				$row[ $name ]      = self::excerpt( $post );
				$taken['textarea'] = true;

				continue;
			}

			if ( 'image' === $type && ! $taken['image'] ) {
				$row[ $name ]   = (int) get_post_thumbnail_id( $post );
				$taken['image'] = true;

				continue;
			}

			if ( 'link' === $type && ! $taken['link'] ) {
				$row[ $name ] = array(
					'url'   => (string) get_permalink( $post ),
					'title' => get_the_title( $post ),
				);

				$taken['link'] = true;

				continue;
			}

			/*
			 * Anything past the first of its type is the designer's second
			 * label on the card — a date, a category, a price. An ordinary post
			 * has nothing to say there, and is left blank rather than given
			 * invented copy. A record of this design's own type never reaches
			 * here: its fields answered by name above.
			 */
		}

		return $row;
	}

	/**
	 * What a record itself says under a given name, if it says anything.
	 *
	 * @param WP_Post $post The record.
	 * @param string  $name The field's name on the card.
	 * @return mixed Null when the record has no such field.
	 */
	private static function own_field( WP_Post $post, string $name ) {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $name, $post->ID );

			if ( null !== $value && '' !== $value && array() !== $value ) {
				return $value;
			}
		}

		/*
		 * Read directly as well, because a build seeds these rows itself and
		 * must not depend on ACF being loaded in whichever request happened to
		 * run the cron tick.
		 */
		$meta = get_post_meta( $post->ID, $name, true );

		return '' === $meta || null === $meta ? null : $meta;
	}

	/**
	 * A short description of a post, written or taken from its body.
	 *
	 * @param WP_Post $post The record.
	 * @return string
	 */
	private static function excerpt( WP_Post $post ): string {
		$written = trim( (string) $post->post_excerpt );

		if ( '' !== $written ) {
			return $written;
		}

		return wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 28 );
	}
}
