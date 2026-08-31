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
	private const ROOT_CLASS = 'qs-design';

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
	 */
	public const VERSION = 7;

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
	 * @param string               $css    The rules this section needs, lifted unchanged.
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
		self::$type   = '' !== trim( $singular ) ? DesignType::key( $singular ) : '';

		if ( '' === $slug ) {
			return null;
		}

		$render = self::render_php( $html, $plan, self::$scope );

		if ( null === $render ) {
			return null;
		}

		$styled = '' !== trim( $css );

		$files = array(
			'block.json'  => self::block_json( $slug, $title, $styled, $origin, $plan, self::$says ),
			'fields.json' => self::fields_json( $slug, $title, $plan, $html ),
			'render.php'  => $render,
		);

		if ( $styled ) {
			$files['style.css'] = self::style_css( $title, $css );
		}

		/*
		 * A listing needs somewhere for its records to live. Described beside
		 * the block that reads them, so that the type goes when the import
		 * does and a theme update never touches it.
		 */
		if ( 'listing' === ( $plan['kind'] ?? '' ) && '' !== trim( $singular ) ) {
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

		return $files;
	}

	/**
	 * The block's manifest.
	 *
	 * @param string               $slug   Block slug.
	 * @param string               $title  Inserter title.
	 * @param bool                 $styled Whether a stylesheet was written beside it.
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
			$json['style'] = 'file:./style.css';
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
		}

		return $plan;
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
	 * Write the design's stylesheet and script once, beside the blocks.
	 *
	 * A design ships one stylesheet and one script, written against a whole
	 * page. Slicing either per section re-runs it in whatever order a page's
	 * blocks happen to load — for CSS that lets a duplicate rule from another
	 * page of the archive win the cascade, and for JS it means a handler
	 * querying for a button that lives in a different block and silently
	 * finding nothing. So both are kept whole and named by every generated
	 * `block.json`, which leaves per-block loading intact: a page with no
	 * imported section on it still fetches neither.
	 *
	 * @param string $css The design's stylesheet, already rewritten.
	 * @param string $js  The design's scripts, concatenated.
	 * @return array{css:int,js:int} Bytes written.
	 */
	public static function write_canonical( string $css, string $js ): array {
		$dir     = self::dir();
		$written = array(
			'css' => 0,
			'js'  => 0,
		);

		if ( ! wp_mkdir_p( $dir ) ) {
			return $written;
		}

		$css = trim( $css );

		if ( '' !== $css ) {
			$sheet = $css . "\n\n"
				. "/*\n"
				. " * The theme sets `h1,h2,h3,h4,h5,h6{color:var(--wp--preset--color--contrast)}`\n"
				. " * globally (theme.json's `elements.heading`) and loads it after this\n"
				. " * stylesheet, so a heading above that leans on inheritance for its\n"
				. " * colour instead of stating one loses the cascade to the theme's rule.\n"
				. " * One class more specific than the theme's bare tag selector settles\n"
				. " * it in the section's favour, on `render.php`'s own root — see\n"
				. " * `BlockWriter::mark_root()`.\n"
				. " */\n"
				. '.' . self::ROOT_CLASS . " h1,\n"
				. '.' . self::ROOT_CLASS . " h2,\n"
				. '.' . self::ROOT_CLASS . " h3,\n"
				. '.' . self::ROOT_CLASS . " h4,\n"
				. '.' . self::ROOT_CLASS . " h5,\n"
				. '.' . self::ROOT_CLASS . " h6 {\n"
				. "\tcolor: inherit;\n"
				. "}\n";

			$written['css'] = (int) file_put_contents( $dir . '/_canonical.css', $sheet );
		}

		$js = trim( $js );

		if ( '' !== $js ) {
			$written['js'] = (int) file_put_contents( $dir . '/_canonical.js', $js . "\n" );
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
		$fields = self::side_by_side( $fields );

		$item = $plan['item'] ?? null;

		if ( is_array( $item ) && 'listing' !== ( $plan['kind'] ?? 'single' ) ) {
			$rows = array();

			foreach ( (array) ( $item['fields'] ?? array() ) as $field ) {
				$rows[] = self::acf_field( $slug . '_row', (array) $field, $rows_say, true );
			}

			$rows = self::disambiguate_labels( $rows );
			$rows = self::side_by_side( $rows );

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

		$group = array(
			'key'      => $key,
			'title'    => '' !== trim( $title ) ? $title : $slug,
			'fields'   => $fields,
			'location' => $location,
			'active'   => true,
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
	 * The stylesheet, with a line saying where it came from.
	 *
	 * @param string $title What the section is.
	 * @param string $css   The rules, unchanged.
	 * @return string CSS.
	 */
	public static function style_css( string $title, string $css ): string {
		return "/*\n * " . str_replace( '*/', '', $title ) . "\n *\n"
			. " * Lifted from the design's own stylesheet, unchanged. The selectors\n"
			. " * match the class names on render.php because both came out of the\n"
			. " * same archive; do not rename either without renaming the other.\n */\n\n"
			. trim( $css ) . "\n\n"
			. "/*\n"
			. " * The theme sets `h1,h2,h3,h4,h5,h6{color:var(--wp--preset--color--contrast)}`\n"
			. " * globally (theme.json's `elements.heading`) and loads it after this\n"
			. " * stylesheet, so a heading above that leans on inheritance for its\n"
			. " * colour instead of stating one loses the cascade to the theme's rule.\n"
			. " * One class more specific than the theme's bare tag selector settles\n"
			. " * it in the section's favour, on `render.php`'s own root — see\n"
			. " * `BlockWriter::mark_root()`.\n"
			. " */\n"
			. '.' . self::ROOT_CLASS . " h1,\n"
			. '.' . self::ROOT_CLASS . " h2,\n"
			. '.' . self::ROOT_CLASS . " h3,\n"
			. '.' . self::ROOT_CLASS . " h4,\n"
			. '.' . self::ROOT_CLASS . " h5,\n"
			. '.' . self::ROOT_CLASS . " h6 {\n"
			. "\tcolor: inherit;\n"
			. "}\n";
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

		// The rows the design drew are one row repeated; keep the first, drop the rest.
		if ( is_array( $item ) && isset( $item['parent'], $item['path'] ) ) {
			self::keep_first_row( $body, (string) $item['parent'] );
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

				self::wrap_row( $row, $kind, (array) ( $item['fields'] ?? array() ) );
			}
		}

		return self::to_php( self::inner_html( $body ) );
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
		 * A copyright line is the one piece of copy that must not be frozen.
		 * The design says "© 2026" as literal text; wrapped verbatim, every
		 * site built from this archive is wrong from the next New Year and
		 * nobody notices for eleven months. The words stay editable — the year
		 * inside them is read from the clock at render time.
		 */
		if ( in_array( $type, array( 'text', 'textarea' ), true ) && self::is_dated( $node->textContent ) ) {
			$type = 'dated';
		}

		$token = self::token( ( '' !== $scope ? $scope . '.' : '' ) . $type . '.' . $name );

		if ( 'image' === $type ) {
			$node->setAttribute( 'src', $token );
			$node->setAttribute( 'alt', self::token( ( '' !== $scope ? $scope . '.' : '' ) . 'alt.' . $name ) );

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
			 */
			foreach ( $node->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					return;
				}
			}
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
	 * @param DOMNode $body  Section root.
	 * @param string  $holds  Path to whatever holds the rows.
	 * @return void
	 */
	private static function keep_first_row( DOMNode $body, string $holds ): void {
		$holder = SectionPlan::at( $body, $holds );

		if ( ! $holder instanceof DOMElement ) {
			return;
		}

		$seen = false;

		foreach ( iterator_to_array( $holder->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
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
			return '<?php foreach ( \Qwerty\Soft\Support\DesignField::rows( ' . "'items'" . ', $block ?? null, ' . $site . ' ) as $qsoft_row ) : ?>';
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
				if ( ! $child instanceof DOMElement ) {
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
			if ( ! $child instanceof DOMElement ) {
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

		if ( 'image' === $type ) {
			return $node->getAttribute( 'src' );
		}

		if ( 'link' === $type ) {
			return array(
				'url'    => $node->getAttribute( 'href' ),
				'title'  => trim( $node->textContent ),
				'target' => '',
			);
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
	 * `style_css()` a selector one class more specific than the theme's, so a
	 * section's own headings answer to the section, not to the theme.
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
