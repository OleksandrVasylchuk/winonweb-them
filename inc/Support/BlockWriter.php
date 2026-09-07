<?php
/**
 * Turns one section of a design into a block of its own.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * The writer that makes a block out of a section.
 *
 * A section is not translated into the theme's blocks — it is wrapped in one.
 * The markup the designer wrote is the markup that ships; the stylesheet that
 * targets it ships beside it; and the only thing added is a field wherever
 * there is something an editor should be able to change.
 *
 * That is the whole of the idea, and the reason for it is measurable. The
 * translating pipeline dropped 190 of the 253 class names the design's own
 * stylesheet targets, which left the rules on the page and nothing for them to
 * style — the header and the hero rendered as neither the design nor the
 * theme. Markup that is copied cannot lose a class, because nothing rewrites
 * it.
 *
 * The block is a real registered block in the "Qwerty Soft blocks" category,
 * so a section lifted out of one page can be dropped onto any other from the
 * inserter, carrying its own stylesheet with it.
 */
final class BlockWriter {

	/**
	 * How a field's place in the markup is held until the PHP is written.
	 *
	 * The markup is edited as a document and serialised once, so the PHP has
	 * to travel through the DOM as text. Control characters were the obvious
	 * choice and are the wrong one: `saveHTML()` drops them, which turned
	 * every field back into the literal word "QSOFT" on the page.
	 *
	 * So the marker is printable, and made collision-proof instead of
	 * unlikely — it carries a digest of the section it belongs to, so the only
	 * way a design could contain one is by containing its own hash.
	 *
	 * @var string
	 */
	private static $marker = 'QSOFT';

	/**
	 * The token shape, filled with the marker and the field.
	 */
	private const TOKEN = '~%1$s.%2$s~';

	/**
	 * The class every section's own root element carries.
	 *
	 * @see mark_root()
	 */
	public const ROOT_CLASS = 'qs-design';

	/**
	 * The class the body of a site built from a design carries.
	 *
	 * The editor's canvas body carries `.editor-styles-wrapper` instead;
	 * `BODY_SCOPE` matches either, and is what a design's own `body{…}` rule
	 * is lifted onto so it can beat the theme's global styles.
	 */
	public const SITE_CLASS = 'qs-design-site';

	/**
	 * The selector a design's `body` rule is written against.
	 */
	public const BODY_SCOPE = 'body:is(.qs-design-site, .editor-styles-wrapper)';

	/**
	 * The ACF options page the site-wide fields live on.
	 */
	public const OPTIONS_PAGE = 'qs-design-content';

	/**
	 * What this writer produces, so an older block can be told apart.
	 *
	 * A generated block is never overwritten — editing one by hand is expected
	 * and a rebuild must not throw that away. But a block written by an earlier
	 * version of this writer can be wrong in ways no editor caused: the first
	 * ones named only `acf.renderTemplate` and called `get_field()` directly,
	 * so on a site without ACF Pro they rendered nothing at all. Stamping the
	 * version is what lets a rebuild replace exactly those and leave the rest.
	 *
	 * Raise this whenever a change makes previously generated blocks wrong.
	 *
	 * 7: a repeated row that is itself a field — a strip of anchors, a bare
	 * `<li>` — used to read as N flat fields instead of a repeater a person
	 * can add to or remove from, and a repeat guessed from a single-field row
	 * used to default to "listing", which draws nothing on a fresh site with
	 * no posts of a type nobody made. Field groups also lacked instructions,
	 * repeated same-type fields had no side-by-side widths, and duplicate
	 * labels ("Link", "Link") had nothing telling them apart.
	 *
	 * 8: a block used to enqueue a per-section slice of the design's CSS,
	 * which re-ran the stylesheet's cascade in block order and let another
	 * page's duplicate rules win — and left `_canonical.css` written but
	 * loaded by nothing. A block now names the canonical handle for the
	 * stylesheet its page actually links (`style` and `viewScript` both), a
	 * handoff carrying several sites gets a canonical file per source, and no
	 * slice is written at all.
	 *
	 * 9: a text field keeps the inline markup the designer put inside it — a
	 * highlighted span, a deliberate `<br>` — instead of flattening it to
	 * words (the hero read "Program,Built" live). Every planted element
	 * carries a `data-qs-field` mark and every repeated row a `data-qs-row`
	 * one, which is what lets the editor offer the section for editing on
	 * the canvas rather than only from the sidebar. A `<picture>` loses its
	 * `<source>`s when its `<img>` becomes a field, so replacing the picture
	 * replaces what is shown; a link built of elements keeps them and still
	 * gets its words as a field; and the holder of a repeat keeps whatever it
	 * held besides the rows.
	 *
	 * 10: a field group long enough to be a wall is divided the way the design
	 * divides the section — the footer's three link columns become three tabs
	 * named by the design's own headings — and a type's one explanatory
	 * sentence is printed once per division rather than under all eleven of
	 * its links.
	 */
	public const VERSION = 10;

	/**
	 * Where a block's values are kept: with the block, or with the site.
	 *
	 * A section in the middle of a page holds its own words, and two copies of
	 * it should be able to say different things. The header and the footer are
	 * the opposite: they appear on every page, and the address in the footer is
	 * one address. Keeping those with the block would mean editing the same
	 * telephone number in two template parts and hoping they match.
	 *
	 * So chrome is written against the options page instead, and the editor
	 * changes the footer's small print in one place under Appearance.
	 *
	 * @var string
	 */
	private static $scope = 'block';

	/**
	 * What the design's own markup said, by field name.
	 *
	 * Written into the template as each field's fallback. A field with
	 * nothing stored used to render nothing, which made a block dragged out
	 * of the inserter onto a second page come up as an empty frame, and made
	 * any field a repair could not match to a stored value disappear. The
	 * words the designer wrote are the right thing to show when there is
	 * nothing better, and they are in the markup already.
	 *
	 * @var array<string, mixed>
	 */
	private static $says = array();

	/**
	 * What a site-wide field's name is prefixed with.
	 *
	 * ACF stores an option under `options_{field name}`, and that key is the
	 * whole of its identity — the field group it belongs to does not appear in
	 * it. So a footer calling a field `link` writes `options_link`, and the
	 * next design's footer calling its own field `link` writes to the same row.
	 * Seeding skips what is already there, so the second site would quietly
	 * display the first one's address and telephone number.
	 *
	 * The block's own slug carries a digest of its markup, which makes it
	 * unique per section, so it is what the names are scoped by. Only the name
	 * is prefixed; the label an editor reads stays "Link".
	 *
	 * @var string
	 */
	private static $prefix = '';

	/**
	 * Where a real WordPress menu goes inside a wrapped header.
	 *
	 * The header keeps the design's own markup — its brand, its call to action,
	 * its classes — but the list of pages inside it must not be frozen copy. A
	 * navigation held as fields would mean adding a page in one screen and
	 * remembering to add it to the menu in another, which is the difference
	 * between a site somebody can run and a mockup.
	 *
	 * So the caller empties that one element and leaves this marker in it. It
	 * is an HTML comment because a comment survives the DOM round trip
	 * untouched and can never collide with a design's own text.
	 */
	public const MENU_MARK = '<!--qs:menu-->';

	/**
	 * The menu a wrapped header should render, when it has one.
	 *
	 * @var bool
	 */
	private static $menu = false;

	/**
	 * Where the hidden half of an imported form goes.
	 *
	 * A design's form is markup and nothing else: inputs, a button, and an
	 * action that points at "#" because a script was going to catch the
	 * submit. Wrapped as it stands, it looked finished and threw every
	 * message away. So the form keeps every class the design gave it and
	 * gains what the theme's own handler expects — a nonce, the action, the
	 * time trap, the honeypot — printed here.
	 *
	 * A comment for the same reason MENU_MARK is one: it survives the DOM
	 * round trip and cannot collide with a design's own text.
	 */
	public const FORM_MARK = '<!--qs:form-fields-->';

	/**
	 * What an imported form's `action` says until the template is written.
	 *
	 * A fragment, so the serialiser leaves it alone — every character in it
	 * is one a URI may hold unencoded.
	 */
	public const FORM_ACTION = '#qs-form-action';

	/**
	 * Whether this section turned out to hold a form to wire up.
	 *
	 * @var bool
	 */
	private static $forms = false;

	/**
	 * The post type a listing block reads, when it has one.
	 *
	 * Empty for a listing whose records the model could not name, which falls
	 * back to ordinary posts rather than to nothing: a grid that draws the last
	 * three posts is wrong in a way somebody notices and fixes, while a grid
	 * that draws nothing looks like a broken import.
	 *
	 * @var string
	 */
	private static $type = '';

	/**
	 * What kind of section is being written: single, repeat or listing.
	 *
	 * A listing's rows are records, edited where records are edited, so the
	 * row markup gets no canvas marks; a repeat's rows are the block's own
	 * and do.
	 *
	 * @var string
	 */
	private static $kind = 'single';

	/**
	 * One token, in the marker this section is using.
	 *
	 * @param string $body What the token says.
	 * @return string
	 */
	private static function token( string $body ): string {
		return sprintf( self::TOKEN, self::$marker, $body );
	}

	/**
	 * What a field is called where its value is kept.
	 *
	 * The same for a block-scoped field, whose values live with the block and
	 * so cannot collide with anything. Prefixed for a site-wide one, because
	 * those all share one namespace.
	 *
	 * @param string $name The field's name in the plan.
	 * @param string $slug The block's slug, when the caller knows it.
	 * @return string
	 */
	private static function stored_name( string $name, string $slug = '' ): string {
		$prefix = '' !== $slug ? str_replace( '-', '_', self::slug( $slug ) ) : self::$prefix;

		return '' === $prefix ? $name : $prefix . '_' . $name;
	}

	/**
	 * Longest a generated directory name may be.
	 *
	 * Windows stops at 260 characters for a whole path and the importer
	 * already spends most of that on the uploads folder, so a block folder
	 * named after a section title has to be short enough not to finish it off.
	 */
	private const SLUG_LIMIT = 40;

	/**
	 * Write the four files that make one section a block.
	 *
	 * @param string               $html   Section markup, as the splitter cut it.
	 * @param array<string, mixed> $plan   What SectionPlan made of it.
	 * @param string               $slug   Block slug, without the `qs/design-` prefix.
	 * @param string               $title  What the block is called in the inserter.
	 * @param string               $css    Unused since version 8; the block wears the canonical stylesheet instead of a slice.
	 * @param string               $dir    Directory to write into.
	 * @param string               $origin Page of the archive this came from.
	 * @param string               $scope  block to keep values with the block, option to keep them with the site.
	 * @param bool                 $menu     Whether this block holds the site's navigation.
	 * @param string               $singular What one record of a listing is called.
	 * @return array<string, string>|null The files written, or null when nothing could be.
	 */
	public static function write( string $html, array $plan, string $slug, string $title, string $css, string $dir, string $origin = '', string $scope = 'block', bool $menu = false, string $singular = '' ): ?array {
		self::$scope = 'option' === $scope ? 'option' : 'block';
		self::$says  = self::values( $html, $plan );

		$slug = self::slug( $slug );

		// Site-wide fields share one namespace, so their names carry the block's.
		self::$prefix = 'option' === self::$scope ? str_replace( '-', '_', $slug ) : '';
		self::$menu   = $menu;
		self::$type   = '' !== trim( $singular ) ? DesignType::for_singular( $singular ) : '';

		if ( '' === $slug ) {
			return null;
		}

		$render = self::render_php( $html, $plan, self::$scope );

		if ( null === $render ) {
			return null;
		}

		/*
		 * No per-section stylesheet. The design's CSS is written against a
		 * whole page, so a block wears the canonical sheet for the page it
		 * came from — see `write_canonical()` — and a slice of extracted
		 * rules is exactly the thing that re-ran the cascade in block order
		 * and let another page's duplicates win. `$css` still decides
		 * nothing here; a section with no extracted rules of its own stands
		 * on the same page the sheet styles.
		 */
		unset( $css );

		$files = array(
			'block.json'  => self::block_json( $slug, $title, true, $origin, $plan, self::$says ),
			'fields.json' => self::fields_json( $slug, $title, $plan, $html ),
			'render.php'  => $render,
		);

		/*
		 * A listing needs somewhere for its records to live. Described beside
		 * the block that reads them, so that the type goes when the import
		 * does and a theme update never touches it.
		 */
		if ( 'listing' === ( $plan['kind'] ?? '' ) && '' !== trim( $singular ) && 'product' !== self::$type ) {
			$type = DesignType::describe( $singular, (array) ( $plan['item']['fields'] ?? array() ) );

			if ( null !== $type ) {
				$files['type.json'] = (string) wp_json_encode( $type, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
			}
		}

		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return null;
		}

		foreach ( $files as $name => $body ) {
			if ( false === file_put_contents( trailingslashit( $dir ) . $name, $body ) ) {
				return null;
			}
		}

		// A block rewritten from version 7 leaves its slice behind; nothing names it any more.
		if ( is_file( trailingslashit( $dir ) . 'style.css' ) ) {
			unlink( trailingslashit( $dir ) . 'style.css' );
		}

		return $files;
	}

	/**
	 * Which canonical source each origin page belongs to, for the current run.
	 *
	 * Keyed the way `write()` receives its `$origin`: the page's
	 * archive-relative file. Set from the job before blocks are written; an
	 * empty map routes everything to the primary handle, which is also right
	 * for a design that is one site.
	 *
	 * @var array<string, string>
	 */
	private static array $sheet_routes = array();

	/**
	 * Tell the writer which canonical source each page belongs to.
	 *
	 * @param array<string, string> $routes Origin file to source key; '' is the primary.
	 * @return void
	 */
	public static function route_styles( array $routes ): void {
		self::$sheet_routes = $routes;
	}

	/**
	 * The registered handle for one canonical source.
	 *
	 * @param string $key Source key from `DesignStylesheet::routes()`; '' for the primary.
	 * @return string
	 */
	public static function canonical_handle( string $key = '' ): string {
		return '' === $key ? self::CANONICAL_HANDLE : self::CANONICAL_HANDLE . '-' . $key;
	}

	/**
	 * The block's manifest.
	 *
	 * @param string               $slug   Block slug.
	 * @param string               $title  Inserter title.
	 * @param bool                 $styled Whether the block names the design's canonical stylesheet and script.
	 * @param string               $origin Page of the archive this came from.
	 * @param array<string, mixed> $plan   What SectionPlan made of it.
	 * @param array<string, mixed> $says   What the design's markup said, by field name.
	 * @return string JSON.
	 */
	public static function block_json( string $slug, string $title, bool $styled, string $origin = '', array $plan = array(), array $says = array() ): string {
		$json = array(
			'$schema'     => 'https://schemas.wp.org/trunk/block.json',
			'apiVersion'  => 3,
			'name'        => 'qs/design-' . $slug,
			'title'       => '' !== trim( $title ) ? $title : $slug,

			/*
			 * The same category as the theme's own blocks, and deliberately.
			 * A section wrapped out of one page is worth having on another —
			 * the design drew one call to action and the site will want it in
			 * three places — and a block nobody can find in the inserter is
			 * not reusable however well it is built.
			 */
			'category'    => 'qs',
			'description' => self::describe( $title, $slug, $origin, $plan ),
			'keywords'    => self::keywords( $title, $origin ),
			'textdomain'  => 'qwerty-soft-signal',
			'supports'    => array(
				'anchor'     => true,
				'html'       => false,

				// The pencil. Off would leave the sidebar as the only way in.
				'mode'       => true,

				/*
				 * The design decided how this section looks. Offering the
				 * colour and spacing panels invites somebody to overrule it
				 * from the sidebar and then wonder why the page stopped
				 * matching the mockup.
				 */
				'color'      => false,
				'spacing'    => false,
				'typography' => false,
			),

			/*
			 * Named twice, for two different renderers, and that is the point.
			 * ACF uses `acf.renderTemplate`; WordPress itself uses `render`. A
			 * block that named only the first drew nothing at all on a site
			 * without ACF Pro — structurally correct pages that were visually
			 * empty, in the editor as well as on the front, with no error to
			 * say why. Naming both means the plugin costs the editing and
			 * never the page.
			 */
			'render'      => 'file:./render.php',
			'acf'         => array(

				/*
				 * `preview`, not `auto`.
				 *
				 * Both keep the section looking like itself while it is being
				 * worked on rather than flipping the whole thing to a form the
				 * moment somebody clicks it — `auto` does that the instant the
				 * block is selected, which is a jolt for something the size of
				 * a section. `preview` waits for a deliberate switch instead.
				 *
				 * ACF Pro ships a toolbar toggle for exactly that switch. It is
				 * currently unreachable: ACF Pro 6.8.1's own block toolbar
				 * component computes `j(clientId) || I()` before deciding
				 * whether to draw it, where `I()` is
				 * `document.querySelectorAll('iframe[name="editor-canvas"]').length>0`
				 * — true on every screen a supported WordPress edits a block
				 * on, page or Site Editor alike, since 6.3, for any block from
				 * any plugin. ACF's own changelog (6.3.11) blames this on API
				 * version 3 specifically, but that is not what the installed
				 * build's code checks; `apiVersion: 2` was tried here and
				 * changed nothing live. Fields are edited from the Block
				 * sidebar tab instead, which ACF attaches independently of the
				 * toggle and which does work. `preview` is kept anyway — a
				 * fresh page still shows the section as the design drew it
				 * rather than as an empty form, and it costs nothing to leave
				 * the door open for an ACF release that un-hides the toggle.
				 */
				'mode'           => 'preview',
				'renderTemplate' => 'render.php',
			),

			/*
			 * What the inserter draws when somebody hovers the block.
			 *
			 * Without it the preview panel says only the block's name, and a
			 * list of thirty sections out of one design is thirty names that
			 * all sound alike. With it the panel renders the section itself,
			 * with the design's own words in it, and choosing between them
			 * becomes looking rather than guessing.
			 */
			'example'     => array(
				'attributes' => array(
					'mode' => 'preview',
					'data' => self::example_data( $plan, $says ),
				),
			),
		);

		if ( $styled ) {
			/*
			 * The design's stylesheet and script, whole, from the source this
			 * block's page links — never a per-section slice, and never
			 * another site's sheet out of the same handoff. The same handle
			 * names both; WordPress keeps style and script handles apart.
			 */
			$handle = self::canonical_handle( (string) ( self::$sheet_routes[ $origin ] ?? '' ) );

			$json['style']      = $handle;
			$json['viewScript'] = $handle;
		}

		/*
		 * Which page of the archive this came out of. Not decoration: the
		 * design's own links are relative — `reports.html` means one thing
		 * inside `en/` and another inside `ru/` — so rewriting them to real
		 * pages at the end of a build is impossible without knowing where the
		 * section was standing when it was written.
		 */
		if ( '' !== trim( $origin ) ) {
			$json['qsDesignOrigin'] = $origin;
		}

		$json['qsDesignVersion'] = self::VERSION;

		return (string) wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/**
	 * Whether a block on disk was written by this version of the writer.
	 *
	 * @param string $dir The block's directory.
	 * @return bool True when it is current, or absent and so needs writing.
	 */
	public static function current( string $dir ): bool {
		$manifest = trailingslashit( $dir ) . 'block.json';

		if ( ! is_file( $manifest ) ) {
			return false;
		}

		$raw = file_get_contents( $manifest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by this class.

		if ( false === $raw ) {
			return false;
		}

		$json = json_decode( $raw, true );

		return is_array( $json ) && (int) ( $json['qsDesignVersion'] ?? 0 ) >= self::VERSION;
	}

	/**
	 * What one record of a block's listing is called, read back off the disk.
	 *
	 * The singular is the model's answer, given once, on the build that
	 * wrote the block. Re-runs skip the model, arrived with '' and so could
	 * never seed a record again — a rebuilt page's listing had a type and no
	 * way to fill it. The answer was sitting in type.json the whole time.
	 *
	 * @param string $dir The block's directory.
	 * @return string The singular, or '' when the block keeps no records.
	 */
	public static function singular_of( string $dir ): string {
		$manifest = trailingslashit( $dir ) . 'type.json';

		if ( ! is_file( $manifest ) ) {
			return '';
		}

		$json = json_decode( (string) file_get_contents( $manifest ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by this class.

		return is_array( $json ) ? (string) ( $json['singular'] ?? '' ) : '';
	}

	/**
	 * Rename a plan's fields to match the block already on disk.
	 *
	 * A block that exists is never rewritten, so its `render.php` keeps
	 * whatever names it was built with. The values for a rebuild are computed
	 * from a fresh plan, and a fresh plan gets fresh names — the model is asked
	 * again and is under no obligation to answer identically. The two then
	 * disagree, the template reads `heading` while the block carries `title`,
	 * and every field on the page renders empty.
	 *
	 * So the names come from the block itself. Matched by position, because
	 * that is what they share: both lists are the same fields in the same
	 * order, and it is only what they are called that drifted.
	 *
	 * @param array<string, mixed> $plan What SectionPlan made of the section.
	 * @param string               $dir  The block's directory.
	 * @return array<string, mixed> The plan, speaking the block's names.
	 */
	public static function adopt( array $plan, string $dir ): array {
		$group = self::group_of( $dir );

		if ( null === $group ) {
			return $plan;
		}

		$stored = array();
		$rows   = array();

		foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( 'repeater' === ( $field['type'] ?? '' ) ) {
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
					if ( is_array( $sub ) && isset( $sub['name'] ) ) {
						$rows[] = (string) $sub['name'];
					}
				}

				continue;
			}

			/*
			 * A listing's own two fields are the block's question, not the
			 * section's content, and no plan field corresponds to them.
			 */
			if ( in_array( (string) ( $field['name'] ?? '' ), array( 'source', 'limit' ), true ) ) {
				continue;
			}

			if ( isset( $field['name'] ) ) {
				$stored[] = (string) $field['name'];
			}
		}

		$plan['fields'] = self::relabelled( (array) ( $plan['fields'] ?? array() ), $stored );

		$item = $plan['item'] ?? null;

		if ( is_array( $item ) ) {
			$item['fields'] = self::relabelled( (array) ( $item['fields'] ?? array() ), $rows );
			$plan['item']   = $item;

			/*
			 * The kind comes from the block too, not only the names. A fresh
			 * structural plan judges six uniform cards a listing; the block on
			 * disk knows what was actually decided when it was written — a
			 * repeater field means the rows live in the block, `source` and
			 * `limit` mean they are the site's records. Guessing again on a
			 * rebuild made values() skip the rows of a section whose template
			 * was standing there reading them, and every card on the page
			 * disappeared.
			 */
			if ( array() !== $rows ) {
				$plan['kind'] = 'repeat';
			} elseif ( self::group_has( $group, 'source' ) && self::group_has( $group, 'limit' ) ) {
				$plan['kind'] = 'listing';
			}
		}

		return $plan;
	}

	/**
	 * Whether a stored field group holds a field of one name.
	 *
	 * @param array<string, mixed> $group Field group, decoded.
	 * @param string               $name  Field name.
	 * @return bool
	 */
	private static function group_has( array $group, string $name ): bool {
		foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && (string) ( $field['name'] ?? '' ) === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Put stored names back onto a plan's fields, in order.
	 *
	 * @param array<int, array<string, mixed>> $fields The plan's fields.
	 * @param array<int, string>               $names  What the block calls them.
	 * @return array<int, array<string, mixed>>
	 */
	private static function relabelled( array $fields, array $names ): array {
		/*
		 * Only when the two describe the same section. A design that changed
		 * between builds produces a different digest and so a different block,
		 * but a plan and a group of different lengths would otherwise be
		 * zipped together and every field after the first difference would be
		 * given the wrong name.
		 */
		if ( count( $fields ) !== count( $names ) ) {
			return $fields;
		}

		foreach ( $fields as $index => $field ) {
			if ( '' !== $names[ $index ] ) {
				$fields[ $index ]['name'] = $names[ $index ];
			}
		}

		return $fields;
	}

	/**
	 * The field group written beside a block, if there is one.
	 *
	 * @param string $dir The block's directory.
	 * @return array<string, mixed>|null
	 */
	private static function group_of( string $dir ): ?array {
		$file = trailingslashit( $dir ) . 'fields.json';

		if ( ! is_file( $file ) ) {
			return null;
		}

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by this class.

		if ( false === $raw ) {
			return null;
		}

		$group = json_decode( $raw, true );

		return is_array( $group ) ? $group : null;
	}

	/**
	 * Where generated blocks are written.
	 *
	 * Asked for rather than spelled out, so that a test run can be given a
	 * directory of its own. The suite used to build its fixture into the very
	 * directory a developer's real import lives in, and then tidy up by
	 * deleting whatever had appeared while it ran — which on a machine where a
	 * build was in flight meant deleting that build's blocks. Every page on
	 * the site then pointed at blocks that were not there.
	 *
	 * @return string Absolute path, without a trailing slash.
	 */
	public static function dir(): string {
		/**
		 * Filter where the importer writes the blocks it generates.
		 *
		 * @param string $dir Absolute path, without a trailing slash.
		 */
		return (string) apply_filters( 'qwerty_soft/design_blocks_dir', QSOFT_DIR . '/blocks/design' );
	}

	/**
	 * The handle every generated block names for the design's own assets.
	 *
	 * @var string
	 */
	public const CANONICAL_HANDLE = 'qs-design-canonical';

	/**
	 * Write the design's stylesheets and scripts, whole, beside the blocks.
	 *
	 * A design's stylesheet is written against a whole page. Slicing it per
	 * section re-runs it in whatever order a page's blocks happen to load —
	 * a duplicate rule from another page wins the cascade, and a script split
	 * the same way queries for a button that lives in a different block and
	 * silently finds nothing. So each source is kept whole and named by the
	 * blocks that came from its pages, which leaves per-block loading intact:
	 * a page with no imported section on it still fetches nothing.
	 *
	 * One file per source, not one file altogether: a handoff carrying two
	 * sites has two stylesheets that redefine the same classes, and
	 * concatenating them dressed every page in whichever sorted last. The
	 * primary source is `_canonical.css`/`.js`; every other becomes
	 * `_canonical-{key}.css`/`.js` under the matching handle — see
	 * `canonical_handle()`.
	 *
	 * @param array<int, array{key:string, css:string, js:string}> $sources From `DesignStylesheet::compile_sources()`.
	 * @return array{css:int,js:int} Bytes written.
	 */
	public static function write_canonical( array $sources ): array {
		$dir     = self::dir();
		$written = array(
			'css' => 0,
			'js'  => 0,
		);

		if ( ! wp_mkdir_p( $dir ) ) {
			return $written;
		}

		$kept = array();

		foreach ( $sources as $source ) {
			$key    = (string) ( $source['key'] ?? '' );
			$suffix = '' === $key ? '' : '-' . $key;
			$css    = trim( (string) ( $source['css'] ?? '' ) );
			$js     = trim( (string) ( $source['js'] ?? '' ) );

			if ( '' !== $css ) {
				/*
				 * First, so the design's own rules below win every tie.
				 *
				 * theme.json's `elements` set colour, size, weight, spacing
				 * and decoration on headings, links and buttons for the whole
				 * site, at the specificity of a bare tag, and load after the
				 * design's stylesheet. Where the design states its own value,
				 * `DesignStylesheet::scoped()` has already lifted its rule one
				 * class above the theme's. Where it states nothing and leans on
				 * the browser's defaults — an `h1` at 2em, a link underlined —
				 * the theme's value would leak in; `revert` sends each of those
				 * back to the browser, which is what the design's own page had.
				 */
				$reset = ':is(h1, h2, h3, h4, h5, h6)';

				// An @import the compiler could only hoist has to stay first, even ahead of the resets.
				$imports = '';

				if ( preg_match( '/^(?:\s*@import\b[^;]*;\s*)+/i', $css, $lead ) ) {
					$imports = trim( $lead[0] ) . "\n\n";
					$css     = (string) substr( $css, strlen( $lead[0] ) );
				}

				$sheet = $imports
					. "/*\n"
					. " * Resets, before the design's own rules, so the theme's element styles\n"
					. " * (theme.json `elements.heading`, `elements.link`, `elements.button`,\n"
					. " * `styles.typography` on body, and the `:where()` rules in `styles.css`)\n"
					. " * do not reach inside a wrapped section. See BlockWriter::write_canonical().\n"
					. " */\n"
					. self::BODY_SCOPE . " {\n"
					. "\tfont: revert;\n"
					. "\tletter-spacing: revert;\n"
					. "\ttext-transform: revert;\n"
					. "\tcolor: revert;\n"
					. "\tbackground: revert;\n"
					. "}\n"
					. '.' . self::ROOT_CLASS . ' ' . $reset . ",\n"
					. '.' . self::ROOT_CLASS . $reset . " {\n"
					. "\tcolor: inherit;\n"
					. "\tfont-size: revert;\n"
					. "\tfont-weight: revert;\n"
					. "\tline-height: revert;\n"
					. "\tletter-spacing: revert;\n"
					. "\ttext-transform: revert;\n"
					. "\ttext-wrap: revert;\n"
					. "\tmargin: revert;\n"
					. "}\n"
					. '.' . self::ROOT_CLASS . " :is(p, li) {\n"
					. "\ttext-wrap: revert;\n"
					. "}\n"
					. "/* The theme's block gap: 12px between every pair of blocks, which a design's sections never had. */\n"
					. ':is(.' . self::SITE_CLASS . ', .editor-styles-wrapper) :is(.wp-site-blocks, .is-layout-flow, .is-layout-constrained) > :is(.' . self::ROOT_CLASS . ', :has(.' . self::ROOT_CLASS . ")) {\n"
					. "\tmargin-block-start: 0;\n"
					. "\tmargin-block-end: 0;\n"
					. "}\n"
					. '.' . self::ROOT_CLASS . " :is(img, svg, video, canvas) {\n"
					. "\tmax-width: revert;\n"
					. "\theight: revert;\n"
					. "}\n"
					. '.' . self::ROOT_CLASS . " a,\n"
					. '.' . self::ROOT_CLASS . " a:hover,\n"
					. '.' . self::ROOT_CLASS . " a:focus {\n"
					. "\tcolor: revert;\n"
					. "\ttext-decoration: revert;\n"
					. "}\n"
					. '.' . self::ROOT_CLASS . " :is(button, input, select, textarea) {\n"
					. "\tfont: revert;\n"
					. "\tcolor: revert;\n"
					. "\tbackground: revert;\n"
					. "\tborder: revert;\n"
					. "\tborder-radius: revert;\n"
					. "\tpadding: revert;\n"
					. "\tmin-height: revert;\n"
					. "\twidth: revert;\n"
					. "}\n\n"
					. $css . "\n";

				$name = '_canonical' . $suffix . '.css';

				$written['css'] += (int) file_put_contents( $dir . '/' . $name, $sheet );

				$kept[ $name ] = true;
			}

			if ( '' !== $js ) {
				$name = '_canonical' . $suffix . '.js';

				$written['js'] += (int) file_put_contents( $dir . '/' . $name, $js . "\n" );

				$kept[ $name ] = true;
			}
		}

		/*
		 * A rebuild from a different archive leaves the old archive's source
		 * files behind under keys nothing routes to any more. Registered by
		 * glob, they would keep loading for blocks that still name them —
		 * stale by definition, so they go.
		 */
		foreach ( (array) glob( $dir . '/_canonical*.*' ) as $file ) {
			if ( ! isset( $kept[ basename( (string) $file ) ] ) ) {
				unlink( (string) $file );
			}
		}

		return $written;
	}

	/**
	 * The ACF field group, as local JSON.
	 *
	 * @param string               $slug  Block slug.
	 * @param string               $title Inserter title.
	 * @param array<string, mixed> $plan  What SectionPlan made of it.
	 * @param string               $html  The section's markup, for the fields' defaults.
	 * @return string JSON.
	 */
	public static function fields_json( string $slug, string $title, array $plan, string $html = '' ): string {
		$key    = 'group_qs_design_' . str_replace( '-', '_', $slug );
		$fields = array();

		/*
		 * The design's own words, kept as each field's default.
		 *
		 * A field with nothing stored renders nothing, which is right for a
		 * placement somebody emptied on purpose and wrong everywhere else: a
		 * block dragged out of the inserter onto a second page came up blank,
		 * and so did every field a repair could not match to a stored value.
		 * The default is what the designer wrote, so the worst case is the
		 * section as delivered.
		 */
		$says     = '' === $html ? array() : self::values( $html, $plan );
		$rows_say = isset( $says['items'] ) && is_array( $says['items'] ) ? (array) ( $says['items'][0] ?? array() ) : array();

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			$fields[] = self::acf_field( $slug, (array) $field, $says );
		}

		$fields = self::disambiguate_labels( $fields );
		$fields = self::tabbed( $slug, $fields, (array) ( $plan['fields'] ?? array() ), $says, $html );

		/*
		 * Three fields to a row is right on the Site content screen, which is
		 * the width of the page. It is wrong in the block sidebar, which is
		 * about 280 pixels: a third of that is 90, and "Approve
		 * privileged-access remediation plan" was being typed into it.
		 */
		if ( 'option' === self::$scope ) {
			$fields = self::side_by_side( $fields );
		}

		$item = $plan['item'] ?? null;

		if ( is_array( $item ) && 'listing' !== ( $plan['kind'] ?? 'single' ) ) {
			$rows = array();

			/*
			 * The repeat gets a division of its own once the section has any.
			 * Left to fall after the last tab it would join whichever part of
			 * the design happened to come last, and a repeat is not part of
			 * that part — it is the list.
			 */
			if ( self::divided( $fields ) ) {
				$fields[] = self::divider( $slug, 'tab_items', __( 'Items', 'qwerty-soft-signal' ), false );
			}

			foreach ( (array) ( $item['fields'] ?? array() ) as $field ) {
				$rows[] = self::acf_field( $slug . '_row', (array) $field, $rows_say, true );
			}

			$rows = self::disambiguate_labels( $rows );

			if ( 'option' === self::$scope ) {
				$rows = self::side_by_side( $rows );
			}

			/*
			 * ACF draws a row's fields once per row, so an explanation on one
			 * of them is printed as many times as there are rows — seven
			 * copies of "Choose a page, or paste a web address" down a
			 * seven-item navigation. Where the row is a single field the
			 * repeat's own instruction already covers it and the label says
			 * the rest; a row of several keeps them, because there the
			 * sentence is telling one field from another.
			 */
			$rows = count( $rows ) > 1 ? self::instructions_once( $rows ) : self::unexplained( $rows );

			$fields[] = array(
				'key'          => 'field_qs_' . str_replace( '-', '_', $slug ) . '_items',
				'label'        => __( 'Items', 'qwerty-soft-signal' ),
				'name'         => 'items',
				'type'         => 'repeater',
				'layout'       => 'block',
				'button_label' => __( 'Add item', 'qwerty-soft-signal' ),
				'instructions' => __( 'One row per repeated entry. Add, remove or reorder rows as needed.', 'qwerty-soft-signal' ),

				/*
				 * Which sub-field a collapsed row shows in its header. Without
				 * one, a footer's navigation is eight rows all called "Link"
				 * and the only way to find the one you want is to open each in
				 * turn. ACF names this setting by field key.
				 */
				'collapsed'    => self::row_summary( $rows ),
				'sub_fields'   => $rows,
			);
		}

		if ( is_array( $item ) && 'listing' === ( $plan['kind'] ?? 'single' ) ) {
			/*
			 * A listing holds a choice, not content. Six report cards are the
			 * site's own records and the design's card is the template for one
			 * of them, so the block asks which records to show and how many —
			 * and adding a seventh report becomes publishing a post rather
			 * than editing a page.
			 */
			$fields[] = array(
				'key'           => 'field_qs_' . str_replace( '-', '_', $slug ) . '_source',
				'label'         => __( 'Show', 'qwerty-soft-signal' ),
				'name'          => 'source',
				'type'          => 'post_object',

				/*
				 * Narrowed to this design's own records where there are any, so
				 * the picker offers reports rather than every post on the site.
				 */
				'post_type'     => '' !== self::$type ? array( self::$type ) : array(),
				'multiple'      => 1,
				'return_format' => 'id',
				'instructions'  => __( 'Leave empty to show the most recent automatically.', 'qwerty-soft-signal' ),
			);

			$fields[] = array(
				'key'           => 'field_qs_' . str_replace( '-', '_', $slug ) . '_limit',
				'label'         => __( 'How many', 'qwerty-soft-signal' ),
				'name'          => 'limit',
				'type'          => 'number',
				'default_value' => (int) ( $item['count'] ?? 3 ),
				'min'           => 1,
				'instructions'  => __( 'The most this position will display at once.', 'qwerty-soft-signal' ),
			);
		}

		/*
		 * Where the editor finds these fields.
		 *
		 * A section's own fields belong to its block, because two copies of a
		 * section should be able to say different things. The header and the
		 * footer are the opposite: they are on every page, so their words go on
		 * the options page and one edit reaches all of them.
		 *
		 * The block rule names the block as it is registered, not with an
		 * `acf/` prefix. ACF prefixes only the blocks it registers itself; one
		 * declared in a block.json keeps that file's namespace, and a rule
		 * naming the wrong one attaches the group to nothing at all.
		 */
		$location = 'option' === self::$scope
			? array(
				array(
					array(
						'param'    => 'options_page',
						'operator' => '==',
						'value'    => self::OPTIONS_PAGE,
					),
				),
			)
			: array(
				array(
					array(
						'param'    => 'block',
						'operator' => '==',
						'value'    => 'qs/design-' . $slug,
					),
				),
			);

		/*
		 * Which box comes first when two field groups share a screen.
		 *
		 * Only the chrome does: the header's group and the footer's are both
		 * on the Site content page, and with both at ACF's default the footer
		 * came out on top — the site read bottom-up, and the first thing an
		 * editor met was its own small print. A section's group is alone on
		 * the block it belongs to and stays where it is.
		 */
		$group = array(
			'key'        => $key,
			'title'      => '' !== trim( $title ) ? $title : $slug,
			'fields'     => self::instructions_once( $fields ),
			'location'   => $location,
			'menu_order' => str_starts_with( $slug, 'site-footer-' ) ? 1 : 0,
			'active'     => true,
		);

		return (string) wp_json_encode( $group, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/**
	 * One field, in the shape ACF wants.
	 *
	 * @param string               $scope Key prefix, so two blocks never collide.
	 * @param array<string, mixed> $field What SectionPlan found.
	 * @param array<string, mixed> $says  What the design said, by field name.
	 * @param bool                 $row   True for a repeater's sub-field.
	 * @return array<string, mixed>
	 */
	private static function acf_field( string $scope, array $field, array $says = array(), bool $row = false ): array {
		$name = (string) ( $field['name'] ?? 'field' );
		$type = (string) ( $field['type'] ?? 'text' );

		$acf = array(
			'key'   => 'field_qs_' . str_replace( '-', '_', $scope ) . '_' . $name,
			'label' => (string) ( $field['label'] ?? ucfirst( $name ) ),

			/*
			 * The stored name, which for a site-wide field carries the block's
			 * slug. ACF keeps an option under `options_{name}` and that key is
			 * the whole of its identity, so two footers both calling a field
			 * `link` would write to the same row — and the second site would
			 * quietly show the first one's address. The label stays "Link".
			 *
			 * A repeater's sub-field is the exception, and prefixing one was a
			 * bug: its value is stored under `options_{repeater}_{n}_{name}`,
			 * so the repeater's own name already scopes it and nothing can
			 * collide. Prefixed, the sub-field asked ACF for a key that is
			 * never written, ACF answered with the field's default — the
			 * archive's own unrewritten `research.html` — and `render.php`,
			 * which reads the row by its plain name, found no such key at all
			 * and drew a footer of empty links.
			 */
			'name'  => $row ? $name : self::stored_name( $name ),
			'type'  => 'text',
		);

		if ( 'textarea' === $type ) {
			$acf['type'] = 'textarea';
			$acf['rows'] = 3;
		}

		/*
		 * Words that carry their own markup get a box tall enough to see it
		 * in, and a note saying which tags are kept — the rest is stripped on
		 * the way out, which the person typing a `<script>` into it should
		 * know before they wonder where it went.
		 *
		 * Rich as the design left it, though, not as the plan hoped: a line
		 * planned to keep its markup whose words turn out to have none — a
		 * copyright line, rendered as words so the year can be written into it
		 * — is an ordinary field, and promising an editor it keeps `<strong>`
		 * when the page will escape it is worse than saying nothing.
		 */
		$rich = ! empty( $field['rich'] ) && is_string( $says[ $name ] ?? null ) && str_contains( (string) $says[ $name ], '<' );

		if ( $rich && in_array( $type, array( 'text', 'textarea' ), true ) ) {
			$acf['type'] = 'textarea';
			$acf['rows'] = 'text' === $type ? 2 : 3;
		}

		if ( 'image' === $type ) {
			$acf['type']          = 'image';
			$acf['return_format'] = 'array';
			$acf['preview_size']  = 'medium';
		}

		if ( 'link' === $type ) {
			$acf['type']          = 'link';
			$acf['return_format'] = 'array';
		}

		/*
		 * A picture cannot be a default: ACF wants an attachment and the
		 * design has a file path. Everything else keeps what it said.
		 */
		if ( 'image' !== $type && isset( $says[ $name ] ) && ( is_string( $says[ $name ] ) || is_array( $says[ $name ] ) ) && array() !== (array) $says[ $name ] && '' !== $says[ $name ] ) {
			$acf['default_value'] = $says[ $name ];
		}

		/*
		 * Never blank. A generated field group is handed to whoever edits the
		 * site next, not to the person who imported it, and "Link" or "Text"
		 * on its own says nothing about what that word is for in this design —
		 * the label survives a redesign, the instruction is what keeps the
		 * field usable by someone who never saw the original.
		 */
		$acf['instructions'] = self::field_instructions( $acf['type'] );

		if ( $rich && in_array( $type, array( 'text', 'textarea' ), true ) ) {
			$acf['instructions'] = __( 'Text with the design\'s own formatting. Keeps <br>, <strong>, <em> and <span class="…">; any other tag is removed.', 'qwerty-soft-signal' );
		}

		return $acf;
	}

	/**
	 * The one sentence every field type is explained by, absent anything more specific.
	 *
	 * @param string $type ACF field type.
	 * @return string
	 */
	private static function field_instructions( string $type ): string {
		switch ( $type ) {
			case 'textarea':
				return __( 'A short paragraph of text, shown in this position.', 'qwerty-soft-signal' );

			case 'link':
				return __( 'Choose a page, or paste a web address, and the text shown for it.', 'qwerty-soft-signal' );

			case 'image':
				return __( 'The picture shown in this position.', 'qwerty-soft-signal' );

			case 'number':
				return __( 'A whole number.', 'qwerty-soft-signal' );

			case 'url':
				return __( 'A web address, including https://.', 'qwerty-soft-signal' );

			case 'email':
				return __( 'An email address.', 'qwerty-soft-signal' );

			default:
				return __( 'A short line of text, shown in this position.', 'qwerty-soft-signal' );
		}
	}

	/**
	 * Tell apart fields that would otherwise share a label.
	 *
	 * SectionPlan names every link "Link" and every paragraph "Text" — right
	 * for one of each, unreadable for the footer that has ten links in a row
	 * and nothing on screen to say which is which without opening every one.
	 * The design's own words settle it where there are any — "Link — Contact"
	 * beats "Link 4" — and a plain count is the fallback for whatever has none,
	 * an image chief among them.
	 *
	 * Two passes, because a hint can collide too. A card grid whose fourth
	 * field is always a check-mark glyph turned four different fields into
	 * four fields all called "Check — ✓" — worse than before, because now they
	 * *look* told apart without being it. The second pass catches whatever the
	 * first still left sharing a label, hinted or not, and numbers just that.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields in source order.
	 * @return array<int, array<string, mixed>>
	 */
	private static function disambiguate_labels( array $fields ): array {
		$seen = array();

		foreach ( $fields as $field ) {
			$label          = (string) ( $field['label'] ?? '' );
			$seen[ $label ] = ( $seen[ $label ] ?? 0 ) + 1;
		}

		foreach ( $fields as $index => $field ) {
			$label = (string) ( $field['label'] ?? '' );

			if ( '' === $label || ( $seen[ $label ] ?? 0 ) < 2 ) {
				continue;
			}

			$hint = self::label_hint( $field );

			if ( '' !== $hint ) {
				$fields[ $index ]['label'] = $label . ' — ' . $hint;
			}
		}

		$after = array();

		foreach ( $fields as $field ) {
			$label           = (string) ( $field['label'] ?? '' );
			$after[ $label ] = ( $after[ $label ] ?? 0 ) + 1;
		}

		$ordinal = array();

		foreach ( $fields as $index => $field ) {
			$label = (string) ( $field['label'] ?? '' );

			if ( ( $after[ $label ] ?? 0 ) < 2 ) {
				continue;
			}

			$ordinal[ $label ]         = ( $ordinal[ $label ] ?? 0 ) + 1;
			$fields[ $index ]['label'] = $label . ' ' . $ordinal[ $label ];
		}

		return $fields;
	}

	/**
	 * A few words of the design's own copy, to tell one field from its sibling.
	 *
	 * @param array<string, mixed> $field One ACF field, as {@see self::acf_field()} built it.
	 * @return string
	 */
	private static function label_hint( array $field ): string {
		$type    = (string) ( $field['type'] ?? '' );
		$default = $field['default_value'] ?? null;

		if ( 'link' === $type && is_array( $default ) && '' !== trim( (string) ( $default['title'] ?? '' ) ) ) {
			return self::trim_hint( (string) $default['title'] );
		}

		if ( in_array( $type, array( 'text', 'textarea', 'url', 'email' ), true ) && is_string( $default ) && '' !== trim( $default ) ) {
			return self::trim_hint( $default );
		}

		return '';
	}

	/**
	 * Cut a piece of design copy down to something that still fits a label.
	 *
	 * @param string $text Raw text from the design.
	 * @return string
	 */
	private static function trim_hint( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

		if ( mb_strlen( $text ) <= 24 ) {
			return $text;
		}

		/*
		 * Cut at a word, not at the twenty-fourth character. A label reading
		 * "Text — Market intelligence is f…" is the sort of thing that looks
		 * like a rendering fault rather than a name, and it is what an editor
		 * is handed to work with.
		 */
		$cut   = mb_substr( $text, 0, 24 );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > 8 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, ' ,.;:—-' ) . '…';
	}

	/**
	 * Widths that turn a run of same-kind fields into a row instead of a column.
	 *
	 * A footer with ten link fields listed the way SectionPlan found them was
	 * ten full-width rows — a scroll to fill in an address book one entry at a
	 * time, with nothing on screen to show how many were left. Two fields of a
	 * kind sit at 50% each, three or more at 33%, wrapping into as many rows as
	 * the run needs; a field with no neighbour of its own kind keeps the full
	 * width a paragraph or an image needs to be read.
	 *
	 * A `textarea` holds a sentence, not a word, so it never goes past two
	 * columns even in a longer run — three of them side by side turned a
	 * one-line disclaimer into four wrapped ones, which is a worse edit than
	 * the single column it replaced.
	 *
	 * Widths are worked out row by row rather than once for the whole run. Ten
	 * links at three columns is three full rows and a fourth holding one —
	 * and giving that leftover field the same 33% as its full rows left it
	 * sitting in a third of the row with nothing beside it to explain why.
	 * However many of a kind land in the last row is how many that row's
	 * width divides by, so a leftover of one gets the full width it is
	 * already the only thing on, and a leftover of two still gets 50/50.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields in source order.
	 * @return array<int, array<string, mixed>>
	 */
	private static function side_by_side( array $fields ): array {
		$max   = array(
			'text'     => 3,
			'link'     => 3,
			'url'      => 3,
			'email'    => 3,
			'image'    => 3,
			'number'   => 3,
			'textarea' => 2,
		);
		$count = count( $fields );
		$index = 0;

		while ( $index < $count ) {
			$type = (string) ( $fields[ $index ]['type'] ?? '' );
			$run  = 1;

			// A run of three ends where the design's own division does.
			if ( self::is_divider( (array) $fields[ $index ] ) ) {
				++$index;

				continue;
			}

			while ( $index + $run < $count && (string) ( $fields[ $index + $run ]['type'] ?? '' ) === $type ) {
				++$run;
			}

			if ( $run > 1 && isset( $max[ $type ] ) ) {
				$columns = $max[ $type ];
				$offset  = 0;

				while ( $offset < $run ) {
					$row = min( $columns, $run - $offset );

					if ( $row > 1 ) {
						$width = round( 100 / $row, 2 );

						for ( $i = 0; $i < $row; $i++ ) {
							$fields[ $index + $offset + $i ]['wrapper'] = array( 'width' => (string) $width );
						}
					}

					$offset += $row;
				}
			}

			$index += $run;
		}

		return $fields;
	}

	/**
	 * How many fields a group holds before dividing it is worth the tabs.
	 *
	 * Under this a person reads the whole group at a glance, and a row of tabs
	 * is one more thing between them and the field they came for.
	 *
	 * @var int
	 */
	private const TAB_MIN = 10;

	/**
	 * The most fields one division holds before the divider looks deeper.
	 *
	 * @var int
	 */
	private const TAB_MAX = 6;

	/**
	 * The most divisions worth making. A wall of tabs is the wall again.
	 *
	 * @var int
	 */
	private const TAB_LIMIT = 8;

	/**
	 * How far into the markup the divider will look for a division.
	 *
	 * @var int
	 */
	private const TAB_DEPTH = 10;

	/**
	 * Divide a long field group the way the design divides the section.
	 *
	 * A footer of nineteen fields is a wall: "Link — Ongoing assurance" says
	 * what the link is and nothing about which of three columns it stands in,
	 * so an editor changing one address reads eleven labels to find it. The
	 * design already answers that — its three columns are three elements — and
	 * every field carries the address SectionPlan recorded, so the division is
	 * the markup's own rather than one invented here.
	 *
	 * The tab is named for what the design calls that part: the holder's class
	 * where it reads as a name (`.footer-brand` is "Footer brand"), else the
	 * words in it (a column headed "AI Security" is the AI Security tab), else
	 * the first field's label. Nothing is called "Group 2".
	 *
	 * @param string                           $slug   Block slug, for the keys.
	 * @param array<int, array<string, mixed>> $fields The ACF fields, in order.
	 * @param array<int, array<string, mixed>> $plan   The same fields as SectionPlan found them.
	 * @param array<string, mixed>             $says   What the design said, by field name.
	 * @param string                           $html   The section's markup.
	 * @return array<int, array<string, mixed>>
	 */
	private static function tabbed( string $slug, array $fields, array $plan, array $says, string $html ): array {
		$fields = array_values( $fields );
		$plan   = array_values( $plan );

		if ( count( $fields ) < self::TAB_MIN || count( $fields ) !== count( $plan ) ) {
			return $fields;
		}

		$paths = array();

		foreach ( $plan as $index => $field ) {
			$path = (string) ( $field['path'] ?? '' );

			/*
			 * A plan without addresses cannot be divided by them. That is the
			 * repaired-by-hand case rather than a generated one, and leaving
			 * the group flat is the honest answer to it.
			 */
			if ( '' === $path ) {
				return $fields;
			}

			$paths[ $index ] = $path;
		}

		$buckets = self::split_by_path( $paths, array_keys( $paths ), 0 );

		if ( count( $buckets ) < 2 || count( $buckets ) > self::TAB_LIMIT ) {
			return $fields;
		}

		$body    = self::body_of( $html );
		$holders = array();

		foreach ( $buckets as $at => $bucket ) {
			$holders[ $at ] = self::holder_name( $bucket, $paths, $body );
		}

		/*
		 * A class that names every one of them names none of them. Three cards
		 * all held by a `.task` are three tabs called "Task", and what tells
		 * them apart is what each one says rather than what they are all built
		 * from — so a repeated holder is dropped in favour of the words.
		 */
		$times = array_count_values( array_filter( $holders ) );
		$out   = array();
		$taken = array();

		foreach ( $buckets as $at => $bucket ) {
			$holder = (string) $holders[ $at ];
			$label  = '' !== $holder && 1 === ( $times[ $holder ] ?? 0 )
				? $holder
				: self::tab_words( $bucket, $fields, $plan, $says );

			/*
			 * Two columns of a design can be headed the same word. A tab is
			 * what a person navigates by, so two of them reading alike is
			 * worse here than in a label — the second says which one it is.
			 */
			$taken[ $label ] = ( $taken[ $label ] ?? 0 ) + 1;

			if ( $taken[ $label ] > 1 ) {
				$label .= ' ' . $taken[ $label ];
			}

			$out[] = self::divider( $slug, 'tab_' . ( (int) $at + 1 ), $label, 0 === (int) $at );

			foreach ( $bucket as $index ) {
				$out[] = $fields[ $index ];
			}
		}

		return $out;
	}

	/**
	 * The fewest fields a division holds before it stops being one.
	 *
	 * A tab holding a single field is not a part of a page, it is that field
	 * behind a click — and a section divided into seven of them reads worse
	 * than the list it replaced. Where the markup cannot offer parts of at
	 * least two, it is offering the layout's structure rather than the page's,
	 * and the group stays flat.
	 *
	 * @var int
	 */
	private const TAB_LEAST = 2;

	/**
	 * Where the markup itself divides a list of fields.
	 *
	 * Descends the addresses a step at a time. A level every field passes
	 * through together is not a division and is stepped over; the first level
	 * that branches is one, and a branch still holding more fields than a tab
	 * should is divided again inside itself.
	 *
	 * A branch that is refused — too many parts, or a part of one field — ends
	 * the search rather than sending it deeper. Past a real branch the
	 * addresses no longer line up: the next step of a field under one holder
	 * and of a field under another are both "the first child", and dividing by
	 * it would sort fields from different parts of the page into one tab.
	 *
	 * @param array<int, string> $paths   Address by field index.
	 * @param array<int, int>    $indices The field indices to divide.
	 * @param int                $depth   How many steps in to look.
	 * @return array<int, array<int, int>> Field indices, grouped, in source order.
	 */
	private static function split_by_path( array $paths, array $indices, int $depth ): array {
		if ( $depth > self::TAB_DEPTH || count( $indices ) < self::TAB_LEAST * 2 ) {
			return array( $indices );
		}

		$buckets = array();

		foreach ( $indices as $index ) {
			$steps = explode( '/', (string) ( $paths[ $index ] ?? '' ) );

			/*
			 * A field that is the holder of the others — a link whose own
			 * words are a field too — has no step at this depth. Dividing here
			 * would put the parent in one tab and its children in the next, so
			 * this level is not a division.
			 */
			if ( ! isset( $steps[ $depth ] ) ) {
				return array( $indices );
			}

			$buckets[ $steps[ $depth ] ][] = $index;
		}

		if ( count( $buckets ) < 2 ) {
			return self::split_by_path( $paths, $indices, $depth + 1 );
		}

		if ( count( $buckets ) > self::TAB_LIMIT ) {
			return array( $indices );
		}

		foreach ( $buckets as $bucket ) {
			if ( count( $bucket ) < self::TAB_LEAST ) {
				return array( $indices );
			}
		}

		$out = array();

		foreach ( $buckets as $bucket ) {
			if ( count( $bucket ) > self::TAB_MAX ) {
				foreach ( self::split_by_path( $paths, $bucket, $depth + 1 ) as $deeper ) {
					$out[] = $deeper;
				}

				continue;
			}

			$out[] = $bucket;
		}

		return $out;
	}

	/**
	 * What one division of a field group calls itself, in the design's words.
	 *
	 * @param array<int, int>                  $bucket Field indices in this division.
	 * @param array<int, array<string, mixed>> $fields The ACF fields.
	 * @param array<int, array<string, mixed>> $plan   The same fields as SectionPlan found them.
	 * @param array<string, mixed>             $says   What the design said, by field name.
	 * @return string
	 */
	private static function tab_words( array $bucket, array $fields, array $plan, array $says ): string {
		/*
		 * The words the column is headed with. A design writes them for a
		 * reader, which is what a tab wants; the length test is what keeps a
		 * paragraph from becoming one.
		 */
		foreach ( $bucket as $index ) {
			$field = (array) ( $plan[ $index ] ?? array() );
			$words = $says[ (string) ( $field['name'] ?? '' ) ] ?? null;

			if ( 'text' !== (string) ( $field['type'] ?? '' ) || ! is_string( $words ) ) {
				continue;
			}

			$words = trim( (string) wp_strip_all_tags( $words ) );

			if ( '' !== $words && mb_strlen( $words ) <= 40 ) {
				return $words;
			}
		}

		$label = (string) ( $fields[ $bucket[0] ]['label'] ?? '' );

		// Without the hint disambiguate_labels() added: a tab is not that long.
		$label = trim( (string) preg_replace( '/\s+—.*$/u', '', $label ) );

		return '' === $label ? __( 'More', 'qwerty-soft-signal' ) : $label;
	}

	/**
	 * The design's own name for the element a division of fields sits in.
	 *
	 * @param array<int, int>    $bucket Field indices in this division.
	 * @param array<int, string> $paths  Address by field index.
	 * @param DOMNode|null       $body   The section, parsed, or null.
	 * @return string Empty when the markup offers no name.
	 */
	private static function holder_name( array $bucket, array $paths, ?DOMNode $body ): string {
		/*
		 * One field is not a part of the page with a name of its own — its
		 * address resolves to itself, and the tab would be named after the
		 * field standing in it. Its own label says more.
		 */
		if ( ! $body instanceof DOMNode || count( $bucket ) < 2 ) {
			return '';
		}

		$common = null;

		foreach ( $bucket as $index ) {
			$steps = explode( '/', (string) ( $paths[ $index ] ?? '' ) );

			if ( null === $common ) {
				$common = $steps;

				continue;
			}

			$keep = array();

			foreach ( $common as $at => $step ) {
				if ( ( $steps[ $at ] ?? null ) !== $step ) {
					break;
				}

				$keep[] = $step;
			}

			$common = $keep;
		}

		if ( null === $common || array() === $common ) {
			return '';
		}

		$node = SectionPlan::at( $body, implode( '/', $common ) );

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		foreach ( explode( ' ', (string) $node->getAttribute( 'class' ) ) as $token ) {
			$name = self::readable_class( $token );

			if ( '' !== $name ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * A class an editor could read as the name of a part of the page.
	 *
	 * The design's own class is the best name a divider can carry — a
	 * `.footer-brand` is "Footer brand" and needs nothing invented. But half of
	 * them belong to the grid rather than to the page, and "Col 4" names
	 * nothing: a class with a digit in it, or built from one of the words a
	 * layout is made of, is refused, so the words inside the column can be
	 * used instead.
	 *
	 * @param string $token One token of a class attribute.
	 * @return string The name, or empty.
	 */
	private static function readable_class( string $token ): string {
		$token = trim( $token );

		if ( '' === $token || 1 === preg_match( '/\d/', $token ) ) {
			return '';
		}

		$words = strtolower( trim( (string) preg_replace( '/[^A-Za-z]+/', ' ', $token ) ) );

		if ( mb_strlen( $words ) < 4 ) {
			return '';
		}

		$layout = array( 'col', 'cols', 'column', 'columns', 'row', 'rows', 'item', 'items', 'cell', 'inner', 'outer', 'wrap', 'wrapper', 'box', 'grid', 'flex', 'container', 'content', 'block', 'group', 'left', 'right', 'main', 'side', 'part', 'top', 'bottom', 'first', 'last' );

		foreach ( explode( ' ', $words ) as $word ) {
			if ( in_array( $word, $layout, true ) ) {
				return '';
			}
		}

		return ucfirst( $words );
	}

	/**
	 * Say a field type's sentence once, not under every field that shares it.
	 *
	 * The footer has eleven links, and eleven copies of "Choose a page, or
	 * paste a web address, and the text shown for it" is not eleven times the
	 * help. It is a screen twice as long as it needs to be, with the eleven
	 * labels that do differ held apart by the sentence that does not. The
	 * first field of a kind keeps the explanation and the rest are read from
	 * it; a divider starts the counting again, because a tab is a screen of
	 * its own and the sentence may not have been seen on it.
	 *
	 * @param array<int, array<string, mixed>> $fields The ACF fields, in order.
	 * @return array<int, array<string, mixed>>
	 */
	private static function instructions_once( array $fields ): array {
		$said = array();

		foreach ( $fields as $index => $field ) {
			if ( self::is_divider( (array) $field ) ) {
				$said = array();

				continue;
			}

			$says = (string) ( $field['instructions'] ?? '' );

			if ( '' === $says ) {
				continue;
			}

			if ( isset( $said[ $says ] ) ) {
				$fields[ $index ]['instructions'] = '';

				continue;
			}

			$said[ $says ] = true;
		}

		return $fields;
	}

	/**
	 * The longest a divider's name may be before it stops fitting.
	 *
	 * A tab strip is read sideways and a design's own heading can be a
	 * sentence. The words are still under the divider, in the field they came
	 * from; this is only what is written on the handle.
	 *
	 * @var int
	 */
	private const TAB_LABEL = 28;

	/**
	 * One divider, in the shape the screen it is drawn on wants.
	 *
	 * A tab strip suits the Site content screen, which is as wide as the page.
	 * The block sidebar is a column about 280 pixels across, where five tabs
	 * wrap into a stack of stubs and none of them can be read; an accordion is
	 * the same division drawn down the page instead of across it, and it lets
	 * two parts be open at once.
	 *
	 * @param string $slug  Block slug, for the key.
	 * @param string $name  What makes this divider's key unique.
	 * @param string $label What is written on it.
	 * @param bool   $first Whether it is the first of the group.
	 * @return array<string, mixed>
	 */
	private static function divider( string $slug, string $name, string $label, bool $first ): array {
		if ( mb_strlen( $label ) > self::TAB_LABEL ) {
			$label = rtrim( mb_substr( $label, 0, self::TAB_LABEL - 1 ) ) . '…';
		}

		$divider = array(
			'key'   => 'field_qs_' . str_replace( '-', '_', $slug ) . '_' . $name,
			'label' => $label,
			'name'  => '',
			'type'  => 'option' === self::$scope ? 'tab' : 'accordion',
		);

		if ( 'tab' === $divider['type'] ) {
			$divider['placement'] = 'top';

			return $divider;
		}

		/*
		 * Open on the first, so a block selected in the editor shows its words
		 * rather than a stack of closed headings; and more than one at a time,
		 * because comparing two parts is most of what editing a section is.
		 */
		$divider['open']         = $first ? 1 : 0;
		$divider['multi_expand'] = 1;
		$divider['endpoint']     = 0;

		return $divider;
	}

	/**
	 * Whether a field is one of the dividers {@see self::divider()} writes.
	 *
	 * @param array<string, mixed> $field One ACF field.
	 * @return bool
	 */
	private static function is_divider( array $field ): bool {
		return in_array( (string) ( $field['type'] ?? '' ), array( 'tab', 'accordion' ), true );
	}

	/**
	 * Whether a field group already carries dividers.
	 *
	 * @param array<int, array<string, mixed>> $fields The ACF fields.
	 * @return bool
	 */
	private static function divided( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( self::is_divider( (array) $field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Take the explanation off every field in a list.
	 *
	 * @param array<int, array<string, mixed>> $fields The ACF fields.
	 * @return array<int, array<string, mixed>>
	 */
	private static function unexplained( array $fields ): array {
		foreach ( $fields as $index => $field ) {
			if ( isset( $field['instructions'] ) ) {
				$fields[ $index ]['instructions'] = '';
			}
		}

		return $fields;
	}

	/**
	 * The body of a fragment, parsed.
	 *
	 * @param string $html Markup.
	 * @return DOMNode|null
	 */
	private static function body_of( string $html ) {
		$dom = self::parse( $html );

		if ( null === $dom ) {
			return null;
		}

		$body = ( new DOMXPath( $dom ) )->query( '//body' )->item( 0 );

		return $body instanceof DOMNode ? $body : null;
	}

	/**
	 * One value from the design, written as PHP source a template can hold.
	 *
	 * @param mixed $value Whatever the markup said.
	 * @return string PHP source.
	 */
	private static function literal( $value ): string {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writing PHP source, which is what var_export() is for.
		}

		if ( is_array( $value ) ) {
			$parts = array();

			foreach ( $value as $key => $one ) {
				// One level, which is every shape a field takes: a link, or a picture.
				if ( is_array( $one ) ) {
					continue;
				}

				$parts[] = var_export( (string) $key, true ) . ' => ' . self::literal( $one ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writing PHP source, which is what var_export() is for.
			}

			return 'array( ' . implode( ', ', $parts ) . ' )';
		}

		return 'null';
	}

	/**
	 * What this section is, in a sentence somebody scanning the inserter can use.
	 *
	 * A name is not enough. Thirty sections out of one design give thirty
	 * headings that all sound like the same company saying the same thing, and
	 * "Premium market intelligence for food trade decisions" tells nobody
	 * whether the block is a hero, a price table or a footer strip. What
	 * distinguishes them is what they are made of and where they came from, so
	 * that is what this says.
	 *
	 * @param string               $title  What the section is called.
	 * @param string               $slug   Block slug, as a last resort.
	 * @param string               $origin The archive file it came out of.
	 * @param array<string, mixed> $plan   What SectionPlan made of it.
	 * @return string
	 */
	private static function describe( string $title, string $slug, string $origin, array $plan ): string {
		$counts = array(
			'text'   => 0,
			'image'  => 0,
			'link'   => 0,
			'repeat' => 0,
		);

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			$type = (string) ( $field['type'] ?? 'text' );

			if ( 'image' === $type ) {
				++$counts['image'];
			} elseif ( 'link' === $type || 'url' === $type ) {
				++$counts['link'];
			} else {
				++$counts['text'];
			}
		}

		$item = $plan['item'] ?? null;

		if ( is_array( $item ) ) {
			$counts['repeat'] = (int) ( $item['count'] ?? 0 );
		}

		$parts = array();

		if ( $counts['text'] > 0 ) {
			/* translators: %d: how many pieces of text the section holds. */
			$parts[] = sprintf( _n( '%d piece of text', '%d pieces of text', $counts['text'], 'qwerty-soft-signal' ), $counts['text'] );
		}

		if ( $counts['image'] > 0 ) {
			/* translators: %d: how many pictures the section holds. */
			$parts[] = sprintf( _n( '%d picture', '%d pictures', $counts['image'], 'qwerty-soft-signal' ), $counts['image'] );
		}

		if ( $counts['link'] > 0 ) {
			/* translators: %d: how many links the section holds. */
			$parts[] = sprintf( _n( '%d link', '%d links', $counts['link'], 'qwerty-soft-signal' ), $counts['link'] );
		}

		if ( $counts['repeat'] > 0 ) {
			/* translators: %d: how many repeating items the section holds. */
			$parts[] = sprintf( _n( '%d repeating item', '%d repeating items', $counts['repeat'], 'qwerty-soft-signal' ), $counts['repeat'] );
		}

		$what = '' !== trim( $title ) ? $title : $slug;
		$page = '' === trim( $origin ) ? '' : basename( $origin );

		/*
		 * Where the words are edited, said on the block itself, because the
		 * obvious way to edit one is unavailable and nothing else says so.
		 * ACF Pro ships a pencil on the block toolbar that swaps the section
		 * for its fields; its own code hides that pencil whenever the editor
		 * canvas is an iframe, which is every screen since WordPress 6.3. The
		 * fields are all still there in the sidebar — an editor just has no
		 * way of knowing that from looking at a section that ignores clicks.
		 * The description is the one line WordPress shows directly above them.
		 */
		$where = __( 'Its text is edited in the Block tab of the sidebar.', 'qwerty-soft-signal' );

		if ( array() === $parts ) {
			return '' === $page
				/* translators: 1: what the section is, 2: where its fields are edited. */
				? sprintf( __( 'A section from the design: %1$s. %2$s', 'qwerty-soft-signal' ), $what, $where )
				/* translators: 1: what the section is, 2: the design file it came from, 3: where its fields are edited. */
				: sprintf( __( '%1$s, from %2$s in the design. %3$s', 'qwerty-soft-signal' ), $what, $page, $where );
		}

		$made = implode( ', ', $parts );

		return '' === $page
			/* translators: 1: what the section is, 2: a list such as "3 pieces of text, 1 link", 3: where its fields are edited. */
			? sprintf( __( '%1$s — %2$s. %3$s', 'qwerty-soft-signal' ), $what, $made, $where )
			/* translators: 1: what the section is, 2: a list such as "3 pieces of text, 1 link", 3: the design file it came from, 4: where its fields are edited. */
			: sprintf( __( '%1$s — %2$s. From %3$s in the design. %4$s', 'qwerty-soft-signal' ), $what, $made, $page, $where );
	}

	/**
	 * Words that should find this block in the inserter's search.
	 *
	 * @param string $title  What the section is called.
	 * @param string $origin The archive file it came out of.
	 * @return array<int, string>
	 */
	private static function keywords( string $title, string $origin ): array {
		$words = array( __( 'design', 'qwerty-soft-signal' ), __( 'section', 'qwerty-soft-signal' ) );

		if ( '' !== trim( $origin ) ) {
			$words[] = basename( $origin, '.html' );
		}

		$found = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( $title ) );

		foreach ( is_array( $found ) ? $found : array() as $word ) {
			if ( mb_strlen( $word ) >= 4 && count( $words ) < 8 ) {
				$words[] = $word;
			}
		}

		return array_values( array_unique( array_filter( $words ) ) );
	}

	/**
	 * The values the inserter's preview is drawn with: the design's own.
	 *
	 * @param array<string, mixed> $plan What SectionPlan made of it.
	 * @param array<string, mixed> $says What the design's markup said.
	 * @return array<string, mixed>
	 */
	private static function example_data( array $plan, array $says ): array {
		$data = array();

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name || ! isset( $says[ $name ] ) ) {
				continue;
			}

			/*
			 * A picture is left out. The example is stored in the block's own
			 * manifest, where an attachment id from this site would be a lie on
			 * any other, and the template falls back to the design's file path
			 * anyway.
			 */
			if ( 'image' === (string) ( $field['type'] ?? 'text' ) ) {
				continue;
			}

			$data[ $name ] = $says[ $name ];
		}

		return $data;
	}

	/**
	 * The render template: the design's markup with the fields echoed into it.
	 *
	 * @param string               $html  Section markup.
	 * @param array<string, mixed> $plan  What SectionPlan made of it.
	 * @param string               $scope block or option.
	 * @return string|null PHP, or null when the markup could not be read.
	 */
	public static function render_php( string $html, array $plan, string $scope = 'block' ): ?string {
		/*
		 * Taken as an argument rather than inherited. The scope used to be
		 * whatever the last call to write() happened to leave behind, so
		 * rendering a page section straight after a footer produced a template
		 * that read the options page — correct only for as long as nobody
		 * called this in a different order.
		 */
		self::$scope = 'option' === $scope ? 'option' : 'block';
		self::$says  = self::values( $html, $plan );
		self::$forms = false;

		// A block-scoped render has no namespace, and must not inherit one.
		if ( 'block' === self::$scope ) {
			self::$prefix = '';
		}

		$dom = self::parse( $html );

		if ( null === $dom ) {
			return null;
		}

		/*
		 * Tied to this section's own bytes. A design would have to contain a
		 * digest of itself to collide with it, which is the difference between
		 * "unlikely" and "cannot".
		 */
		self::$marker = 'QSOFT' . strtoupper( substr( md5( $html ), 0, 8 ) );

		$xpath = new DOMXPath( $dom );
		$body  = $xpath->query( '//body' )->item( 0 );

		if ( ! $body instanceof DOMNode ) {
			return null;
		}

		self::mark_root( $body );

		$item = $plan['item'] ?? null;
		$kind = (string) ( $plan['kind'] ?? 'single' );

		self::$kind = $kind;

		// The rows the design drew are one row repeated; keep the first, drop the rest.
		if ( is_array( $item ) && isset( $item['parent'], $item['path'] ) ) {
			self::keep_first_row( $body, (string) $item['parent'], (string) ( $item['selector'] ?? '' ) );
		}

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			self::plant( $body, (array) $field, '' );
		}

		if ( is_array( $item ) ) {
			$row = SectionPlan::at( $body, (string) $item['path'] );

			if ( $row instanceof DOMElement ) {
				foreach ( (array) ( $item['fields'] ?? array() ) as $field ) {
					self::plant( $row, (array) $field, 'row' );
				}

				/*
				 * A listing card links to its record. Left alone, the template
				 * kept the example card's own address — the first record's,
				 * root-absolute — and every card on the site pointed there,
				 * which on a subdirectory install is a 404. The row hands its
				 * permalink over; see DesignListing::row().
				 */
				if ( 'listing' === $kind && 'a' === strtolower( $row->tagName ) && '' !== $row->getAttribute( 'href' ) ) {
					$row->setAttribute( 'href', self::token( 'row.url.permalink' ) );
				}

				self::wrap_row( $row, $kind, (array) ( $item['fields'] ?? array() ) );
			}
		}

		/*
		 * Last, because it adds a child to the form. Every field above is
		 * found by a path counted through the tree, and inserting anything
		 * before they are planted moves the thing the path was pointing at.
		 */
		self::wire_forms( $body );

		return self::to_php( self::inner_html( $body ) );
	}

	/**
	 * Point the design's own forms at the theme's handler.
	 *
	 * Nothing is replaced and nothing is renamed except what has to be: the
	 * form's own markup, classes and layout are what the design drew, and the
	 * only edits are the ones that decide where a submission goes — the
	 * method, the action, and the four field names the handler reads.
	 *
	 * A search box is left alone. It is a form, it is nobody's contact form,
	 * and wiring it up would mean emailing the studio every search.
	 *
	 * @param DOMNode $body The section.
	 * @return void
	 */
	private static function wire_forms( DOMNode $body ): void {
		$dom = $body->ownerDocument;

		if ( ! $dom instanceof DOMDocument ) {
			return;
		}

		$xpath = new DOMXPath( $dom );
		$forms = $xpath->query( './/form', $body );

		if ( false === $forms ) {
			return;
		}

		foreach ( $forms as $form ) {
			if ( ! $form instanceof DOMElement || self::is_search_form( $xpath, $form ) ) {
				continue;
			}

			$controls = $xpath->query( './/input | .//textarea | .//select', $form );

			// A form with nothing to type in is a button in disguise.
			if ( false === $controls || 0 === $controls->length ) {
				continue;
			}

			$form->setAttribute( 'method', 'post' );
			$form->setAttribute( 'action', self::FORM_ACTION );

			self::name_controls( $controls );

			$mark = $dom->createComment( trim( self::FORM_MARK, '<!->' ) );

			if ( $form->firstChild instanceof DOMNode ) {
				$form->insertBefore( $mark, $form->firstChild );
			} else {
				$form->appendChild( $mark );
			}

			self::$forms = true;
		}
	}

	/**
	 * Give the design's controls the names the handler reads.
	 *
	 * The handler takes four: name, email, subject, message. They are matched
	 * by what the control is before what it is called — a textarea is the
	 * message whatever the design named it, `type="email"` is the address —
	 * and only then by the words around it, so a form written in another
	 * language still lands on the right field through its input types.
	 *
	 * Anything left over keeps its own name. A phone number the design asked
	 * for is not something to silently drop, and the handler ignores what it
	 * does not know.
	 *
	 * @param DOMNodeList $controls The form's inputs, textareas and selects.
	 * @return void
	 */
	private static function name_controls( DOMNodeList $controls ): void {
		$taken = array();
		$spare = array();

		foreach ( $controls as $control ) {
			if ( ! $control instanceof DOMElement ) {
				continue;
			}

			$tag  = strtolower( $control->tagName );
			$type = strtolower( $control->getAttribute( 'type' ) );

			// Hidden fields and buttons are the design's business, not ours.
			if ( 'input' === $tag && in_array( $type, array( 'hidden', 'submit', 'button', 'image', 'reset' ), true ) ) {
				continue;
			}

			$says = strtolower(
				$control->getAttribute( 'name' ) . ' '
				. $control->getAttribute( 'id' ) . ' '
				. $control->getAttribute( 'placeholder' ) . ' '
				. $control->getAttribute( 'aria-label' ) . ' '
				. $control->getAttribute( 'autocomplete' )
			);

			$field = '';

			if ( 'textarea' === $tag ) {
				$field = 'message';
			} elseif ( 'email' === $type || 1 === preg_match( '/\bmail\b|e-?mail/i', $says ) ) {
				$field = 'email';
			} elseif ( 1 === preg_match( '/subject|topic|regarding/i', $says ) ) {
				$field = 'subject';
			} elseif ( 1 === preg_match( '/\bname\b|full-?name|first-?name|your-?name/i', $says ) ) {
				$field = 'name';
			} elseif ( 1 === preg_match( '/message|comment|enquiry|inquiry|question/i', $says ) ) {
				$field = 'message';
			}

			if ( '' === $field || isset( $taken[ $field ] ) ) {
				// Held back: an unnamed text box is a name field if nothing else claims it.
				if ( 'input' === $tag && in_array( $type, array( '', 'text' ), true ) ) {
					$spare[] = $control;
				}

				continue;
			}

			$taken[ $field ] = true;
			$control->setAttribute( 'name', 'qsoft_' . $field );
		}

		foreach ( array( 'name', 'subject' ) as $field ) {
			if ( isset( $taken[ $field ] ) || array() === $spare ) {
				continue;
			}

			$control = array_shift( $spare );

			if ( $control instanceof DOMElement ) {
				$taken[ $field ] = true;
				$control->setAttribute( 'name', 'qsoft_' . $field );
			}
		}
	}

	/**
	 * Whether one form is the site's search.
	 *
	 * @param DOMXPath   $xpath Document xpath.
	 * @param DOMElement $form  The form.
	 * @return bool
	 */
	private static function is_search_form( DOMXPath $xpath, DOMElement $form ): bool {
		if ( 'search' === strtolower( $form->getAttribute( 'role' ) ) ) {
			return true;
		}

		if ( 1 === preg_match( '/\bsearch\b/i', $form->getAttribute( 'class' ) . ' ' . $form->getAttribute( 'id' ) ) ) {
			return true;
		}

		$search = $xpath->query( './/input[@type="search"]', $form );

		return false !== $search && $search->length > 0;
	}

	/**
	 * Put a token where a field's value belongs.
	 *
	 * @param DOMNode              $root  Where the field's path starts.
	 * @param array<string, mixed> $field The field.
	 * @param string               $scope Empty for the section, "row" inside a repeat.
	 * @return void
	 */
	private static function plant( DOMNode $root, array $field, string $scope ): void {
		$node = SectionPlan::at( $root, (string) ( $field['path'] ?? '' ) );

		if ( ! $node instanceof DOMElement ) {
			return;
		}

		$name = (string) ( $field['name'] ?? '' );
		$type = (string) ( $field['type'] ?? 'text' );

		/*
		 * A value that lives in an attribute — a card's `data-no`, drawn by
		 * the stylesheet — is written back into that attribute, and the
		 * element is otherwise left alone.
		 */
		if ( ! empty( $field['attr'] ) ) {
			$node->setAttribute( (string) $field['attr'], self::token( ( '' !== $scope ? $scope . '.' : '' ) . 'attr.' . $name ) );

			return;
		}

		/*
		 * A copyright line is the one piece of copy that must not be frozen.
		 * The design says "© 2026" as literal text; wrapped verbatim, every
		 * site built from this archive is wrong from the next New Year and
		 * nobody notices for eleven months. The words stay editable — the year
		 * inside them is read from the clock at render time.
		 */
		if ( in_array( $type, array( 'text', 'textarea' ), true ) && self::is_dated( $node->textContent ) ) {
			$type = 'dated';
		}

		/*
		 * Words with the designer's own markup still in them. The field type
		 * stays what it was for ACF; only the reading changes, from escaping
		 * the string to allowing the inline tags through.
		 */
		if ( in_array( $type, array( 'text', 'textarea' ), true ) && ! empty( $field['rich'] ) ) {
			$type = 'rich';
		}

		$token = self::token( ( '' !== $scope ? $scope . '.' : '' ) . $type . '.' . $name );

		/*
		 * Where this field is on the canvas. The editor reads the marks to
		 * offer the element itself for editing — click the heading, type —
		 * and the front end strips them; see DesignField::unmarked().
		 *
		 * A listing's rows are records and their fields are not marked: what
		 * the card shows is edited on the record, not on the page.
		 */
		if ( 'row' !== $scope || 'listing' !== self::$kind ) {
			$node->setAttribute( 'data-qs-field', $name );
			$node->setAttribute( 'data-qs-type', $type );
		}

		if ( 'image' === $type ) {
			$node->setAttribute( 'src', $token );
			$node->setAttribute( 'alt', self::token( ( '' !== $scope ? $scope . '.' : '' ) . 'alt.' . $name ) );

			/*
			 * A `<picture>` shows its `<source>` before its `<img>`, so with
			 * the sources left in place a replaced picture changed the `src`
			 * and the browser went on drawing the archive's own file. With
			 * the image a field, the sources go: the field is the picture.
			 */
			$picture = null;

			for ( $up = $node->parentNode; $up instanceof DOMElement; $up = $up->parentNode ) {
				if ( 'picture' === strtolower( $up->tagName ) ) {
					$picture = $up;

					break;
				}
			}

			if ( $picture instanceof DOMElement ) {
				/*
				 * libxml parses HTML 4, to which `<source>` is not a void
				 * element, so the `<img>` arrives nested inside the last
				 * `<source>` rather than beside it. It is lifted out first, or
				 * removing the sources would remove the picture with them.
				 */
				$picture->appendChild( $node );

				foreach ( iterator_to_array( $picture->getElementsByTagName( 'source' ) ) as $source ) {
					if ( $source->parentNode instanceof DOMNode ) {
						$source->parentNode->removeChild( $source );
					}
				}
			}

			return;
		}

		if ( 'link' === $type ) {
			$node->setAttribute( 'href', self::token( ( '' !== $scope ? $scope . '.' : '' ) . 'url.' . $name ) );

			/*
			 * A link built out of elements keeps them.
			 *
			 * Replacing what a node holds is right for a link whose content is
			 * words. It is wrong for one built out of markup: the brand in
			 * this design is an anchor wrapping a `.brand-mark` badge and a
			 * `.brand-text` wordmark, and wiping its children to write a text
			 * field took both away — the stylesheet still had rules for them
			 * and nothing left to apply them to. The address stays editable;
			 * the structure stays the designer's.
			 *
			 * The words it has of its own stay editable too. A button that is
			 * "Discuss an assessment" followed by an arrow icon used to keep
			 * its words frozen in the template while its field carried a
			 * title nothing read; now the field's title goes where the words
			 * were, and the icon stays where it was.
			 */
			$built = false;
			$words = '' !== trim( self::own_words( $node ) );

			foreach ( $node->childNodes as $child ) {
				if ( ! $child instanceof DOMElement ) {
					continue;
				}

				$built = true;

				// A child that says something is part of the words; the structure keeps them all.
				if ( '' !== trim( $child->textContent ) ) {
					$words = false;
				}
			}

			if ( $built ) {
				if ( $words ) {
					self::plant_in_text( $node, $token );
				}

				return;
			}
		}

		/*
		 * Words beside decoration — an icon, a picture — keep the decoration
		 * where it was and take the field where the words were.
		 */
		if ( ! empty( $field['words'] ) ) {
			self::plant_in_text( $node, $token );

			return;
		}

		/*
		 * Replace what the node holds, not the node. The element carries the
		 * design's classes and the stylesheet is aimed at them, so the tag and
		 * its attributes have to survive; only the words inside it become a
		 * field.
		 */
		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}

		$node->appendChild( $node->ownerDocument->createTextNode( $token ) );
	}

	/**
	 * The text an element holds directly, ignoring what its children say.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private static function own_words( DOMElement $node ): string {
		$text = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= (string) $child->nodeValue;
			}
		}

		return $text;
	}

	/**
	 * Put a token where an element's own words are, leaving its children alone.
	 *
	 * The longest run of text the element holds directly is taken to be its
	 * words; every other text node of its own is dropped, so the field is
	 * the one place the words come from.
	 *
	 * @param DOMElement $node  Element.
	 * @param string     $token What to write.
	 * @return void
	 */
	private static function plant_in_text( DOMElement $node, string $token ): void {
		$best = null;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' !== trim( (string) $child->nodeValue ) ) {
				if ( null === $best || strlen( trim( (string) $child->nodeValue ) ) > strlen( trim( (string) $best->nodeValue ) ) ) {
					$best = $child;
				}
			}
		}

		if ( null === $best ) {
			$node->appendChild( $node->ownerDocument->createTextNode( $token ) );

			return;
		}

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && $child !== $best && '' !== trim( (string) $child->nodeValue ) ) {
				$node->removeChild( $child );
			}
		}

		/*
		 * The surrounding whitespace survives, so an icon that was set off by
		 * a space stays set off by one.
		 */
		$text  = (string) $best->nodeValue;
		$lead  = (string) substr( $text, 0, strlen( $text ) - strlen( ltrim( $text ) ) );
		$trail = (string) substr( $text, strlen( rtrim( $text ) ) );

		$best->nodeValue = $lead . $token . $trail;
	}

	/**
	 * The sub-field a collapsed repeater row should show in its header.
	 *
	 * Whatever reads best as a name for the row: a heading if the row has one,
	 * then any other words, and a link only when the row is nothing but links
	 * — a link's own title is the words on it, which is exactly what a
	 * navigation row wants shown.
	 *
	 * @param array<int, array<string, mixed>> $rows The row's fields, as ACF wants them.
	 * @return string A field key, or empty when there is nothing worth showing.
	 */
	private static function row_summary( array $rows ): string {
		$preferred = array( 'text', 'textarea', 'link' );

		foreach ( $preferred as $type ) {
			foreach ( $rows as $field ) {
				if ( ( $field['type'] ?? '' ) !== $type ) {
					continue;
				}

				$name = (string) ( $field['name'] ?? '' );

				// A heading is the row's name when it has one, whatever it is called.
				if ( 'text' === $type && ! preg_match( '/head|title|name|label/i', $name ) ) {
					continue;
				}

				return (string) ( $field['key'] ?? '' );
			}
		}

		foreach ( $rows as $field ) {
			if ( in_array( (string) ( $field['type'] ?? '' ), $preferred, true ) ) {
				return (string) ( $field['key'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Whether a line is a copyright notice whose year the clock should own.
	 *
	 * A frozen year is the obvious case. The other one is a design that fills
	 * its year in the browser — `© <span id="year"></span> Name` — where
	 * wrapping keeps the words, drops the empty span, and leaves "©  Name" on
	 * every page for good. Both want the same treatment, so the copyright mark
	 * is what this asks for; {@see DesignField::dated()} replaces a year when
	 * there is one and writes it in when there is not.
	 *
	 * @param string $text What the node says.
	 * @return bool
	 */
	private static function is_dated( string $text ): bool {
		return 1 === preg_match( '/©|&copy;|\bcopyright\b/i', $text );
	}

	/**
	 * Leave one row where the design drew several.
	 *
	 * @param DOMNode $body     Section root.
	 * @param string  $holds    Path to whatever holds the rows.
	 * @param string  $selector The rows' signature, so only rows are removed.
	 * @return void
	 */
	private static function keep_first_row( DOMNode $body, string $holds, string $selector = '' ): void {
		$holder = SectionPlan::at( $body, $holds );

		if ( ! $holder instanceof DOMElement ) {
			return;
		}

		$seen = false;

		/*
		 * Only the rows go. The holder is not always rows and nothing else —
		 * a grid's heading or its "view all" link can sit beside the cards —
		 * and deleting every element but the first took those with it, or,
		 * when the cards came after them, took every card and kept the
		 * heading as the "row".
		 */
		foreach ( iterator_to_array( $holder->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement || ! SectionPlan::is_row( $child, $selector ) ) {
				continue;
			}

			if ( ! $seen ) {
				$seen = true;

				continue;
			}

			$holder->removeChild( $child );
		}
	}

	/**
	 * Put the loop markers around the one row that was kept.
	 *
	 * @param DOMElement                       $row    The row.
	 * @param string                           $kind   single, repeat or listing.
	 * @param array<int, array<string, mixed>> $fields What one row holds.
	 * @return void
	 */
	private static function wrap_row( DOMElement $row, string $kind, array $fields ): void {
		/*
		 * A listing loop has to know the row's field names, because the posts
		 * it reads have to be handed back under those names for the markup to
		 * find them. They travel in the token rather than being looked up
		 * later: by the time the PHP is written the plan is out of reach.
		 */
		$names = array();

		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			$type = (string) ( $field['type'] ?? 'text' );

			if ( '' !== $name ) {
				$names[] = $type . '=' . $name;
			}
		}

		$open = 'listing' === $kind
			? self::token( 'loop.listing.' . implode( ',', $names ) )
			: self::token( 'loop.repeat' );

		$close = self::token( 'endloop' );
		$doc   = $row->ownerDocument;

		/*
		 * Which row this is, for the canvas. The editor reads the mark to
		 * offer "add a row" and "remove this row" on the section itself, and
		 * to know which row's fields a click landed in.
		 */
		if ( 'listing' !== $kind ) {
			$row->setAttribute( 'data-qs-row', 'items' );
			$row->setAttribute( 'data-qs-index', self::token( 'index.items' ) );
		}

		$row->parentNode->insertBefore( $doc->createTextNode( $open ), $row );

		if ( $row->nextSibling ) {
			$row->parentNode->insertBefore( $doc->createTextNode( $close ), $row->nextSibling );

			return;
		}

		$row->parentNode->appendChild( $doc->createTextNode( $close ) );
	}

	/**
	 * Swap every token for the PHP that fills it, and escape as we go.
	 *
	 * @param string $markup Serialised markup with tokens in it.
	 * @return string PHP.
	 */
	private static function to_php( string $markup ): string {
		$head = "<?php\n"
			. "/**\n"
			. " * A section of the design, rendered as the design wrote it.\n"
			. " *\n"
			. " * Generated by the importer. The markup below is the archive's own; the\n"
			. " * only additions are the field values. Editing it by hand is allowed and\n"
			. " * survives, because nothing regenerates a block that already exists.\n"
			. " *\n"
			. " * @package Qwerty\\Soft\n"
			. " * @license GPL-2.0-or-later\n"
			. " */\n\n"
			. "declare( strict_types = 1 );\n\n"
			. "defined( 'ABSPATH' ) || exit;\n\n";

		/*
		 * Every character in a token is one a URI may hold unencoded. That is
		 * not decoration: `href` and `src` are URI attributes, and the
		 * serialiser percent-encodes anything in them that a URI may not hold
		 * — which turned the first attempt's braces into `%7B%7B` and left the
		 * link and the image with a literal token for an address.
		 *
		 * The body may still come back entity-escaped from a text node, so it
		 * is decoded before it is read.
		 */
		$body = preg_replace_callback(
			'/~' . preg_quote( self::$marker, '/' ) . '\.([^~]+)~/',
			static function ( array $found ): string {
				return self::php_for( html_entity_decode( (string) $found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			},
			$markup
		);

		/*
		 * The one place a wrapped section holds something other than its own
		 * markup. The header keeps its brand, its call to action and its
		 * classes; the list of pages inside it becomes a real menu, so adding
		 * a page does not also mean remembering to edit a block.
		 *
		 * `wp_nav_menu`-style output would lose the design's own wrapper, so
		 * the navigation block is rendered in place of what the design drew
		 * and the surrounding element keeps styling it.
		 */
		if ( self::$menu ) {
			/*
			 * Looked up when the page is drawn, never written in.
			 *
			 * A generated header is written once and then left alone, while
			 * every rebuild makes a fresh navigation post — so a header built
			 * on Monday held the id of a menu deleted on Tuesday, and the top
			 * of the site drew an empty list. The helper reads whichever menu
			 * the site has now.
			 */
			$body = str_replace(
				self::MENU_MARK,
				'<?php echo do_blocks( \\Qwerty\\Soft\\Support\\DesignField::menu() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered block markup. ?>',
				(string) $body
			);
		}

		/*
		 * The other place a wrapped section holds something that is not its
		 * own markup. The design's form keeps every class it was drawn with
		 * and gains the half a form needs in order to send anything: where it
		 * posts, and the hidden fields the handler reads.
		 */
		if ( self::$forms ) {
			$body = str_replace(
				self::FORM_MARK,
				'<?php \\Qwerty\\Soft\\Support\\DesignForm::fields(); ?>',
				(string) $body
			);

			$body = str_replace(
				self::FORM_ACTION,
				'<?php echo esc_url( \\Qwerty\\Soft\\Support\\DesignForm::action() ); ?>',
				(string) $body
			);
		}

		return $head . '?>' . "\n" . trim( (string) $body ) . "\n";
	}

	/**
	 * The PHP one token becomes.
	 *
	 * @param string $token What was between the markers.
	 * @return string PHP, ready to sit in markup.
	 */
	private static function php_for( string $token ): string {
		$parts = explode( '.', $token );
		$scope = 'row' === ( $parts[0] ?? '' ) ? array_shift( $parts ) : '';
		$kind  = (string) ( $parts[0] ?? '' );
		$name  = (string) ( $parts[1] ?? '' );

		// Whether this block's values belong to the site rather than to the block.
		$site = 'option' === self::$scope ? 'true' : 'false';

		if ( 'loop' === $kind && 'repeat' === $name ) {
			return '<?php foreach ( \Qwerty\Soft\Support\DesignField::rows( ' . "'items'" . ', $block ?? null, ' . $site . ' ) as $qsoft_i => $qsoft_row ) : ?>';
		}

		if ( 'index' === $kind ) {
			return '<?php echo (int) ( $qsoft_i ?? 0 ); ?>';
		}

		if ( 'loop' === $kind && 'listing' === $name ) {
			$shape = array();

			foreach ( explode( ',', (string) ( $parts[2] ?? '' ) ) as $pair ) {
				if ( false === strpos( $pair, '=' ) ) {
					continue;
				}

				list( $type, $field ) = explode( '=', $pair, 2 );

				$shape[] = "'" . $field . "' => '" . $type . "'";
			}

			return '<?php foreach ( \Qwerty\Soft\Support\DesignListing::rows( \Qwerty\Soft\Support\DesignField::value( '
				. "'source'" . ', $block ?? null ), (int) \Qwerty\Soft\Support\DesignField::value( ' . "'limit'" . ', $block ?? null ), array( '
				. implode( ', ', $shape )
				. ' ), ' . "'" . self::$type . "'" . ' ) as $qsoft_row ) : ?>';
		}

		if ( 'endloop' === $kind ) {
			return '<?php endforeach; ?>';
		}

		/*
		 * Read through the helper rather than through `get_field()` directly.
		 * The helper works whether or not ACF is installed: with it, the words
		 * are editable; without it they come straight back out of the block.
		 * Calling `get_field()` in the template would be a fatal error on a
		 * site that has not installed the plugin yet.
		 */
		$said = self::literal( self::$says[ $name ] ?? null );

		$reader = 'option' === self::$scope
			? "\\Qwerty\\Soft\\Support\\DesignField::site( '" . self::stored_name( $name ) . "', " . $said . ' )'
			: "\\Qwerty\\Soft\\Support\\DesignField::value( '" . $name . "', \$block ?? null, " . $said . ' )';

		$value = 'row' === $scope
			? "\$qsoft_row['" . $name . "'] ?? ''"
			: $reader;

		if ( 'image' === $kind ) {
			return '<?php echo esc_url( \\Qwerty\\Soft\\Support\\DesignField::image_url( ' . $value . ' ) ); ?>';
		}

		if ( 'alt' === $kind ) {
			return '<?php echo esc_attr( \\Qwerty\\Soft\\Support\\DesignField::image_alt( ' . $value . ' ) ); ?>';
		}

		if ( 'attr' === $kind ) {
			return '<?php echo esc_attr( (string) ( ' . $value . ' ) ); ?>';
		}

		if ( 'url' === $kind ) {
			return '<?php echo \\Qwerty\\Soft\\Support\\DesignField::url( ' . $value . ' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by url(), which leaves a relative link relative. ?>';
		}

		if ( 'link' === $kind ) {
			return '<?php echo esc_html( \\Qwerty\\Soft\\Support\\DesignField::link_text( ' . $value . ' ) ); ?>';
		}

		/*
		 * Text and textarea both come out escaped. A design's own copy is not
		 * a place that needs markup, and the one thing worse than a paragraph
		 * that cannot hold a link is a field somebody can put a script into.
		 */
		if ( 'dated' === $kind ) {
			return '<?php echo esc_html( \\Qwerty\\Soft\\Support\\DesignField::dated( ' . $value . ' ) ); ?>';
		}

		/*
		 * The one field that holds markup, and only the inline tags the
		 * designer used: a highlighted span, a line break, emphasis.
		 * `inline()` is the escaping — `wp_kses()` over that short list — so
		 * the echo is as safe as `esc_html()` while keeping what was drawn.
		 */
		if ( 'rich' === $kind ) {
			return '<?php echo \\Qwerty\\Soft\\Support\\DesignField::inline( ' . $value . ' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by inline(), which is wp_kses() over the inline tags a field may hold. ?>';
		}

		return '<?php echo esc_html( (string) ( ' . $value . ' ) ); ?>';
	}

	/**
	 * The values the design already holds, ready to become the block's content.
	 *
	 * Without this the wrapping is useless. `render.php` echoes fields, the
	 * fields start empty, and a freshly built page renders the design's frame
	 * around nothing at all — which looks far more broken than the translation
	 * it replaced. The copy the designer wrote is the starting content, and it
	 * is read back out of the same markup the template was cut from.
	 *
	 * Images come back as the archive's own `src`, because only the caller
	 * knows what that file was imported as.
	 *
	 * @param string               $html Section markup.
	 * @param array<string, mixed> $plan What SectionPlan made of it.
	 * @return array<string, mixed> Field name => value, with `items` for a repeat.
	 */
	public static function values( string $html, array $plan ): array {
		$dom = self::parse( $html );

		if ( null === $dom ) {
			return array();
		}

		$xpath = new DOMXPath( $dom );
		$body  = $xpath->query( '//body' )->item( 0 );

		if ( ! $body instanceof DOMNode ) {
			return array();
		}

		$values = array();

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			$found = self::value_of( $body, (array) $field );

			if ( null !== $found ) {
				$values[ (string) $field['name'] ] = $found;
			}
		}

		$item = $plan['item'] ?? null;

		// A listing takes its rows from the site's records, so it starts with none.
		if ( ! is_array( $item ) || 'listing' === ( $plan['kind'] ?? 'single' ) ) {
			return $values;
		}

		$holder = SectionPlan::at( $body, (string) ( $item['parent'] ?? '' ) );
		$rows   = array();

		if ( $holder instanceof DOMElement ) {
			foreach ( $holder->childNodes as $child ) {
				if ( ! $child instanceof DOMElement || ! SectionPlan::is_row( $child, (string) ( $item['selector'] ?? '' ) ) ) {
					continue;
				}

				$row = array();

				foreach ( (array) ( $item['fields'] ?? array() ) as $field ) {
					$found = self::value_of( $child, (array) $field );

					if ( null !== $found ) {
						$row[ (string) $field['name'] ] = $found;
					}
				}

				$rows[] = $row;
			}
		}

		$values['items'] = $rows;

		return $values;
	}

	/**
	 * Every row a repeat drew, whatever kind the section turned out to be.
	 *
	 * `values()` deliberately leaves a listing's rows out, because a listing's
	 * content belongs to the site's records rather than to the block. This is
	 * how those records get made in the first place: the cards the designer
	 * drew, read once, so each can become a record somebody can then add a
	 * seventh of.
	 *
	 * @param string               $html Section markup.
	 * @param array<string, mixed> $plan What SectionPlan made of it.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_of( string $html, array $plan ): array {
		$item = $plan['item'] ?? null;

		if ( ! is_array( $item ) ) {
			return array();
		}

		$dom = self::parse( $html );

		if ( null === $dom ) {
			return array();
		}

		$xpath = new DOMXPath( $dom );
		$body  = $xpath->query( '//body' )->item( 0 );

		if ( ! $body instanceof DOMNode ) {
			return array();
		}

		$holder = SectionPlan::at( $body, (string) ( $item['parent'] ?? '' ) );

		if ( ! $holder instanceof DOMElement ) {
			return array();
		}

		$rows = array();

		foreach ( $holder->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || ! SectionPlan::is_row( $child, (string) ( $item['selector'] ?? '' ) ) ) {
				continue;
			}

			$row = array();

			foreach ( (array) ( $item['fields'] ?? array() ) as $field ) {
				$found = self::value_of( $child, (array) $field );

				if ( null !== $found ) {
					$row[ (string) $field['name'] ] = $found;
				}
			}

			if ( array() !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * What one node says, in the shape its field wants.
	 *
	 * @param DOMNode              $root  Where the field's path starts.
	 * @param array<string, mixed> $field The field.
	 * @return mixed The value, or null when the node is not there.
	 */
	private static function value_of( DOMNode $root, array $field ) {
		$node = SectionPlan::at( $root, (string) ( $field['path'] ?? '' ) );

		if ( ! $node instanceof DOMElement ) {
			return null;
		}

		$type = (string) ( $field['type'] ?? 'text' );

		if ( ! empty( $field['attr'] ) ) {
			$raw = $node->getAttribute( (string) $field['attr'] );

			// An inline style is CSS and only CSS; what could never be is taken out.
			if ( 'style' === $field['attr'] ) {
				$raw = (string) preg_replace( '/expression\s*\([^)]*\)|javascript\s*:|-moz-binding\s*:[^;]*;?|behavior\s*:[^;]*;?|@import/i', '', $raw );
			}

			return $raw;
		}

		if ( 'image' === $type ) {
			return $node->getAttribute( 'src' );
		}

		if ( 'link' === $type ) {
			return array(
				'url'    => $node->getAttribute( 'href' ),

				// A link that is a card has no words of its own; its contents are fields of theirs.
				'title'  => empty( $field['open'] ) ? trim( $node->textContent ) : '',
				'target' => '',
			);
		}

		/*
		 * The words with their markup, for a field that keeps it. Reduced to
		 * the allowed tags here as well as at render time, so the stored
		 * value is already what the page will show.
		 *
		 * A copyright line is the exception, because it is not read back the
		 * same way: {@see self::plant()} renders it through `esc_html()` so
		 * that the year can be written into words rather than into markup. A
		 * design that fills its own year in the browser writes
		 * `© <span id="year"></span> Name`, and keeping that span put the span
		 * itself on the page, spelled out, under the year the theme had just
		 * filled in — "© 2026 <span id="year"></span> Chalir".
		 */
		if ( ! empty( $field['rich'] ) && ! self::is_dated( $node->textContent ) ) {
			return trim( DesignField::inline( self::inner_html( $node ) ) );
		}

		// The words beside decoration: only what the element says itself.
		if ( ! empty( $field['words'] ) ) {
			return trim( (string) preg_replace( '/\s+/u', ' ', self::own_words( $node ) ) );
		}

		return trim( $node->textContent );
	}

	/**
	 * One instance of a generated block, as it sits in a page.
	 *
	 * ACF keeps a block's content in the block comment rather than in post
	 * meta, which is what makes a design block reusable: the same block on
	 * three pages holds three different sets of words. The flat `items_0_name`
	 * spelling is ACF's own, not an invention here.
	 *
	 * @param string               $slug   Block slug.
	 * @param array<string, mixed> $plan   What SectionPlan made of it.
	 * @param array<string, mixed> $values What values() found, with images resolved.
	 * @return string Block markup.
	 */
	public static function instance( string $slug, array $plan, array $values ): string {
		$slug  = self::slug( $slug );
		$scope = str_replace( '-', '_', $slug );
		$data  = array();

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name || ! array_key_exists( $name, $values ) ) {
				continue;
			}

			$data[ $name ]       = $values[ $name ];
			$data[ '_' . $name ] = 'field_qs_' . $scope . '_' . $name;
		}

		$rows = isset( $values['items'] ) && is_array( $values['items'] ) ? $values['items'] : array();
		$item = $plan['item'] ?? null;

		/*
		 * A listing shows as many records as the design showed cards. The
		 * field's default says the same number, but a default only reaches
		 * the page when ACF is asked in a context that loads it — rendered
		 * without, `limit` came back empty, fell to the runtime fallback of
		 * three, and a twelve-card catalogue drew a quarter of itself.
		 */
		if ( is_array( $item ) && 'listing' === ( $plan['kind'] ?? 'single' ) && (int) ( $item['count'] ?? 0 ) > 0 ) {
			$data['limit']  = (int) $item['count'];
			$data['_limit'] = 'field_qs_' . $scope . '_limit';
		}

		if ( is_array( $item ) && 'listing' !== ( $plan['kind'] ?? 'single' ) ) {
			$data['items']  = count( $rows );
			$data['_items'] = 'field_qs_' . $scope . '_items';

			foreach ( $rows as $index => $row ) {
				foreach ( (array) $row as $name => $value ) {
					$data[ 'items_' . $index . '_' . $name ]  = $value;
					$data[ '_items_' . $index . '_' . $name ] = 'field_qs_' . $scope . '_row_' . $name;
				}
			}
		}

		$attributes = array(
			'name' => 'qs/design-' . $slug,
			'data' => $data,
			'mode' => 'preview',
		);

		/*
		 * Encoded by WordPress's own serialiser where there is one, and not by
		 * a plain json_encode(), because the difference is a correctness bug
		 * rather than a style preference: a block comment ends at `-->`, and a
		 * value holding two hyphens — a date range, an em-dash written as
		 * `--`, a CSS custom property — would close it early and spill the
		 * rest of the JSON onto the page as text. `serialize_block_attributes()`
		 * escapes those, along with `<`, `>` and `&`.
		 */
		if ( function_exists( 'serialize_block_attributes' ) ) {
			$encoded = serialize_block_attributes( $attributes );
		} else {
			/*
			 * The same escaping by hand, because the fallback has to be as
			 * correct as the thing it stands in for. A value holding two
			 * hyphens closes the comment early and spills the rest of the JSON
			 * onto the page as text — a date range or an em-dash typed as `--`
			 * is enough — so it cannot be left to whichever function happens to
			 * exist.
			 */
			$encoded = (string) wp_json_encode( $attributes, JSON_HEX_TAG | JSON_HEX_AMP );

			/*
			 * Only the hyphens. `json_encode()` already escapes a forward
			 * slash, and escaping it a second time turned `qs/design-x` into
			 * `qs\\/design-x` — a literal backslash in the block's own name.
			 */
			$encoded = (string) str_replace( '--', '\\u002d\\u002d', $encoded );
		}

		return '<!-- wp:qs/design-' . $slug . ' ' . $encoded . ' /-->';
	}

	/**
	 * The option rows a chrome block's values become.
	 *
	 * ACF keeps an option as two rows: the value under `options_{name}`, and
	 * the field key it belongs to under `_options_{name}`. Writing both by hand
	 * rather than calling `update_field()` is deliberate — a build runs on
	 * cron, and requiring ACF to be loaded at that moment would make the
	 * footer's words depend on which request happened to seed them.
	 *
	 * @param string               $slug   Block slug.
	 * @param array<string, mixed> $plan   What SectionPlan made of it.
	 * @param array<string, mixed> $values What values() found, with images resolved.
	 * @return array<string, mixed> Option name => value.
	 */
	public static function option_values( string $slug, array $plan, array $values ): array {
		$scope = str_replace( '-', '_', self::slug( $slug ) );
		$rows  = array();

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name || ! array_key_exists( $name, $values ) ) {
				continue;
			}

			$stored = self::stored_name( $name, $slug );

			$rows[ 'options_' . $stored ]  = $values[ $name ];
			$rows[ '_options_' . $stored ] = 'field_qs_' . $scope . '_' . $name;
		}

		$item  = $plan['item'] ?? null;
		$items = isset( $values['items'] ) && is_array( $values['items'] ) ? $values['items'] : array();

		if ( ! is_array( $item ) || 'listing' === ( $plan['kind'] ?? 'single' ) ) {
			return $rows;
		}

		$rows['options_items']  = count( $items );
		$rows['_options_items'] = 'field_qs_' . $scope . '_items';

		foreach ( $items as $index => $row ) {
			foreach ( (array) $row as $name => $value ) {
				$rows[ 'options_items_' . $index . '_' . $name ]  = $value;
				$rows[ '_options_items_' . $index . '_' . $name ] = 'field_qs_' . $scope . '_row_' . $name;
			}
		}

		return $rows;
	}

	/**
	 * Parse a fragment without letting libxml complain about HTML5.
	 *
	 * @param string $html Markup.
	 * @return DOMDocument|null
	 */
	private static function parse( string $html ) {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$ok = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>',
			LIBXML_NOWARNING | LIBXML_NOERROR
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? $dom : null;
	}

	/**
	 * The class a section's own root carries, so its own stylesheet can reach it.
	 *
	 * A design's heading colour is usually never set on the heading itself —
	 * `.hero{color:var(--ink)}` and a plain `.hero h1` for size, trusting
	 * inheritance the way the archive's own page did. The theme sets its own
	 * `h1,h2,h3,h4,h5,h6{color:var(--wp--preset--color--contrast)}` globally
	 * (theme.json's `elements.heading`), at the same specificity, and it loads
	 * after a block's own stylesheet — so on every heading that leans on
	 * inheritance instead of stating its own colour, the theme's rule wins the
	 * cascade and the design's colour never renders. This mark gives
	 * `write_canonical()` a selector one class more specific than the theme's,
	 * so a section's own headings answer to the section, not to the theme.
	 *
	 * @param DOMNode $body The parsed section's containing body.
	 * @return void
	 */
	private static function mark_root( DOMNode $body ): void {
		foreach ( $body->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$classes   = preg_split( '/\s+/', trim( $child->getAttribute( 'class' ) ) );
			$classes   = array_filter( (array) $classes, static fn( $name ) => '' !== $name );
			$classes[] = self::ROOT_CLASS;

			$child->setAttribute( 'class', implode( ' ', array_unique( $classes ) ) );
		}
	}

	/**
	 * What a node holds, as markup.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function inner_html( DOMNode $node ): string {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			$html .= (string) $node->ownerDocument->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * A directory name that is safe on every filesystem and short enough for Windows.
	 *
	 * @param string $slug Proposed name.
	 * @return string
	 */
	public static function slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( $slug, '-' );

		if ( strlen( $slug ) > self::SLUG_LIMIT ) {
			$slug = trim( substr( $slug, 0, self::SLUG_LIMIT ), '-' );
		}

		return $slug;
	}
}
