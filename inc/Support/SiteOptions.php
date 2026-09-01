<?php
/**
 * The site's own words, kept once rather than per page.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The values behind the Site content screen.
 *
 * A section in the middle of a page keeps its own words, because two copies of
 * it should be able to say different things. The chrome is the opposite: the
 * footer is on every page, so the address in it is one address. Holding it with
 * the block would mean editing the same telephone number in two template parts
 * and hoping the two agree.
 *
 * ACF keeps an option as two rows — the value under `options_{name}` and the
 * field key it belongs to under `_options_{name}`. Those are written directly
 * rather than through `update_field()` because a build runs on cron, and making
 * the footer's words depend on whether ACF happened to be loaded in that
 * request would be a race with somebody's content on the losing side.
 */
final class SiteOptions {

	/**
	 * Where the list of options an import wrote is kept.
	 *
	 * Recorded rather than guessed at, so that undoing an import removes
	 * exactly what it added and nothing a person put there afterwards.
	 */
	public const REGISTER = 'qwerty_soft_design_options';

	/**
	 * Whether ACF Pro is here, which is what a wrapped import needs.
	 *
	 * Checked before a build rather than discovered after one. Without it the
	 * import still produces correct pages — the design's own markup, its own
	 * stylesheet, its words — but nothing on them can be edited, the block
	 * inserter calls every section "Unsupported", and there is no options page
	 * for the footer. That is a site somebody has to rebuild rather than a site
	 * they can work on, and finding out at the end costs the whole build.
	 *
	 * Pro specifically: blocks and the repeater are both Pro features, so the
	 * free plugin passes a `function_exists()` test and then fails at the point
	 * it is needed.
	 *
	 * @return bool
	 */
	public static function editable(): bool {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return false;
		}

		if ( defined( 'ACF_PRO' ) && ACF_PRO ) {
			return true;
		}

		/*
		 * Older releases set it as a setting rather than a constant, and a
		 * site that has one but not the other is still Pro.
		 */
		return function_exists( 'acf_get_setting' ) && (bool) acf_get_setting( 'pro' );
	}

	/**
	 * Whether any generated block put its fields on the options page.
	 *
	 * @return bool
	 */
	public static function has_fields(): bool {
		$found = glob( BlockWriter::dir() . '/*/fields.json' );

		if ( ! is_array( $found ) ) {
			return false;
		}

		foreach ( $found as $file ) {
			$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A file inside the theme, written by the importer.

			if ( false !== $raw && str_contains( $raw, BlockWriter::OPTIONS_PAGE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write the values an import read out of the design, and remember them.
	 *
	 * @param array<string, mixed> $rows Option name => value.
	 * @return void
	 */
	public static function seed( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		$written = (array) get_option( self::REGISTER, array() );

		foreach ( $rows as $name => $value ) {
			$name = (string) $name;

			/*
			 * An option that is already there is left alone. Rebuilding the
			 * same design should not undo the afternoon somebody spent
			 * rewriting the footer.
			 */
			if ( null !== get_option( $name, null ) ) {
				continue;
			}

			update_option( $name, $value, false );

			$written[] = $name;
		}

		update_option( self::REGISTER, array_values( array_unique( $written ) ), false );
	}

	/**
	 * Where the chrome these options came from stood in the archive.
	 *
	 * Kept because the design's links are relative: `about.html` written in
	 * the footer of a page inside `en/` means the English one. Rewriting those
	 * addresses to real pages at the end of a build needs to know where the
	 * footer was standing when it was written.
	 */
	public const ORIGIN = 'qwerty_soft_design_options_origin';

	/**
	 * The navigation a wrapped header should draw.
	 *
	 * Held as an option rather than written into the header, because the header
	 * is generated once and never rewritten while every rebuild makes a fresh
	 * navigation post. A baked id belonged to a menu that a later build had
	 * already deleted, and the top of the site rendered an empty list.
	 */
	public const MENU = 'qwerty_soft_design_menu';

	/**
	 * Option holding one navigation per language: language code => menu ID.
	 *
	 * The design's Russian header is not its English header translated — it
	 * even lists different pages — so each language keeps a menu of its own
	 * and the header draws the one belonging to the page it stands on.
	 *
	 * @var string
	 */
	public const MENUS = 'qwerty_soft_design_menus';

	/**
	 * Every option row an import wrote, as name => value.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$rows = array();

		foreach ( (array) get_option( self::REGISTER, array() ) as $name ) {
			if ( is_string( $name ) ) {
				$rows[ $name ] = get_option( $name, null );
			}
		}

		return $rows;
	}

	/**
	 * Delete every option an import wrote.
	 *
	 * @return int How many were removed.
	 */
	public static function reset(): int {
		$written = (array) get_option( self::REGISTER, array() );
		$removed = 0;

		foreach ( $written as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			/*
			 * Checked rather than trusted. This deletes options, and the
			 * register is a stored list — a corrupted or tampered one naming
			 * `siteurl` would take the site down, so only ACF's own two
			 * prefixes are ever removed.
			 */
			if ( ! str_starts_with( $name, 'options_' ) && ! str_starts_with( $name, '_options_' ) ) {
				continue;
			}

			delete_option( $name );
			++$removed;
		}

		delete_option( self::REGISTER );

		return $removed;
	}
}
