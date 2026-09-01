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
		$asked = array(
			'post_type'           => '' !== $type && post_type_exists( $type ) ? $type : 'post',
			'posts_per_page'      => $limit,
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		/*
		 * The page's own language, and only it. A multilingual import seeds
		 * each language's cards as records of the one type, and "the latest
		 * three" across all of them put Chinese reports on the Russian page.
		 * Records from before languages were stamped still show everywhere —
		 * an empty grid on an old site would be the worse failure.
		 */
		$language = (string) get_post_meta( get_the_ID(), SiteAssembler::LANG_META, true );

		if ( '' !== $language ) {
			$asked['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The language IS the query; the meta is indexed by key.
				'relation' => 'OR',
				array(
					'key'   => SiteAssembler::LANG_META,
					'value' => $language,
				),
				array(
					'key'     => SiteAssembler::LANG_META,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query( $asked );

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

		/*
		 * Every row knows the record it came from. The design's example card
		 * links somewhere — the wrap plants that href as this field, because
		 * the frozen address of the first record is where every card on the
		 * site used to point.
		 */
		if ( ! isset( $shape['permalink'] ) ) {
			$row['permalink'] = array(
				'url'   => (string) get_permalink( $post ),
				'title' => get_the_title( $post ),
			);
		}

		$fields = self::design_fields( $post->post_type );
		$used   = array();

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
				$used[ $name ]  = true;
				$taken[ $type ] = true;

				continue;
			}

			/*
			 * Second answer: the same kind of field, in order, from the
			 * record's own set. Two sections listing the same record type name
			 * the card's parts in their own words — the home page's report
			 * card asks for `reference` and `summary`, the store page seeded
			 * its reports as `report_type` and `description` — and matching
			 * by name alone rendered half of every borrowed card empty. A
			 * record keeps the shape either card draws: its n-th text answers
			 * the card's n-th unanswered text.
			 */
			foreach ( $fields as $theirs => $kind ) {
				if ( $kind !== $type || isset( $used[ $theirs ] ) ) {
					continue;
				}

				$said = self::own_field( $post, $theirs );

				if ( null !== $said ) {
					$row[ $name ]    = $said;
					$used[ $theirs ] = true;
					$taken[ $type ]  = true;

					continue 2;
				}
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
	 * The fields a record type carries, in the order its design section drew them.
	 *
	 * Read from the ACF group DesignType registered for the type, because that
	 * group is the type's shape: one field per part of the card, in card
	 * order. Without ACF there is nothing to read and matching stays by name.
	 *
	 * @param string $type Post type key.
	 * @return array<string, string> Field name => field type.
	 */
	private static function design_fields( string $type ): array {
		static $known = array();

		if ( isset( $known[ $type ] ) ) {
			return $known[ $type ];
		}

		$fields = array();

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups( array( 'post_type' => $type ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( is_array( $field ) && isset( $field['name'], $field['type'] ) ) {
						$fields[ (string) $field['name'] ] = (string) $field['type'];
					}
				}
			}
		}

		$known[ $type ] = $fields;

		return $fields;
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
