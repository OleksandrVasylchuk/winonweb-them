<?php
/**
 * Bootstrap for the integration tests that run against a real WordPress.
 *
 * The unit tests in tools/test-support.php cover the classes that are pure
 * functions of their input. Everything else in the theme — the contact form,
 * the importer, the SEO head — only means anything with a database and an
 * uploads folder underneath it, so these tests borrow a real install.
 *
 * Borrowing is the operative word. The install may be somebody's actual site,
 * so every test runs inside a database transaction that is always rolled
 * back, and every file it writes under uploads/ is removed afterwards. A test
 * that cannot keep that promise fails loudly rather than quietly leaving
 * something behind.
 *
 * Run: npm run test:wp
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The transaction that isolates each test is the point of this file.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Local files the tests themselves create, inspected and removed again.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Printing failed values to the terminal.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SHOW TABLE STATUS columns are named by MySQL (Name, Engine).

if ( 'cli' !== PHP_SAPI ) {
	exit( 'The integration tests run from the command line only.' );
}

/**
 * Find the WordPress install: WOW_WP_PATH first, else walk up from the theme.
 *
 * @return string Absolute directory holding wp-load.php, or empty.
 */
function wow_locate_wordpress(): string {
	$configured = getenv( 'WOW_WP_PATH' );

	if ( is_string( $configured ) && '' !== $configured ) {
		$configured = rtrim( str_replace( '\\', '/', $configured ), '/' );

		return is_file( $configured . '/wp-load.php' ) ? $configured : '';
	}

	$dir = str_replace( '\\', '/', dirname( __DIR__, 2 ) );

	for ( $i = 0; $i < 6; $i++ ) {
		if ( is_file( $dir . '/wp-load.php' ) ) {
			return $dir;
		}

		$parent = dirname( $dir );

		if ( $parent === $dir ) {
			break;
		}

		$dir = $parent;
	}

	return '';
}

$wow_wp_path = wow_locate_wordpress();

if ( '' === $wow_wp_path ) {
	echo "skipped: no WordPress found (set WOW_WP_PATH)\n";
	exit( 0 );
}

/*
 * Enough of a request for WordPress to build URLs. The host is corrected to
 * the site's own once the options table is readable.
 */
$wow_host = getenv( 'WOW_WP_HOST' );

$_SERVER['HTTP_HOST']       = is_string( $wow_host ) && '' !== $wow_host ? $wow_host : 'localhost';
$_SERVER['SERVER_NAME']     = $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own switch; it must be set before wp-load.php.
define( 'WOW_SIGNAL_TESTING', true );

require $wow_wp_path . '/wp-load.php';

$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];

// ----------------------------------------------------------------- counters

$wow_checks   = 0;
$wow_failures = 0;

/**
 * Assert a condition, counting the result.
 *
 * Same shape and output as tools/test-support.php, so the two runners read
 * the same way in a terminal.
 *
 * @param bool   $passed Whether the assertion held.
 * @param string $label  What was being asserted.
 * @param mixed  $actual Optional value to print when the assertion fails.
 * @return bool The condition, so callers can bail out of dependent checks.
 */
function wow_assert( bool $passed, string $label, $actual = null ): bool {
	++$GLOBALS['wow_checks'];

	if ( $passed ) {
		return true;
	}

	++$GLOBALS['wow_failures'];
	echo '  FAIL  ' . $label . "\n";

	if ( null !== $actual ) {
		$printed = is_scalar( $actual ) ? (string) $actual : var_export( $actual, true );
		echo '        got: ' . mb_substr( $printed, 0, 600 ) . "\n";
	}

	return false;
}

/**
 * Announce a group of checks.
 *
 * @param string $title Group name.
 * @return void
 */
function wow_group( string $title ): void {
	echo "\n=== " . $title . " ===\n";
}

/**
 * Print a line of context that is neither a pass nor a failure.
 *
 * @param string $text What to say.
 * @return void
 */
function wow_info( string $text ): void {
	echo '  INFO  ' . $text . "\n";
}

/**
 * Print a skip line: the check could not run here, which is not a failure.
 *
 * @param string $text Why.
 * @return void
 */
function wow_skip( string $text ): void {
	echo '  SKIP  ' . $text . "\n";
}

/**
 * Print the tally and exit with the right status.
 *
 * Shutdown hooks are dropped first: anything a test queued there (the contact
 * form's delete-transient, say) would otherwise run after the rollback,
 * outside the transaction, and touch the real tables.
 *
 * @return never
 */
function wow_finish(): void {
	remove_all_actions( 'shutdown' );

	printf( "\n%d checks, %d failure(s)\n", $GLOBALS['wow_checks'], $GLOBALS['wow_failures'] );

	exit( $GLOBALS['wow_failures'] > 0 ? 1 : 0 );
}

// -------------------------------------------------------------- environment

/**
 * Refuse to run unless the install is one the tests can borrow safely.
 *
 * @return void
 */
function wow_preflight(): void {
	global $wpdb;

	$theme_dir = realpath( dirname( __DIR__, 2 ) );
	$active    = realpath( get_template_directory() );

	if ( false === $theme_dir || false === $active || $theme_dir !== $active ) {
		echo "ERROR  the active theme is not this checkout.\n";
		echo '       active: ' . get_template_directory() . "\n";
		echo '       tests:  ' . dirname( __DIR__, 2 ) . "\n";
		exit( 1 );
	}

	// Rollback only works on a transactional engine; MyISAM would commit every write.
	$tables = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	$others = array();

	foreach ( (array) $tables as $table ) {
		if ( 'InnoDB' !== $table->Engine ) {
			$others[] = $table->Name . ' (' . $table->Engine . ')';
		}
	}

	if ( array() !== $others ) {
		echo "ERROR  some tables are not InnoDB, so a test could not be rolled back:\n";
		echo '       ' . implode( ', ', $others ) . "\n";
		exit( 1 );
	}

	if ( wp_using_ext_object_cache() ) {
		echo "ERROR  a persistent object cache is active; a rolled-back test would leave stale cache entries behind.\n";
		exit( 1 );
	}

	$admins = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		)
	);

	if ( array() === $admins ) {
		echo "ERROR  the site has no administrator to run the tests as.\n";
		exit( 1 );
	}

	wp_set_current_user( (int) $admins[0]->ID );
}

wow_preflight();

// -------------------------------------------------------------- isolation

/**
 * The directories under uploads/ the theme writes to during an import.
 *
 * Watched rather than the whole uploads tree, which on a real site can hold
 * tens of thousands of files.
 *
 * @return array<int, string>
 */
function wow_watched_dirs(): array {
	$uploads = wp_upload_dir();
	$base    = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' );

	return array(
		$base . '/wow-signal-designs',
		$base . '/fonts',
		rtrim( str_replace( '\\', '/', (string) $uploads['path'] ), '/' ),
	);
}

/**
 * Every file under a directory, as forward-slash absolute paths.
 *
 * @param string $dir Directory; missing is fine.
 * @return array<string, true>
 */
function wow_list_files( string $dir ): array {
	$found = array();

	if ( ! is_dir( $dir ) ) {
		return $found;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $entry ) {
		$found[ str_replace( '\\', '/', $entry->getPathname() ) ] = true;
	}

	return $found;
}

/**
 * A picture of the watched directories to compare against later.
 *
 * A directory that does not exist yet is recorded as null, so "missing" and
 * "present but empty" stay distinct: only the former is removed afterwards.
 *
 * @return array<string, array<string, true>|null>
 */
function wow_uploads_snapshot(): array {
	$snapshot = array();

	foreach ( wow_watched_dirs() as $dir ) {
		$snapshot[ $dir ] = is_dir( $dir ) ? wow_list_files( $dir ) : null;
	}

	return $snapshot;
}

/**
 * Files and directories that appeared since the snapshot.
 *
 * @param array<string, array<string, true>|null> $snapshot Earlier picture.
 * @param array<int, string>                      $ignore   Path prefixes to leave out.
 * @return array<int, string> Deepest first, so directories come after their contents.
 */
function wow_uploads_new( array $snapshot, array $ignore = array() ): array {
	$new = array();

	foreach ( $snapshot as $dir => $before ) {
		foreach ( wow_list_files( $dir ) as $path => $unused ) {
			if ( null !== $before && isset( $before[ $path ] ) ) {
				continue;
			}

			// Windows hands back both slash styles; compare on one.
			$normal = str_replace( '\\', '/', $path );

			foreach ( $ignore as $prefix ) {
				if ( str_starts_with( $normal, str_replace( '\\', '/', $prefix ) ) ) {
					continue 2;
				}
			}

			$new[] = $path;
		}

		// A watched directory that did not exist before is itself new.
		if ( null === $before && is_dir( $dir ) && ! in_array( $dir, $ignore, true ) ) {
			$new[] = $dir;
		}
	}

	return $new;
}

/**
 * Remove whatever a test left under uploads/.
 *
 * @param array<string, array<string, true>|null> $snapshot Picture taken before the test.
 * @return int How many entries were removed.
 */
function wow_uploads_cleanup( array $snapshot ): int {
	$removed = 0;

	foreach ( wow_uploads_new( $snapshot ) as $path ) {
		if ( is_dir( $path ) ) {
			wow_remove_tree( $path );
			++$removed;
		} elseif ( is_file( $path ) ) {
			unlink( $path );
			++$removed;
		}
	}

	return $removed;
}

/**
 * Delete a directory and everything in it.
 *
 * @param string $dir Absolute path.
 * @return void
 */
function wow_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	foreach ( wow_list_files( $dir ) as $path => $unused ) {
		if ( is_dir( $path ) ) {
			rmdir( $path );
		} else {
			unlink( $path );
		}
	}

	rmdir( $dir );
}

/**
 * Run one test inside a transaction that is always rolled back.
 *
 * A marker row is written at the start and checked for after the rollback: if
 * it survived, something inside the test committed — an implicit commit from
 * DDL, a stray COMMIT — and the database now holds whatever else the test did.
 * That is reported as a failure so nobody discovers it from the site instead.
 *
 * @param string   $name Test name for the report.
 * @param callable $body The test.
 * @return void
 */
function wow_test( string $name, callable $body ): void {
	global $wpdb;

	wow_group( $name );

	wp_cache_flush();

	$snapshot = wow_uploads_snapshot();
	$marker   = 'wow_signal_test_marker_' . bin2hex( random_bytes( 4 ) );

	$wpdb->query( 'START TRANSACTION' );

	try {
		add_option( $marker, '1', '', false );

		$body();
	} catch ( Throwable $e ) {
		wow_assert( false, $name . ' threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();

		$survivor = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $marker ) );

		if ( null !== $survivor ) {
			delete_option( $marker );
			wow_assert( false, $name . ': a COMMIT sneaked in — the marker row survived the rollback. Check the test for DDL or explicit commits.' );
		}

		$removed = wow_uploads_cleanup( $snapshot );

		if ( $removed > 0 ) {
			wow_info( sprintf( 'removed %d file(s)/folder(s) the test left under uploads/', $removed ) );
		}
	}
}

/**
 * Absolute path of a fixture file.
 *
 * @param string $relative Path inside tests/fixtures/.
 * @return string
 */
function wow_fixture( string $relative ): string {
	return str_replace( '\\', '/', dirname( __DIR__ ) ) . '/fixtures/' . ltrim( $relative, '/' );
}
