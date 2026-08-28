<?php
/**
 * Baseline SEO: meta description, social cards and structured data.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;

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
		return (bool) apply_filters( 'qwerty_soft/seo_delegated', $owned );
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
		return (array) apply_filters( 'qwerty_soft/schema_organization', $node );
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
				'name'     => __( 'Home', 'qwerty-soft-signal' ),
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

		// Nothing behind a password belongs in structured data.
		if ( post_password_required( $post_id ) ) {
			return $node;
		}

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
	 * Longest description search engines reliably show, in characters.
	 */
	private const DESCRIPTION_LENGTH = 160;

	/**
	 * Best available description for the current view.
	 *
	 * Password-protected posts return an empty string so nothing behind the
	 * password leaks into the head. The content fallback strips headings
	 * before flattening the rest: otherwise an h2 and the paragraph after it
	 * run together into one sentence that never existed.
	 *
	 * @return string
	 */
	private function description(): string {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			if ( post_password_required( $post_id ) ) {
				return '';
			}

			if ( has_excerpt( $post_id ) ) {
				return $this->clip( (string) get_post_field( 'post_excerpt', $post_id ) );
			}

			return $this->clip( $this->flatten_content( (string) get_post_field( 'post_content', $post_id ) ) );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return $this->clip( (string) term_description() );
		}

		return $this->clip( (string) get_bloginfo( 'description' ) );
	}

	/**
	 * Reduce block content to the prose a description can be built from.
	 *
	 * @param string $content Raw post_content.
	 * @return string Plain text, still uncut.
	 */
	private function flatten_content( string $content ): string {
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = (string) excerpt_remove_blocks( $content );
		}

		$content = (string) preg_replace( '/<!--.*?-->/s', ' ', $content );
		$content = (string) preg_replace( '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $content );

		return $content;
	}

	/**
	 * Strip, decode, collapse and cut a string to the description length.
	 *
	 * The cut lands on a word boundary and the ellipsis is appended only when
	 * something was actually removed.
	 *
	 * @param string $text Any HTML or plain text.
	 * @return string
	 */
	private function clip( string $text ): string {
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= self::DESCRIPTION_LENGTH ) {
			return $text;
		}

		// Leave room for the ellipsis itself.
		$cut   = mb_substr( $text, 0, self::DESCRIPTION_LENGTH - 1 );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t\n\r\0\x0B,;:.-" ) . '…';
	}

	/**
	 * Featured image URL for social cards.
	 *
	 * @return string
	 */
	private function social_image(): string {
		if ( ! is_singular() || post_password_required( get_queried_object_id() ) ) {
			return '';
		}

		$image_id = (int) get_post_thumbnail_id( get_queried_object_id() );

		if ( $image_id <= 0 ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $image_id, 'qwerty-soft-signal-wide' );

		return is_array( $src ) ? (string) $src[0] : '';
	}

	/**
	 * Canonical URL for the current request.
	 *
	 * Search, author, date, paged and 404 views return an empty string: they
	 * have no canonical URL the theme can vouch for, and claiming the home
	 * page for them would mislead every crawler. The printer skips empty.
	 *
	 * @return string
	 */
	private function current_url(): string {
		if ( is_search() || is_author() || is_date() || is_404() || is_paged() ) {
			return '';
		}

		if ( is_singular() ) {
			return (string) get_permalink( get_queried_object_id() );
		}

		if ( is_front_page() ) {
			return home_url( '/' );
		}

		if ( is_home() ) {
			$blog_id = (int) get_option( 'page_for_posts' );

			return $blog_id > 0 ? (string) get_permalink( $blog_id ) : home_url( '/' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object_id() );

			return is_string( $link ) ? $link : '';
		}

		return '';
	}
}
