<?php
/**
 * Safe intake and indexing of an uploaded design archive.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Unpacks a design ZIP into a private working directory and indexes it.
 *
 * A design archive is attacker-shaped input even when it comes from a trusted
 * designer: it is a container of arbitrary paths and arbitrary bytes. Every
 * defence below exists because the naive version of this class is a remote
 * file write:
 *
 * - **Zip slip** — an entry named `../../../wp-config.php` writes outside the
 *   destination. Every path is normalised and re-checked against the root.
 * - **Zip bomb** — a few KB can expand to gigabytes. Entry count, per-file
 *   size and total uncompressed size are all capped before extraction.
 * - **Executable payloads** — `.php`, `.phtml`, `.htaccess` and friends are
 *   refused outright; only an allow-list of design assets is written.
 * - **Direct execution** — the working directory is created with its own
 *   `.htaccess` and `index.php`, so even on a misconfigured server nothing
 *   inside it can be requested over HTTP.
 *
 * The archive is *data to read*, never code to run.
 */
final class DesignArchive {

	/**
	 * Directory under wp-content/uploads that holds unpacked archives.
	 */
	private const BASE_DIR = 'wow-signal-designs';

	/**
	 * Maximum entries in one archive.
	 */
	private const MAX_ENTRIES = 3000;

	/**
	 * Maximum uncompressed size of a single file (12 MB).
	 */
	private const MAX_FILE_BYTES = 12582912;

	/**
	 * Maximum uncompressed size of the whole archive (200 MB).
	 */
	private const MAX_TOTAL_BYTES = 209715200;

	/**
	 * File extensions that may be written to disk.
	 *
	 * Everything a design legitimately contains, and nothing a server can be
	 * tricked into executing.
	 */
	private const ALLOWED_EXTENSIONS = array(
		'html',
		'htm',
		'css',
		'js',
		'mjs',
		'jsx',
		'json',
		'csv',
		'md',
		'txt',
		'xml',
		'svg',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'webp',
		'avif',
		'ico',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'eot',
	);

	/**
	 * Extensions that are refused loudly rather than silently skipped.
	 */
	private const DANGEROUS_EXTENSIONS = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phps',
		'phtml',
		'phar',
		'htaccess',
		'htpasswd',
		'ini',
		'sh',
		'bash',
		'exe',
		'dll',
		'so',
		'cgi',
		'pl',
		'py',
	);

	/**
	 * Absolute path of the private designs directory, creating it if needed.
	 *
	 * @return string|WP_Error
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'wow_signal_uploads', (string) $uploads['error'] );
		}

		$base = trailingslashit( $uploads['basedir'] ) . self::BASE_DIR;

		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'wow_signal_mkdir', __( 'Could not create the designs folder inside uploads.', 'wow-signal' ) );
		}

		self::protect( $base );

		return $base;
	}

	/**
	 * Drop guards so nothing in the directory can be fetched over HTTP.
	 *
	 * Belt and braces: the .htaccess covers Apache, the web.config covers
	 * IIS, the index.php covers servers that ignore both, and none is relied
	 * on for correctness — the extension allow-list already refuses anything
	 * executable.
	 *
	 * nginx reads neither guard file. A site on nginx needs a `location`
	 * block in its server configuration that denies this directory, for
	 * example `location ^~ /wp-content/uploads/wow-signal-designs/ { deny all; }`
	 * (adjusted to the real uploads path). See the theme documentation.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private static function protect( string $dir ): void {
		$htaccess  = trailingslashit( $dir ) . '.htaccess';
		$webconfig = trailingslashit( $dir ) . 'web.config';
		$index     = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard file must exist before WP_Filesystem is available during an upload request.
				$htaccess,
				"# Design sources are read by PHP only, never served.\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}

		if ( ! file_exists( $webconfig ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
				$webconfig,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<!-- Design sources are read by PHP only, never served. -->\n"
				. "<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n"
				. "\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n"
				. "\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n"
				. "\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n"
			);
		}

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
		}
	}

	/**
	 * Remove a partially unpacked design.
	 *
	 * Used when an archive turns out to be larger than it declared: the
	 * half-written tree must not linger in uploads, and its slug must not be
	 * offered on the designs list.
	 *
	 * @param string $root Absolute design root.
	 * @return void
	 */
	private static function discard( string $root ): void {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->delete( $root, true );
		}
	}

	/**
	 * Delete one unpacked design.
	 *
	 * The root must resolve to a directory strictly inside the designs
	 * folder; anything else — the folder itself, a path that escapes it — is
	 * refused rather than deleted.
	 *
	 * @param string $root Absolute design root.
	 * @return bool Whether it is gone.
	 */
	public static function remove( string $root ): bool {
		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			return false;
		}

		$real_base = realpath( $base );
		$real_root = realpath( $root );

		if ( false === $real_base || false === $real_root || ! is_dir( $real_root ) ) {
			return false;
		}

		$real_base = rtrim( str_replace( '\\', '/', $real_base ), '/' );
		$real_root = rtrim( str_replace( '\\', '/', $real_root ), '/' );

		if ( $real_root === $real_base || ! str_starts_with( $real_root . '/', $real_base . '/' ) ) {
			return false;
		}

		self::discard( $real_root );

		return ! is_dir( $real_root );
	}

	/**
	 * Delete every unpacked design, keeping the folder and its guard files.
	 *
	 * @return int How many designs were removed.
	 */
	public static function purge(): int {
		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			return 0;
		}

		$removed = 0;
		$entries = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );

		foreach ( $entries ? $entries : array() as $dir ) {
			if ( self::remove( $dir ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * How much disk the unpacked designs take, and how many there are.
	 *
	 * @return array{count:int,bytes:int}
	 */
	public static function footprint(): array {
		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		$entries = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );
		$bytes   = 0;

		foreach ( $entries ? $entries : array() as $dir ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += (int) $file->getSize();
				}
			}
		}

		return array(
			'count' => $entries ? count( $entries ) : 0,
			'bytes' => $bytes,
		);
	}

	/**
	 * Say why a file would not open as a ZIP, in terms the uploader can act on.
	 *
	 * "Could not be opened" sends people hunting through server settings when
	 * the answer is usually on their own disk: a RAR renamed to .zip, a
	 * download that stopped half-way, or a folder dragged onto the form. The
	 * first bytes of the file and libzip's own error code tell which.
	 *
	 * @param string $path Uploaded file.
	 * @param int    $code ZipArchive::open() error code.
	 * @return string
	 */
	private static function open_failure_message( string $path, int $code ): string {
		$size = is_readable( $path ) ? (int) filesize( $path ) : 0;
		$head = $size > 0 ? (string) file_get_contents( $path, false, null, 0, 8 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Eight bytes of a temp upload, for a diagnostic only.

		if ( 0 === $size ) {
			return __( 'The uploaded file is empty (0 bytes). Zip the design folder again and upload the new file.', 'wow-signal' );
		}

		$kinds = array(
			'Rar!'       => __( 'a RAR archive', 'wow-signal' ),
			"7z\xBC\xAF" => __( 'a 7-Zip archive', 'wow-signal' ),
			"\x1F\x8B"   => __( 'a gzip/tar.gz archive', 'wow-signal' ),
			'%PDF'       => __( 'a PDF', 'wow-signal' ),
			'<!DO'       => __( 'an HTML page', 'wow-signal' ),
			'<htm'       => __( 'an HTML page', 'wow-signal' ),
			'{'          => __( 'a JSON file', 'wow-signal' ),
		);

		foreach ( $kinds as $magic => $kind ) {
			if ( str_starts_with( $head, $magic ) ) {
				return sprintf(
					/* translators: %s: what the file actually is, e.g. "a RAR archive". */
					__( 'That file is %s with a .zip name, not a ZIP archive. Create a real ZIP of the design folder (right-click → Compress / Send to → Compressed folder) and upload that.', 'wow-signal' ),
					$kind
				);
			}
		}

		if ( str_starts_with( $head, "PK\x03\x04" ) || str_starts_with( $head, "PK\x05\x06" ) ) {
			$reasons = array(
				ZipArchive::ER_INCONS => __( 'it is damaged or was not fully downloaded', 'wow-signal' ),
				ZipArchive::ER_NOZIP  => __( 'its directory is unreadable', 'wow-signal' ),
				ZipArchive::ER_MEMORY => __( 'the server ran out of memory reading it', 'wow-signal' ),
				ZipArchive::ER_OPEN   => __( 'the server could not open the temporary file', 'wow-signal' ),
				ZipArchive::ER_READ   => __( 'the server could not read the temporary file', 'wow-signal' ),
				ZipArchive::ER_SEEK   => __( 'the server could not seek in the temporary file', 'wow-signal' ),
			);

			return sprintf(
				/* translators: 1: reason, 2: libzip error code. */
				__( 'That ZIP looks right but %1$s (code %2$d). Re-zip the folder and try again; if it keeps happening, the file may use a compression PHP cannot read — choose "Deflate"/standard ZIP in your archiver.', 'wow-signal' ),
				$reasons[ $code ] ?? __( 'could not be opened', 'wow-signal' ),
				$code
			);
		}

		return sprintf(
			/* translators: 1: libzip error code, 2: file size in bytes. */
			__( 'That file could not be opened as a ZIP archive (code %1$d, %2$s bytes). It does not start like a ZIP, so it is probably not one: zip the design folder itself and upload the result.', 'wow-signal' ),
			$code,
			number_format_i18n( $size )
		);
	}

	/**
	 * Extract an uploaded ZIP into its own sub-directory.
	 *
	 * @param string $zip_path Absolute path of the uploaded temporary file.
	 * @param string $label    Human label used to derive the folder name.
	 * @return array{slug:string, path:string, files:int, bytes:int, skipped:array<int,string>}|WP_Error
	 */
	public static function unpack( string $zip_path, string $label ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'wow_signal_no_zip',
				__( 'This server has no ZIP support in PHP, so archives cannot be unpacked. Ask your host to enable the zip extension.', 'wow-signal' )
			);
		}

		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		/*
		 * The slug travels in REST URLs that accept [a-z0-9-] only.
		 * sanitize_title() percent-encodes anything non-Latin — a file called
		 * "архів.zip" became "%d0%b0…" and no route would match it again.
		 * Keep ASCII only; a name with nothing left falls back to "design".
		 */
		$slug = strtolower( remove_accents( $label ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );
		$slug = substr( $slug, 0, 60 );
		$slug = '' !== $slug ? $slug : 'design';
		$slug = $slug . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$root = trailingslashit( $base ) . $slug;

		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'wow_signal_mkdir', __( 'Could not create a folder for this design.', 'wow-signal' ) );
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path );

		if ( true !== $opened ) {
			return new WP_Error( 'wow_signal_bad_zip', self::open_failure_message( $zip_path, is_int( $opened ) ? $opened : 0 ) );
		}

		$count = $zip->numFiles;

		if ( $count > self::MAX_ENTRIES ) {
			$zip->close();

			return new WP_Error(
				'wow_signal_too_many',
				sprintf(
					/* translators: %d: maximum number of files. */
					__( 'That archive holds more than %d files. Send the design folder on its own, without build output or dependencies.', 'wow-signal' ),
					self::MAX_ENTRIES
				)
			);
		}

		$real_root = realpath( $root );

		if ( false === $real_root ) {
			$zip->close();

			return new WP_Error( 'wow_signal_mkdir', __( 'Could not resolve the destination folder.', 'wow-signal' ) );
		}

		$written  = 0;
		$bytes    = 0;
		$inflated = 0;
		$skipped  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( false === $stat ) {
				continue;
			}

			$name = (string) $stat['name'];
			$size = (int) $stat['size'];

			// Directories are recreated implicitly by the file writes below.
			if ( '' === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}

			$verdict = self::vet_entry( $name, $size );

			if ( null !== $verdict ) {
				// An empty reason means "drop it quietly" — noise, not a threat.
				if ( '' !== $verdict ) {
					$skipped[] = $verdict;
				}

				continue;
			}

			$bytes += $size;

			if ( $bytes > self::MAX_TOTAL_BYTES ) {
				$zip->close();
				self::discard( $root );

				return new WP_Error(
					'wow_signal_too_big',
					__( 'That archive unpacks to more than 200 MB. Remove videos, design binaries and node_modules before sending it.', 'wow-signal' )
				);
			}

			$target = self::safe_target( $real_root, $name );

			if ( null === $target ) {
				$skipped[] = sprintf(
					/* translators: %s: entry path inside the archive. */
					__( '%s — refused, the path points outside the folder', 'wow-signal' ),
					$name
				);
				continue;
			}

			$dir = dirname( $target );

			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				$skipped[] = $name;
				continue;
			}

			$stream = $zip->getStream( $name );

			if ( ! is_resource( $stream ) ) {
				$skipped[] = $name;
				continue;
			}

			$out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming avoids loading a large asset into memory; WP_Filesystem has no streaming API.

			if ( false === $out ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with getStream().
				$skipped[] = $name;
				continue;
			}

			$copied = stream_copy_to_stream( $stream, $out, self::MAX_FILE_BYTES + 1 );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen() above.
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with getStream().

			/*
			 * The declared size is only a claim. Count what actually came out
			 * of the entry, and treat a copy that hit the per-file ceiling as
			 * truncated — a bomb declares small and inflates large.
			 */
			$copied    = false === $copied ? 0 : (int) $copied;
			$inflated += $copied;

			if ( $copied > self::MAX_FILE_BYTES || $inflated > self::MAX_TOTAL_BYTES ) {
				$zip->close();
				self::discard( $root );

				return new WP_Error(
					'wow_signal_too_big',
					__( 'That archive unpacks to more than it declares, past the size limit. Remove videos, design binaries and node_modules before sending it.', 'wow-signal' )
				);
			}

			++$written;
		}

		$zip->close();

		if ( 0 === $written ) {
			return new WP_Error(
				'wow_signal_empty',
				__( 'Nothing usable was found in that archive. It should contain the design HTML, its stylesheets and its images.', 'wow-signal' )
			);
		}

		return array(
			'slug'    => $slug,
			'path'    => $root,
			'files'   => $written,
			'bytes'   => $bytes,
			'skipped' => array_slice( $skipped, 0, 40 ),
		);
	}

	/**
	 * Decide whether one archive entry may be written.
	 *
	 * Three outcomes, and the difference matters: null means write it, an
	 * empty string means drop it without saying anything (editor noise), and a
	 * non-empty string means drop it and tell the user why. An earlier version
	 * conflated "quiet skip" with "allow" and happily unpacked a .htaccess out
	 * of the archive — hence the explicit tri-state.
	 *
	 * @param string $name Entry path inside the archive.
	 * @param int    $size Uncompressed size in bytes.
	 * @return string|null Null to write, '' to drop silently, else the reason.
	 */
	private static function vet_entry( string $name, int $size ): ?string {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$basename  = strtolower( basename( $name ) );

		// Any dot-file is dropped: .htaccess, .env, .git internals, .DS_Store.
		if ( str_starts_with( $basename, '.' ) ) {
			return '';
		}

		if ( str_contains( $name, '__MACOSX' ) || str_contains( $name, '/.git/' ) ) {
			return '';
		}

		// Dependency trees are never part of a design and blow the file cap.
		if ( preg_match( '#(^|/)(node_modules|vendor|\.next|dist/cache)(/|$)#i', $name ) ) {
			return '';
		}

		if ( in_array( $extension, self::DANGEROUS_EXTENSIONS, true ) ) {
			return sprintf(
				/* translators: %s: entry path inside the archive. */
				__( '%s — refused, executable files are never unpacked', 'wow-signal' ),
				$name
			);
		}

		if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
			return sprintf(
				/* translators: %s: entry path inside the archive. */
				__( '%s — skipped, not a design file', 'wow-signal' ),
				$name
			);
		}

		if ( $size > self::MAX_FILE_BYTES ) {
			return sprintf(
				/* translators: %s: entry path inside the archive. */
				__( '%s — skipped, larger than 12 MB', 'wow-signal' ),
				$name
			);
		}

		return null;
	}

	/**
	 * Resolve an archive entry to an absolute path inside the root.
	 *
	 * Returns null when the entry tries to escape — the zip-slip guard.
	 *
	 * @param string $real_root Canonical destination root.
	 * @param string $name      Entry path inside the archive.
	 * @return string|null
	 */
	private static function safe_target( string $real_root, string $name ): ?string {
		$normalised = str_replace( '\\', '/', $name );
		$raw        = array();

		foreach ( explode( '/', $normalised ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				// Refuse rather than silently resolving — an entry that walks
				// up is never legitimate in a design archive.
				return null;
			}

			// Windows drive prefixes and control characters.
			if ( preg_match( '#^[a-z]:$#i', $segment ) || preg_match( '#[\x00-\x1f]#', $segment ) ) {
				return null;
			}

			$raw[] = $segment;
		}

		if ( array() === $raw ) {
			return null;
		}

		/*
		 * Directory names and the file name need different treatment.
		 * sanitize_file_name() assumes it is looking at a file, so a folder
		 * honestly named "css" comes back as "unnamed-file.css" and every
		 * relative URL in the design breaks. Directories therefore get a
		 * conservative character filter, and only the last segment — the
		 * actual file — goes through the file-name sanitiser.
		 */
		$segments = array();
		$last     = count( $raw ) - 1;

		foreach ( $raw as $index => $segment ) {
			$clean = $index === $last
				? self::clean_file_name( $segment )
				: self::clean_directory_name( $segment );

			if ( '' === $clean ) {
				return null;
			}

			$segments[] = $clean;
		}

		$candidate = trailingslashit( $real_root ) . implode( '/', $segments );

		// Second, independent check: the parent must still resolve inside root.
		$parent      = dirname( $candidate );
		$real_parent = file_exists( $parent ) ? realpath( $parent ) : null;

		if ( null !== $real_parent && false !== $real_parent ) {
			$normalised_parent = str_replace( '\\', '/', $real_parent );
			$normalised_root   = str_replace( '\\', '/', $real_root );

			if ( ! str_starts_with( $normalised_parent, $normalised_root ) ) {
				return null;
			}
		}

		return $candidate;
	}

	/**
	 * Filter a directory name down to safe characters, preserving its meaning.
	 *
	 * @param string $segment Raw directory name from the archive.
	 * @return string Cleaned name, or '' when nothing usable is left.
	 */
	private static function clean_directory_name( string $segment ): string {
		$clean = preg_replace( '#[^A-Za-z0-9 _.\-]#u', '-', $segment );
		$clean = is_string( $clean ) ? $clean : '';
		$clean = trim( $clean, ". \t\n\r\0\x0B" );

		// Reserved Windows device names would make the path unusable there.
		if ( preg_match( '#^(con|prn|aux|nul|com[1-9]|lpt[1-9])$#i', $clean ) ) {
			$clean = 'dir-' . strtolower( $clean );
		}

		return substr( $clean, 0, 100 );
	}

	/**
	 * Filter a file name, keeping the extension the allow-list already vetted.
	 *
	 * The sanitize_file_name() helper is not used here either: it rewrites `page.dc.html`
	 * to `page.dc_.html` as a double-extension defence. That defence is already
	 * covered — only the final extension is ever written and it must be on the
	 * allow-list — and mangling the name breaks the links between the design's
	 * own pages.
	 *
	 * @param string $segment Raw file name from the archive.
	 * @return string Cleaned name, or '' when nothing usable is left.
	 */
	private static function clean_file_name( string $segment ): string {
		$clean = preg_replace( '#[^A-Za-z0-9 _.\-]#u', '-', $segment );
		$clean = is_string( $clean ) ? $clean : '';
		$clean = ltrim( $clean, '.' );
		$clean = trim( $clean );

		return substr( $clean, 0, 180 );
	}

	/**
	 * Strip the single wrapper folder most archives are exported with.
	 *
	 * `project/en/index.html` and `en/index.html` should be indexed the same
	 * way, or language detection misses every archive zipped from its parent.
	 *
	 * @param array<int, string> $relatives Paths relative to the design root.
	 * @return string Prefix to strip, including the trailing slash, or ''.
	 */
	private static function common_prefix( array $relatives ): string {
		$first = null;

		foreach ( $relatives as $relative ) {
			$slash = strpos( $relative, '/' );

			if ( false === $slash ) {
				// A file sits at the top level, so there is no single wrapper.
				return '';
			}

			$segment = substr( $relative, 0, $slash );

			if ( null === $first ) {
				$first = $segment;
				continue;
			}

			if ( $first !== $segment ) {
				return '';
			}
		}

		return null === $first ? '' : $first . '/';
	}

	/**
	 * Screenshots of the finished design, when the archive carries any.
	 *
	 * Claude Design exports a `screenshots/` folder beside the HTML, and a
	 * hand-built archive sometimes has one too. They are worth finding: a
	 * picture of the intended result answers questions no amount of markup
	 * can — how much air a section has, whether a rule is a divider or a
	 * highlight, which of two headings is meant to dominate.
	 *
	 * Nothing here assumes the folder exists. A design without screenshots
	 * converts exactly as it did before; it just gets less help.
	 *
	 * @param string $root Absolute path of the unpacked design.
	 * @return array<string, string> Lower-case basename without extension => absolute path.
	 */
	public static function screenshots( string $root ): array {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		if ( '' === $root || ! is_dir( $root ) ) {
			return array();
		}

		$shots = array();

		/*
		 * The folders are looked for, rather than every file in the archive
		 * being walked and filtered. A design can hold thousands of images and
		 * a build asks this question once per page; scanning all of them to
		 * find the handful in `screenshots/` is work done over and over for an
		 * answer two directory reads already have. Two levels deep covers both
		 * shapes a real archive comes in: the folder at the root, and the
		 * folder inside the single wrapper directory a ZIP usually adds.
		 */
		foreach ( self::screenshot_dirs( $root ) as $dir ) {
			$entries = glob( $dir . '/*.{png,jpg,jpeg,webp,PNG,JPG,JPEG,WEBP}', GLOB_BRACE );

			foreach ( $entries ? $entries : array() as $entry ) {
				$path = str_replace( '\\', '/', $entry );
				$key  = strtolower( pathinfo( $path, PATHINFO_FILENAME ) );

				if ( ! isset( $shots[ $key ] ) ) {
					$shots[ $key ] = $path;
				}
			}
		}

		return $shots;
	}

	/**
	 * Directories in the archive that hold screenshots rather than content.
	 *
	 * @param string $root Absolute path of the unpacked design, slashes forward.
	 * @return array<int, string>
	 */
	private static function screenshot_dirs( string $root ): array {
		$names = array( 'screenshots', 'screenshot', 'previews', 'preview', 'shots' );
		$found = array();

		$bases    = array( $root );
		$children = glob( $root . '/*', GLOB_ONLYDIR );

		foreach ( $children ? $children : array() as $child ) {
			$bases[] = str_replace( '\\', '/', $child );
		}

		foreach ( $bases as $base ) {
			foreach ( $names as $name ) {
				$dir = $base . '/' . $name;

				if ( is_dir( $dir ) ) {
					$found[] = $dir;
				}
			}
		}

		return $found;
	}

	/**
	 * The screenshot that shows one page, when there is one.
	 *
	 * Matched on the page's own file name first — `en/about.html` against
	 * `about.png` — then on the names a front page is filed under, because an
	 * archive's home screenshot is as often `home` as `index`.
	 *
	 * @param string $root Absolute path of the unpacked design.
	 * @param string $file Page file, relative to the root.
	 * @return string Absolute path, or an empty string.
	 */
	public static function screenshot_for( string $root, string $file ): string {
		$shots = self::screenshots( $root );

		if ( array() === $shots ) {
			return '';
		}

		$name = strtolower( (string) preg_replace( '/\.(dc\.)?html?$/i', '', basename( $file ) ) );

		$candidates = array( $name );

		if ( 'index' === $name || 'home' === $name ) {
			$candidates = array( 'index', 'home', 'homepage', 'front' );
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $shots[ $candidate ] ) ) {
				return $shots[ $candidate ];
			}
		}

		/*
		 * A screenshot named for the page with something appended —
		 * "about-desktop", "index@2x" — still shows the page.
		 */
		foreach ( $shots as $key => $path ) {
			if ( str_starts_with( $key, $name ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Index an unpacked design: which pages exist, and what they reference.
	 *
	 * @param string $root Absolute path of the unpacked design.
	 * @return array{pages:array<int,array<string,mixed>>, stylesheets:array<int,string>, images:int, languages:array<int,string>}
	 */
	public static function index( string $root ): array {
		$pages       = array();
		$stylesheets = array();
		$images      = 0;
		$languages   = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path      = str_replace( '\\', '/', $file->getPathname() );
			$relative  = ltrim( str_replace( str_replace( '\\', '/', $root ), '', $path ), '/' );
			$extension = strtolower( $file->getExtension() );

			if ( in_array( $extension, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg' ), true ) ) {
				++$images;
				continue;
			}

			if ( 'css' === $extension ) {
				$stylesheets[] = $relative;
				continue;
			}

			if ( in_array( $extension, array( 'html', 'htm' ), true ) ) {
				$pages[] = self::describe_page( $path, $relative );
			}
		}

		/*
		 * Language detection runs after the wrapper folder is known, so
		 * `project/en/index.html` is read the same as `en/index.html`.
		 */
		$prefix = self::common_prefix( array_column( $pages, 'file' ) );

		foreach ( $pages as $index => $page ) {
			$inner                   = '' !== $prefix ? substr( $page['file'], strlen( $prefix ) ) : $page['file'];
			$pages[ $index ]['path'] = $inner;

			if ( preg_match( '#^([a-z]{2})/#', $inner, $match ) ) {
				$languages[ $match[1] ] = true;
			}
		}

		usort(
			$pages,
			static function ( array $a, array $b ): int {
				return $b['sections'] <=> $a['sections'];
			}
		);

		return array(
			'pages'       => $pages,
			'stylesheets' => $stylesheets,
			'images'      => $images,
			'languages'   => array_keys( $languages ),
		);
	}

	/**
	 * Summarise one HTML page without loading it into the editor.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $relative Path relative to the design root.
	 * @return array<string, mixed>
	 */
	private static function describe_page( string $path, string $relative ): array {
		$html = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading a local file the plugin just unpacked.

		$title = '';

		if ( preg_match( '#<title[^>]*>(.*?)</title>#si', $html, $match ) ) {
			$title = trim( wp_strip_all_tags( $match[1] ) );
		}

		return array(
			'file'     => $relative,
			'title'    => '' !== $title ? $title : $relative,
			'bytes'    => strlen( $html ),
			'sections' => preg_match_all( '#<section\b#i', $html ),
			'headings' => preg_match_all( '#<h[1-3]\b#i', $html ),
		);
	}
}
