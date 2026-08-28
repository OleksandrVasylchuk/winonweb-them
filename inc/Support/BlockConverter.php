<?php
/**
 * Deterministic HTML to Gutenberg block conversion.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a design section into blocks without asking a model.
 *
 * A model writes better copy decisions than this ever will, but it is not
 * always available: a key costs money, a subscription needs a human to ferry
 * text. So the baseline is structural and free. Headings stay headings, lists
 * stay lists, repeated sibling cards become columns, and every value that
 * would have been a literal is mapped onto a theme preset instead.
 *
 * The result is honest rather than clever: it will not invent a layout the
 * markup does not describe, and anything it cannot express it reports rather
 * than approximating. A model pass on top of this improves it; without one the
 * site still works.
 */
final class BlockConverter {

	/**
	 * Inline elements kept verbatim inside a text block.
	 *
	 * @var array<int, string>
	 */
	private const INLINE_KEEP = array( 'a', 'strong', 'b', 'em', 'i', 'span', 'br', 'code', 'sub', 'sup', 'mark', 'abbr', 'time', 'small', 'u' );

	/**
	 * Elements that never survive into page content.
	 *
	 * @var array<int, string>
	 */
	private const DROP = array( 'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'svg', 'canvas', 'form', 'input', 'select', 'textarea', 'button' );

	/**
	 * Class-name fragments that mark a short label above a heading.
	 *
	 * @var array<int, string>
	 */
	private const EYEBROW_HINTS = array( 'eyebrow', 'kicker', 'overline', 'tagline', 'label', 'badge', 'chip', 'tag', 'pill' );

	/**
	 * Class-name fragments that mark a call-to-action link.
	 *
	 * @var array<int, string>
	 */
	private const BUTTON_HINTS = array( 'btn', 'button', 'cta' );

	/**
	 * Notes gathered while converting the current section.
	 *
	 * @var array<int, string>
	 */
	private array $concerns = array();

	/**
	 * What a person will be able to edit in the current section.
	 *
	 * @var array<int, string>
	 */
	private array $editable = array();

	/**
	 * Whether this section may still emit an h1.
	 *
	 * @var bool
	 */
	private bool $allow_h1 = false;

	/**
	 * The palette slug this section's band should use.
	 *
	 * @var string
	 */
	private string $background = 'base';

	/**
	 * The design's own gradient for this band, if it has one.
	 *
	 * @var string
	 */
	private string $gradient = '';

	/**
	 * Whether this band needs light text on it.
	 *
	 * @var bool
	 */
	private bool $on_dark = false;

	/**
	 * The photograph the design painted behind this band, if any.
	 *
	 * Kept as the design's own path so the assembler can repoint it at the
	 * Media Library afterwards.
	 *
	 * @var string
	 */
	private string $cover = '';

	/**
	 * How deep into nested column sets the walk currently is.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Section class name to the colour the design paints it, as hex.
	 *
	 * @var array<string, string>
	 */
	private array $section_colors = array();

	/**
	 * The palette the site is about to wear, slug to hex.
	 *
	 * @var array<string, string>
	 */
	private array $palette = array();

	/**
	 * The design's stylesheets, resolvable per element. Null means "structure only".
	 *
	 * @var CssIndex|null
	 */
	private ?CssIndex $css = null;

	/**
	 * Theme spacing presets, slug to pixels at desktop width.
	 *
	 * @var array<string, float>|null
	 */
	private ?array $spacing_presets = null;

	/**
	 * Theme font-size presets, slug to pixels at desktop width.
	 *
	 * @var array<string, float>
	 */
	private array $font_presets = array();

	/**
	 * Theme radius tokens, name to pixels.
	 *
	 * @var array<string, float>
	 */
	private array $radius_tokens = array();

	/**
	 * Whether the current section needed a value no preset was close to.
	 *
	 * @var bool
	 */
	private bool $literal_used = false;

	/**
	 * The colour currently behind the text being converted, as hex.
	 *
	 * Starts as the band and narrows as the walk enters a card or panel with
	 * its own background. Every text colour taken from the design is checked
	 * against it before it is allowed through.
	 *
	 * @var string
	 */
	private string $surface = '#ffffff';

	/**
	 * Padding the design gave the current section, side to block value.
	 *
	 * @var array<string, string>
	 */
	private array $band_padding = array();

	/**
	 * The design's content width for the current section, e.g. "1440px".
	 *
	 * @var string
	 */
	private string $content_size = '';

	/**
	 * Whether the design centres the current section's text.
	 *
	 * @var bool
	 */
	private bool $centered = false;

	/**
	 * Tolerance for snapping a design value onto a theme preset.
	 *
	 * @var float
	 */
	private const TOLERANCE = 0.12;

	/**
	 * Class-name prefixes that mean something to WordPress or the theme.
	 *
	 * A design class with one of these would collide with block supports,
	 * style variations or the theme's own CSS, so it is never carried over.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_PREFIXES = array( 'wp-', 'is-', 'has-', 'align', 'qs-', 'screen-reader', 'entry-' );

	/**
	 * Exact WordPress body/post classes a design may echo; `page-hero` and
	 * `site-footer` are the design's own and stay.
	 */
	private const RESERVED_PATTERN = '/^(post|page)-(id-)?\d+$|^page-template|^post-type-|^type-|^status-|^hentry$|^logged-in$|^admin-bar$|^site-(title|logo|tagline)$|^custom-logo/';

	/**
	 * Bare state words a script would have toggled; meaningless on a static block.
	 *
	 * @var array<int, string>
	 */
	private const STATE_WORDS = array( 'active', 'open', 'hidden', 'visible', 'show', 'collapsed' );

	/**
	 * Most design classes a single block carries.
	 *
	 * @var int
	 */
	private const MAX_CARRIED = 6;

	/**
	 * Tags a Group block may be saved with.
	 *
	 * @var array<int, string>
	 */
	private const GROUP_TAGS = array( 'div', 'header', 'main', 'section', 'article', 'aside', 'footer', 'nav' );

	/**
	 * The current section's own classes, carried onto its band.
	 *
	 * @var array<int, string>
	 */
	private array $section_classes = array();

	/**
	 * The current section's own id, carried onto its band.
	 *
	 * A design's primary navigation is half in-page links — `#catalog`,
	 * `#checkout-flow`, `#newsletter`. Drop the ids and every one of them
	 * lands at the top of the page instead of at the section it names.
	 *
	 * @var string
	 */
	private string $section_anchor = '';

	/**
	 * The section's root element; its classes go on the band, not on an inner panel.
	 *
	 * @var DOMElement|null
	 */
	private ?DOMElement $root = null;

	/**
	 * Every design class carried onto a block so far, as a set.
	 *
	 * @var array<string, true>
	 */
	private array $carried = array();

	/**
	 * How many blocks received at least one design class.
	 *
	 * @var int
	 */
	private int $classed_blocks = 0;

	/**
	 * Design classes that ended up on blocks, across every section converted.
	 *
	 * The stylesheet importer uses this to keep only the rules that can still
	 * match something.
	 *
	 * @return array<int, string>
	 */
	public function carried_classes(): array {
		return array_keys( $this->carried );
	}

	/**
	 * Counters for the report.
	 *
	 * @return array{classed_blocks:int,classes:int}
	 */
	public function stats(): array {
		return array(
			'classed_blocks' => $this->classed_blocks,
			'classes'        => count( $this->carried ),
		);
	}

	/**
	 * The design's own classes on an element, filtered to the ones worth keeping.
	 *
	 * @param array<int, string> $raw     Class names as written in the design.
	 * @param bool               $section Whether these sit on a section, where dark/light are meaningful.
	 * @return array<int, string>
	 */
	private function design_classes( array $raw, bool $section = false ): array {
		$kept     = array();
		$faithful = self::faithful();

		foreach ( $raw as $class ) {
			$class = trim( (string) $class );

			// A class that needs sanitising is one the stylesheet could not have matched anyway.
			if ( '' === $class || sanitize_html_class( $class ) !== $class || isset( $kept[ $class ] ) ) {
				continue;
			}

			$lower = strtolower( $class );

			foreach ( self::RESERVED_PREFIXES as $prefix ) {
				if ( str_starts_with( $lower, $prefix ) ) {
					continue 2;
				}
			}

			if ( 1 === preg_match( self::RESERVED_PATTERN, $lower ) ) {
				continue;
			}

			/*
			 * A state word and a bare `dark` are dead weight when the theme
			 * does the styling. When the design's own stylesheet does, they
			 * are load-bearing: `.lang a.active` paints the current language,
			 * `.dark .card` inverts a card on a dark band. Same for the cap —
			 * six classes is plenty to hang a theme style on and not enough
			 * to reproduce a design, where the seventh may be the one the
			 * grid is written against.
			 */
			if ( ! $faithful && in_array( $lower, self::STATE_WORDS, true ) ) {
				continue;
			}

			if ( ! $faithful && ! $section && in_array( $lower, array( 'dark', 'light' ), true ) ) {
				continue;
			}

			$kept[ $class ] = true;

			if ( ! $faithful && count( $kept ) >= self::MAX_CARRIED ) {
				break;
			}
		}

		return array_keys( $kept );
	}

	/**
	 * Put an element's design classes on the block being built from it.
	 *
	 * The class goes in two places, because WordPress keeps them in two: the
	 * `className` attribute the editor reads, and the wrapper's class list in
	 * the saved HTML. A block with one and not the other is flagged as
	 * modified the moment it is opened.
	 *
	 * @param DOMElement|null      $node    Source element; null carries nothing.
	 * @param array<string, mixed> $attrs   Block attributes, extended in place.
	 * @param array<int, string>   $classes Wrapper class list, extended in place.
	 * @return void
	 */
	private function carry( ?DOMElement $node, array &$attrs, array &$classes ): void {
		if ( null === $node || $node === $this->root ) {
			return;
		}

		$list = preg_split( '/\s+/', $node->getAttribute( 'class' ) );

		$this->carry_classes( $this->design_classes( is_array( $list ) ? $list : array() ), $attrs, $classes );
	}

	/**
	 * Put an already-filtered class list on a block.
	 *
	 * @param array<int, string>   $kept    Filtered design classes.
	 * @param array<string, mixed> $attrs   Block attributes, extended in place.
	 * @param array<int, string>   $classes Wrapper class list, extended in place.
	 * @return void
	 */
	private function carry_classes( array $kept, array &$attrs, array &$classes ): void {
		$kept = array_values( array_diff( $kept, $classes ) );

		if ( array() === $kept ) {
			return;
		}

		$existing           = isset( $attrs['className'] ) ? trim( (string) $attrs['className'] ) : '';
		$attrs['className'] = trim( $existing . ' ' . implode( ' ', $kept ) );

		foreach ( $kept as $class ) {
			$classes[]               = $class;
			$this->carried[ $class ] = true;
		}

		++$this->classed_blocks;
	}

	/**
	 * Teach the converter what the design's own colours are.
	 *
	 * Without this every band falls back to the page colour, which is honest
	 * but flat. With it, a section the design painted grey comes out on the
	 * palette slug closest to that grey — so the rhythm of the original
	 * survives without a single literal colour reaching the markup.
	 *
	 * With the stylesheets as well, the blocks also take the design's own
	 * sizes: section padding, heading scale, button shape, card treatment —
	 * each snapped to a theme preset when one is close, kept exact otherwise.
	 *
	 * @param array<string, string> $section_colors Class name to hex.
	 * @param array<string, string> $palette        Slug to hex.
	 * @param CssIndex|string|null  $css            The design's stylesheets, or the design root to read them from.
	 * @return void
	 */
	public function use_design( array $section_colors, array $palette, $css = null ): void {
		$this->section_colors = $section_colors;
		$this->palette        = $palette;

		if ( $css instanceof CssIndex ) {
			$this->css = $css;
		} elseif ( is_string( $css ) && '' !== $css && is_dir( $css ) ) {
			$this->css = CssIndex::from_directory( $css );
		} else {
			$this->css = null;
		}
	}

	/**
	 * Convert one section record from SectionSplitter.
	 *
	 * @param array<string, mixed> $section  Section record.
	 * @param bool                 $is_first Whether this is the page's first section.
	 * @return array{markup:string,summary:string,editable:array<int,string>,concerns:array<int,string>}
	 */
	public function convert( array $section, bool $is_first = false ): array {
		$this->concerns     = array();
		$this->editable     = array();
		$this->allow_h1     = $is_first;
		$this->literal_used = false;
		$this->background   = $this->band_colour( $section );

		$dom  = self::load( (string) $section['html'] );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		$this->root            = null;
		$this->section_classes = $this->design_classes( array_map( 'strval', (array) ( $section['classes'] ?? array() ) ), true );

		if ( $body instanceof DOMElement ) {
			foreach ( $body->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					$this->root = $child;
					break;
				}
			}
		}

		$this->section_anchor = $this->root instanceof DOMElement
			? self::clean_anchor( $this->root->getAttribute( 'id' ) )
			: '';

		$this->cover = $body instanceof DOMElement ? $this->background_image( $section, $body ) : '';

		if ( '' !== $this->cover ) {
			// A picture behind text always gets a dim, and a dim needs light text.
			$this->gradient = '';
			$this->on_dark  = true;
		}

		$this->surface = $this->band_surface();

		if ( $body instanceof DOMElement ) {
			$this->read_section_styles( $body );
		}

		$inner = $body instanceof DOMNode ? $this->children( $body ) : '';

		if ( $this->literal_used ) {
			$this->concerns[] = __( 'Some sizes from the design had no matching theme preset and were kept as exact values; adjust them in the Site Editor if you want them on the scale.', 'qwerty-soft-signal' );
		}

		if ( '' === trim( $inner ) ) {
			return array(
				'markup'   => '',
				'summary'  => '',
				'editable' => array(),
				'concerns' => array( __( 'This section held nothing that could become content.', 'qwerty-soft-signal' ) ),
			);
		}

		$position = (int) $section['position'];
		$markup   = $this->band( $inner, $position, (string) $section['label'] );

		if ( self::faithful() ) {
			$markup = self::as_designed( $markup );
		}

		return array(
			'markup'   => $markup,
			'summary'  => $this->summary( $section ),
			'editable' => array_values( array_unique( $this->editable ) ),
			'concerns' => array_values( array_unique( $this->concerns ) ),
		);
	}

	/**
	 * An element id that is safe to put back on a block.
	 *
	 * @param string $id Raw id attribute.
	 * @return string The id, or '' when it is unusable.
	 */
	private static function clean_anchor( string $id ): string {
		$id = trim( $id );

		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_.:-]*$/', $id ) ? $id : '';
	}

	/**
	 * Whether the conversion keeps the design's own styling rather than the theme's.
	 *
	 * The converter has always done two jobs at once: read the design's
	 * structure, and restate its styling in the theme's vocabulary — palette
	 * slugs, spacing steps, layout containers. The second job is what makes an
	 * imported site editable in the Site Editor, and it is also what makes it
	 * *not* the design: a hero the archive painted `#101827` at 90px of padding
	 * becomes `surface` at `spacing|80`, close but not the same.
	 *
	 * Faithful is the default because the design is the brief. The design's own
	 * stylesheet is installed alongside the blocks and its class names are kept
	 * on them, so with the theme's opinions taken back out the page renders
	 * exactly as the archive did — the structure is blocks, the styling is the
	 * design's, and what improves is the markup underneath: real headings, real
	 * lists, real buttons, alt text, one h1.
	 *
	 * Filter it off to get the old behaviour, where an import wears the theme.
	 *
	 * @return bool
	 */
	public static function faithful(): bool {
		/**
		 * Whether an import keeps the design's styling exactly.
		 *
		 * @since 1.4.0
		 *
		 * @param bool $faithful True to keep the design's own styling.
		 */
		return (bool) apply_filters( 'qwerty_soft/faithful_styles', true );
	}

	/**
	 * Take the theme's styling opinions back out of finished block markup.
	 *
	 * Structure, classes and anything the design itself stated are kept; every
	 * preset the converter reached for — a palette slug, a spacing step, a
	 * font-size preset, a layout container, a width alignment — is removed,
	 * from the block attributes and from the markup they render into.
	 *
	 * Done here rather than at each of the forty places that add one: the rule
	 * is about the finished page, it is stated once, and the conversion above
	 * stays the same code whichever mode it runs in.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	public static function as_designed( string $markup ): string {
		$blocks = parse_blocks( $markup );

		return serialize_blocks( self::undress( $blocks ) );
	}

	/**
	 * Strip theme presets from a tree of parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function undress( array $blocks ): array {
		foreach ( $blocks as $index => $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

			foreach ( array( 'align', 'backgroundColor', 'textColor', 'gradient', 'fontSize', 'layout', 'borderColor', 'overlayColor', 'dimRatio' ) as $key ) {
				unset( $attrs[ $key ] );
			}

			if ( isset( $attrs['style'] ) && is_array( $attrs['style'] ) ) {
				$attrs['style'] = self::without_presets( $attrs['style'] );

				if ( array() === $attrs['style'] ) {
					unset( $attrs['style'] );
				}
			}

			$blocks[ $index ]['attrs'] = $attrs;

			foreach ( array( 'innerHTML', 'innerContent' ) as $key ) {
				if ( ! isset( $block[ $key ] ) ) {
					continue;
				}

				$blocks[ $index ][ $key ] = is_array( $block[ $key ] )
					? array_map(
						static function ( $piece ) {
							return is_string( $piece ) ? self::undress_html( $piece ) : $piece;
						},
						$block[ $key ]
					)
					: self::undress_html( (string) $block[ $key ] );
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::undress( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

	/**
	 * Drop every style value that points at a theme preset.
	 *
	 * A literal the design stated — `border-radius: 8px`, a hex colour lifted
	 * from its stylesheet — is the design's and stays. Anything written as
	 * `var:preset|…` or `var:custom|…` is the theme talking and goes.
	 *
	 * @param array<string, mixed> $style Style attribute.
	 * @return array<string, mixed>
	 */
	private static function without_presets( array $style ): array {
		foreach ( $style as $key => $value ) {
			if ( is_array( $value ) ) {
				$style[ $key ] = self::without_presets( $value );

				if ( array() === $style[ $key ] ) {
					unset( $style[ $key ] );
				}

				continue;
			}

			if ( is_string( $value ) && ( str_contains( $value, 'var:preset|' ) || str_contains( $value, 'var:custom|' ) || str_contains( $value, 'var(--wp--' ) ) ) {
				unset( $style[ $key ] );
			}
		}

		return $style;
	}

	/**
	 * The same removal, in the markup a block renders into.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	private static function undress_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		/*
		 * Preset classes: colours, sizes, widths, layout containers. Bounded
		 * by whitespace or by the quote on either side, so the first class in
		 * an attribute is caught as well as the ones after it.
		 */
		$html = (string) preg_replace(
			'#(?<=[\s"])(?:align(?:full|wide)|has-background|has-text-color|has-link-color|has-global-padding|has-custom-font-size|has-[a-z0-9-]+-(?:color|background-color|font-size|gradient-background)|is-layout-[a-z]+|wp-container-[a-z0-9-]+|is-content-justification-[a-z]+|wp-block-[a-z-]+-is-layout-[a-z]+)(?=[\s"])#',
			'',
			$html
		);

		// The gaps those left behind.
		$html = (string) preg_replace_callback(
			'#\sclass="([^"]*)"#',
			static function ( array $found ): string {
				$classes = trim( (string) preg_replace( '#\s+#', ' ', $found[1] ) );

				return '' === $classes ? '' : ' class="' . $classes . '"';
			},
			$html
		);

		// Inline declarations that resolve to a theme preset.
		$html = (string) preg_replace_callback(
			'#\sstyle="([^"]*)"#',
			static function ( array $found ): string {
				$kept = array();

				foreach ( explode( ';', $found[1] ) as $declaration ) {
					$declaration = trim( $declaration );

					if ( '' === $declaration || str_contains( $declaration, 'var(--wp--' ) ) {
						continue;
					}

					$kept[] = $declaration;
				}

				return array() === $kept ? '' : ' style="' . implode( ';', $kept ) . '"';
			},
			$html
		);

		// A class attribute emptied by the above is not worth keeping.
		return (string) preg_replace( '#\sclass="\s*"#', '', $html );
	}

	/**
	 * Wrap a section's blocks in a full-width band.
	 *
	 * Bands alternate between the page and surface colours so consecutive
	 * sections read as separate, which is what the borders and shadows in a
	 * static design were doing.
	 *
	 * @param string $inner    Inner block markup.
	 * @param int    $position Section index.
	 * @param string $label    Section label, kept as the block's name in the editor.
	 * @return string
	 */
	private function band( string $inner, int $position, string $label ): string {
		$padding = 0 === $position ? '90' : '80';

		/*
		 * The theme's rhythm by default; the design's own padding where it
		 * stated one. Sides the design left alone keep the theme value, so a
		 * section that only set its top edge is not left flush at the bottom.
		 */
		$sides = array_merge(
			array(
				'top'    => 'var:preset|spacing|' . $padding,
				'bottom' => 'var:preset|spacing|' . $padding,
			),
			$this->band_padding
		);

		$spacing = array( 'padding' => self::ordered_sides( $sides ) );
		$layout  = array( 'type' => 'constrained' );

		if ( '' !== $this->content_size ) {
			$layout['contentSize'] = $this->content_size;
		}

		if ( $this->centered ) {
			$layout['justifyContent'] = 'center';
		}

		if ( self::faithful() ) {
			// See as_group(): the design's own stylesheet sets the rhythm inside a band too.
			$spacing['blockGap'] = '0';
		}

		$attrs = array(
			'tagName'  => 'section',
			'metadata' => array( 'name' => $label ),
			'align'    => 'full',
			'style'    => array( 'spacing' => $spacing ),
			'layout'   => $layout,
		);

		$classes = array( 'wp-block-group', 'alignfull' );
		$css     = self::box_css( 'padding', $spacing['padding'] );

		if ( '' !== $this->cover ) {
			return $this->cover_band( $inner, $label, $spacing, $css, $layout );
		}

		if ( '' !== $this->gradient ) {
			// The design's own gradient, carried across whole.
			$attrs['style']['color']['gradient'] = $this->gradient;
			$classes[]                           = 'has-background';
			$css                                 = 'background:' . $this->gradient . ';' . $css;
		} else {
			$attrs['backgroundColor'] = $this->background;
			$classes[]                = 'has-' . $this->background . '-background-color';
			$classes[]                = 'has-background';
		}

		/*
		 * A dark band on a light site needs its text inverted, and saying so
		 * on the band itself means every paragraph inside inherits it instead
		 * of each one carrying a colour that would have to be corrected.
		 */
		if ( $this->on_dark ) {
			$attrs['textColor'] = 'base';
			$classes[]          = 'has-base-color';
			$classes[]          = 'has-text-color';
		}

		// The section's own classes, so the design's stylesheet still finds it.
		$this->carry_classes( $this->section_classes, $attrs, $classes );

		if ( '' !== $this->section_anchor ) {
			$attrs['anchor'] = $this->section_anchor;
		}

		return '<!-- wp:group ' . wp_json_encode( $attrs ) . " -->\n"
			. '<section' . ( '' === $this->section_anchor ? '' : ' id="' . esc_attr( $this->section_anchor ) . '"' ) . ' class="' . implode( ' ', $classes ) . '" style="' . $css . '">'
			. $inner
			. "</section>\n<!-- /wp:group -->";
	}

	/**
	 * Wrap a section's blocks in a Cover block carrying the design's photograph.
	 *
	 * A section the design painted with a picture is a Cover, not a Group: the
	 * Cover block is the one an editor can swap the picture on, and it ships
	 * the dim that keeps the text over it readable.
	 *
	 * @param string               $inner   Inner block markup.
	 * @param string               $label   Section label.
	 * @param array<string, mixed> $spacing Spacing style.
	 * @param string               $css     Inline padding declaration.
	 * @param array<string, mixed> $layout  Layout attribute.
	 * @return string
	 */
	private function cover_band( string $inner, string $label, array $spacing, string $css, array $layout = array( 'type' => 'constrained' ) ): string {
		$attrs = array(
			'url'      => $this->cover,
			'dimRatio' => 50,
			'isDark'   => true,
			'tagName'  => 'section',
			'metadata' => array( 'name' => $label ),
			'align'    => 'full',
			'style'    => array( 'spacing' => $spacing ),
			'layout'   => $layout,
		);

		$this->editable[] = __( 'Background image', 'qwerty-soft-signal' );

		$classes = array( 'wp-block-cover', 'alignfull' );

		$this->carry_classes( $this->section_classes, $attrs, $classes );

		return '<!-- wp:cover ' . wp_json_encode( $attrs ) . " -->\n"
			. '<section class="' . implode( ' ', $classes ) . '" style="' . $css . '">'
			. '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
			. '<img class="wp-block-cover__image-background" alt="" src="' . $this->esc_ref( $this->cover ) . '" data-object-fit="cover"/>'
			. '<div class="wp-block-cover__inner-container">'
			. $inner
			. "</div></section>\n<!-- /wp:cover -->";
	}

	/**
	 * The picture the design put behind a section, as the design's own path.
	 *
	 * Looked for on the section itself, on its first element child (designs
	 * often paint the wrapper and pad an inner div), and in the stylesheet's
	 * rules for the section's classes. Gradients are not pictures and are
	 * left to band_colour().
	 *
	 * @param array<string, mixed> $section Section record.
	 * @param DOMElement           $body    Parsed section wrapper.
	 * @return string Relative or absolute URL, or an empty string.
	 */
	private function background_image( array $section, DOMElement $body ): string {
		$candidates = array();

		foreach ( $body->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$candidates[] = $child;
				break;
			}
		}

		if ( isset( $candidates[0] ) ) {
			foreach ( $candidates[0]->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					$candidates[] = $child;
					break;
				}
			}
		}

		foreach ( $candidates as $element ) {
			$url = self::url_in( $element->getAttribute( 'style' ) );

			if ( '' !== $url ) {
				return $url;
			}
		}

		foreach ( (array) ( $section['classes'] ?? array() ) as $class ) {
			$found = $this->section_colors[ strtolower( (string) $class ) ] ?? '';
			$url   = '' === $found ? '' : self::url_in( 'background:' . $found );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * The first image URL in a background declaration, or nothing.
	 *
	 * @param string $style Inline style or declaration text.
	 * @return string
	 */
	private static function url_in( string $style ): string {
		if ( 1 !== preg_match( '/background(?:-image)?\s*:\s*([^;]+)/i', $style, $declaration ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $declaration[1], $url ) ) {
			return '';
		}

		$url = trim( $url[1] );

		// A data: URI is materialised to a file before the converter runs; anything else inline is refused.
		if ( '' === $url || str_starts_with( $url, '#' ) || 1 === preg_match( '#^(data|javascript|vbscript):#i', $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Which palette slug this section's band should be painted with.
	 *
	 * @param array<string, mixed> $section Section record.
	 * @return string
	 */
	private function band_colour( array $section ): string {
		$this->gradient = '';
		$this->on_dark  = false;

		if ( array() === $this->section_colors || array() === $this->palette ) {
			return 'base';
		}

		foreach ( (array) ( $section['classes'] ?? array() ) as $class ) {
			$key = strtolower( (string) $class );

			if ( ! isset( $this->section_colors[ $key ] ) ) {
				continue;
			}

			$found = $this->section_colors[ $key ];

			// A picture is handled by background_image(), not as a colour.
			if ( str_starts_with( strtolower( trim( $found ) ), 'url(' ) ) {
				continue;
			}

			if ( str_contains( strtolower( $found ), 'gradient' ) ) {
				$this->gradient = $found;
				$this->on_dark  = $this->gradient_is_dark( $found );

				return 'base';
			}

			$slug = $this->nearest_slug( $found );

			if ( '' !== $slug ) {
				$this->on_dark = $this->is_dark( $this->palette[ $slug ] )
					&& ! $this->is_dark( $this->palette['base'] ?? '#ffffff' );

				return $slug;
			}
		}

		return 'base';
	}

	/**
	 * Whether a colour is dark enough to need light text on it.
	 *
	 * @param string $hex Colour.
	 * @return bool
	 */
	private function is_dark( string $hex ): bool {
		list( $r, $g, $b ) = self::channels( $hex );

		return ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255 < 0.45;
	}

	/**
	 * Whether a gradient reads as dark overall.
	 *
	 * @param string $gradient Gradient declaration.
	 * @return bool
	 */
	private function gradient_is_dark( string $gradient ): bool {
		if ( ! preg_match_all( '/#[0-9a-f]{3,8}\b/i', $gradient, $found ) ) {
			return false;
		}

		$dark = 0;

		foreach ( $found[0] as $hex ) {
			if ( $this->is_dark( substr( $hex, 0, 7 ) ) ) {
				++$dark;
			}
		}

		return $dark > count( $found[0] ) / 2;
	}

	/**
	 * The palette entry closest to a given colour.
	 *
	 * Only background-ish slugs are candidates: a section painted with the
	 * brand colour is a rarity, and guessing it wrong turns a whole band
	 * unreadable.
	 *
	 * @param string $hex Colour from the design.
	 * @return string
	 */
	private function nearest_slug( string $hex ): string {
		$best     = '';
		$distance = PHP_INT_MAX;

		foreach ( array( 'base', 'surface', 'surface-2', 'contrast' ) as $slug ) {
			if ( ! isset( $this->palette[ $slug ] ) ) {
				continue;
			}

			$gap = self::colour_distance( $hex, $this->palette[ $slug ] );

			if ( $gap < $distance ) {
				$distance = $gap;
				$best     = $slug;
			}
		}

		// Too far from anything in the palette to be worth claiming.
		return $distance <= 9000 ? $best : '';
	}

	/**
	 * Squared distance between two colours.
	 *
	 * @param string $one Colour.
	 * @param string $two Colour.
	 * @return int
	 */
	private static function colour_distance( string $one, string $two ): int {
		$a = self::channels( $one );
		$b = self::channels( $two );

		return ( $a[0] - $b[0] ) ** 2 + ( $a[1] - $b[1] ) ** 2 + ( $a[2] - $b[2] ) ** 2;
	}

	/**
	 * A colour's channels.
	 *
	 * @param string $hex Colour.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function channels( string $hex ): array {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return array( 0, 0, 0 );
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Walk a node's children and concatenate the blocks they produce.
	 *
	 * @param DOMNode $node Parent node.
	 * @return string
	 */
	private function children( DOMNode $node ): string {
		$out = array();

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$block = $this->node( $child );

			if ( '' !== trim( $block ) ) {
				$out[] = $block;
			}
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Convert a single node.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function node( DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( (string) $node->nodeValue );

			return '' === $text ? '' : $this->paragraph( esc_html( $text ) );
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->tagName );

		if ( 'form' === $tag ) {
			return $this->form( $node );
		}

		/*
		 * A button outside a form is not a form control. It is a tab, a filter
		 * or a toggle the design drives with JavaScript, and its label is
		 * content: eight stage buttons reading "01 Project Start", "02 Design"
		 * are eight things the page says. Dropping them with the form controls
		 * took a quarter of the words off a page whose lists they introduced.
		 */
		if ( 'button' === $tag && ! $this->within_form( $node ) ) {
			return $this->control( $node );
		}

		if ( in_array( $tag, self::DROP, true ) ) {
			$this->note_dropped( $tag );

			return '';
		}

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $this->heading( $node, (int) substr( $tag, 1 ) );

			case 'p':
				return $this->text_element( $node );

			case 'ul':
			case 'ol':
				return $this->list( $node, 'ol' === $tag );

			case 'img':
				return $this->image( $node );

			case 'picture':
				return $this->picture( $node );

			case 'figure':
				return $this->figure( $node );

			case 'table':
				return $this->table( $node );

			case 'blockquote':
				return $this->quote( $node );

			case 'hr':
				return "<!-- wp:separator -->\n"
					. '<hr class="wp-block-separator has-alpha-channel-opacity"/>'
					. "\n<!-- /wp:separator -->";

			case 'br':
				return '';

			case 'a':
				if ( $this->is_button( $node ) ) {
					return $this->buttons( array( $node ) );
				}

				/*
				 * A link around a picture is a linked picture, not a sentence
				 * with a picture in it.
				 */
				$picture = $this->only_image( $node );

				if ( null !== $picture ) {
					return $this->image( $picture, null, $this->href( $node ) );
				}

				/*
				 * A link around a whole card — the pattern every Tailwind
				 * design uses for a product tile — is a card. Read as inline
				 * text it collapsed into one paragraph: the picture gone, the
				 * title no longer a heading, three tiles in a grid reduced to
				 * three sentences with the words in the right order and none
				 * of the structure anybody would edit.
				 */
				if ( $this->wraps_blocks( $node ) ) {
					return $this->linked_card( $node );
				}

				/*
				 * A stand-alone link ("View report →") is a paragraph whose
				 * whole text is the link. Converting it as plain text would
				 * keep the words and lose where they went.
				 */
				$label = $this->inline( $node );

				if ( '' === trim( wp_strip_all_tags( $label ) ) ) {
					return '';
				}

				$this->editable[] = __( 'Link text and target', 'qwerty-soft-signal' );

				return $this->styled_text( $node, '<a href="' . $this->esc_ref( $this->href( $node ) ) . '">' . $label . '</a>' );

			default:
				/*
				 * A custom element with nothing inside it is a canvas the
				 * page's own script would have drawn on. There is no script
				 * here, so say so rather than leave a silent gap.
				 */
				if ( str_contains( $tag, '-' ) && '' === trim( (string) $node->textContent ) && 0 === $node->getElementsByTagName( 'img' )->length ) {
					$this->concerns[] = sprintf(
						/* translators: %s: custom element name, e.g. hero-viz. */
						__( 'A JS-rendered visual (%s) was left out. Add an image block where it stood if the page needs something there.', 'qwerty-soft-signal' ),
						$tag
					);

					return '';
				}

				return $this->container( $node );
		}
	}

	/**
	 * Whether an element sits inside a form.
	 *
	 * @param DOMElement $node Element.
	 * @return bool
	 */
	private function within_form( DOMElement $node ): bool {
		$parent = $node->parentNode;

		while ( $parent instanceof DOMElement ) {
			if ( 'form' === strtolower( $parent->tagName ) ) {
				return true;
			}

			$parent = $parent->parentNode;
		}

		return false;
	}

	/**
	 * A control the design works with JavaScript, kept as the words it shows.
	 *
	 * Nothing here can be made to work — the script that ran it is not coming
	 * with the design — so the honest conversion is its label, styled as the
	 * design styled it, plus one note saying what it used to do.
	 *
	 * @param DOMElement $node Button.
	 * @return string
	 */
	private function control( DOMElement $node ): string {
		$label = $this->inline( $node );

		// An icon-only control says nothing; there is nothing to keep.
		if ( '' === trim( wp_strip_all_tags( $label ) ) ) {
			return '';
		}

		$this->concerns[] = __( 'A control the design drives with JavaScript — a tab, a filter, a toggle — was kept as its label. Give it something to do in the editor if the page needs it.', 'qwerty-soft-signal' );

		return $this->styled_text( $node, $label );
	}

	/**
	 * The single picture a link wraps, when that is all it wraps.
	 *
	 * @param DOMElement $node Anchor.
	 * @return DOMElement|null
	 */
	private function only_image( DOMElement $node ): ?DOMElement {
		$images = $node->getElementsByTagName( 'img' );

		if ( 1 !== $images->length ) {
			return null;
		}

		$image = $images->item( 0 );

		return $image instanceof DOMElement && '' === trim( (string) $node->textContent ) ? $image : null;
	}

	/**
	 * Whether a link wraps structure rather than words.
	 *
	 * A heading inside it, a picture beside text, a list, a table, or simply
	 * several block children: any of those and the anchor is a card.
	 *
	 * @param DOMElement $node Anchor.
	 * @return bool
	 */
	private function wraps_blocks( DOMElement $node ): bool {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'figure', 'blockquote' ) as $tag ) {
			if ( $node->getElementsByTagName( $tag )->length > 0 ) {
				return true;
			}
		}

		if ( $node->getElementsByTagName( 'img' )->length > 0 && '' !== trim( (string) $node->textContent ) ) {
			return true;
		}

		$blocks = 0;

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement && in_array( strtolower( $child->tagName ), array( 'div', 'p', 'section', 'article', 'header', 'footer' ), true ) ) {
				++$blocks;
			}
		}

		return $blocks >= 2;
	}

	/**
	 * Convert a link that wraps a card, keeping both the card and its destination.
	 *
	 * WordPress has no linked Group, so the destination is carried the way a
	 * person would write it by hand: on the card's title, and on its picture.
	 * Everything else converts exactly as it would inside a div.
	 *
	 * @param DOMElement $node Anchor.
	 * @return string
	 */
	private function linked_card( DOMElement $node ): string {
		$href = $this->href( $node );

		if ( '#' !== $href ) {
			$this->link_lead( $node, $href );

			foreach ( $node->getElementsByTagName( 'img' ) as $image ) {
				$image->setAttribute( 'data-qs-href', $href );
			}
		}

		$this->editable[] = __( 'Card title, text, picture and where the card links to', 'qwerty-soft-signal' );

		return $this->container( $node );
	}

	/**
	 * Put the card's link on the first thing a reader would click.
	 *
	 * The heading if there is one, otherwise the first line with words in it.
	 *
	 * @param DOMElement $node Anchor.
	 * @param string     $href Destination.
	 * @return void
	 */
	private function link_lead( DOMElement $node, string $href ): void {
		$lead = null;

		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div' ) as $tag ) {
			foreach ( $node->getElementsByTagName( $tag ) as $candidate ) {
				if ( '' === trim( (string) $candidate->textContent ) || $candidate->getElementsByTagName( 'a' )->length > 0 ) {
					continue;
				}

				// A container full of other blocks is not the line to link.
				if ( in_array( $tag, array( 'div', 'span' ), true ) && $candidate->getElementsByTagName( 'div' )->length > 0 ) {
					continue;
				}

				$lead = $candidate;
				break 2;
			}
		}

		if ( ! $lead instanceof DOMElement ) {
			return;
		}

		$document = $lead->ownerDocument;

		if ( ! $document instanceof DOMDocument ) {
			return;
		}

		$link = $document->createElement( 'a' );
		$link->setAttribute( 'href', $href );

		while ( null !== $lead->firstChild ) {
			$link->appendChild( $lead->firstChild );
		}

		$lead->appendChild( $link );
	}

	/**
	 * A block-level container: either a column set, a button row, or a pass-through.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private function container( DOMElement $node ): string {
		$faithful = self::faithful();

		/*
		 * A row of `.btn` anchors is a Buttons block — the right block, and
		 * the wrong pill. What paints a design's button is the class on the
		 * anchor, and a Button block cannot carry one there without failing
		 * block validation the moment the page is opened. So the theme mode
		 * gets the block, and faithful leaves the row as it found it: the
		 * anchors keep their classes and the design's CSS draws the buttons
		 * it always drew.
		 */
		if ( ! $faithful ) {
			$buttons = $this->button_links( $node );

			if ( array() !== $buttons ) {
				return $this->buttons( $buttons, $node );
			}
		}

		/*
		 * Meaning before shape. A design builds a checklist out of divs and a
		 * statistic out of two spans; mapping those to paragraphs is faithful
		 * to the tags and useless to the person who has to edit them later.
		 */
		$quote = $this->as_quote( $node );

		if ( null !== $quote ) {
			return $quote;
		}

		/*
		 * Reading divs as a list or a statistic is a real improvement when the
		 * theme is about to restyle them anyway. When the design's stylesheet
		 * is what paints the page, those divs are what its rules match, and
		 * rewriting them is how a laid-out card becomes a column of lines.
		 */
		if ( ! $faithful ) {
			$checklist = $this->as_list( $node );

			if ( null !== $checklist ) {
				return $checklist;
			}

			$metric = $this->as_metric( $node );

			if ( null !== $metric ) {
				return $metric;
			}
		}

		// A run of similar siblings is a grid in the design; make it columns.
		$cards = $this->card_children( $node );

		/*
		 * With the design's CSS at hand the question has a real answer: the
		 * element is a row only if the design laid it out as one
		 * (`display:grid` or a row-direction flex). A plain block holding a
		 * section head and a card grid stacks, exactly as it does in the
		 * design — splitting it into two columns would squeeze the grid
		 * into the right half. Without CSS, fall back to the old guess and
		 * keep it to the top of the section.
		 */
		$layout    = $this->layout_of( $node );
		$max_depth = null === $this->css ? 1 : 2;
		$is_row    = null === $this->css ? true : in_array( $layout, array( 'grid', 'row' ), true );

		if ( ! $faithful && count( $cards ) >= 2 && $is_row && $this->depth < $max_depth ) {
			/*
			 * A painted grid — a newsletter card holding copy and a form — is
			 * a panel with columns inside, so its background, border and
			 * padding wrap the row rather than vanish.
			 */
			$box     = $this->boxed( $node, false );
			$dark    = $this->on_dark;
			$surface = $this->surface;

			if ( null !== $box && null !== $box['dark'] ) {
				$this->on_dark = (bool) $box['dark'];
				$this->surface = (string) $box['surface'];
			}

			++$this->depth;
			$out = $this->columns( $cards, $box );
			--$this->depth;

			$this->on_dark = $dark;
			$this->surface = $surface;

			return $out;
		}

		if ( $this->is_inline_only( $node ) ) {
			/*
			 * A flex row of chips ("Canada", "United States") sits on one
			 * line in the design; stacking each span as its own paragraph
			 * would turn a row of pills into a column of lines.
			 */
			$stacked = 'row' === $layout ? array() : $this->stacked_children( $node );

			if ( array() !== $stacked ) {
				$lines = $this->stack( $stacked );

				/*
				 * Three lines the design stacked inside `.report-cover` are
				 * three blocks and one box. The class that paints the box has
				 * nowhere to go once the lines are separate blocks, so
				 * faithful keeps the box around them.
				 */
				return $faithful ? $this->wrap_group( $node, $lines ) : $lines;
			}

			$text = $this->inline( $node );

			return '' === trim( wp_strip_all_tags( $text ) ) ? '' : $this->styled_text( $node, $text );
		}

		/*
		 * Faithful keeps the box itself. Every wrapper the design wrote stays
		 * a wrapper — same tag, same classes, nothing added — because that is
		 * what its stylesheet is written against: `.reports-grid` lays the
		 * cards out, `.report-card` frames one, `.report-body` pads it.
		 * Flattening them was the single reason an imported page arrived as a
		 * column of paragraphs that shared the design's words and none of its
		 * shape.
		 */
		if ( $faithful ) {
			return $this->as_group( $node );
		}

		/*
		 * A container the design painted or framed — a CTA band, a dashboard
		 * mock, a dark slab with cards in it — is a panel, and a panel is a
		 * Group the owner can restyle. One that only positions its children
		 * has nothing to say and is passed through.
		 */
		$box = $this->boxed( $node, false );

		if ( null !== $box ) {
			return $this->panel( $node, $box );
		}

		return $this->children( $node );
	}

	/**
	 * Keep a container exactly as the design wrote it, as a Group.
	 *
	 * @param DOMElement $node Container.
	 * @return string
	 */
	private function as_group( DOMElement $node ): string {
		return $this->wrap_group( $node, $this->children( $node ) );
	}

	/**
	 * Put a container's own tag, classes and anchor back round converted blocks.
	 *
	 * @param DOMElement $node  Container.
	 * @param string     $inner Converted inner blocks.
	 * @return string
	 */
	private function wrap_group( DOMElement $node, string $inner ): string {
		if ( '' === trim( $inner ) ) {
			return '';
		}

		// The section's own element is already the band; wrapping it would nest it inside itself.
		if ( $node === $this->root ) {
			return $inner;
		}

		$tag = strtolower( $node->tagName );

		if ( ! in_array( $tag, self::GROUP_TAGS, true ) ) {
			$tag = 'div';
		}

		$attrs   = 'div' === $tag ? array() : array( 'tagName' => $tag );
		$classes = array( 'wp-block-group' );
		$id      = self::clean_anchor( $node->getAttribute( 'id' ) );

		/*
		 * A Group with no gap of its own inherits the theme's, and the theme's
		 * is twenty-four pixels between children the design had touching. Zero
		 * hands the rhythm back to the design's stylesheet, which states it —
		 * and states it at a specificity that still wins, because the rule
		 * WordPress writes for the gap is wrapped in `:where()`.
		 */
		$attrs['style'] = array( 'spacing' => array( 'blockGap' => '0' ) );

		if ( '' !== $id ) {
			$attrs['anchor'] = $id;
		}

		$this->carry( $node, $attrs, $classes );

		return '<!-- wp:group' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . " -->\n"
			. '<' . $tag . ( '' === $id ? '' : ' id="' . esc_attr( $id ) . '"' ) . ' class="' . implode( ' ', $classes ) . '">'
			. $inner
			. '</' . $tag . ">\n<!-- /wp:group -->";
	}

	/**
	 * How the design lays an element's children out: grid, row, column, or block.
	 *
	 * @param DOMElement $node Container.
	 * @return string grid|row|column|block|unknown
	 */
	private function layout_of( DOMElement $node ): string {
		if ( null === $this->css ) {
			return 'unknown';
		}

		$declared = $this->css->declared_for( $node );
		$display  = strtolower( trim( (string) ( $declared['display'] ?? '' ) ) );

		if ( 'grid' === $display || 'inline-grid' === $display ) {
			/*
			 * A grid with one track (or none declared) is a vertical list
			 * that happens to use grid for its gap — rows, not columns.
			 */
			$template = strtolower( trim( (string) ( $declared['grid-template-columns'] ?? '' ) ) );
			$tracks   = self::grid_tracks( $template );

			if ( '' === $template || 'none' === $template || 1 === $tracks || ( 0 === $tracks && ! str_contains( $template, 'repeat(' ) && 1 !== preg_match( '/\s/', $template ) ) ) {
				return 'column';
			}

			return 'grid';
		}

		if ( 'flex' === $display || 'inline-flex' === $display ) {
			$direction = strtolower( trim( (string) ( $declared['flex-direction'] ?? 'row' ) ) );

			return str_starts_with( $direction, 'column' ) ? 'column' : 'row';
		}

		return '' === $display ? 'block' : $display;
	}

	/**
	 * Wrap a painted container's children in a Group carrying its treatment.
	 *
	 * @param DOMElement           $node Container.
	 * @param array<string, mixed> $box  Treatment from boxed().
	 * @return string
	 */
	private function panel( DOMElement $node, array $box ): string {
		return $this->panel_markup( $node, $box, $this->within( $box, $node ) );
	}

	/**
	 * The Group markup for a painted container around already-converted content.
	 *
	 * @param DOMElement           $node  Container.
	 * @param array<string, mixed> $box   Treatment from boxed().
	 * @param string               $inner Converted inner blocks.
	 * @return string
	 */
	private function panel_markup( DOMElement $node, array $box, string $inner ): string {
		if ( '' === trim( $inner ) ) {
			return '';
		}

		// A painted slab spans its container in the design, so it is wide, not column-width.
		$attrs           = array( 'align' => 'wide' ) + $box['attrs'];
		$attrs['layout'] = array( 'type' => 'constrained' );
		$classes         = array_merge( array( 'wp-block-group', 'alignwide' ), $box['classes'] );
		$style           = '' === $box['css'] ? '' : ' style="' . $box['css'] . '"';

		$this->carry( $node, $attrs, $classes );

		return '<!-- wp:group ' . wp_json_encode( $attrs ) . " -->\n"
			. '<div class="' . implode( ' ', $classes ) . '"' . $style . '>'
			. $inner
			. "</div>\n<!-- /wp:group -->";
	}

	/**
	 * Convert a container's children with its own background in effect.
	 *
	 * A dark card on a light band inverts the text inside it, exactly as a
	 * dark band does for a section; the inversion is scoped to the card and
	 * undone afterwards.
	 *
	 * @param array<string, mixed>|null $box  Treatment from boxed(), or null for none.
	 * @param DOMElement                $node Container.
	 * @return string
	 */
	private function within( ?array $box, DOMElement $node ): string {
		$dark    = $this->on_dark;
		$surface = $this->surface;

		if ( null !== $box && null !== $box['dark'] ) {
			$this->on_dark = (bool) $box['dark'];
			$this->surface = (string) $box['surface'];
		}

		$inner = $this->children( $node );

		$this->on_dark = $dark;
		$this->surface = $surface;

		return $inner;
	}

	/**
	 * A run of ticked lines that a design built out of divs.
	 *
	 * @param DOMElement $node Container.
	 * @return string|null
	 */
	private function as_list( DOMElement $node ): ?string {
		$items = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( (string) $child->nodeValue ) ) {
					return null;
				}

				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $child->textContent ) );

			if ( '' === $text ) {
				continue;
			}

			/*
			 * A list item is a line, not a card. Without this a row of three
			 * feature cards — each of which happens to contain a checklist
			 * somewhere inside — collapses into three list items with every
			 * word of the card welded together.
			 */
			if ( mb_strlen( $text ) > 120 ) {
				return null;
			}

			// A tick either as a glyph or as the class the design gave it.
			$marked = 1 === preg_match( '/^[\x{2713}\x{2714}\x{2705}\x{2022}\x{25AA}\x{25CF}\x{2043}\x{2010}-\x{2015}\-\x{2192}\x{25B8}]\s*/u', $text );

			if ( ! $marked ) {
				// Only the marker the item itself opens with, not one nested deep.
				$first = null;

				foreach ( $child->childNodes as $candidate ) {
					if ( $candidate instanceof DOMElement ) {
						$first = $candidate;
						break;
					}
				}

				$marked = null !== $first
					&& str_contains( strtolower( $first->getAttribute( 'class' ) ), 'check' );
			}

			if ( ! $marked ) {
				return null;
			}

			$items[] = trim( (string) preg_replace( '/^[\x{2713}\x{2714}\x{2705}\x{2022}\x{25AA}\x{25CF}\x{2043}\x{2010}-\x{2015}\-\x{2192}\x{25B8}]\s*/u', '', $text ) );
		}

		if ( count( $items ) < 2 ) {
			return null;
		}

		$this->editable[] = __( 'List items', 'qwerty-soft-signal' );

		$out = array();

		foreach ( $items as $item ) {
			$out[] = "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->";
		}

		$attrs   = array( 'className' => 'is-style-checks' );
		$classes = array( 'wp-block-list', 'is-style-checks' );

		$this->carry( $node, $attrs, $classes );

		return '<!-- wp:list ' . wp_json_encode( $attrs ) . " -->\n"
			. '<ul class="' . implode( ' ', $classes ) . '">'
			. implode( "\n\n", $out )
			. "</ul>\n<!-- /wp:list -->";
	}

	/**
	 * A figure with a caption under it, as the theme's Metric block.
	 *
	 * Gives the site owner a block with real settings — the number, what goes
	 * before and after it, whether it counts up — instead of two paragraphs
	 * they have to guess the relationship between.
	 *
	 * @param DOMElement $node Container.
	 * @return string|null
	 */
	private function as_metric( DOMElement $node ): ?string {
		$parts = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$loose = trim( (string) $child->nodeValue );

				if ( '' !== $loose ) {
					$parts[] = $loose;
				}

				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $child->textContent ) );

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		list( $value, $label ) = $parts;

		// The first half has to read as a figure, the second as its caption.
		if ( 1 !== preg_match( '/^[^\p{L}]*\d[\d\s.,]*\s*[%+kmKM€$£]*\+?$/u', $value ) ) {
			return null;
		}

		if ( mb_strlen( $value ) > 12 || mb_strlen( $label ) > 60 || mb_strlen( $label ) < 2 ) {
			return null;
		}

		$this->editable[] = __( 'Figure and its caption', 'qwerty-soft-signal' );

		return '<!-- wp:qs/metric ' . wp_json_encode(
			array(
				'value' => $value,
				'label' => $label,
			)
		) . ' /-->';
	}

	/**
	 * A testimonial, as a real quotation.
	 *
	 * @param DOMElement $node Container.
	 * @return string|null
	 */
	private function as_quote( DOMElement $node ): ?string {
		$class = strtolower( $node->getAttribute( 'class' ) );
		$hint  = false;

		foreach ( array( 'quote', 'qtext', 'testimonial', 'review' ) as $word ) {
			if ( str_contains( $class, $word ) ) {
				$hint = true;
				break;
			}
		}

		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );

		// Curly quotes around a long passage say the same thing the class does.
		$quoted = 1 === preg_match( '/^[\x{201C}\x{00AB}"]/u', $text ) && mb_strlen( $text ) > 60;

		if ( ! $hint && ! $quoted ) {
			return null;
		}

		if ( '' === $text || ! $this->is_inline_only( $node ) ) {
			return null;
		}

		$this->editable[] = __( 'Quotation', 'qwerty-soft-signal' );

		$attrs   = array();
		$classes = array( 'wp-block-quote' );

		$this->carry( $node, $attrs, $classes );

		return '<!-- wp:quote' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . " -->\n"
			. '<blockquote class="' . implode( ' ', $classes ) . '">'
			. "<!-- wp:paragraph -->\n<p>" . $this->inline( $node ) . "</p>\n<!-- /wp:paragraph -->"
			. "</blockquote>\n<!-- /wp:quote -->";
	}

	/**
	 * Inline children a stylesheet was stacking as separate lines.
	 *
	 * `<span>Role</span><strong>CEO</strong><small>Aquaprole</small>` is one
	 * run of inline elements to HTML, but the design's CSS made each a block.
	 * Flattening it gives "RoleCEOAquaprole", so when a container holds only
	 * inline elements and no loose text of its own, each child becomes its own
	 * line — which is both what it looked like and easier to edit.
	 *
	 * @param DOMElement $node Container.
	 * @return array<int, DOMElement>
	 */
	private function stacked_children( DOMElement $node ): array {
		$children = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				// Loose text means this really is one sentence, not a stack.
				if ( '' !== trim( (string) $child->nodeValue ) ) {
					return array();
				}

				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			// A link belongs in the sentence, not on a line of its own.
			if ( 'a' === $tag || 'br' === $tag ) {
				return array();
			}

			if ( '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			$children[] = $child;
		}

		return count( $children ) >= 2 ? $children : array();
	}

	/**
	 * Emit stacked inline children as separate text blocks.
	 *
	 * @param array<int, DOMElement> $children Children.
	 * @return string
	 */
	private function stack( array $children ): string {
		$out      = array();
		$faithful = self::faithful();

		foreach ( $children as $child ) {
			$text = $this->inline( $child );

			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				continue;
			}

			/*
			 * The line keeps the tag it was written as.
			 *
			 * A design says `<span>Since</span><strong>2004</strong>` and
			 * styles it `.stat strong { font-size: 22px; font-weight: 700 }`.
			 * Flattened to two paragraphs both lines came out the same size,
			 * and the figure a card exists to show read like its caption. The
			 * tag costs nothing and is what the rule matches.
			 */
			$tag = strtolower( $child->tagName );

			if ( $faithful && in_array( $tag, self::INLINE_KEEP, true ) && ! in_array( $tag, array( 'a', 'br' ), true ) ) {
				$text = '<' . $tag . '>' . $text . '</' . $tag . '>';
			}

			$out[] = $this->styled_text( $child, $text );
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Direct children that look like repeated cards.
	 *
	 * @param DOMElement $node Parent.
	 * @return array<int, DOMElement>
	 */
	private function card_children( DOMElement $node ): array {
		$candidates = array();

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$tag = strtolower( $child->tagName );

				if ( in_array( $tag, array( 'div', 'article', 'li', 'section', 'aside', 'nav', 'figure', 'form' ), true ) ) {
					$candidates[] = $child;
					continue;
				}
			}

			if ( XML_TEXT_NODE === $child->nodeType && '' !== trim( (string) $child->nodeValue ) ) {
				return array();
			}

			if ( $child instanceof DOMElement ) {
				return array();
			}
		}

		if ( count( $candidates ) < 2 || count( $candidates ) > 6 ) {
			return array();
		}

		// Only a grid if the children actually carry comparable content.
		foreach ( $candidates as $candidate ) {
			if ( '' === trim( (string) $candidate->textContent ) && 0 === $candidate->getElementsByTagName( 'img' )->length ) {
				return array();
			}
		}

		return $candidates;
	}

	/**
	 * Wrap card children in a columns block.
	 *
	 * @param array<int, DOMElement>    $cards Card elements.
	 * @param array<string, mixed>|null $box   Treatment of the grid container itself, worn by the row.
	 * @return string
	 */
	private function columns( array $cards, ?array $box = null ): string {
		$columns = array();
		$parent  = $cards[0]->parentNode;
		$grid    = $parent instanceof DOMElement && null !== $this->css ? $this->css->declared_for( $parent ) : array();

		// Unequal tracks ("1.06fr .72fr") become column widths; equal ones stay fluid.
		$weights = self::track_weights( (string) ( $grid['grid-template-columns'] ?? '' ), count( $cards ) );

		/*
		 * Vertical alignment lives on each column: core pins the row itself to
		 * `align-items:normal !important` and centres through the column's own
		 * `is-vertically-aligned-*` class.
		 */
		$align_items = strtolower( trim( (string) ( $grid['align-items'] ?? '' ) ) );
		$vertical    = 'center' === $align_items ? 'center' : ( in_array( $align_items, array( 'end', 'flex-end' ), true ) ? 'bottom' : '' );

		foreach ( $cards as $index => $card ) {
			$box   = $this->boxed( $card, true );
			$inner = 'form' === strtolower( $card->tagName ) ? $this->form( $card ) : $this->within( $box, $card );

			if ( '' === trim( $inner ) ) {
				continue;
			}

			$attrs   = null === $box ? array() : $box['attrs'];
			$classes = array_merge( array( 'wp-block-column' ), null === $box ? array() : $box['classes'] );
			$css     = null === $box ? '' : $box['css'];

			if ( '' !== $vertical ) {
				$attrs   = array( 'verticalAlignment' => $vertical ) + $attrs;
				$classes = array_merge( array( 'wp-block-column', 'is-vertically-aligned-' . $vertical ), array_slice( $classes, 1 ) );
			}

			if ( null !== $weights && isset( $weights[ $index ] ) ) {
				$attrs['width'] = $weights[ $index ];
				$css            = ( '' === $css ? '' : $css . ';' ) . 'flex-basis:' . $weights[ $index ];
			}

			$style = '' === $css ? '' : ' style="' . $css . '"';

			$this->carry( $card, $attrs, $classes );

			$columns[] = '<!-- wp:column' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . " -->\n"
				. '<div class="' . implode( ' ', $classes ) . '"' . $style . '>'
				. $inner
				. "</div>\n<!-- /wp:column -->";
		}

		if ( count( $columns ) < 2 ) {
			return implode( "\n\n", $columns );
		}

		/*
		 * Card styling only where there are actually cards. Two columns are a
		 * layout — a hero split between copy and a portrait — and giving that
		 * a card background paints a white panel over a dark band, which is
		 * how white text ends up on white. On a dark band nothing gets the
		 * card treatment at all: the theme's card colour is chosen against the
		 * page, not against a band that inverts it.
		 */
		// With the design's own CSS carried along, its cards style themselves.
		$as_cards = count( $columns ) >= 3 && ! $this->on_dark && null === $this->css;
		$attrs    = array( 'align' => 'wide' );
		$class    = '';
		$row_css  = '';

		/*
		 * A painted grid wears its paint itself. Wrapping it in a Group would
		 * make that Group the design's grid container and its one child —
		 * the whole row — a single grid item, squeezed into the first track.
		 */
		if ( null !== $box ) {
			$attrs   = array_replace_recursive( $attrs, $box['attrs'] );
			$class  .= '' === implode( '', $box['classes'] ) ? '' : ' ' . implode( ' ', $box['classes'] );
			$row_css = (string) $box['css'];
		}

		// The row carries the same alignment, as the editor writes it.
		if ( '' !== $vertical ) {
			$attrs['verticalAlignment'] = $vertical;
			$class                     .= ' are-vertically-aligned-' . $vertical;
		}

		if ( $as_cards ) {
			$attrs['className'] = 'is-style-cards';
			$class              = ' is-style-cards';
		}

		// The grid's own classes on the column set.
		$grid_classes = array();

		$this->carry( $parent instanceof DOMElement ? $parent : null, $attrs, $grid_classes );

		if ( array() !== $grid_classes ) {
			$class .= ' ' . implode( ' ', $grid_classes );
		}

		// The design's own gutter, when it stated one.
		$gap = CssIndex::px( (string) ( $grid['column-gap'] ?? '' ) );

		if ( null !== $gap && $gap > 0 ) {
			$attrs['style'] = array( 'spacing' => array( 'blockGap' => $this->spacing_value( $gap ) ) );
		}

		/*
		 * Six columns across a page is not a layout, it is a squeeze: numbers
		 * end up one character per line. Wrap into rows instead — by the
		 * design's own track count when it declared a plain grid, otherwise
		 * three at a time, which is what the design's grid did at every width
		 * below its widest.
		 */
		$tracks = self::grid_tracks( (string) ( $grid['grid-template-columns'] ?? '' ) );

		/*
		 * `repeat(auto-fit, minmax(220px, 1fr))` lays out as many as fit the
		 * content width; estimate that from the design's own container width.
		 */
		if ( 0 === $tracks && 1 === preg_match( '/minmax\(\s*(\d+(?:\.\d+)?)(px|rem|em)/i', (string) ( $grid['grid-template-columns'] ?? '' ), $min ) ) {
			$min_px = 'px' === strtolower( $min[2] ) ? (float) $min[1] : (float) $min[1] * 16;
			$tracks = $min_px > 0 ? (int) floor( 1180 / ( $min_px + 18 ) ) : 0;
		}

		// Only a declared track count is trusted past four across.
		$per_row = $tracks >= 2 && $tracks <= 6 ? $tracks : ( count( $columns ) > 4 ? 3 : count( $columns ) );
		$rows    = array();

		foreach ( array_chunk( $columns, $per_row ) as $row ) {
			$rows[] = '<!-- wp:columns ' . wp_json_encode( $attrs ) . " -->\n"
				. '<div class="wp-block-columns alignwide' . $class . '"' . ( '' === $row_css ? '' : ' style="' . $row_css . '"' ) . '>'
				. implode( "\n\n", $row )
				. "</div>\n<!-- /wp:columns -->";
		}

		return implode( "\n\n", $rows );
	}

	/**
	 * Column widths from an explicit, unequal track list.
	 *
	 * `minmax(0,1.06fr) minmax(360px,.72fr)` is 60% / 40%. Equal tracks, a
	 * repeat(), or a list that does not match the column count return null so
	 * the columns stay fluid.
	 *
	 * @param string $value grid-template-columns.
	 * @param int    $count Columns being laid out.
	 * @return array<int, string>|null Percentages, e.g. "59.55%".
	 */
	private static function track_weights( string $value, int $count ): ?array {
		$value = trim( $value );

		if ( '' === $value || $count < 2 || str_contains( strtolower( $value ), 'repeat(' ) ) {
			return null;
		}

		// Split on spaces outside parentheses.
		$tokens = preg_split( '/\s+(?![^()]*\))/', $value );
		$tokens = is_array( $tokens ) ? array_values( array_filter( $tokens, 'strlen' ) ) : array();

		if ( count( $tokens ) !== $count ) {
			return null;
		}

		$weights = array();

		foreach ( $tokens as $token ) {
			if ( 1 === preg_match( '/(\d*\.?\d+)fr\s*\)?$/i', $token, $fr ) ) {
				$weights[] = (float) $fr[1];
			} elseif ( 'auto' === strtolower( $token ) || '1fr' === strtolower( $token ) ) {
				$weights[] = 1.0;
			} else {
				return null;
			}
		}

		$sum = array_sum( $weights );

		if ( $sum <= 0 || count( array_unique( array_map( 'strval', $weights ) ) ) < 2 ) {
			return null;
		}

		return array_map(
			static fn( float $weight ): string => rtrim( rtrim( number_format( $weight / $sum * 100, 2, '.', '' ), '0' ), '.' ) . '%',
			$weights
		);
	}

	/**
	 * How many columns a grid-template-columns value lays out.
	 *
	 * `repeat(3, 1fr)` is three; `1.1fr .9fr` is two; `repeat(auto-fit, …)`
	 * is unknown and reported as zero so the caller keeps its own rule.
	 *
	 * @param string $value Declaration value.
	 * @return int
	 */
	private static function grid_tracks( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( 1 === preg_match( '/^repeat\(\s*(\d+)\s*,/i', $value, $count ) ) {
			return (int) $count[1];
		}

		if ( str_contains( strtolower( $value ), 'repeat(' ) ) {
			return 0;
		}

		// Top-level tracks only: minmax(0, 1fr) is one track, not two.
		$tracks = preg_split( '/\s+(?![^()]*\))/', $value );

		return is_array( $tracks ) ? count( array_filter( $tracks ) ) : 0;
	}

	/**
	 * Links inside this element that are styled as buttons.
	 *
	 * @param DOMElement $node Parent.
	 * @return array<int, DOMElement>
	 */
	private function button_links( DOMElement $node ): array {
		$links = array();

		foreach ( $node->getElementsByTagName( 'a' ) as $link ) {
			if ( $this->is_button( $link ) ) {
				$links[] = $link;
			}
		}

		// Only treat the element as a button row when that is all it holds.
		$text = trim( (string) $node->textContent );
		$sum  = '';

		foreach ( $links as $link ) {
			$sum .= trim( (string) $link->textContent );
		}

		return '' !== $sum && str_replace( ' ', '', $sum ) === str_replace( ' ', '', $text ) ? $links : array();
	}

	/**
	 * Whether a link is presented as a button.
	 *
	 * @param DOMElement $link Anchor.
	 * @return bool
	 */
	private function is_button( DOMElement $link ): bool {
		$class = strtolower( $link->getAttribute( 'class' ) );

		foreach ( self::BUTTON_HINTS as $hint ) {
			if ( str_contains( $class, $hint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A row of buttons.
	 *
	 * @param array<int, DOMElement> $links   Anchors.
	 * @param DOMElement|null        $wrapper The element holding the row, whose classes go on the Buttons block.
	 * @return string
	 */
	private function buttons( array $links, ?DOMElement $wrapper = null ): string {
		$out = array();

		foreach ( $links as $index => $link ) {
			$label = trim( (string) $link->textContent );

			if ( '' === $label ) {
				continue;
			}

			$href   = $this->href( $link );
			$design = $this->button_styles( $link, $index > 0 );

			$outline = $design['outline'];
			$attrs   = $design['attrs'];
			$classes = array( 'wp-block-button' );
			$link_cl = array( 'wp-block-button__link' );

			if ( $outline ) {
				$attrs['className'] = 'is-style-outline';
				$classes[]          = 'is-style-outline';
			}

			/*
			 * The anchor's classes are deliberately not carried. They would
			 * land on the block wrapper, not the <a>, and the design's `.btn`
			 * padding and min-height would then draw a second pill around the
			 * real one. The colours, radius and padding the design gave the
			 * button are already on the block as attributes.
			 */

			/*
			 * The outline style draws itself in the body-text colour, so on an
			 * inverted band it has to be told to use the page colour instead
			 * or it comes out dark on dark.
			 */
			if ( $outline && $this->on_dark && ! isset( $attrs['textColor'] ) ) {
				$attrs['textColor'] = 'base';
			}

			// Classes in the order the Button block's save writes them.
			if ( isset( $attrs['textColor'] ) ) {
				$link_cl[] = 'has-' . $attrs['textColor'] . '-color';
			}

			if ( isset( $attrs['backgroundColor'] ) ) {
				$link_cl[] = 'has-' . $attrs['backgroundColor'] . '-background-color';
			}

			if ( isset( $attrs['textColor'] ) ) {
				$link_cl[] = 'has-text-color';
			}

			if ( isset( $attrs['backgroundColor'] ) ) {
				$link_cl[] = 'has-background';
			}

			$link_cl = array_merge( $link_cl, $design['classes'] );

			if ( isset( $attrs['fontSize'] ) || isset( $attrs['style']['typography']['fontSize'] ) ) {
				$link_cl[] = 'has-custom-font-size';
			}

			$link_cl[] = 'wp-element-button';
			$style     = '' === $design['css'] ? '' : ' style="' . $design['css'] . '"';

			$out[] = '<!-- wp:button' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . " -->\n"
				. '<div class="' . implode( ' ', $classes ) . '">'
				. '<a class="' . implode( ' ', $link_cl ) . '" href="' . $this->esc_ref( $href ) . '"' . $style . '>'
				. esc_html( $label )
				. "</a></div>\n<!-- /wp:button -->";

			$this->editable[] = __( 'Button label and link', 'qwerty-soft-signal' );
		}

		if ( array() === $out ) {
			return '';
		}

		$row_attrs   = array();
		$row_classes = array( 'wp-block-buttons' );

		$this->carry( $wrapper, $row_attrs, $row_classes );

		return '<!-- wp:buttons' . ( array() === $row_attrs ? '' : ' ' . wp_json_encode( $row_attrs ) ) . " -->\n"
			. '<div class="' . implode( ' ', $row_classes ) . '">'
			. implode( "\n\n", $out )
			. "</div>\n<!-- /wp:buttons -->";
	}

	/**
	 * A heading, with its level kept sane for the page.
	 *
	 * @param DOMElement $node  Heading element.
	 * @param int        $level Source level.
	 * @return string
	 */
	private function heading( DOMElement $node, int $level ): string {
		$text = $this->inline( $node );

		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			return '';
		}

		if ( 1 === $level ) {
			if ( $this->allow_h1 ) {
				$this->allow_h1 = false;
			} else {
				$level = 2;
			}
		}

		$sizes = array(
			1 => 'display',
			2 => 'xx-large',
			3 => 'large',
			4 => 'medium',
			5 => 'medium',
			6 => 'small',
		);
		$size  = $sizes[ $level ] ?? 'medium';

		/*
		 * The display size is built for two or three words. A whole sentence
		 * at that size fills the screen before it finishes, so long headings
		 * step down a notch.
		 */
		$length = mb_strlen( trim( wp_strip_all_tags( $text ) ) );

		if ( 'display' === $size && $length > 38 ) {
			$size = 'xxx-large';
		} elseif ( 'xx-large' === $size && $length > 64 ) {
			$size = 'x-large';
		}

		$this->editable[] = __( 'Heading text', 'qwerty-soft-signal' );

		$design = $this->typography( $node, true );
		$attrs  = array( 'level' => $level );

		// The design's own size where it set one; the level's default otherwise.
		if ( isset( $design['attrs']['fontSize'] ) || isset( $design['attrs']['style']['typography']['fontSize'] ) ) {
			$attrs = array_merge( $attrs, $design['attrs'] );
		} else {
			$attrs['fontSize'] = $size;
			$attrs             = array_merge( $attrs, $design['attrs'] );
			$design['classes'] = array_merge( array( 'has-' . $size . '-font-size' ), $design['classes'] );
		}

		/*
		 * The theme paints headings with the body-text colour, which is chosen
		 * against the page and disappears on an inverted band. Text inside the
		 * band inherits, but a heading carries its own colour and has to be
		 * told. A colour the design chose for this heading wins if it reads
		 * against what is behind it.
		 */
		$colour = $this->text_colour( $node, $this->on_dark ? 'base' : 'contrast' );

		if ( null !== $colour ) {
			$attrs['textColor'] = $colour;
		} elseif ( $this->on_dark ) {
			$attrs['textColor'] = 'base';
		}

		$classes = array( 'wp-block-heading' );

		foreach ( $design['classes'] as $class ) {
			if ( str_starts_with( $class, 'has-text-align-' ) ) {
				$classes[] = $class;
			}
		}

		if ( isset( $attrs['textColor'] ) ) {
			$classes[] = 'has-' . $attrs['textColor'] . '-color';
			$classes[] = 'has-text-color';
		}

		foreach ( $design['classes'] as $class ) {
			if ( ! str_starts_with( $class, 'has-text-align-' ) ) {
				$classes[] = $class;
			}
		}

		$this->carry( $node, $attrs, $classes );

		$style = '' === $design['css'] ? '' : ' style="' . $design['css'] . '"';

		return '<!-- wp:heading ' . wp_json_encode( $attrs ) . " -->\n"
			. '<h' . $level . ' class="' . implode( ' ', $classes ) . '"' . $style . '>' . $text . '</h' . $level . '>'
			. "\n<!-- /wp:heading -->";
	}

	/**
	 * A paragraph or paragraph-like element.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private function text_element( DOMElement $node ): string {
		$text = $this->inline( $node );

		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			return '';
		}

		return $this->styled_text( $node, $text );
	}

	/**
	 * Apply the theme's text presets based on what the element looks like.
	 *
	 * @param DOMElement $node Element.
	 * @param string     $text Inline HTML.
	 * @return string
	 */
	private function styled_text( DOMElement $node, string $text ): string {
		$plain = trim( wp_strip_all_tags( $text ) );

		if ( $this->is_eyebrow( $node, $plain ) ) {
			$this->editable[] = __( 'Small label above a heading', 'qwerty-soft-signal' );

			/*
			 * accent-ink rather than accent: a brand colour is picked to be
			 * seen as a fill, and plenty of them are unreadable at label size.
			 * The readable sibling keeps the hue and clears the minimum.
			 */
			$slug = $this->text_colour( $node, $this->on_dark ? 'accent' : 'accent-ink' ) ?? ( $this->on_dark ? 'accent' : 'accent-ink' );

			// The theme's label treatment, with whatever the design said on top.
			$design = $this->typography( $node, false );
			$attrs  = array_replace_recursive(
				array(
					'textColor' => $slug,
					'fontSize'  => 'x-small',
					'style'     => array(
						'typography' => array(
							'textTransform' => 'uppercase',
							'letterSpacing' => '0.14em',
							'fontWeight'    => '700',
						),
					),
				),
				$design['attrs']
			);

			if ( isset( $attrs['style']['typography']['fontSize'] ) ) {
				unset( $attrs['fontSize'] );
			}

			$classes = array();

			foreach ( $design['classes'] as $class ) {
				if ( str_starts_with( $class, 'has-text-align-' ) ) {
					$classes[] = $class;
				}
			}

			$classes[] = 'has-' . $slug . '-color';
			$classes[] = 'has-text-color';

			if ( isset( $attrs['fontSize'] ) ) {
				$classes[] = 'has-' . $attrs['fontSize'] . '-font-size';
			}

			$this->carry( $node, $attrs, $classes );

			return '<!-- wp:paragraph ' . wp_json_encode( $attrs ) . " -->\n"
				. '<p class="' . implode( ' ', $classes ) . '" style="' . self::typography_css( $attrs['style']['typography'] ) . '">'
				. $text
				. "</p>\n<!-- /wp:paragraph -->";
		}

		if ( $this->is_title( $node, $plain ) ) {
			return $this->heading( $node, 3 );
		}

		$this->editable[] = __( 'Body text', 'qwerty-soft-signal' );

		return $this->paragraph( $text, $node );
	}

	/**
	 * Whether an element is a card's title rather than its copy.
	 *
	 * Designs stop using h3 the moment a card gets its own stylesheet, so the
	 * headings that structure a page disappear into divs. Left alone, a page
	 * of twelve cards has one heading and thirty paragraphs — unusable with a
	 * screen reader and invisible to search engines.
	 *
	 * @param DOMElement $node  Element.
	 * @param string     $plain Its text.
	 * @return bool
	 */
	private function is_title( DOMElement $node, string $plain ): bool {
		if ( '' === $plain || mb_strlen( $plain ) > 90 ) {
			return false;
		}

		// A sentence is copy, however short.
		if ( 1 === preg_match( '/[.!?]\s+\p{Lu}/u', $plain ) ) {
			return false;
		}

		$class = strtolower( $node->getAttribute( 'class' ) );

		foreach ( array( 'title', 'heading', 'headline', 'name', 'subject', 'question' ) as $word ) {
			if ( str_contains( $class, $word ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A muted body paragraph.
	 *
	 * @param string          $text Inline HTML.
	 * @param DOMElement|null $node The element it came from, for the design's styling.
	 * @return string
	 */
	private function paragraph( string $text, ?DOMElement $node = null ): string {
		/*
		 * On a dark band the paragraph says nothing about its colour and
		 * inherits the light text set on the band. Naming "muted" here would
		 * paint dark grey on dark navy — the palette's muted is chosen against
		 * the page background, not against every band on it.
		 */
		$attrs   = array();
		$classes = array();

		$colour = null === $node ? null : $this->text_colour( $node, $this->on_dark ? 'base' : 'muted' );

		if ( null !== $colour ) {
			$attrs['textColor'] = $colour;
		} elseif ( ! $this->on_dark ) {
			$attrs['textColor'] = 'muted';
		}

		$design = null === $node ? array(
			'attrs'   => array(),
			'classes' => array(),
			'css'     => '',
		) : $this->typography( $node, false );

		$attrs = array_merge( $attrs, $design['attrs'] );

		foreach ( $design['classes'] as $class ) {
			if ( str_starts_with( $class, 'has-text-align-' ) ) {
				$classes[] = $class;
			}
		}

		if ( isset( $attrs['textColor'] ) ) {
			$classes[] = 'has-' . $attrs['textColor'] . '-color';
			$classes[] = 'has-text-color';
		}

		foreach ( $design['classes'] as $class ) {
			if ( ! str_starts_with( $class, 'has-text-align-' ) ) {
				$classes[] = $class;
			}
		}

		$this->carry( $node, $attrs, $classes );

		$open  = array() === $attrs ? '<!-- wp:paragraph -->' : '<!-- wp:paragraph ' . wp_json_encode( $attrs ) . ' -->';
		$class = array() === $classes ? '' : ' class="' . implode( ' ', $classes ) . '"';
		$style = '' === $design['css'] ? '' : ' style="' . $design['css'] . '"';

		return $open . "\n<p" . $class . $style . '>' . $text . "</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Whether an element reads as a small label rather than body copy.
	 *
	 * @param DOMElement $node  Element.
	 * @param string     $plain Its text.
	 * @return bool
	 */
	private function is_eyebrow( DOMElement $node, string $plain ): bool {
		if ( mb_strlen( $plain ) > 48 || '' === $plain ) {
			return false;
		}

		// A stand-alone link ("RKMII™" in a footer column) is navigation, not a label.
		if ( 'a' === strtolower( $node->tagName ) ) {
			return false;
		}

		// A row of chips is several short items, not one label.
		if ( 'row' === $this->layout_of( $node ) && $node->getElementsByTagName( 'span' )->length > 1 ) {
			return false;
		}

		$class = strtolower( $node->getAttribute( 'class' ) );

		foreach ( self::EYEBROW_HINTS as $hint ) {
			if ( str_contains( $class, $hint ) ) {
				return true;
			}
		}

		// All-caps short text is a label whatever it is called.
		return mb_strtoupper( $plain, 'UTF-8' ) === $plain && mb_strlen( $plain ) > 2;
	}

	/**
	 * A list.
	 *
	 * @param DOMElement $node    List element.
	 * @param bool       $ordered Whether it is ordered.
	 * @return string
	 */
	private function list( DOMElement $node, bool $ordered ): string {
		$items  = array();
		$ticked = 0;
		$total  = 0;

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			++$total;

			/*
			 * Designs draw a tick either as a glyph or as a span with a
			 * "check" class. Both are the theme's Check list style; the glyph
			 * itself must not end up welded to the first word.
			 */
			$marker = $this->leading_marker( $child );

			if ( null !== $marker ) {
				++$ticked;

				// Faithful leaves the tick where it is: `.check` is what paints it.
				if ( ! self::faithful() ) {
					$child->removeChild( $marker );
				}
			}

			$text = trim( $this->inline( $child ) );
			$text = (string) preg_replace( '/^(?:[\x{2713}\x{2714}\x{2705}\x{2022}\x{25AA}\x{25CF}\x{2043}\x{2192}\x{25B8}]|&#10003;|&#10004;)\s*/u', '', $text, 1, $glyphs );

			if ( $glyphs > 0 && null === $marker ) {
				++$ticked;
			}

			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				continue;
			}

			$items[] = "<!-- wp:list-item -->\n<li>" . $text . "</li>\n<!-- /wp:list-item -->";
		}

		if ( array() === $items ) {
			return '';
		}

		$this->editable[] = __( 'List items', 'qwerty-soft-signal' );

		$checks  = ! self::faithful() && ! $ordered && $total > 0 && $ticked === $total;
		$tag     = $ordered ? 'ol' : 'ul';
		$attrs   = $ordered ? array( 'ordered' => true ) : array();
		$classes = array( 'wp-block-list' );

		if ( $checks ) {
			$attrs['className'] = 'is-style-checks';
			$classes[]          = 'is-style-checks';
		}

		$this->carry( $node, $attrs, $classes );

		return '<!-- wp:list' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . " -->\n"
			. '<' . $tag . ' class="' . implode( ' ', $classes ) . '">'
			. implode( "\n\n", $items )
			. '</' . $tag . ">\n<!-- /wp:list -->";
	}

	/**
	 * The tick element a list item opens with, if any.
	 *
	 * @param DOMElement $item List item.
	 * @return DOMElement|null
	 */
	private function leading_marker( DOMElement $item ): ?DOMElement {
		foreach ( $item->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( (string) $child->nodeValue ) ) {
					return null;
				}

				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$class = strtolower( $child->getAttribute( 'class' ) );
			$text  = trim( (string) $child->textContent );
			$glyph = 1 === preg_match( '/^[\x{2713}\x{2714}\x{2705}\x{2022}\x{25AA}\x{25CF}\x{2043}\x{2192}\x{25B8}]?$/u', $text );

			if ( ( str_contains( $class, 'check' ) || str_contains( $class, 'tick' ) || str_contains( $class, 'icon' ) ) && ( $glyph || '' === $text || 'svg' === strtolower( $child->tagName ) ) ) {
				return $child;
			}

			return null;
		}

		return null;
	}

	/**
	 * An image.
	 *
	 * @param DOMElement      $node   Image element.
	 * @param DOMElement|null $figure The figure wrapping it, whose classes the block also takes.
	 * @param string          $href   Where the picture links, when it was a link or sits in one.
	 * @return string
	 */
	private function image( DOMElement $node, ?DOMElement $figure = null, string $href = '' ): string {
		$src = trim( $node->getAttribute( 'src' ) );

		// A lazy-loaded image keeps its real file in a data attribute.
		if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $attribute ) {
				$fallback = trim( $node->getAttribute( $attribute ) );

				if ( '' !== $fallback && ! str_starts_with( $fallback, 'data:' ) ) {
					$src = $fallback;
					break;
				}
			}
		}

		if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
			return '';
		}

		$alt = $node->getAttribute( 'alt' );

		$this->editable[] = __( 'Image and its alt text', 'qwerty-soft-signal' );

		if ( '' === trim( $alt ) ) {
			$this->concerns[] = __( 'An image had no alt text in the design. Describe it in the editor, or mark it decorative.', 'qwerty-soft-signal' );
		}

		// The figure's classes first, then the picture's own, all on the block.
		$attrs   = array( 'sizeSlug' => 'large' );
		$classes = array( 'wp-block-image', 'size-large' );

		$this->carry( $figure, $attrs, $classes );
		$this->carry( $node, $attrs, $classes );

		/*
		 * A picture that was a link stays a link. The destination arrives
		 * either from the anchor this image was the whole of, or on the image
		 * itself when it sits inside a card that is one big link.
		 */
		$href = '' !== $href ? $href : trim( $node->getAttribute( 'data-qs-href' ) );
		$open = '';
		$shut = '';

		if ( '' !== $href && '#' !== $href ) {
			$attrs['linkDestination'] = 'custom';
			$attrs['href']            = $href;
			$open                     = '<a href="' . $this->esc_ref( $href ) . '">';
			$shut                     = '</a>';
		}

		return '<!-- wp:image ' . wp_json_encode( $attrs ) . " -->\n"
			. '<figure class="' . implode( ' ', $classes ) . '">'
			. $open
			. '<img src="' . $this->esc_ref( $src ) . '" alt="' . esc_attr( $alt ) . '"/>'
			. $shut
			. "</figure>\n<!-- /wp:image -->";
	}

	/**
	 * A picture element: take the fallback img.
	 *
	 * @param DOMElement $node Picture element.
	 * @return string
	 */
	private function picture( DOMElement $node ): string {
		$img = $node->getElementsByTagName( 'img' )->item( 0 );

		return $img instanceof DOMElement ? $this->image( $img ) : '';
	}

	/**
	 * A figure: its image, plus a caption when there is one.
	 *
	 * @param DOMElement $node Figure element.
	 * @return string
	 */
	private function figure( DOMElement $node ): string {
		$img = $node->getElementsByTagName( 'img' )->item( 0 );

		if ( ! $img instanceof DOMElement ) {
			return $this->children( $node );
		}

		return $this->image( $img, $node );
	}

	/**
	 * A quotation.
	 *
	 * @param DOMElement $node Blockquote.
	 * @return string
	 */
	private function quote( DOMElement $node ): string {
		$inner = $this->children( $node );

		if ( '' === trim( $inner ) ) {
			return '';
		}

		$this->editable[] = __( 'Quotation', 'qwerty-soft-signal' );

		$attrs   = array();
		$classes = array( 'wp-block-quote' );

		$this->carry( $node, $attrs, $classes );

		return '<!-- wp:quote' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . " -->\n"
			. '<blockquote class="' . implode( ' ', $classes ) . '">' . $inner . "</blockquote>\n<!-- /wp:quote -->";
	}

	/**
	 * A table.
	 *
	 * @param DOMElement $node Table element.
	 * @return string
	 */
	private function table( DOMElement $node ): string {
		$rows = array(
			'head' => array(),
			'body' => array(),
		);

		foreach ( $node->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();
			$head  = false;

			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement ) {
					continue;
				}

				$tag = strtolower( $cell->tagName );

				if ( 'th' !== $tag && 'td' !== $tag ) {
					continue;
				}

				$head    = $head || 'th' === $tag;
				$cells[] = '<' . $tag . '>' . $this->inline( $cell ) . '</' . $tag . '>';
			}

			if ( array() === $cells ) {
				continue;
			}

			$rows[ $head ? 'head' : 'body' ][] = '<tr>' . implode( '', $cells ) . '</tr>';
		}

		if ( array() === $rows['head'] && array() === $rows['body'] ) {
			return '';
		}

		$this->editable[] = __( 'Table contents', 'qwerty-soft-signal' );

		$attrs   = array( 'hasFixedLayout' => true );
		$classes = array( 'wp-block-table' );

		$this->carry( $node, $attrs, $classes );

		$html = '<figure class="' . implode( ' ', $classes ) . '"><table class="has-fixed-layout">';

		if ( array() !== $rows['head'] ) {
			$html .= '<thead>' . implode( '', $rows['head'] ) . '</thead>';
		}

		$html .= '<tbody>' . implode( '', $rows['body'] ) . '</tbody></table></figure>';

		return '<!-- wp:table ' . wp_json_encode( $attrs ) . " -->\n" . $html . "\n<!-- /wp:table -->";
	}

	/**
	 * Serialise a node's contents as safe inline HTML.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function inline( DOMNode $node ): string {
		$out      = '';
		$previous = null;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$out     .= esc_html( (string) $child->nodeValue );
				$previous = $child;
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			if ( in_array( $tag, self::DROP, true ) ) {
				continue;
			}

			/*
			 * Two inline elements butted together with no text between them
			 * ("<span>Latest</span><a>China</a>") were separated by CSS in the
			 * design. Flattened to one paragraph they would read as one word,
			 * so a space stands in for the gap the layout used to give them.
			 */
			if ( $previous instanceof DOMElement && '' !== $out && 1 !== preg_match( '/\s$/u', $out ) && '' !== trim( (string) $child->textContent ) ) {
				$out .= ' ';
			}

			$previous = $child;

			if ( 'br' === $tag ) {
				$out .= '<br>';
				continue;
			}

			if ( ! in_array( $tag, self::INLINE_KEEP, true ) ) {
				$out .= $this->inline( $child );
				continue;
			}

			if ( 'a' === $tag ) {
				/*
				 * `.btn`, `.text-link`, `.nav-cta` — a link's class is how a
				 * design tells a pill from a sentence. Kept when the design's
				 * own stylesheet came with it, dropped when the theme is doing
				 * the styling and the class would only collide.
				 */
				$raw          = preg_split( '/\s+/', trim( $child->getAttribute( 'class' ) ) );
				$link_classes = self::faithful() ? $this->design_classes( is_array( $raw ) ? $raw : array() ) : array();
				$attr         = array() === $link_classes ? '' : ' class="' . esc_attr( implode( ' ', $link_classes ) ) . '"';

				$out .= '<a' . $attr . ' href="' . $this->esc_ref( $this->href( $child ) ) . '">' . $this->inline( $child ) . '</a>';
				continue;
			}

			// Keep the emphasis, drop every attribute that could carry styling.
			$keep = in_array( $tag, array( 'strong', 'b', 'em', 'i', 'code', 'sub', 'sup', 'mark' ), true ) ? $tag : null;

			/*
			 * A classed span is a chip, a tag, a highlight — something the
			 * design's own CSS paints. With that CSS carried to the site the
			 * class is what keeps a "Canada" pill a pill, so it stays; every
			 * other attribute still goes.
			 */
			// "© <span id="year"></span>" — the design's script filled the year; here it is a fact.
			if ( 'span' === $tag && '' === trim( (string) $child->textContent ) && 1 === preg_match( '/\byear\b/i', $child->getAttribute( 'id' ) . ' ' . $child->getAttribute( 'class' ) ) ) {
				$out     .= esc_html( gmdate( 'Y' ) );
				$previous = $child;
				continue;
			}

			if ( null === $keep && 'span' === $tag && ( null !== $this->css || self::faithful() ) ) {
				$raw          = preg_split( '/\s+/', trim( $child->getAttribute( 'class' ) ) );
				$span_classes = $this->design_classes( is_array( $raw ) ? $raw : array() );

				// Bare spans in a flex row are chips too: `.country-chips span { … }` needs the element to exist.
				$chip = array() === $span_classes && $node instanceof DOMElement && 'row' === $this->layout_of( $node );

				if ( array() !== $span_classes || $chip ) {
					$attr = array() === $span_classes ? '' : ' class="' . esc_attr( implode( ' ', $span_classes ) ) . '"';
					$out .= '<span' . $attr . '>' . $this->inline( $child ) . '</span>';
					continue;
				}
			}

			$out .= null === $keep ? $this->inline( $child ) : '<' . $keep . '>' . $this->inline( $child ) . '</' . $keep . '>';
		}

		return trim( $out );
	}

	/**
	 * A link target that will not break when the page moves.
	 *
	 * @param DOMElement $link Anchor.
	 * @return string
	 */
	private function href( DOMElement $link ): string {
		$href = trim( $link->getAttribute( 'href' ) );

		if ( '' === $href ) {
			return '#';
		}

		if ( 1 === preg_match( '#^(javascript|vbscript|data):#i', $href ) ) {
			$this->concerns[] = __( 'A link ran a script instead of going somewhere; it now points nowhere.', 'qwerty-soft-signal' );

			return '#';
		}

		return $href;
	}

	/**
	 * Escape a link or image target without inventing a protocol for it.
	 *
	 * WordPress turns "about.html" into "http://about.html" when it escapes a
	 * URL, which is not the same link — and it hides the relative path the
	 * assembler needs in order to repoint it at the real page later. Absolute
	 * URLs still go through esc_url(); relative ones are attribute-escaped,
	 * which is safe because href() has already refused every executable scheme
	 * and the block validator refuses them again independently.
	 *
	 * @param string $href Validated target.
	 * @return string
	 */
	private function esc_ref( string $href ): string {
		if ( 1 === preg_match( '#^(https?:)?//#i', $href ) || 1 === preg_match( '#^(mailto|tel):#i', $href ) ) {
			return esc_url( $href );
		}

		return esc_attr( $href );
	}

	/**
	 * Whether an element holds only inline content.
	 *
	 * @param DOMElement $node Element.
	 * @return bool
	 */
	private function is_inline_only( DOMElement $node ): bool {
		foreach ( $node->getElementsByTagName( '*' ) as $descendant ) {
			if ( ! in_array( strtolower( $descendant->tagName ), self::INLINE_KEEP, true ) ) {
				return false;
			}
		}

		return '' !== trim( (string) $node->textContent );
	}

	/**
	 * A form becomes the theme's contact form, which actually sends.
	 *
	 * The design's markup would post nowhere. The theme's block keeps the
	 * place, the button label and the intent; a newsletter sign-up and a
	 * contact form differ only in copy, which the owner edits in place.
	 *
	 * @param DOMElement $node Form element.
	 * @return string
	 */
	private function form( DOMElement $node ): string {
		$label = '';

		foreach ( array( 'button', 'input' ) as $tag ) {
			foreach ( $node->getElementsByTagName( $tag ) as $control ) {
				if ( ! $control instanceof DOMElement ) {
					continue;
				}

				$type = strtolower( $control->getAttribute( 'type' ) );

				if ( 'input' === $tag && 'submit' !== $type ) {
					continue;
				}

				$label = 'input' === $tag ? trim( $control->getAttribute( 'value' ) ) : trim( (string) $control->textContent );

				if ( '' !== $label ) {
					break 2;
				}
			}
		}

		$attrs = array();

		if ( '' !== $label && mb_strlen( $label ) <= 60 ) {
			$attrs['submitLabel'] = $label;
		}

		$classes = array();
		$this->carry( $node, $attrs, $classes );

		$this->editable[] = __( 'Form: recipient, labels and success message', 'qwerty-soft-signal' );
		$this->concerns[] = __( 'The design’s form was replaced with the theme’s Contact form block, which sends to the site’s admin address. Adjust its fields and recipient in the editor.', 'qwerty-soft-signal' );

		return '<!-- wp:qs/contact-form' . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . ' /-->';
	}

	/**
	 * Record that something could not be carried over.
	 *
	 * @param string $tag Element name.
	 * @return void
	 */
	private function note_dropped( string $tag ): void {
		if ( 'form' === $tag || 'input' === $tag || 'select' === $tag || 'textarea' === $tag || 'button' === $tag ) {
			$this->concerns[] = __( 'This section had a form. Add the theme’s Contact form block where it belongs — a form copied as markup would not send anything.', 'qwerty-soft-signal' );

			return;
		}

		if ( 'svg' === $tag || 'canvas' === $tag ) {
			$this->concerns[] = __( 'Decorative vector artwork was left out. The theme’s gradients stand in for it; add a real image if you need the original.', 'qwerty-soft-signal' );

			return;
		}

		if ( 'iframe' === $tag || 'object' === $tag || 'embed' === $tag ) {
			$this->concerns[] = __( 'An embedded frame was left out. Use the matching embed block if you need it back.', 'qwerty-soft-signal' );
		}
	}

	/**
	 * Read what the design said about the section itself before walking it.
	 *
	 * Padding comes from the section, or from its wrapper when the section
	 * left its own edges alone; the content width from whichever of the two
	 * has a max-width; centring from either.
	 *
	 * @param DOMElement $body Parsed section wrapper.
	 * @return void
	 */
	private function read_section_styles( DOMElement $body ): void {
		$this->band_padding = array();
		$this->content_size = '';
		$this->centered     = false;

		if ( null === $this->css ) {
			return;
		}

		$section = null;

		foreach ( $body->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$section = $child;
				break;
			}
		}

		if ( null === $section ) {
			return;
		}

		$own  = $this->css->declared_for( $section );
		$wrap = null;

		foreach ( $section->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$inner = $this->css->declared_for( $child );

			if ( isset( $inner['max-width'] ) || 1 === preg_match( '/\b(wrap|container|inner|content)\b/', strtolower( $child->getAttribute( 'class' ) ) ) ) {
				$wrap = $inner;
			}

			break;
		}

		foreach ( array( 'top', 'bottom', 'left', 'right' ) as $side ) {
			$px = CssIndex::px( (string) ( $own[ 'padding-' . $side ] ?? '' ) );

			if ( ( null === $px || $px <= 0 ) && null !== $wrap && ( 'top' === $side || 'bottom' === $side ) ) {
				$px = CssIndex::px( (string) ( $wrap[ 'padding-' . $side ] ?? '' ) );
			}

			if ( null !== $px && $px > 0 ) {
				$this->band_padding[ $side ] = $this->spacing_value( $px );
			}
		}

		foreach ( array( $wrap, $own ) as $source ) {
			$width = CssIndex::px( (string) ( $source['max-width'] ?? '' ) );

			if ( null !== $width && $width >= 320 ) {
				$this->content_size = self::trim_number( $width ) . 'px';
				break;
			}
		}

		$align = strtolower( (string) ( $this->css->styles_for( $section )['text-align'] ?? '' ) );

		if ( '' === $align && null !== $wrap ) {
			$align = strtolower( (string) ( $wrap['text-align'] ?? '' ) );
		}

		$this->centered = 'center' === $align;
	}

	/**
	 * The colour behind the band's text, as hex.
	 *
	 * @return string
	 */
	private function band_surface(): string {
		if ( '' !== $this->cover ) {
			return '#333333';
		}

		if ( '' !== $this->gradient ) {
			return $this->gradient_average( $this->gradient );
		}

		return $this->palette[ $this->background ] ?? '#ffffff';
	}

	/**
	 * A gradient flattened to the mean of its stops, for contrast arithmetic.
	 *
	 * @param string $gradient Gradient declaration.
	 * @return string
	 */
	private function gradient_average( string $gradient ): string {
		if ( ! preg_match_all( '/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b/i', $gradient, $found ) ) {
			return $this->on_dark ? '#222222' : '#ffffff';
		}

		$sum = array( 0, 0, 0 );

		foreach ( $found[0] as $hex ) {
			$channels = self::channels( $hex );
			$sum[0]  += $channels[0];
			$sum[1]  += $channels[1];
			$sum[2]  += $channels[2];
		}

		$count = count( $found[0] );

		return sprintf( '#%02x%02x%02x', (int) round( $sum[0] / $count ), (int) round( $sum[1] / $count ), (int) round( $sum[2] / $count ) );
	}

	/**
	 * The typography a design gave an element, as block attributes.
	 *
	 * Only what the design set: a size is snapped to the nearest theme preset
	 * within tolerance and otherwise kept exact; weight, line height, letter
	 * spacing, transform and alignment are carried as written. For headings
	 * the size and weight have to be on the element itself, because browsers
	 * reset both and so the design's inherited value was never what showed.
	 *
	 * @param DOMElement $node    Element.
	 * @param bool       $heading Whether it is a heading.
	 * @return array{attrs:array<string,mixed>,classes:array<int,string>,css:string}
	 */
	private function typography( DOMElement $node, bool $heading ): array {
		$empty = array(
			'attrs'   => array(),
			'classes' => array(),
			'css'     => '',
		);

		if ( null === $this->css ) {
			return $empty;
		}

		$all   = $this->css->styles_for( $node );
		$reset = $heading ? $this->css->declared_for( $node ) : $all;

		$attrs   = array();
		$typo    = array();
		$classes = array();
		$px      = CssIndex::px( (string) ( $reset['font-size'] ?? '' ) );

		if ( null !== $px && $px > 0 ) {
			$this->presets();
			$slug = self::nearest_preset( $px, $this->font_presets );

			if ( '' !== $slug ) {
				$attrs['fontSize'] = $slug;
				$classes[]         = 'has-' . $slug . '-font-size';
			} else {
				$typo['fontSize']   = self::rem( $px );
				$this->literal_used = true;
			}
		}

		/*
		 * A heading the design sets in a different face from its siblings
		 * (a serif section title over a sans page) keeps that face. Only a
		 * declaration on the element itself counts — an inherited family is
		 * the site's, not the block's.
		 */
		$family = trim( (string) ( $reset['font-family'] ?? '' ) );

		if ( $heading && '' !== $family && 1 !== preg_match( '/[<>{};]|url\(|expression/i', $family ) ) {
			$typo['fontFamily'] = $family;
		}

		$weight = strtolower( trim( (string) ( $reset['font-weight'] ?? '' ) ) );
		$weight = array(
			'bold'   => '700',
			'normal' => '400',
		)[ $weight ] ?? $weight;

		if ( 1 === preg_match( '/^[1-9]00$/', $weight ) ) {
			$typo['fontWeight'] = $weight;
		}

		$spacing = self::number( (string) ( $all['letter-spacing'] ?? '' ), array( 'em', 'px', 'rem' ) );

		if ( null !== $spacing && '0' !== $spacing ) {
			$typo['letterSpacing'] = $spacing;
		}

		$height = self::number( (string) ( $all['line-height'] ?? '' ), array( '', 'px', 'rem', 'em', '%' ) );

		if ( null !== $height && '0' !== $height ) {
			$typo['lineHeight'] = $height;
		}

		$transform = strtolower( trim( (string) ( $all['text-transform'] ?? '' ) ) );

		if ( in_array( $transform, array( 'uppercase', 'lowercase', 'capitalize' ), true ) ) {
			$typo['textTransform'] = $transform;
		}

		$align = strtolower( trim( (string) ( $all['text-align'] ?? '' ) ) );

		if ( 'center' === $align || 'right' === $align ) {
			$typo['textAlign'] = $align;
			$classes[]         = 'has-text-align-' . $align;
		}

		if ( array() !== $typo ) {
			$attrs['style'] = array( 'typography' => $typo );
		}

		return array(
			'attrs'   => $attrs,
			'classes' => $classes,
			'css'     => self::typography_css( $typo ),
		);
	}

	/**
	 * The inline declarations WordPress writes for a typography style object.
	 *
	 * Alignment is a class, not a declaration, so it is left out here.
	 *
	 * @param array<string, string> $typo style.typography.
	 * @return string
	 */
	private static function typography_css( array $typo ): string {
		$map = array(
			'fontSize'      => 'font-size',
			'fontStyle'     => 'font-style',
			'fontWeight'    => 'font-weight',
			'letterSpacing' => 'letter-spacing',
			'lineHeight'    => 'line-height',
			'textTransform' => 'text-transform',
		);
		$out = array();

		foreach ( $map as $key => $property ) {
			if ( isset( $typo[ $key ] ) ) {
				$out[] = $property . ':' . $typo[ $key ];
			}
		}

		return implode( ';', $out );
	}

	/**
	 * A numeric CSS value normalised the way the editor writes it.
	 *
	 * `-.025em` becomes `-0.025em`; anything with a unit outside the allowed
	 * set, or a keyword, is declined.
	 *
	 * @param string             $value Declaration value.
	 * @param array<int, string> $units Acceptable units; '' for unitless.
	 * @return string|null
	 */
	private static function number( string $value, array $units ): ?string {
		$value = strtolower( trim( $value ) );

		if ( 1 !== preg_match( '/^(-?\d*\.?\d+)(px|rem|em|%)?$/', $value, $found ) ) {
			return null;
		}

		$unit = $found[2] ?? '';

		if ( ! in_array( $unit, $units, true ) ) {
			return null;
		}

		$number = (float) $found[1];

		if ( 0.0 === $number ) {
			return '0';
		}

		return rtrim( rtrim( number_format( $number, 4, '.', '' ), '0' ), '.' ) . $unit;
	}

	/**
	 * The palette slug for a colour the design put on this element's text.
	 *
	 * Null when the design set none, when it is the default for this kind
	 * of element anyway, when no palette entry is close enough to stand in
	 * for it, or when it would not read against what is behind it. A raw
	 * value never gets through: either the palette has it or it is dropped.
	 *
	 * @param DOMElement $node    Element.
	 * @param string     $fallback The slug the element would get without the design.
	 * @return string|null
	 */
	private function text_colour( DOMElement $node, string $fallback ): ?string {
		if ( null === $this->css ) {
			return null;
		}

		$raw = (string) ( $this->css->styles_for( $node )['color'] ?? '' );

		if ( '' === $raw ) {
			return null;
		}

		$hex = DesignTokens::to_hex( $raw, array() );

		if ( null === $hex ) {
			return null;
		}

		if ( isset( $this->palette[ $fallback ] ) && self::colour_distance( $hex, $this->palette[ $fallback ] ) <= 4000 ) {
			return null;
		}

		// Text never borrows a fill or hairline slug, however close the hex: those roles move independently.
		$slug = $this->nearest_any_slug( $hex, 4000, array( 'border', 'border-strong', 'surface', 'surface-2' ) );

		// For text, the readable sibling of the brand colour is the one to name.
		if ( 'accent' === $slug && isset( $this->palette['accent-ink'] ) && self::colour_distance( $hex, $this->palette['accent-ink'] ) <= 4000 ) {
			$slug = 'accent-ink';
		}

		if ( '' === $slug || $slug === $fallback ) {
			return null;
		}

		return DesignTokens::contrast( $this->palette[ $slug ], $this->surface ) >= 4.5 ? $slug : null;
	}

	/**
	 * The palette entry closest to a colour, from the whole palette.
	 *
	 * @param string             $hex     Colour.
	 * @param int                $limit   Largest squared distance still counted as a match.
	 * @param array<int, string> $exclude Slugs that must not be chosen, whatever the distance.
	 * @return string
	 */
	private function nearest_any_slug( string $hex, int $limit, array $exclude = array() ): string {
		$best     = '';
		$distance = PHP_INT_MAX;

		foreach ( $this->palette as $slug => $candidate ) {
			if ( in_array( (string) $slug, $exclude, true ) ) {
				continue;
			}

			$gap = self::colour_distance( $hex, $candidate );

			if ( $gap < $distance ) {
				$distance = $gap;
				$best     = (string) $slug;
			}
		}

		return $distance <= $limit ? $best : '';
	}

	/**
	 * The treatment a design gave a container: fill, frame, corners, padding.
	 *
	 * Null when there is nothing worth carrying. In card mode corners and
	 * padding are enough on their own, because the column is going to be a
	 * card anyway; elsewhere only a fill or a frame makes a container a
	 * panel. A fill that matches what is already behind the element is not
	 * a fill.
	 *
	 * @param DOMElement $node Container.
	 * @param bool       $card Whether the container is already a card.
	 * @return array{attrs:array<string,mixed>,classes:array<int,string>,css:string,dark:bool|null,surface:string|null}|null
	 */
	private function boxed( DOMElement $node, bool $card ): ?array {
		if ( null === $this->css ) {
			return null;
		}

		$styles     = $this->css->declared_for( $node );
		$background = $this->background_of( $styles );
		$border     = $this->border_of( $styles );

		if ( null !== $background && '' === $background['gradient'] && strtolower( $background['hex'] ) === strtolower( $this->surface ) ) {
			$background = null;
		}

		$radius  = CssIndex::px( (string) ( $styles['border-radius'] ?? '' ) );
		$padding = array();

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$px = CssIndex::px( (string) ( $styles[ 'padding-' . $side ] ?? '' ) );

			if ( null !== $px && $px > 0 ) {
				$padding[ $side ] = $px;
			}
		}

		if ( null === $background && null === $border ) {
			/*
			 * A translucent panel (`rgba(255,255,255,.06)` over a dark hero)
			 * has no palette colour to map to, but it is still a box the
			 * design drew — its carried class paints it once the design's
			 * CSS is on the site. A framed container (radius and padding)
			 * counts the same way.
			 */
			$paint       = (string) ( $styles['background-color'] ?? '' ) . ' ' . (string) ( $styles['background'] ?? '' ) . ' ' . (string) ( $styles['border-color'] ?? '' ) . ' ' . (string) ( $styles['border'] ?? '' );
			$translucent = 1 === preg_match( '/\b(rgba|hsla|color-mix)\(/i', $paint );
			$framed      = null !== $radius && $radius > 0 && array() !== $padding;
			$empty       = ( null === $radius || $radius <= 0 ) && array() === $padding;

			if ( $card ? $empty : ! ( $framed || $translucent ) ) {
				return null;
			}
		}

		$attrs   = array();
		$style   = array();
		$css     = array();
		$dark    = null;
		$surface = null;

		if ( null !== $background ) {
			if ( '' !== $background['gradient'] ) {
				$style['color'] = array( 'gradient' => $background['gradient'] );
				$css[]          = 'background:' . $background['gradient'];
			} else {
				$attrs['backgroundColor'] = $background['slug'];
			}

			$dark    = $background['dark'];
			$surface = $background['hex'];

			if ( $dark !== $this->on_dark ) {
				$attrs['textColor'] = $dark ? 'base' : 'contrast';
			}
		}

		if ( null !== $border ) {
			$attrs['borderColor']     = $border['slug'];
			$style['border']['width'] = $border['width'];
			$css[]                    = 'border-width:' . $border['width'];
		}

		if ( null !== $radius && $radius > 0 ) {
			$style['border']['radius'] = $this->radius_value( $radius );
			$css[]                     = 'border-radius:' . $style['border']['radius'];
		}

		if ( array() !== $padding ) {
			$sides = array();

			foreach ( $padding as $side => $px ) {
				$sides[ $side ] = $this->spacing_value( $px );
			}

			$style['spacing'] = array( 'padding' => $sides );
			$css[]            = self::box_css( 'padding', $sides );
		}

		if ( array() !== $style ) {
			$attrs['style'] = $style;
		}

		// Classes in the order the block supports write them: colour, then border.
		$classes = array();

		if ( isset( $attrs['textColor'] ) ) {
			$classes[] = 'has-' . $attrs['textColor'] . '-color';
		}

		if ( isset( $attrs['backgroundColor'] ) ) {
			$classes[] = 'has-' . $attrs['backgroundColor'] . '-background-color';
		}

		if ( isset( $attrs['textColor'] ) ) {
			$classes[] = 'has-text-color';
		}

		if ( isset( $attrs['backgroundColor'] ) || isset( $style['color']['gradient'] ) ) {
			$classes[] = 'has-background';
		}

		if ( isset( $attrs['borderColor'] ) ) {
			$classes[] = 'has-border-color';
			$classes[] = 'has-' . $attrs['borderColor'] . '-border-color';
		}

		return array(
			'attrs'   => $attrs,
			'classes' => $classes,
			'css'     => implode( ';', $css ),
			'dark'    => $dark,
			'surface' => $surface,
		);
	}

	/**
	 * What a design painted behind an element, as something a block can carry.
	 *
	 * A flat colour becomes the nearest background slug; a gradient with
	 * solid stops is kept whole. A picture, a translucent gradient, or a
	 * colour nothing in the palette is close to all return null — the first
	 * is a Cover's job, the other two cannot be judged for contrast.
	 *
	 * @param array<string, string> $styles Resolved styles.
	 * @return array{gradient:string,slug:string,hex:string,dark:bool}|null
	 */
	private function background_of( array $styles ): ?array {
		$image = strtolower( trim( (string) ( $styles['background-image'] ?? 'none' ) ) );

		if ( str_contains( $image, 'url(' ) ) {
			return null;
		}

		if ( str_contains( $image, 'gradient' ) ) {
			if ( 1 === preg_match( '/(?:rgba|hsla)\([^)]*,\s*0?\.\d+\s*\)|color-mix|var\(/', $image ) ) {
				return null;
			}

			$gradient = DesignTokens::clean_gradient( (string) $styles['background-image'], array() );

			if ( null === $gradient ) {
				return null;
			}

			return array(
				'gradient' => $gradient,
				'slug'     => '',
				'hex'      => $this->gradient_average( $gradient ),
				'dark'     => $this->gradient_is_dark( $gradient ),
			);
		}

		$hex = DesignTokens::to_hex( (string) ( $styles['background-color'] ?? '' ), array() );

		if ( null === $hex ) {
			return null;
		}

		$slug = $this->nearest_slug( $hex );

		if ( '' === $slug ) {
			return null;
		}

		return array(
			'gradient' => '',
			'slug'     => $slug,
			'hex'      => $this->palette[ $slug ],
			'dark'     => $this->is_dark( $this->palette[ $slug ] ) && ! $this->is_dark( $this->palette['base'] ?? '#ffffff' ),
		);
	}

	/**
	 * A visible, solid-colour frame the design drew around an element.
	 *
	 * @param array<string, string> $styles Resolved styles.
	 * @return array{slug:string,width:string}|null
	 */
	private function border_of( array $styles ): ?array {
		$style = strtolower( trim( (string) ( $styles['border-style'] ?? '' ) ) );

		if ( ! in_array( $style, array( 'solid', 'dashed', 'dotted', 'double' ), true ) ) {
			return null;
		}

		$width = CssIndex::px( (string) ( $styles['border-width'] ?? '' ) );

		if ( null === $width || $width <= 0 ) {
			return null;
		}

		$hex = DesignTokens::to_hex( (string) ( $styles['border-color'] ?? '' ), array() );

		if ( null === $hex ) {
			return null;
		}

		$slug = $this->nearest_any_slug( $hex, 4000 );

		if ( '' === $slug ) {
			return null;
		}

		return array(
			'slug'  => $slug,
			'width' => self::trim_number( $width ) . 'px',
		);
	}

	/**
	 * What the design made of a button: filled or outline, shape, size, colours.
	 *
	 * @param DOMElement $link      Anchor.
	 * @param bool       $secondary Whether it is not the first button in its row.
	 * @return array{outline:bool,attrs:array<string,mixed>,classes:array<int,string>,css:string}
	 */
	private function button_styles( DOMElement $link, bool $secondary ): array {
		$out = array(
			'outline' => $secondary,
			'attrs'   => array(),
			'classes' => array(),
			'css'     => '',
		);

		if ( null === $this->css ) {
			return $out;
		}

		$styles    = $this->css->styles_for( $link );
		$class     = strtolower( $link->getAttribute( 'class' ) );
		$fill      = DesignTokens::to_hex( (string) ( $styles['background-color'] ?? '' ), array() );
		$fill_slug = null === $fill ? '' : $this->nearest_any_slug( $fill, 4000 );
		$border    = $this->border_of( $styles );

		// A transparent face with a frame, or a name that says so, is the outline style.
		if ( null === $fill && ( null !== $border || 1 === preg_match( '/ghost|outline|secondary|tertiary/', $class ) ) ) {
			$out['outline'] = true;
		} elseif ( '' !== $fill_slug || 1 === preg_match( '/primary|solid|fill/', $class ) ) {
			$out['outline'] = false;
		}

		$attrs  = array();
		$style  = array();
		$css    = array();
		$behind = $this->surface;

		if ( ! $out['outline'] && '' !== $fill_slug ) {
			$attrs['backgroundColor'] = $fill_slug;
			$behind                   = $this->palette[ $fill_slug ];
		}

		$text      = DesignTokens::to_hex( (string) ( $styles['color'] ?? '' ), array() );
		$text_slug = null === $text ? '' : $this->nearest_any_slug( $text, 4000 );

		if ( isset( $attrs['backgroundColor'] ) ) {
			/*
			 * A filled button needs a label that reads on its fill. The design's
			 * choice first, then the page colour, then the ink; if nothing in
			 * the palette reads on that fill, the fill goes rather than the text.
			 */
			$chosen = '';

			foreach ( array_unique( array_filter( array( $text_slug, 'base', 'contrast' ) ) ) as $slug ) {
				if ( isset( $this->palette[ $slug ] ) && DesignTokens::contrast( $this->palette[ $slug ], $behind ) >= 4.5 ) {
					$chosen = $slug;
					break;
				}
			}

			if ( '' === $chosen ) {
				unset( $attrs['backgroundColor'] );
			} else {
				$attrs['textColor'] = $chosen;
			}
		} elseif ( '' !== $text_slug && ( $this->on_dark ? 'base' : 'contrast' ) !== $text_slug
			&& DesignTokens::contrast( $this->palette[ $text_slug ], $behind ) >= 4.5 ) {
			$attrs['textColor'] = $text_slug;
		}

		$radius = CssIndex::px( (string) ( $styles['border-radius'] ?? '' ) );

		if ( null !== $radius && $radius > 0 ) {
			$style['border']['radius'] = $this->radius_value( $radius );
			$css[]                     = 'border-radius:' . $style['border']['radius'];
		}

		$sides = array();

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$px = CssIndex::px( (string) ( $styles[ 'padding-' . $side ] ?? '' ) );

			if ( null !== $px && $px > 0 ) {
				$sides[ $side ] = $this->spacing_value( $px );
			}
		}

		if ( array() !== $sides ) {
			$style['spacing'] = array( 'padding' => $sides );
			$css[]            = self::box_css( 'padding', $sides );
		}

		$typo    = array();
		$classes = array();
		$px      = CssIndex::px( (string) ( $styles['font-size'] ?? '' ) );

		if ( null !== $px && $px > 0 ) {
			$this->presets();
			$slug = self::nearest_preset( $px, $this->font_presets );

			if ( '' !== $slug ) {
				$attrs['fontSize'] = $slug;
				$classes[]         = 'has-' . $slug . '-font-size';
			} else {
				$typo['fontSize']   = self::rem( $px );
				$this->literal_used = true;
			}
		}

		$weight = strtolower( trim( (string) ( $styles['font-weight'] ?? '' ) ) );
		$weight = 'bold' === $weight ? '700' : $weight;

		if ( 1 === preg_match( '/^[1-9]00$/', $weight ) ) {
			$typo['fontWeight'] = $weight;
		}

		if ( array() !== $typo ) {
			$style['typography'] = $typo;
			$css[]               = self::typography_css( $typo );
		}

		if ( array() !== $style ) {
			$attrs['style'] = $style;
		}

		$out['attrs']   = $attrs;
		$out['classes'] = $classes;
		$out['css']     = implode( ';', array_filter( $css ) );

		return $out;
	}

	/**
	 * Load the theme's presets once, as pixels at desktop width.
	 *
	 * Fluid sizes and clamp() ranges are read at their maximum, which is
	 * what shows at the width a design is drawn at.
	 *
	 * @return void
	 */
	private function presets(): void {
		if ( null !== $this->spacing_presets ) {
			return;
		}

		$this->spacing_presets = array();

		foreach ( self::preset_list( wp_get_global_settings( array( 'spacing', 'spacingSizes' ) ) ) as $preset ) {
			$px = self::preset_px( (string) ( $preset['size'] ?? '' ) );

			if ( null !== $px && isset( $preset['slug'] ) ) {
				$this->spacing_presets[ (string) $preset['slug'] ] = $px;
			}
		}

		foreach ( self::preset_list( wp_get_global_settings( array( 'typography', 'fontSizes' ) ) ) as $preset ) {
			$px = self::preset_px( (string) ( $preset['size'] ?? '' ) );

			if ( null !== $px && isset( $preset['slug'] ) ) {
				$this->font_presets[ (string) $preset['slug'] ] = $px;
			}
		}

		$radius = wp_get_global_settings( array( 'custom', 'radius' ) );

		foreach ( is_array( $radius ) ? $radius : array() as $name => $value ) {
			$px = is_string( $value ) ? self::preset_px( $value ) : null;

			if ( null !== $px ) {
				$this->radius_tokens[ (string) $name ] = $px;
			}
		}
	}

	/**
	 * The presets from a global-settings answer, whichever origin holds them.
	 *
	 * @param mixed $settings Return of wp_get_global_settings().
	 * @return array<int, array<string, mixed>>
	 */
	private static function preset_list( $settings ): array {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
			if ( isset( $settings[ $origin ] ) && is_array( $settings[ $origin ] ) && array() !== $settings[ $origin ] ) {
				return $settings[ $origin ];
			}
		}

		return array_is_list( $settings ) ? $settings : array();
	}

	/**
	 * A preset size in pixels; the upper bound for a clamp().
	 *
	 * @param string $size Preset size.
	 * @return float|null
	 */
	private static function preset_px( string $size ): ?float {
		$size = trim( $size );

		if ( 1 === preg_match( '/^clamp\((.*)\)$/i', $size, $range ) ) {
			$parts = explode( ',', $range[1] );
			$size  = trim( (string) end( $parts ) );
		}

		return CssIndex::px( $size );
	}

	/**
	 * The preset closest to a value, when one is within tolerance.
	 *
	 * @param float                $px      Design value in pixels.
	 * @param array<string, float> $presets Slug to pixels.
	 * @return string Slug, or an empty string.
	 */
	private static function nearest_preset( float $px, array $presets ): string {
		$best = '';
		$gap  = PHP_FLOAT_MAX;

		foreach ( $presets as $slug => $size ) {
			if ( $size <= 0 ) {
				continue;
			}

			$relative = abs( $px - $size ) / $size;

			if ( $relative <= self::TOLERANCE && $relative < $gap ) {
				$gap  = $relative;
				$best = (string) $slug;
			}
		}

		return $best;
	}

	/**
	 * A length as a spacing preset reference, or an exact rem value.
	 *
	 * @param float $px Design value in pixels.
	 * @return string
	 */
	private function spacing_value( float $px ): string {
		$this->presets();

		$slug = self::nearest_preset( $px, $this->spacing_presets );

		if ( '' !== $slug ) {
			return 'var:preset|spacing|' . $slug;
		}

		$this->literal_used = true;

		return self::rem( $px );
	}

	/**
	 * A corner radius as a theme token reference, or an exact rem value.
	 *
	 * Written as a CSS custom property rather than a `var:` reference: the
	 * radius tokens are theme.json custom values, not presets, and both the
	 * editor and the style engine pass a var() through untouched.
	 *
	 * @param float $px Design value in pixels.
	 * @return string
	 */
	private function radius_value( float $px ): string {
		$this->presets();

		if ( $px >= 500 && isset( $this->radius_tokens['pill'] ) ) {
			return 'var(--wp--custom--radius--pill)';
		}

		$name = self::nearest_preset( $px, $this->radius_tokens );

		if ( '' !== $name ) {
			return 'var(--wp--custom--radius--' . $name . ')';
		}

		$this->literal_used = true;

		return self::rem( $px );
	}

	/**
	 * Pixels as rem, trimmed.
	 *
	 * @param float $px Pixels.
	 * @return string
	 */
	private static function rem( float $px ): string {
		if ( 0.0 === $px ) {
			return '0';
		}

		return self::trim_number( $px / 16 ) . 'rem';
	}

	/**
	 * A float without trailing zeros.
	 *
	 * @param float $value Number.
	 * @return string
	 */
	private static function trim_number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * A block style value as the CSS the editor writes for it.
	 *
	 * @param string $value `var:preset|spacing|50` or a literal.
	 * @return string
	 */
	private static function css_value( string $value ): string {
		if ( str_starts_with( $value, 'var:' ) ) {
			return 'var(--wp--' . str_replace( '|', '--', substr( $value, 4 ) ) . ')';
		}

		return $value;
	}

	/**
	 * Box sides in the order the editor serialises them.
	 *
	 * @param array<string, string> $sides Side to value.
	 * @return array<string, string>
	 */
	private static function ordered_sides( array $sides ): array {
		$out = array();

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $sides[ $side ] ) ) {
				$out[ $side ] = $sides[ $side ];
			}
		}

		return $out;
	}

	/**
	 * Inline declarations for a box property.
	 *
	 * @param string                $property `padding`.
	 * @param array<string, string> $sides    Side to value.
	 * @return string
	 */
	private static function box_css( string $property, array $sides ): string {
		$out = array();

		foreach ( self::ordered_sides( $sides ) as $side => $value ) {
			$out[] = $property . '-' . $side . ':' . self::css_value( $value );
		}

		return implode( ';', $out );
	}

	/**
	 * A one-line description of what the section turned out to be.
	 *
	 * @param array<string, mixed> $section Section record.
	 * @return string
	 */
	private function summary( array $section ): string {
		return sprintf(
			/* translators: 1: section label, 2: word count, 3: image count. */
			__( '"%1$s" — %2$d words and %3$d image(s), converted structurally into editable blocks.', 'qwerty-soft-signal' ),
			(string) $section['label'],
			(int) $section['words'],
			(int) $section['images']
		);
	}

	/**
	 * Parse a fragment into a document.
	 *
	 * @param string $html Fragment.
	 * @return DOMDocument
	 */
	private static function load( string $html ): DOMDocument {
		$dom = new DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();

		return $dom;
	}
}
