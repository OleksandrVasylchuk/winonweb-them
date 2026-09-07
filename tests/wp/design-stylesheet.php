<?php
/**
 * The design's classes survive conversion and its stylesheet lands as Additional CSS.
 *
 * Two halves of one promise — "make it look like the archive". The converter
 * keeps the design's class names on the blocks it emits; DesignStylesheet
 * installs the rules those names pointed at. Either alone does nothing.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\BlockConverter;
use Qwerty\Soft\Support\DesignStylesheet;
use Qwerty\Soft\Support\SectionSplitter;

/**
 * Convert every section of a fixture page with a structure-only converter.
 *
 * @param string         $file      File inside tests/fixtures/design/.
 * @param BlockConverter $converter Converter to use.
 * @return string Block markup for the whole page.
 */
function qsoft_convert_fixture( string $file, BlockConverter $converter ): string {
	$root  = qsoft_fixture( 'design' );
	$split = SectionSplitter::split( $root . '/' . $file );
	$out   = array();

	foreach ( $split['sections'] as $offset => $section ) {
		$result = $converter->convert( $section, 0 === $offset );

		if ( '' !== trim( $result['markup'] ) ) {
			$out[] = $result['markup'];
		}
	}

	return implode( "\n\n", $out );
}

/**
 * Every class attribute on a block wrapper in the markup, one list per block.
 *
 * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
 * @return array<int, array{name:string,attrs:array<string,mixed>,classes:array<int,string>}>
 */
function qsoft_block_classes( array $blocks ): array {
	$rows = array();

	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] ) {
			continue;
		}

		$html    = (string) $block['innerHTML'];
		$classes = array();

		if ( 1 === preg_match( '/^\s*<[a-z0-9]+[^>]*\sclass="([^"]*)"/i', $html, $found ) ) {
			$classes = array_values( array_filter( explode( ' ', $found[1] ) ) );
		}

		$rows[] = array(
			'name'    => (string) $block['blockName'],
			'attrs'   => (array) $block['attrs'],
			'classes' => $classes,
		);

		foreach ( qsoft_block_classes( (array) $block['innerBlocks'] ) as $inner ) {
			$rows[] = $inner;
		}
	}

	return $rows;
}

qsoft_test(
	'BlockConverter: the design\'s classes ride along on the blocks',
	static function (): void {
		$converter = new BlockConverter();
		$converter->use_design( array(), array(), qsoft_fixture( 'design' ) );

		$markup = qsoft_convert_fixture( 'Home.dc.html', $converter );

		qsoft_assert( '' !== $markup, 'Home converted to something' );

		$blocks = parse_blocks( $markup );
		$rows   = qsoft_block_classes( $blocks );
		$stats  = $converter->stats();

		qsoft_assert( $stats['classed_blocks'] >= 5, 'at least five blocks carry a design class', $stats );

		$carried = $converter->carried_classes();

		foreach ( array( 'hero', 'band', 'card', 'grid', 'eyebrow' ) as $want ) {
			qsoft_assert( in_array( $want, $carried, true ), '"' . $want . '" is among the carried classes', $carried );
		}

		$by_class = array();

		foreach ( $rows as $row ) {
			foreach ( $row['classes'] as $class ) {
				$by_class[ $class ][] = $row['name'];
			}

			// The attribute and the HTML agree: every className token is on the wrapper.
			if ( isset( $row['attrs']['className'] ) ) {
				foreach ( explode( ' ', (string) $row['attrs']['className'] ) as $token ) {
					qsoft_assert( in_array( $token, $row['classes'], true ), $row['name'] . ': className "' . $token . '" is also in the wrapper class list', $row['classes'] );
				}
			}

			// Nothing that collides with WordPress came from the design.
			foreach ( $row['classes'] as $class ) {
				$own = str_starts_with( $class, 'wp-' ) || str_starts_with( $class, 'is-' ) || str_starts_with( $class, 'has-' ) || str_starts_with( $class, 'align' ) || 'size-large' === $class;

				if ( ! $own ) {
					qsoft_assert( in_array( $class, $carried, true ), $row['name'] . ': "' . $class . '" on the wrapper is a carried design class', $row['classes'] );
				}
			}

			$carried_here = isset( $row['attrs']['className'] ) ? explode( ' ', (string) $row['attrs']['className'] ) : array();

			foreach ( $carried_here as $token ) {
				qsoft_assert( 1 !== preg_match( '/^(wp-|has-|align|qs-|screen-reader|entry-|post-|page-|site-)/', $token ), $row['name'] . ': className "' . $token . '" is not a reserved prefix' );
			}
		}

		qsoft_assert( in_array( 'core/group', $by_class['hero'] ?? array(), true ), 'the hero section is a group carrying "hero"', $by_class['hero'] ?? null );
		qsoft_assert( in_array( 'core/cover', $by_class['band'] ?? array(), true ), 'the band section is a cover carrying "band"', $by_class['band'] ?? null );

		/*
		 * A grid of cards is a grid of cards, not a Columns block: the
		 * design's `.grid` is what lays it out, and translating it into the
		 * editor's own column set replaced a three-track grid with two halves
		 * and dropped the class the stylesheet was written against.
		 */
		qsoft_assert( in_array( 'core/group', $by_class['card'] ?? array(), true ), 'each card is a group carrying "card"', $by_class['card'] ?? null );
		qsoft_assert( in_array( 'core/group', $by_class['grid'] ?? array(), true ), 'the grid is a group carrying "grid"', $by_class['grid'] ?? null );
		// Buttons deliberately carry nothing: the design's `.btn` padding on the wrapper would draw a second pill.
		qsoft_assert( ! isset( $by_class['button'] ), 'the CTA button carries no design classes', $by_class['button'] ?? null );
		qsoft_assert( ! str_contains( $markup, 'wp-block-button__link button' ) && ! str_contains( $markup, 'button wp-element-button' ), 'the anchor inside the button does not carry the design class' );

		// The markup survives a parse and render round trip with the classes intact.
		$rendered = '';

		foreach ( $blocks as $block ) {
			$rendered .= render_block( $block );
		}

		qsoft_assert( str_contains( $rendered, 'hero' ) && str_contains( $rendered, 'card' ), 'render_block() keeps the carried classes', substr( $rendered, 0, 300 ) );

		/*
		 * Reserved words are refused whatever the element and whatever the
		 * mode. State words and the cap are a different matter: they exist so
		 * a theme-styled import is not littered with classes nothing will
		 * match, and they come off when the design's own stylesheet is the one
		 * doing the matching. Both halves are checked, so neither can drift.
		 */
		$converter2 = new BlockConverter();
		$converter2->use_design( array(), array(), null );

		$section = array(
			'position' => 0,
			'label'    => 'Probe',
			'classes'  => array( 'wp-block-group', 'is-style-x', 'has-fun', 'alignfull', 'qs-thing', 'active', 'dark', 'one', 'two', 'three', 'four', 'five', 'six', 'seven' ),
			'html'     => '<section class="ignored"><h2 class="title active hidden open md:flex is-big wp-x okay">Probe</h2><p class="copy light">Body.</p></section>',
			'words'    => 3,
			'images'   => 0,
		);

		$faithful = $converter2->convert( $section, true )['markup'];

		qsoft_assert( 1 === preg_match( '/<!-- wp:group (\{.*?\}) -->/', $faithful, $open ), 'probe produced a group' );

		$attrs = json_decode( $open[1] ?? '{}', true );
		$names = explode( ' ', (string) ( $attrs['className'] ?? '' ) );

		qsoft_assert(
			array( 'active', 'dark', 'one', 'two', 'three', 'four', 'five', 'six', 'seven' ) === $names,
			'faithful keeps every class the design wrote, and still refuses the reserved ones',
			$names
		);
		qsoft_assert( str_contains( $faithful, '"className":"title active hidden open okay"' ), 'faithful keeps a state word a design stylesheet can match', $faithful );
		qsoft_assert( str_contains( $faithful, '"className":"copy light"' ), 'faithful keeps "light" on a paragraph', $faithful );

		$theme = static function (): bool {
			return false;
		};

		add_filter( 'qwerty_soft/faithful_styles', $theme );

		$converter3 = new BlockConverter();
		$converter3->use_design( array(), array(), null );

		$probe = $converter3->convert( $section, true )['markup'];

		remove_filter( 'qwerty_soft/faithful_styles', $theme );

		qsoft_assert( 1 === preg_match( '/<!-- wp:group (\{.*?\}) -->/', $probe, $open ), 'the theme-styled probe produced a group' );

		$attrs = json_decode( $open[1] ?? '{}', true );
		$names = explode( ' ', (string) ( $attrs['className'] ?? '' ) );

		qsoft_assert( array( 'dark', 'one', 'two', 'three', 'four', 'five' ) === $names, 'section keeps dark, refuses reserved words, stops at six', $names );
		qsoft_assert( str_contains( $probe, '"className":"title okay"' ), 'heading keeps "title okay" only', $probe );
		qsoft_assert( str_contains( $probe, '"className":"copy"' ), 'paragraph drops "light" when not a section', $probe );
	}
);

qsoft_test(
	'DesignStylesheet: import, print, reset',
	static function (): void {
		$root = qsoft_fixture( 'design' );

		// Something already in Additional CSS has to come out the other side untouched.
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = get_post( $post_id );
		$data    = json_decode( (string) $post->post_content, true );
		$data    = is_array( $data ) ? $data : array();

		$seed                  = '.pre-existing{outline:2px solid red}';
		$data['styles']        = isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array();
		$data['styles']['css'] = $seed;

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);
		wp_clean_theme_json_cache();

		qsoft_assert( ! DesignStylesheet::installed(), 'nothing installed before import' );

		$map = array(
			'img/band.jpg' => array(
				'id'  => 12345,
				'url' => 'https://example.test/wp-content/uploads/2026/08/band.jpg',
			),
		);

		$result = DesignStylesheet::import( $root, $map );

		qsoft_assert( $result['bytes'] > 100, 'import produced CSS', $result );
		qsoft_assert( $result['rules'] >= 8, 'several rules survived', $result );
		qsoft_assert( DesignStylesheet::installed(), 'installed() reports the slice' );

		$custom = wp_get_global_stylesheet( array( 'custom-css' ) );

		qsoft_assert( str_contains( $custom, DesignStylesheet::START ) && str_contains( $custom, DesignStylesheet::END ), 'WordPress prints the markers in its custom-css stylesheet', substr( $custom, 0, 200 ) );
		qsoft_assert( str_contains( $custom, $seed ), 'the pre-existing CSS is still there' );
		qsoft_assert( strpos( $custom, $seed ) < strpos( $custom, DesignStylesheet::START ), 'the design CSS is appended after what was there' );
		qsoft_assert( str_contains( $custom, '.card{padding:24px;border-radius:var(--radius);background:#F4F6FB}' ), 'the .card rule came across verbatim', $custom );
		qsoft_assert( str_contains( $custom, ':root{--accent:#2B54E6;' ), 'the :root custom properties are kept', $custom );
		qsoft_assert( str_contains( $custom, 'url("https://example.test/wp-content/uploads/2026/08/band.jpg")' ), 'the relative url() was repointed at the attachment', $custom );
		qsoft_assert( ! str_contains( $custom, 'url(img/band.jpg)' ), 'no archive-relative url() survives' );
		qsoft_assert( ! str_contains( $custom, '@import' ) && ! str_contains( $custom, '@font-face' ), 'no @import or @font-face' );
		qsoft_assert( ! str_contains( $custom, 'body{' ) && ! str_contains( $custom, 'html{' ), 'no body/html rule' );
		qsoft_assert( str_contains( $custom, '.hero{padding:96px 24px;text-align:center}' ), 'the hero padding rule is kept', $custom );
		qsoft_assert( str_contains( $custom, '.popup{position:fixed' ), 'a non-chrome fixed element keeps its position', $custom );
		qsoft_assert( str_contains( $custom, '.site-nav{display:flex' ), 'the nav rule is kept minus any fixed position', $custom );

		// A second import replaces, never stacks.
		DesignStylesheet::import( $root, $map );

		$again = wp_get_global_stylesheet( array( 'custom-css' ) );

		qsoft_assert( 1 === substr_count( $again, DesignStylesheet::START ), 'importing twice leaves one slice', substr_count( $again, DesignStylesheet::START ) );
		qsoft_assert( 1 === substr_count( $again, $seed ), 'and one copy of the pre-existing CSS' );

		// Reset takes exactly the slice.
		qsoft_assert( DesignStylesheet::reset(), 'reset() reports that it removed something' );
		qsoft_assert( ! DesignStylesheet::installed(), 'nothing installed after reset' );

		$after = wp_get_global_stylesheet( array( 'custom-css' ) );

		qsoft_assert( ! str_contains( $after, DesignStylesheet::START ) && ! str_contains( $after, '.card{' ), 'the design CSS is gone', $after );
		qsoft_assert( str_contains( $after, $seed ), 'the pre-existing CSS survived the reset' );
		qsoft_assert( ! DesignStylesheet::reset(), 'a second reset() has nothing to remove' );

		// Rewriting rules, in isolation.
		$dir = qsoft_fixture( 'design' ) . '/tmp-css-' . bin2hex( random_bytes( 3 ) );
		mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Temporary fixture the test removes.
		mkdir( $dir . '/img' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Temporary fixture the test removes.
		file_put_contents( $dir . '/img/present.png', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary fixture the test removes.
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary fixture the test removes.
			$dir . '/site.css',
			'@charset "utf-8"; @import url(x.css); /* c */ @font-face{font-family:X;src:url(x.woff2)}'
			. '*,*::before,*::after{box-sizing:border-box} html,body{margin:0;--page-bg:#fff}'
			. '.site-header{position:sticky;top:0;background:#fff} nav.main{position:fixed;color:red}'
			. '.container{max-width:1200px;margin:0 auto;padding:0 20px} .wrap .inner{margin-left:auto;color:blue}'
			. '.evil{background:url(javascript:alert(1));color:expression(alert(1));behavior:url(x.htc)}'
			. '.gone{background:url(img/missing.png);color:green} .kept{background:url(img/present.png)}'
			. '.quote::before{content:"<"} template{display:none} [hidden]{display:none}'
			. '@media (max-width:600px){ .card{padding:12px} body{font-size:14px} }'
			. '@supports (display:grid){ .grid{display:grid} }'
			. '@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}'
		);

		try {
			$r   = DesignStylesheet::import( $dir, array() );
			$css = wp_get_global_stylesheet( array( 'custom-css' ) );

			qsoft_assert( ! str_contains( $css, '@charset' ) && ! str_contains( $css, '@import' ) && ! str_contains( $css, '@font-face' ), 'statements and @font-face dropped' );
			qsoft_assert( ! str_contains( $css, 'box-sizing' ), 'the * reset is dropped' );
			qsoft_assert( str_contains( $css, 'html,body{--page-bg:#fff}' ), 'html/body keep only their custom properties', $css );
			qsoft_assert( str_contains( $css, '.site-header{top:0;background:#fff}' ), 'sticky header loses position only', $css );
			qsoft_assert( str_contains( $css, 'nav.main{color:red}' ), 'fixed nav loses position only', $css );

			/*
			 * The design's container keeps its width and its centring,
			 * because in faithful mode nothing else is centring the page —
			 * the theme's own layout container was taken out of the template
			 * on purpose. Stripped, every imported page sat against the left
			 * edge of the window.
			 */
			qsoft_assert( str_contains( $css, '.container{max-width:1200px;margin:0 auto;padding:0 20px}' ), 'container keeps its width and centring', $css );
			qsoft_assert( str_contains( $css, '.wrap .inner{margin-left:auto;color:blue}' ), 'inner keeps margin-left:auto', $css );
			qsoft_assert( ! str_contains( $css, '.evil{' ) && ! str_contains( $css, 'expression' ) && ! str_contains( $css, 'javascript:' ) && ! str_contains( $css, 'behavior' ), 'the evil rule is gone entirely', $css );
			qsoft_assert( str_contains( $css, '.gone{color:green}' ), 'a url() to a missing file drops that declaration only', $css );
			qsoft_assert( str_contains( $css, '.kept{background:url(img/present.png)}' ), 'a url() to a file in the archive but not in the map is left as written', $css );
			qsoft_assert( ! str_contains( $css, '<' ), 'no raw < in the stored CSS', $css );
			qsoft_assert( str_contains( $css, '.quote::before{content:"\\3c "}' ), '< is escaped, not lost', $css );
			qsoft_assert( ! str_contains( $css, 'template{' ) && ! str_contains( $css, '[hidden]{' ), 'display:none helpers on bare tags are dropped', $css );
			qsoft_assert( str_contains( $css, '@media (max-width:600px){.card{padding:12px}}' ), '@media keeps its content, minus body', $css );
			qsoft_assert( str_contains( $css, '@supports (display:grid){.grid{display:grid}}' ), '@supports is kept', $css );
			qsoft_assert( str_contains( $css, '@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}' ), '@keyframes kept whole', $css );
			qsoft_assert( 1 === $r['unmapped'], 'one url() was in the archive but unmapped', $r );
			qsoft_assert( $r['dropped'] >= 6, 'dropped counter covers statements, font-face, reset, evil, helpers', $r );

			DesignStylesheet::reset();

			/*
			 * Wrapping is a different contract from Additional CSS. A wrapped
			 * section's root carries `.qs-design`, so every rule aimed inside
			 * a section is lifted by that one class to sit above theme.json's
			 * element styles, the design owns html and body, a sticky header
			 * stays sticky, and an @import that is in the archive is inlined
			 * where the statement stood.
			 */
			file_put_contents( $dir . '/x.css', '.imported{color:teal}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary fixture the test removes.
			file_put_contents( $dir . '/page.html', '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><section class="card">x</section></body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Temporary fixture the test removes.

			$compiled = DesignStylesheet::compile_sources( $dir, array(), array( $dir . '/page.html' ) );
			$wrapped  = (string) ( $compiled['sources'][0]['css'] ?? '' );

			qsoft_assert( str_contains( $wrapped, '.card:is(.qs-design, .qs-design *){padding:12px}' ), 'wrapped: a rule inside a section is lifted by exactly one class', $wrapped );
			qsoft_assert( str_contains( $wrapped, '.quote:is(.qs-design, .qs-design *)::before{' ), 'wrapped: the lift goes before a pseudo-element', $wrapped );
			qsoft_assert( str_contains( $wrapped, 'html, body:is(.qs-design-site, .editor-styles-wrapper){margin:0;--page-bg:#fff}' ), 'wrapped: the design owns html and body, and body is lifted above the theme\'s global styles', $wrapped );
			qsoft_assert( str_contains( $wrapped, '.site-header:is(.qs-design, .qs-design *){position:sticky;top:0;background:#fff}' ), 'wrapped: a sticky header stays sticky', $wrapped );
			qsoft_assert( str_contains( $wrapped, '.imported:is(.qs-design, .qs-design *){color:teal}' ), 'wrapped: an @import from the archive is inlined', $wrapped );
			qsoft_assert( ! str_contains( $wrapped, '@import' ), 'wrapped: and the statement itself is gone', $wrapped );
			qsoft_assert( 1 === preg_match( '/@media \(max-width:600px\)\{\.card:is\(\.qs-design, \.qs-design \*\)\{padding:12px\}\s*body:is\([^)]*\)\{font-size:14px\}\}/', $wrapped ), 'wrapped: @media keeps its body rule too', $wrapped );

			unlink( $dir . '/x.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary fixture the test made.
			unlink( $dir . '/page.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary fixture the test made.

			/*
			 * Theme mode is the other half of that promise: there the block
			 * layout owns the content width, so a design container that also
			 * sets one is two containers fighting.
			 */
			$theme = static function (): bool {
				return false;
			};

			add_filter( 'qwerty_soft/faithful_styles', $theme );

			DesignStylesheet::import( $dir, array() );

			$themed = wp_get_global_stylesheet( array( 'custom-css' ) );

			remove_filter( 'qwerty_soft/faithful_styles', $theme );

			qsoft_assert( str_contains( $themed, '.container{padding:0 20px}' ), 'wearing the theme, the container loses max-width and auto margins', $themed );
			qsoft_assert( str_contains( $themed, '.wrap .inner{color:blue}' ), 'wearing the theme, inner loses margin-left:auto', $themed );

			DesignStylesheet::reset();
		} finally {
			unlink( $dir . '/site.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary fixture.
			unlink( $dir . '/img/present.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary fixture.
			rmdir( $dir . '/img' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Temporary fixture.
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Temporary fixture.
		}
	}
);

qsoft_finish();
