<?php
/**
 * What happens to a built site when something replaces the theme folder.
 *
 * A theme update deletes the theme's directory and unpacks the new package
 * over it — `Theme_Upgrader::upgrade()` passes `clear_destination => true` —
 * so `blocks/design/`, which the release ZIP deliberately does not carry, goes
 * with it. Every section of every page then reads "your site doesn't include
 * support for this block" while the words sit safely in the database.
 *
 * Two claims are under test, and they are the two halves of not losing a
 * client's site: what can be rebuilt is rebuilt without anybody asking, and
 * what cannot is said out loud and keeps being said.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Files this test creates, inspects and removes again.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Modules\BlockRecovery;
use Qwerty\Soft\Support\BlockRepair;
use Qwerty\Soft\Support\BlockWriter;
use Qwerty\Soft\Support\DesignArchive;
use Qwerty\Soft\Support\SiteAssembler;

/**
 * Every file of a generated block, by name, so a rebuild can be compared.
 *
 * @param string $dir The block's folder.
 * @return array<string, string> File name => contents.
 */
function qsoft_block_files( string $dir ): array {
	$out = array();

	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		$out[ basename( (string) $file ) ] = (string) file_get_contents( (string) $file );
	}

	ksort( $out );

	return $out;
}

/**
 * Remove a folder and everything in it.
 *
 * @param string $dir The folder.
 * @return void
 */
function qsoft_rmdir( string $dir ): void {
	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		if ( is_dir( (string) $file ) ) {
			qsoft_rmdir( (string) $file );

			continue;
		}

		unlink( (string) $file );
	}

	if ( is_dir( $dir ) ) {
		rmdir( $dir );
	}
}

/**
 * What the module would print on an admin screen.
 *
 * @param BlockRecovery $recovery The module.
 * @return string The notice's markup, or empty.
 */
function qsoft_notice( BlockRecovery $recovery ): string {
	ob_start();
	$recovery->notice();

	return (string) ob_get_clean();
}

qsoft_test(
	'a theme update takes the site\'s own sections; the next admin page puts them back',
	static function (): void {
		$recovery = new BlockRecovery();

		// ---- a built site ------------------------------------------------

		$unpacked = DesignArchive::unpack( qsoft_fixture( 'design.zip' ), 'Fixture design' );

		if ( ! qsoft_assert( is_array( $unpacked ), 'the fixture design unpacks', is_wp_error( $unpacked ) ? $unpacked->get_error_message() : $unpacked ) ) {
			return;
		}

		$root   = (string) $unpacked['path'];
		$report = SiteAssembler::build( $root, DesignArchive::index( $root ), array( 'publish' => false ) );

		if ( ! qsoft_assert( is_array( $report ), 'the fixture design builds', is_wp_error( $report ) ? $report->get_error_message() : $report ) ) {
			SiteAssembler::reset();

			return;
		}

		$made = BlockRepair::missing();

		qsoft_assert( array() === $made, 'a site straight out of a build is missing nothing', $made );

		// ---- the update ---------------------------------------------------

		$slugs = array();

		foreach ( (array) glob( BlockWriter::dir() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$slugs[] = basename( (string) $dir );
		}

		if ( ! qsoft_assert( count( $slugs ) > 1, 'the build wrote more than one block to lose', $slugs ) ) {
			SiteAssembler::reset();

			return;
		}

		/*
		 * One section, taken the way an update takes it: the folder, whole.
		 * Kept first, so the rebuild can be held against it rather than merely
		 * counted.
		 */
		$lost   = (string) $slugs[0];
		$folder = BlockWriter::dir() . '/' . $lost;
		$before = qsoft_block_files( $folder );

		qsoft_rmdir( $folder );

		qsoft_assert( ! is_dir( $folder ), 'the section\'s folder is gone, as an update leaves it' );
		qsoft_assert( in_array( $lost, BlockRepair::missing(), true ), 'and the site reports it missing', BlockRepair::missing() );

		// ---- the next admin page ------------------------------------------

		$recovery->flag();

		qsoft_assert( 1 === (int) get_option( BlockRecovery::CHECK, 0 ), 'the update raises a flag for the next admin page' );

		$recovery->recover();

		qsoft_assert( false === get_option( BlockRecovery::CHECK, false ), 'which is read once and lowered' );
		qsoft_assert( is_dir( $folder ), 'the section is rebuilt without anybody asking' );
		qsoft_assert( array() === BlockRepair::missing(), 'and nothing is left missing', BlockRepair::missing() );

		$after = qsoft_block_files( $folder );

		qsoft_assert(
			array_keys( $before ) === array_keys( $after ),
			'the rebuilt block has the same files as the one that was lost',
			array( array_keys( $before ), array_keys( $after ) )
		);

		qsoft_assert(
			( $before['render.php'] ?? '' ) === ( $after['render.php'] ?? '' ),
			'and its template is the design\'s markup again, character for character'
		);

		$said = qsoft_notice( $recovery );

		qsoft_assert( str_contains( $said, 'rebuilt' ), 'the editor is told what happened', $said );
		qsoft_assert( ! str_contains( $said, 'notice-error' ), 'and it is not an alarm: nothing is still wrong', $said );
		qsoft_assert( '' === qsoft_notice( $recovery ), 'a notice about work already done is not repeated' );

		// ---- the same update, with the design no longer on the server -----

		qsoft_rmdir( $folder );

		/*
		 * Out of the designs folder entirely, not merely renamed inside it:
		 * the repair looks for the archive by walking that folder, and a
		 * design renamed in place is still a design it can find.
		 */
		$stash = dirname( (string) DesignArchive::base_dir() ) . '/qsoft-stashed-design-' . getmypid();

		qsoft_assert( rename( $root, $stash ), 'the design can be moved off the server' );

		$recovery->flag();
		$recovery->recover();

		qsoft_assert( ! is_dir( $folder ), 'without the design there is nothing to rebuild from' );

		$stuck = get_option( BlockRecovery::REPORT, array() );

		qsoft_assert(
			is_array( $stuck ) && in_array( $lost, (array) ( $stuck['left'] ?? array() ), true ),
			'the section is recorded as still missing',
			$stuck
		);

		$alarm = qsoft_notice( $recovery );

		qsoft_assert( str_contains( $alarm, 'notice-error' ), 'the editor gets an alarm rather than a success', $alarm );
		qsoft_assert( str_contains( $alarm, 'qwerty-soft-signal-import' ), 'pointing at the screen that can fix it', $alarm );

		/*
		 * Which of the two reasons is named depends on what else this server
		 * happens to have unpacked, so the claim is that a reason is given at
		 * all rather than which one.
		 */
		qsoft_assert(
			str_contains( $alarm, 'no longer unpacked' ) || str_contains( $alarm, 'could not all be rebuilt' ),
			'and saying why it could not act rather than only that it could not',
			$alarm
		);
		qsoft_assert( '' !== qsoft_notice( $recovery ), 'and it keeps saying so, because the site is still broken' );

		// ---- put the fixture back and clean up ----------------------------

		rename( $stash, $root );
		delete_option( BlockRecovery::REPORT );

		SiteAssembler::reset();

		foreach ( $slugs as $slug ) {
			qsoft_rmdir( BlockWriter::dir() . '/' . $slug );
		}
	}
);

qsoft_test(
	'the flag is raised for this theme\'s update and for nobody else\'s',
	static function (): void {
		$recovery = new BlockRecovery();

		delete_option( BlockRecovery::CHECK );

		$recovery->after_upgrade( null, array( 'type' => 'plugin' ) );
		qsoft_assert( false === get_option( BlockRecovery::CHECK, false ), 'a plugin update is not this theme\'s business' );

		$recovery->after_upgrade(
			null,
			array(
				'type'   => 'theme',
				'themes' => array( 'twentytwentyfour' ),
			)
		);
		qsoft_assert( false === get_option( BlockRecovery::CHECK, false ), 'nor is another theme\'s' );

		$recovery->after_upgrade(
			null,
			array(
				'type'   => 'theme',
				'themes' => array( get_template() ),
			)
		);
		qsoft_assert( 1 === (int) get_option( BlockRecovery::CHECK, 0 ), 'this theme\'s update raises the flag' );

		delete_option( BlockRecovery::CHECK );

		/*
		 * A bulk update that names nothing is a shape this does not recognise,
		 * and looking costs one query where missing it costs the site.
		 */
		$recovery->after_upgrade( null, array( 'type' => 'theme' ) );
		qsoft_assert( 1 === (int) get_option( BlockRecovery::CHECK, 0 ), 'an upgrade that names no theme is looked at anyway' );

		delete_option( BlockRecovery::CHECK );
	}
);

qsoft_finish();
