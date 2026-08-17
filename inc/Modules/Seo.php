<?php
/**
 * Baseline SEO: meta description, social cards and structured data.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the metadata a brochure site needs without a plugin.
 *
 * Everything here stands down automatically when a dedicated SEO plugin is
 * active, so the theme never fights Yoast, Rank Math, SEOPress or AIOSEO.
 */
final class Seo implements Module {

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'meta_tags' ), 3 );
		add_action( 'wp_head', array( $this, 'structured_data' ), 4 );
	}

	/**
	 * Whether another plugin already owns page metadata.
	 *
	 * @return bool
	 */
	private function delegated(): bool {
		$owned = defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| class_exists( 'The_SEO_Framework\\Load' );

		/**
		 * Filter whether the theme should skip emitting SEO metadata.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $owned True when another plugin handles metadata.
		 */
		return (bool) apply_filters( 'wow_signal/seo_delegated', $owned );
	}

	/**
	 * Print the description and Open Graph / Twitter card tags.
	 *
	 * @return void
	 */
	public function meta_tags(): void {
		if ( $this->delegated() || is_404() ) {
			return;
		}

		$description = $this->description();
		$title       = wp_get_document_title();
		$url         = $this->current_url();
		$image       = $this->social_image();

		if ( '' !== $description ) {
			printf(
				'<meta name="description" content="%s">' . "\n",
				esc_attr( $description )
			);
		}

		printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( is_singular( 'post' ) ? 'article' : 'website' ) );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( (string) get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( str_replace( '-', '_', (string) get_bloginfo( 'language' ) ) ) );

		if ( '' !== $url ) {
			printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		}

		if ( '' !== $description ) {
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
		}

		if ( '' !== $image ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
			printf( '<meta name="twitter:card" content="%s">' . "\n", 'summary_large_image' );
		} else {
			printf( '<meta name="twitter:card" content="%s">' . "\n", 'summary' );
		}

		printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );

		if ( '' !== $description ) {
			printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $description ) );
		}
	}

	/**
	 * Print JSON-LD for the organisation, the site and the current document.
	 *
	 * @return void
	 */
	public function structured_data(): void {
		if ( $this->delegated() ) {
			return;
		}

		$graph = array( $this->organization_node(), $this->website_node() );

		$breadcrumbs = $this->breadcrumb_node();

		if ( array() !== $breadcrumbs ) {
			$graph[] = $breadcrumbs;
		}

		if ( is_singular( 'post' ) ) {
			$graph[] = $this->article_node();
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( array_filter( $graph ) ),
		);

		/*
		 * wp_json_encode() escapes forward slashes by default, which turns a
		 * hostile "</script>" inside a title into "<\/script>". That default is
		 * exactly why JSON_UNESCAPED_SLASHES is NOT passed here.
		 */
		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoding escapes the closing-tag sequence; see comment above.
		);
	}

	/**
	 * Organisation node describing the site owner.
	 *
	 * @return array<string, mixed>
	 */
	private function organization_node(): array {
		$node = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => (string) get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo_id = (int) get_theme_mod( 'custom_logo' );

		if ( $logo_id > 0 ) {
			$logo = wp_get_attachment_image_src( $logo_id, 'full' );

			if ( is_array( $logo ) ) {
				$node['logo'] = array(
					'@type'  => 'ImageObject',
					'url'    => $logo[0],
					'width'  => (int) $logo[1],
					'height' => (int) $logo[2],
				);
			}
		}

		/**
		 * Filter the Organization JSON-LD node.
		 *
		 * Use this to add sameAs profiles, contactPoint or address per project.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $node Organization node.
		 */
		return (array) apply_filters( 'wow_signal/schema_organization', $node );
	}

	/**
	 * WebSite node, including the search action.
	 *
	 * @return array<string, mixed>
	 */
	private function website_node(): array {
		return array(
			'@type'           => 'WebSite',
			'@id'             => home_url( '/#website' ),
			'url'             => home_url( '/' ),
			'name'            => (string) get_bloginfo( 'name' ),
			'description'     => (string) get_bloginfo( 'description' ),
			'publisher'       => array( '@id' => home_url( '/#organization' ) ),
			'inLanguage'      => (string) get_bloginfo( 'language' ),
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/?s={search_term_string}' ),
					),
					'query-input' => 'required name=search_term_string',
				),
			),
		);
	}

	/**
	 * Breadcrumb trail for singular views.
	 *
	 * @return array<string, mixed>
	 */
	private function breadcrumb_node(): array {
		if ( ! is_singular() || is_front_page() ) {
			return array();
		}

		$post_id = get_queried_object_id();

		if ( $post_id <= 0 ) {
			return array();
		}

		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => __( 'Home', 'wow-signal' ),
				'item'     => home_url( '/' ),
			),
		);

		$ancestors = array_reverse( (array) get_post_ancestors( $post_id ) );
		$position  = 1;

		foreach ( $ancestors as $ancestor_id ) {
			++$position;
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => wp_strip_all_tags( (string) get_the_title( (int) $ancestor_id ) ),
				'item'     => (string) get_permalink( (int) $ancestor_id ),
			);
		}

		++$position;
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => wp_strip_all_tags( (string) get_the_title( $post_id ) ),
		);

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $this->current_url() . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Article node for single posts.
	 *
	 * @return array<string, mixed>
	 */
	private function article_node(): array {
		$post_id = get_queried_object_id();

		$node = array(
			'@type'            => 'Article',
			'@id'              => $this->current_url() . '#article',
			'headline'         => wp_strip_all_tags( (string) get_the_title( $post_id ) ),
			'datePublished'    => (string) get_the_date( DATE_W3C, $post_id ),
			'dateModified'     => (string) get_the_modified_date( DATE_W3C, $post_id ),
			'mainEntityOfPage' => array( '@id' => $this->current_url() ),
			'publisher'        => array( '@id' => home_url( '/#organization' ) ),
			'isPartOf'         => array( '@id' => home_url( '/#website' ) ),
		);

		$author_id = (int) get_post_field( 'post_author', $post_id );

		if ( $author_id > 0 ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => (string) get_the_author_meta( 'display_name', $author_id ),
			);
		}

		$description = $this->description();

		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$image = $this->social_image();

		if ( '' !== $image ) {
			$node['image'] = $image;
		}

		return $node;
	}

	/**
	 * Best available description for the current view.
	 *
	 * @return string
	 */
	private function description(): string {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$excerpt = has_excerpt( $post_id )
				? (string) get_the_excerpt( $post_id )
				: wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 32, '' );

			return trim( wp_strip_all_tags( $excerpt ) );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return trim( wp_strip_all_tags( (string) term_description() ) );
		}

		return trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
	}

	/**
	 * Featured image URL for social cards.
	 *
	 * @return string
	 */
	private function social_image(): string {
		if ( ! is_singular() ) {
			return '';
		}

		$image_id = (int) get_post_thumbnail_id( get_queried_object_id() );

		if ( $image_id <= 0 ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $image_id, 'wow-signal-wide' );

		return is_array( $src ) ? (string) $src[0] : '';
	}

	/**
	 * Canonical URL for the current request.
	 *
	 * @return string
	 */
	private function current_url(): string {
		if ( is_singular() ) {
			return (string) get_permalink( get_queried_object_id() );
		}

		if ( is_home() ) {
			$blog_id = (int) get_option( 'page_for_posts' );

			return $blog_id > 0 ? (string) get_permalink( $blog_id ) : home_url( '/' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object_id() );

			return is_string( $link ) ? $link : home_url( '/' );
		}

		return home_url( '/' );
	}
}
