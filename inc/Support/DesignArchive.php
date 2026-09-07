<?php
/**
 * Safe intake and indexing of an uploaded design archive.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

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
 * - **Executable payloads** — `.htaccess`, `.exe`, `.phar` and friends are
 *   refused outright; only an allow-list of design assets is written. Server
 *   source a handoff documents itself with — the PHP of a companion plugin —
 *   is unpacked as text, under a name no handler matches.
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
	private const BASE_DIR = 'qwerty-soft-signal-designs';

	/**
	 * Directories earlier versions of the theme unpacked into.
	 *
	 * The theme was called WOW — Signal before it was called Qwerty Soft, and
	 * the designs it unpacked then are still on disk taking up room. Clean-up
	 * sweeps these too, or the one button that says "remove the uploaded
	 * designs" leaves megabytes behind that nothing on the screen can reach.
	 */
	private const LEGACY_BASE_DIRS = array( 'wow-signal-designs' );

	/**
	 * Maximum entries that will be *written* from one archive.
	 *
	 * Counted after vetting, not before: a developer handoff is mostly PDFs,
	 * specification sheets and dependency trees that never reach disk, and
	 * refusing the whole upload because the ZIP's table of contents is long
	 * turned "here is the design" into "re-zip it by hand first".
	 */
	private const MAX_ENTRIES = 60000;

	/**
	 * Maximum uncompressed size of a single file (64 MB).
	 */
	private const MAX_FILE_BYTES = 67108864;

	/**
	 * Maximum uncompressed size written to disk from one archive (1 GB).
	 */
	private const MAX_TOTAL_BYTES = 1073741824;

	/**
	 * Seconds an unpack may take before PHP gives up.
	 *
	 * A thousand-file design is minutes of work, not seconds, and the default
	 * thirty-second limit killed it half-written.
	 */
	private const UNPACK_SECONDS = 900;

	/**
	 * How many archives deep the unpacking goes.
	 *
	 * A box of boxes is normal in a handoff; a box of boxes of boxes of boxes
	 * is a bomb, so the recursion stops rather than following it anywhere.
	 */
	private const NESTING_DEPTH = 4;

	/**
	 * How long a directory path may get before short names are used instead.
	 *
	 * Windows refuses anything past 260 characters unless both the system and
	 * the binary doing the work have opted out, and Apache's PHP normally has
	 * not — which is why this bites on a developer's own machine and not in
	 * the tests. The budget leaves room underneath for the site tree an inner
	 * archive carries: a language folder, a section folder and a long file
	 * name is comfortably a hundred characters on its own.
	 *
	 * @var int
	 */
	private const PATH_BUDGET = 150;

	/**
	 * How long the whole path of one written file may be.
	 *
	 * The folder budget above keeps the tree short; this is the ceiling on
	 * the file at the end of it. Windows stops at 260 characters, and a file
	 * that ran past it used to fail at fopen() and be recorded as its bare
	 * name, with no reason. Now the name is shortened to fit, keeping the
	 * extension, and the rename is written down so the markup that still
	 * uses the long name can be followed to the short one. Ten characters
	 * under the limit, because the destination is a canonical path and the
	 * one that opens it may be spelled slightly longer.
	 *
	 * @var int
	 */
	public const PATH_LIMIT = 250;

	/**
	 * Cyrillic spelled in Latin letters, for file names.
	 *
	 * WordPress's remove_accents() covers the Latin scripts and nothing of
	 * Cyrillic, and the handoffs this studio receives are named in it more
	 * often than not. A plain, reversible-enough spelling — `фото` becomes
	 * `foto` — keeps the file recognisable on disk and in the log; the hash
	 * fallback is for scripts this table does not reach.
	 *
	 * @var array<string, string>
	 */
	private const CYRILLIC = array(
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'г' => 'g',
		'ґ' => 'g',
		'д' => 'd',
		'е' => 'e',
		'ё' => 'yo',
		'є' => 'ye',
		'ж' => 'zh',
		'з' => 'z',
		'и' => 'i',
		'і' => 'i',
		'ї' => 'yi',
		'й' => 'y',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ў' => 'u',
		'ф' => 'f',
		'х' => 'kh',
		'ц' => 'ts',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'shch',
		'ъ' => '',
		'ы' => 'y',
		'ь' => '',
		'э' => 'e',
		'ю' => 'yu',
		'я' => 'ya',
		'А' => 'A',
		'Б' => 'B',
		'В' => 'V',
		'Г' => 'G',
		'Ґ' => 'G',
		'Д' => 'D',
		'Е' => 'E',
		'Ё' => 'Yo',
		'Є' => 'Ye',
		'Ж' => 'Zh',
		'З' => 'Z',
		'И' => 'I',
		'І' => 'I',
		'Ї' => 'Yi',
		'Й' => 'Y',
		'К' => 'K',
		'Л' => 'L',
		'М' => 'M',
		'Н' => 'N',
		'О' => 'O',
		'П' => 'P',
		'Р' => 'R',
		'С' => 'S',
		'Т' => 'T',
		'У' => 'U',
		'Ў' => 'U',
		'Ф' => 'F',
		'Х' => 'Kh',
		'Ц' => 'Ts',
		'Ч' => 'Ch',
		'Ш' => 'Sh',
		'Щ' => 'Shch',
		'Ъ' => '',
		'Ы' => 'Y',
		'Ь' => '',
		'Э' => 'E',
		'Ю' => 'Yu',
		'Я' => 'Ya',
	);

	/**
	 * Where the unpack writes down the names it had to change.
	 *
	 * A dot-file in the design root, so nothing that sweeps the design for
	 * pages, pictures or documents counts it as one of them.
	 *
	 * @var string
	 */
	private const RENAMED_FILE = '.qsoft-renamed.json';

	/**
	 * File extensions that may be written to disk.
	 *
	 * Everything a design legitimately contains, and nothing a server can be
	 * tricked into executing.
	 */
	private const ALLOWED_EXTENSIONS = array(
		'html',
		'htm',

		/*
		 * A ZIP inside the ZIP. Handoffs arrive nested — the source in one
		 * archive, the build in another, the pictures in a third — and a
		 * design the importer refuses to open because it is wrapped twice is
		 * a design nobody can import. Written out here, then expanded in
		 * place by {@see self::expand_nested()} and deleted.
		 */
		'zip',
		'css',
		'scss',
		'sass',
		'less',
		'js',
		'mjs',
		'jsx',

		/*
		 * Component source. A design exported from a React or Vue project has
		 * no static HTML at all — the markup only exists once a browser has
		 * run it — so the components are the design, and refusing them left
		 * the screen with nothing to read. Nothing here is executable by a web
		 * server; it is text the importer reads, exactly like the HTML.
		 */
		'ts',
		'tsx',
		'vue',
		'json',
		'csv',
		'md',
		'txt',
		'xml',
		'yml',
		'yaml',
		'webmanifest',
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
	 * Extensions dropped without a word: paperwork, not design.
	 */
	private const PAPERWORK_EXTENSIONS = array(
		'pdf',
		'doc',
		'docx',
		'xls',
		'xlsx',
		'ppt',
		'pptx',
		'psd',
		'ai',
		'sketch',
		'fig',
		'xd',
		'rar',
		'7z',
		'gz',
		'tgz',
		'mp4',
		'mov',
		'avi',
		'webm',
		'mp3',
		'wav',
		'map',
		'lock',
		'mmd',
		'db',
		'sqlite',
	);

	/**
	 * Server-side source that is unpacked as plain text under a safe name.
	 *
	 * A handoff often ships the WordPress plugin it expects to sit beside —
	 * seven files of it in one recent package — and those files say what the
	 * design means by a report, a translation job, a protected download.
	 * Refusing them threw that away to protect against a risk that is really
	 * about *serving* the file, not about reading it. So they are unpacked
	 * with the extension folded into the name — `class-rk-rest-api.php`
	 * becomes `class-rk-rest-api-php.txt` — which leaves nothing for a
	 * misconfigured `AddHandler` to match, on top of the directory guards.
	 *
	 * The dot is removed rather than suffixed: Apache's mod_mime reads *every*
	 * extension in a name, so `x.php.txt` would still be handed to PHP.
	 */
	private const NEUTRALISED_EXTENSIONS = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phps',
		'phtml',
		'inc',
		'twig',
		'blade',
		'erb',
		'rb',
		'py',
		'pl',
		'sh',
		'bash',
	);

	/**
	 * Extensions that are refused loudly rather than silently skipped.
	 */
	private const DANGEROUS_EXTENSIONS = array(
		'phar',
		'htaccess',
		'htpasswd',
		'ini',
		'exe',
		'dll',
		'so',
		'cgi',
	);

	/**
	 * Absolute path of the private designs directory, creating it if needed.
	 *
	 * @return string|WP_Error
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'qwerty_soft_uploads', (string) $uploads['error'] );
		}

		$base = trailingslashit( $uploads['basedir'] ) . self::BASE_DIR;

		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'qwerty_soft_mkdir', __( 'Could not create the designs folder inside uploads.', 'qwerty-soft-signal' ) );
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
	 * example `location ^~ /wp-content/uploads/qwerty-soft-signal-designs/ { deny all; }`
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
	 * Every directory this class is allowed to delete inside, resolved.
	 *
	 * The current one, plus the ones older versions of the theme wrote to.
	 *
	 * @return array<int, string> Canonical paths, forward slashes, no trailing slash.
	 */
	private static function managed_bases(): array {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return array();
		}

		$names = array_merge( array( self::BASE_DIR ), self::LEGACY_BASE_DIRS );
		$bases = array();

		foreach ( $names as $name ) {
			$path = realpath( trailingslashit( $uploads['basedir'] ) . $name );

			if ( false !== $path && is_dir( $path ) ) {
				$bases[] = rtrim( str_replace( '\\', '/', $path ), '/' );
			}
		}

		return array_values( array_unique( $bases ) );
	}

	/**
	 * Remove a directory and everything under it.
	 *
	 * WP_Filesystem is not trusted to finish this on its own. Two ways it
	 * quietly does not: on a host where the direct transport is unavailable
	 * `WP_Filesystem()` returns false and nothing at all is deleted, and on
	 * Windows the final `rmdir()` of a directory whose children were only just
	 * unlinked fails often enough to be the normal case. Both ended the same
	 * way — an empty folder still listed on the screen as a design, that no
	 * button could remove, because `remove()` reported failure and the list
	 * kept showing what was still on disk.
	 *
	 * So: delete depth-first with plain PHP, retry the directory removals,
	 * and only then fall back to WP_Filesystem for anything left.
	 *
	 * @param string $root Absolute directory path.
	 * @return bool Whether it is gone.
	 */
	private static function discard( string $root ): bool {
		clearstatcache( true, $root );

		if ( ! is_dir( $root ) ) {
			return true;
		}

		try {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $items as $item ) {
				$path = (string) $item->getPathname();

				if ( $item->isDir() && ! $item->isLink() ) {
					self::rmdir_hard( $path );
					continue;
				}

				self::unlink_hard( $path );
			}
		} catch ( \Throwable $error ) {
			// A tree that changed underneath the iterator; the retry below settles it.
			unset( $error );
		}

		if ( self::rmdir_hard( $root ) ) {
			return true;
		}

		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->delete( $root, true );
		}

		clearstatcache( true, $root );

		return ! is_dir( $root );
	}

	/**
	 * Delete one file, taking the read-only bit off if that is what stopped it.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	private static function unlink_hard( string $path ): bool {
		if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Failure is the expected branch and is handled below; WP_Filesystem may be unavailable here.
			return true;
		}

		@chmod( $path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Read-only files are the common cause on Windows.

		return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Second attempt; the caller checks the tree afterwards.
	}

	/**
	 * Remove one directory, retrying while the filesystem catches up.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool
	 */
	private static function rmdir_hard( string $dir ): bool {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			clearstatcache( true, $dir );

			if ( ! is_dir( $dir ) ) {
				return true;
			}

			if ( @rmdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Windows returns false while a handle is still closing; retried below.
				clearstatcache( true, $dir );

				return true;
			}

			usleep( 50000 );
		}

		clearstatcache( true, $dir );

		return ! is_dir( $dir );
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
		$bases     = self::managed_bases();
		$real_root = realpath( $root );

		if ( array() === $bases || false === $real_root || ! is_dir( $real_root ) ) {
			return false;
		}

		$real_root = rtrim( str_replace( '\\', '/', $real_root ), '/' );
		$inside    = false;

		foreach ( $bases as $base ) {
			if ( $real_root === $base ) {
				// The folder that holds the designs is never itself a design.
				return false;
			}

			if ( str_starts_with( $real_root . '/', $base . '/' ) ) {
				$inside = true;
			}
		}

		if ( ! $inside ) {
			return false;
		}

		return self::discard( $real_root );
	}

	/**
	 * Delete every unpacked design, wherever this theme has ever put one.
	 *
	 * The current folder keeps its guard files and stays; a folder left by an
	 * older name of the theme goes entirely, guard files and all, because
	 * nothing will ever write to it again.
	 *
	 * @return array{removed:int,failed:array<int,string>} What went, and what would not.
	 */
	public static function purge(): array {
		$removed = 0;
		$failed  = array();
		$current = self::base_dir();
		$current = is_wp_error( $current ) ? '' : rtrim( str_replace( '\\', '/', (string) realpath( $current ) ), '/' );

		foreach ( self::managed_bases() as $base ) {
			$entries = glob( $base . '/*', GLOB_ONLYDIR );

			foreach ( $entries ? $entries : array() as $dir ) {
				if ( self::remove( $dir ) ) {
					++$removed;
					continue;
				}

				$failed[] = basename( $dir );
			}

			if ( $base !== $current && array() === $failed ) {
				self::discard( $base );
			}
		}

		return array(
			'removed' => $removed,
			'failed'  => $failed,
		);
	}

	/**
	 * How much disk the unpacked designs take, and how many there are.
	 *
	 * @return array{count:int,bytes:int}
	 */
	public static function footprint(): array {
		$count = 0;
		$bytes = 0;

		foreach ( self::managed_bases() as $base ) {
			$entries = glob( $base . '/*', GLOB_ONLYDIR );

			foreach ( $entries ? $entries : array() as $dir ) {
				++$count;

				try {
					$iterator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
					);

					foreach ( $iterator as $file ) {
						if ( $file->isFile() ) {
							$bytes += (int) $file->getSize();
						}
					}
				} catch ( \Throwable $error ) {
					unset( $error );
				}
			}
		}

		return array(
			'count' => $count,
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
			return __( 'The uploaded file is empty (0 bytes). Zip the design folder again and upload the new file.', 'qwerty-soft-signal' );
		}

		$kinds = array(
			'Rar!'       => __( 'a RAR archive', 'qwerty-soft-signal' ),
			"7z\xBC\xAF" => __( 'a 7-Zip archive', 'qwerty-soft-signal' ),
			"\x1F\x8B"   => __( 'a gzip/tar.gz archive', 'qwerty-soft-signal' ),
			'%PDF'       => __( 'a PDF', 'qwerty-soft-signal' ),
			'<!DO'       => __( 'an HTML page', 'qwerty-soft-signal' ),
			'<htm'       => __( 'an HTML page', 'qwerty-soft-signal' ),
			'{'          => __( 'a JSON file', 'qwerty-soft-signal' ),
		);

		foreach ( $kinds as $magic => $kind ) {
			if ( str_starts_with( $head, $magic ) ) {
				return sprintf(
					/* translators: %s: what the file actually is, e.g. "a RAR archive". */
					__( 'That file is %s with a .zip name, not a ZIP archive. Create a real ZIP of the design folder (right-click → Compress / Send to → Compressed folder) and upload that.', 'qwerty-soft-signal' ),
					$kind
				);
			}
		}

		if ( str_starts_with( $head, "PK\x03\x04" ) || str_starts_with( $head, "PK\x05\x06" ) ) {
			$reasons = array(
				ZipArchive::ER_INCONS => __( 'it is damaged or was not fully downloaded', 'qwerty-soft-signal' ),
				ZipArchive::ER_NOZIP  => __( 'its directory is unreadable', 'qwerty-soft-signal' ),
				ZipArchive::ER_MEMORY => __( 'the server ran out of memory reading it', 'qwerty-soft-signal' ),
				ZipArchive::ER_OPEN   => __( 'the server could not open the temporary file', 'qwerty-soft-signal' ),
				ZipArchive::ER_READ   => __( 'the server could not read the temporary file', 'qwerty-soft-signal' ),
				ZipArchive::ER_SEEK   => __( 'the server could not seek in the temporary file', 'qwerty-soft-signal' ),
			);

			return sprintf(
				/* translators: 1: reason, 2: libzip error code. */
				__( 'That ZIP looks right but %1$s (code %2$d). Re-zip the folder and try again; if it keeps happening, the file may use a compression PHP cannot read — choose "Deflate"/standard ZIP in your archiver.', 'qwerty-soft-signal' ),
				$reasons[ $code ] ?? __( 'could not be opened', 'qwerty-soft-signal' ),
				$code
			);
		}

		return sprintf(
			/* translators: 1: libzip error code, 2: file size in bytes. */
			__( 'That file could not be opened as a ZIP archive (code %1$d, %2$s bytes). It does not start like a ZIP, so it is probably not one: zip the design folder itself and upload the result.', 'qwerty-soft-signal' ),
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
				'qwerty_soft_no_zip',
				__( 'This server has no ZIP support in PHP, so archives cannot be unpacked. Ask your host to enable the zip extension.', 'qwerty-soft-signal' )
			);
		}

		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		/*
		 * The slug travels in REST URLs that accept [a-z0-9-] only.
		 * sanitize_title() percent-encodes anything non-Latin - a Cyrillic file
		 * name became "%d0%b0..." and no route would match it again.
		 * Keep ASCII only; a name with nothing left falls back to "design".
		 */
		$slug = strtolower( remove_accents( $label ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );

		/*
		 * Short on purpose. Windows refuses a path over 260 characters unless
		 * both the OS and the running binary have opted out of the limit, and
		 * Apache's PHP usually has not. This folder is the root of everything
		 * a handoff unpacks into — a package folder, a section folder, an
		 * inner archive's folder, then the site's own tree — and sixty
		 * characters spent here is sixty taken off every file below it. A
		 * design whose deepest file ran past the limit did not fail loudly: it
		 * arrived without the archive that held the whole website.
		 */
		$slug = substr( $slug, 0, 28 );
		$slug = '' !== $slug ? $slug : 'design';
		$slug = $slug . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$root = trailingslashit( $base ) . $slug;

		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'qwerty_soft_mkdir', __( 'Could not create a folder for this design.', 'qwerty-soft-signal' ) );
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path );

		if ( true !== $opened ) {
			return new WP_Error( 'qwerty_soft_bad_zip', self::open_failure_message( $zip_path, is_int( $opened ) ? $opened : 0 ) );
		}

		$count = $zip->numFiles;

		/*
		 * A developer handoff is not a design folder and never will be: this
		 * one is four and a half thousand entries, most of them specification
		 * PDFs that are skipped anyway. Reading it takes minutes, so take the
		 * minutes rather than refusing the upload and asking a person to
		 * re-zip a gigabyte by hand.
		 */
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::UNPACK_SECONDS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts in safe mode refuse this; unpacking still proceeds.
		}

		$real_root = realpath( $root );

		if ( false === $real_root ) {
			$zip->close();

			return new WP_Error( 'qwerty_soft_mkdir', __( 'Could not resolve the destination folder.', 'qwerty-soft-signal' ) );
		}

		$written  = 0;
		$inflated = 0;
		$skipped  = array();
		$dropped  = 0;
		$renamed  = array();

		self::extract_into( $zip, $real_root, $written, $inflated, $skipped, $dropped, $renamed );
		$zip->close();

		// Archives inside the archive, opened in place until none are left.
		$nested = self::expand_nested( $real_root, $written, $inflated, $skipped, $dropped, $renamed );

		if ( 0 === $written ) {
			return new WP_Error(
				'qwerty_soft_empty',
				__( 'Nothing usable was found in that archive. It should contain the design HTML or its components, its stylesheets and its images.', 'qwerty-soft-signal' )
			);
		}

		/*
		 * The names that had to change, kept beside the design. The build
		 * happens in a later request than the unpack, and the markup still
		 * says the names the archive used; this is how the media import
		 * follows a picture from the name on the page to the file on disk.
		 */
		if ( array() !== $renamed ) {
			file_put_contents( trailingslashit( $root ) . self::RENAMED_FILE, (string) wp_json_encode( $renamed ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A manifest inside the folder this method just created.
		}

		return array(
			'slug'    => $slug,
			'path'    => $root,
			'files'   => $written,
			'bytes'   => $inflated,
			'skipped' => $skipped,
			'dropped' => $dropped,
			'nested'  => $nested,
			'renamed' => $renamed,
		);
	}

	/**
	 * The names an unpack had to change, as original => written.
	 *
	 * Both sides are paths relative to the design root, forward slashes, the
	 * way the media map and the markup spell them.
	 *
	 * @param string $root Design root.
	 * @return array<string, string>
	 */
	public static function renamed( string $root ): array {
		$path = trailingslashit( $root ) . self::RENAMED_FILE;

		if ( ! is_file( $path ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- The manifest this class wrote.

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$map = array();

		foreach ( $decoded as $original => $written ) {
			if ( is_string( $original ) && is_string( $written ) && '' !== $original && '' !== $written ) {
				$map[ $original ] = $written;
			}
		}

		return $map;
	}

	/**
	 * Open every archive the archive contained, and every archive in those.
	 *
	 * A handoff is often a box of boxes: the source zipped, the build zipped,
	 * the photographs zipped, sometimes all three inside one more. Each inner
	 * archive is unpacked into a folder beside itself, vetted exactly like the
	 * outer one, counted against the same budget, and then deleted — what
	 * stays on disk is the design, not the packaging.
	 *
	 * @param string                $root     Design root, canonical.
	 * @param int                   $written  Files written so far; updated.
	 * @param int                   $inflated Bytes written so far; updated.
	 * @param array<int, string>    $skipped  Skip reasons; appended to.
	 * @param int                   $dropped  Entries dropped; updated.
	 * @param array<string, string> $renamed  Names changed on the way in; appended to.
	 * @return int How many inner archives were opened.
	 */
	private static function expand_nested( string $root, int &$written, int &$inflated, array &$skipped, int &$dropped, array &$renamed ): int {
		$opened = 0;

		for ( $round = 0; $round < self::NESTING_DEPTH; $round++ ) {
			$found = self::inner_archives( $root );

			if ( array() === $found ) {
				break;
			}

			$progress = false;

			foreach ( $found as $archive ) {
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( self::UNPACK_SECONDS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Refused in safe mode; unpacking still proceeds.
				}

				/*
				 * Unpacked beside itself, under a folder named after it, so a
				 * design that refers to `assets/photos/hero.png` still finds
				 * it after `assets/photos.zip` has been opened.
				 */
				$target = self::nested_folder( $archive, 'is_dir' );

				if ( ! wp_mkdir_p( $target ) ) {
					self::unlink_hard( $archive );
					continue;
				}

				$inner  = new ZipArchive();
				$result = $inner->open( $archive );

				if ( true !== $result ) {
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: 1: path inside the design, 2: the ZipArchive error number. */
							__( '%1$s — an archive inside the design that could not be opened (error %2$d)', 'qwerty-soft-signal' ),
							ltrim( substr( $archive, strlen( $root ) ), '/' ),
							is_int( $result ) ? $result : 0
						);
					}

					// Kept, not deleted: an archive nobody could open is still evidence.
					@rmdir( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the empty folder made for an archive that never opened.
					continue;
				}

				++$opened;
				$progress = true;

				/*
				 * One archive at a time, and one archive's failure is its own.
				 * Without this a single unreadable inner ZIP took the loop
				 * down with it and every archive after it stayed closed — and
				 * because what stays closed is invisible, a handoff whose
				 * whole website sat in the last of three boxes was imported as
				 * the two blueprints that happened to come first.
				 *
				 * Renames inside an inner archive are keyed from the design
				 * root, like every other path the build reads, so the folder
				 * the archive opened into is put in front of them.
				 */
				$prefix = ltrim( str_replace( '\\', '/', substr( $target, strlen( $root ) ) ), '/' ) . '/';

				try {
					self::extract_into( $inner, (string) realpath( $target ), $written, $inflated, $skipped, $dropped, $renamed, $prefix );
				} catch ( \Throwable $error ) {
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: 1: path inside the design, 2: the error. */
							__( '%1$s — an archive inside the design that could not be read (%2$s)', 'qwerty-soft-signal' ),
							ltrim( substr( $archive, strlen( $root ) ), '/' ),
							$error->getMessage()
						);
					}
				}

				$inner->close();

				// The packaging has served its purpose.
				self::unlink_hard( $archive );
			}

			// A round that opened nothing will not do better on the next pass.
			if ( ! $progress ) {
				break;
			}
		}

		/*
		 * Anything still boxed at the end is said out loud. Silence here is
		 * the worst outcome this class can produce: the design looks complete,
		 * the page list looks plausible, and the part somebody actually wanted
		 * is sitting on disk as a file nothing will ever open.
		 */
		foreach ( self::inner_archives( $root ) as $left ) {
			if ( count( $skipped ) < 60 ) {
				$skipped[] = sprintf(
					/* translators: %s: path inside the design. */
					__( '%s — an archive inside the design that is still unopened; its pages are not in this import.', 'qwerty-soft-signal' ),
					ltrim( substr( $left, strlen( $root ) ), '/' )
				);
			}
		}

		return $opened;
	}

	/**
	 * Every .zip currently sitting inside the design.
	 *
	 * @param string $root Design root.
	 * @return array<int, string> Absolute paths.
	 */
	private static function inner_archives( string $root ): array {
		$found = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'zip' === strtolower( $file->getExtension() ) ) {
					$found[] = str_replace( '\\', '/', $file->getPathname() );
				}
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		return $found;
	}


	/**
	 * Write one open archive into a directory, vetting every entry.
	 *
	 * Shared by the upload and by every archive found inside it, so an
	 * inner ZIP is held to exactly the same rules as the outer one: the same
	 * allow-list, the same zip-slip guard, the same budget, the same order.
	 *
	 * @param ZipArchive            $zip       Open archive.
	 * @param string                $real_root Canonical destination.
	 * @param int                   $written   Files written; updated.
	 * @param int                   $inflated  Bytes written; updated.
	 * @param array<int, string>    $skipped   Skip reasons; appended to.
	 * @param int                   $dropped   Entries dropped; updated.
	 * @param array<string, string> $renamed   Entries written under another name; appended to.
	 * @param string                $prefix    Where this archive sits under the design root, with a trailing slash, or ''.
	 * @return void
	 */
	private static function extract_into( ZipArchive $zip, string $real_root, int &$written, int &$inflated, array &$skipped, int &$dropped, array &$renamed = array(), string $prefix = '' ): void {
		$count = $zip->numFiles;

		/*
		 * Two passes, and the order is the point. The markup, the stylesheets
		 * and the component source are what the screen reads; the pictures are
		 * what fills the disk. Writing the readable files first means a design
		 * that runs into the size ceiling still arrives with its structure
		 * intact and only loses pictures at the end, instead of failing whole
		 * because a folder of photographs happened to be zipped first.
		 */
		foreach ( array( true, false ) as $documents_pass ) {
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

				$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

				if ( self::is_document( $extension ) !== $documents_pass ) {
					continue;
				}

				$verdict = self::vet_entry( $name, $size );

				if ( null !== $verdict ) {
					// An empty reason means "drop it quietly" — noise, not a threat.
					if ( '' !== $verdict ) {
						++$dropped;

						if ( count( $skipped ) < 60 ) {
							$skipped[] = $verdict;
						}
					}

					continue;
				}

				/*
				 * Past the budget: stop writing, but keep what is already
				 * there. The design is usable with fewer pictures and useless
				 * with none of it, which is what refusing the upload gave.
				 */
				if ( $inflated + $size > self::MAX_TOTAL_BYTES || $written >= self::MAX_ENTRIES ) {
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: %s: entry path inside the archive. */
							__( '%s — not unpacked, the design had already reached the size limit', 'qwerty-soft-signal' ),
							$name
						);
					}

					continue;
				}

				$target = self::safe_target( $real_root, $name );

				if ( null === $target ) {
					++$dropped;
					$skipped[] = sprintf(
						/* translators: %s: entry path inside the archive. */
						__( '%s — refused, the path points outside the folder', 'qwerty-soft-signal' ),
						$name
					);
					continue;
				}

				/*
				 * The whole path, not only the folders, has to fit the system
				 * it is written on. A file past the limit used to fail at
				 * fopen() and be listed by its bare name, which reads as
				 * "skipped for no reason"; now the name is shortened to fit
				 * and the change is written down like any other rename.
				 */
				$fitted = self::fit_path( $target );

				if ( null === $fitted ) {
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: %s: entry path inside the archive. */
							__( '%s — skipped, its path is too long for this system even with the file name shortened', 'qwerty-soft-signal' ),
							$name
						);
					}

					continue;
				}

				$target = $fitted;

				$dir = dirname( $target );

				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: %s: entry path inside the archive. */
							__( '%s — skipped, its folder could not be created', 'qwerty-soft-signal' ),
							$name
						);
					}

					continue;
				}

				$stream = $zip->getStream( $name );

				if ( ! is_resource( $stream ) ) {
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: %s: entry path inside the archive. */
							__( '%s — skipped, the archive could not read it', 'qwerty-soft-signal' ),
							$name
						);
					}

					continue;
				}

				$out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming avoids loading a large asset into memory; WP_Filesystem has no streaming API.

				if ( false === $out ) {
					fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with getStream().
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: %s: entry path inside the archive. */
							__( '%s — skipped, the file could not be created on disk', 'qwerty-soft-signal' ),
							$name
						);
					}

					continue;
				}

				$copied = stream_copy_to_stream( $stream, $out, self::MAX_FILE_BYTES + 1 );
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen() above.
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with getStream().

				/*
				 * The declared size is only a claim. Count what actually came
				 * out of the entry, and treat a copy that hit the per-file
				 * ceiling as truncated — a bomb declares small and inflates
				 * large. One such entry is deleted; it does not condemn the
				 * whole archive.
				 */
				$copied = false === $copied ? 0 : (int) $copied;

				if ( $copied > self::MAX_FILE_BYTES ) {
					self::unlink_hard( $target );
					++$dropped;

					if ( count( $skipped ) < 60 ) {
						$skipped[] = sprintf(
							/* translators: %s: entry path inside the archive. */
							__( '%s — refused, it unpacks to far more than it declares', 'qwerty-soft-signal' ),
							$name
						);
					}

					continue;
				}

				$inflated += $copied;
				++$written;

				/*
				 * Written under a different name than the archive used —
				 * transliterated, shortened, or both. The markup still says
				 * the old one, so the pair is kept for the media import.
				 */
				$original = self::entry_relative( $name );
				$relative = ltrim( str_replace( '\\', '/', substr( $target, strlen( $real_root ) ) ), '/' );

				if ( '' !== $original && $original !== $relative ) {
					$renamed[ $prefix . $original ] = $prefix . $relative;
				}
			}
		}
	}

	/**
	 * Shorten a file name until the whole path fits the system's limit.
	 *
	 * The extension is kept, because the allow-list vetted it and the media
	 * import reads it; the stem is cut and given a short hash of the original
	 * name, so two long names that share a beginning still stay two files.
	 * The same name shortens the same way every time.
	 *
	 * @param string $path  Full destination path.
	 * @param int    $limit Longest path allowed.
	 * @return string|null The path, shortened if it had to be; null when even a bare hash would not fit.
	 */
	public static function fit_path( string $path, int $limit = self::PATH_LIMIT ): ?string {
		if ( strlen( $path ) <= $limit ) {
			return $path;
		}

		$cut  = max( (int) strrpos( $path, '/' ), (int) strrpos( $path, '\\' ) );
		$dir  = substr( $path, 0, $cut );
		$name = substr( $path, $cut + 1 );

		$extension = (string) pathinfo( $name, PATHINFO_EXTENSION );
		$stem      = '' === $extension ? $name : substr( $name, 0, -( strlen( $extension ) + 1 ) );
		$suffix    = '' === $extension ? '' : '.' . $extension;
		$hash      = substr( md5( $name ), 0, 8 );

		// The folders, a slash, the hash and the extension are the floor.
		$room = $limit - strlen( $dir ) - 1 - strlen( $hash ) - strlen( $suffix );

		if ( $room < 0 ) {
			return null;
		}

		// Whatever is left goes to the readable part, with a dash before the hash.
		$short = $room > 1 ? rtrim( substr( $stem, 0, $room - 1 ), '-. ' ) . '-' . $hash : $hash;

		return $dir . '/' . $short . $suffix;
	}

	/**
	 * Where an archive found inside the design is opened.
	 *
	 * Beside itself, under a folder named after it, so a design that refers
	 * to `assets/photos/hero.png` still finds it after `assets/photos.zip`
	 * has been opened.
	 *
	 * A folder named after the archive is the readable choice and the wrong
	 * one when the name is sixty characters long and the archive is already
	 * four folders deep. Windows stops at 260 characters for the whole path,
	 * and what stops there is not this folder but the site inside it —
	 * silently, because an archive that cannot be opened is an archive nobody
	 * sees. So the name is kept while it fits and swapped for a short stable
	 * one when it does not: the same folder every time the same archive is
	 * unpacked, which is what the links inside it need.
	 *
	 * A folder that already exists gets a counter — `photos-2`, never
	 * `photos-2-3-4`, which is what building each try on the last produced —
	 * and the counter is checked against the budget too.
	 *
	 * @param string   $archive Absolute path of the .zip, forward slashes.
	 * @param callable $exists  Whether a folder is already taken; is_dir() in use, injected for tests.
	 * @return string Absolute path of the folder to open it into.
	 */
	public static function nested_folder( string $archive, callable $exists ): string {
		$readable = preg_replace( '#\.zip$#i', '', $archive );
		$readable = is_string( $readable ) && '' !== $readable ? $readable : $archive . '-unpacked';
		$short    = dirname( $archive ) . '/z-' . substr( md5( basename( $archive ) ), 0, 8 );

		$base   = strlen( $readable ) > self::PATH_BUDGET ? $short : $readable;
		$target = $base;
		$suffix = 2;

		while ( $exists( $target ) ) {
			$target = $base . '-' . $suffix;

			if ( strlen( $target ) > self::PATH_BUDGET && $base !== $short ) {
				$base   = $short;
				$target = $base . '-' . $suffix;
			}

			++$suffix;
		}

		return $target;
	}

	/**
	 * An archive entry's path as the design's own markup would spell it.
	 *
	 * Forward slashes, no leading slash, no empty or `.` segments — the same
	 * normalisation safe_target() starts from, without the cleaning, so the
	 * two can be compared to find out whether a name changed.
	 *
	 * @param string $name Entry path inside the archive.
	 * @return string
	 */
	private static function entry_relative( string $name ): string {
		$segments = array();

		foreach ( explode( '/', str_replace( '\\', '/', $name ) ) as $segment ) {
			if ( '' !== $segment && '.' !== $segment ) {
				$segments[] = $segment;
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Whether an extension is something the importer reads rather than serves.
	 *
	 * @param string $extension Lower-case extension, no dot.
	 * @return bool
	 */
	private static function is_document( string $extension ): bool {
		return in_array(
			$extension,
			array_merge(
				array( 'html', 'htm', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'jsx', 'ts', 'tsx', 'vue', 'json', 'csv', 'md', 'txt', 'xml', 'yml', 'yaml', 'webmanifest' ),
				self::NEUTRALISED_EXTENSIONS
			),
			true
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
		if ( preg_match( '#(^|/)(node_modules|vendor|bower_components|\.next|\.nuxt|\.turbo|\.cache|__pycache__|coverage|dist/cache)(/|$)#i', $name ) ) {
			return '';
		}

		/*
		 * The paperwork around a design: specification sheets, manuals,
		 * spreadsheets, lock files, source maps. A developer handoff carries
		 * thousands of them, and listing every one as "skipped" buried the two
		 * lines that mattered under sixteen hundred that did not.
		 */
		if ( in_array( $extension, self::PAPERWORK_EXTENSIONS, true ) || str_ends_with( $basename, '.min.js.map' ) ) {
			return '';
		}

		if ( in_array( $extension, self::DANGEROUS_EXTENSIONS, true ) ) {
			return sprintf(
				/* translators: %s: entry path inside the archive. */
				__( '%s — refused, executable files are never unpacked', 'qwerty-soft-signal' ),
				$name
			);
		}

		// Server-side source is unpacked, but never under a name a server would run.
		if ( in_array( $extension, self::NEUTRALISED_EXTENSIONS, true ) ) {
			return $size > self::MAX_FILE_BYTES ? sprintf(
				/* translators: 1: entry path inside the archive, 2: the per-file size limit, e.g. "64 MB". */
				__( '%1$s — skipped, larger than %2$s', 'qwerty-soft-signal' ),
				$name,
				size_format( self::MAX_FILE_BYTES )
			) : null;
		}

		if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
			return sprintf(
				/* translators: %s: entry path inside the archive. */
				__( '%s — skipped, not a design file', 'qwerty-soft-signal' ),
				$name
			);
		}

		if ( $size > self::MAX_FILE_BYTES ) {
			return sprintf(
				/* translators: 1: entry path inside the archive, 2: the per-file size limit, e.g. "64 MB". */
				__( '%1$s — skipped, larger than %2$s', 'qwerty-soft-signal' ),
				$name,
				size_format( self::MAX_FILE_BYTES )
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
	 * Public because it is pure, and because what it does to a name that is
	 * not ASCII is worth a test of its own.
	 *
	 * @param string $segment Raw directory name from the archive.
	 * @return string Cleaned name, or '' when nothing usable is left.
	 */
	public static function clean_directory_name( string $segment ): string {
		$clean = self::ascii_name( $segment );
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
	public static function clean_file_name( string $segment ): string {
		$extension = (string) pathinfo( $segment, PATHINFO_EXTENSION );
		$stem      = '' === $extension ? $segment : substr( $segment, 0, -( strlen( $extension ) + 1 ) );

		/*
		 * The stem and the extension are cleaned apart, so a hash a foreign
		 * stem earns lands before the dot and the extension the allow-list
		 * vetted comes through as it was.
		 */
		$clean = self::ascii_name( $stem );
		$clean = '' === $extension ? $clean : $clean . '.' . self::ascii_name( $extension );
		$clean = ltrim( $clean, '.' );
		$clean = trim( $clean );

		return substr( self::neutralise( $clean ), 0, 180 );
	}

	/**
	 * Spell one path segment in the characters every filesystem accepts.
	 *
	 * This used to map every character outside `[A-Za-z0-9 _.-]` to a dash,
	 * which turned a Cyrillic picture name into `------.jpg` — six dashes for
	 * six letters, the same six dashes for every other six-letter name, and
	 * a page that still said the original. Now the name is transliterated
	 * first — Cyrillic by the table above, the Latin scripts by WordPress —
	 * and whatever no transliteration can spell is replaced by a short hash
	 * of the original, so different names stay different and the same name
	 * is always spelled the same way.
	 *
	 * @param string $segment One directory or file-name segment, raw.
	 * @return string ASCII, possibly empty.
	 */
	private static function ascii_name( string $segment ): string {
		$valid = 1 === preg_match( '//u', $segment );
		$ascii = $valid ? strtr( $segment, self::CYRILLIC ) : $segment;
		$ascii = $valid && function_exists( 'remove_accents' ) ? remove_accents( $ascii ) : $ascii;

		$clean = preg_replace( $valid ? '#[^A-Za-z0-9 _.\-]+#u' : '#[^A-Za-z0-9 _.\-]+#', '-', $ascii );
		$clean = is_string( $clean ) ? $clean : '';

		if ( $clean === $ascii ) {
			return $clean;
		}

		// Something was lost; keep what came through, and make the rest unmistakable.
		$kept = trim( (string) preg_replace( '#-{2,}#', '-', $clean ), '- ' );

		return ( '' === $kept ? '' : $kept . '-' ) . substr( md5( $segment ), 0, 8 );
	}

	/**
	 * Fold a runnable extension into the file name, leaving plain text behind.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function neutralise( string $name ): string {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::NEUTRALISED_EXTENSIONS, true ) ) {
			return $name;
		}

		return substr( $name, 0, -( strlen( $extension ) + 1 ) ) . '-' . $extension . '.txt';
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
		$components  = 0;

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

			if ( in_array( $extension, array( 'css', 'scss', 'sass', 'less' ), true ) ) {
				$stylesheets[] = $relative;
				continue;
			}

			if ( in_array( $extension, array( 'tsx', 'jsx', 'vue' ), true ) ) {
				++$components;
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

			/*
			 * The address this page will get, worked out by the same function
			 * that will later give it one. A build list that says how many
			 * pages it will make and not which, nor what they will be called,
			 * is a list nobody can check before an hour of work — and checking
			 * it afterwards is how three pages out of a fourteen-page site
			 * went unnoticed until they were built.
			 */
			$pages[ $index ]['slug'] = SiteAssembler::slug_for( (string) $page['file'] );

			/*
			 * The language folder, wherever it sits. Looking only at the front
			 * of the path worked while an archive held one site; a handoff that
			 * carries two versions of the site side by side has its `en/` and
			 * `ru/` three folders down, and every page then read as
			 * language-less — which is how the same page in three languages
			 * came to be listed as three unrelated pages.
			 */
			$language = self::language_in( $inner );

			if ( '' !== $language ) {
				$languages[ $language ] = true;
			}

			$pages[ $index ]['language'] = $language;
		}

		usort(
			$pages,
			static function ( array $a, array $b ): int {
				// Pages with something in them first, app shells last.
				return array( $b['shell'] ? 0 : 1, $b['sections'], $b['words'] ) <=> array( $a['shell'] ? 0 : 1, $a['sections'], $a['words'] );
			}
		);

		$readable = 0;

		foreach ( $pages as $page ) {
			if ( empty( $page['shell'] ) ) {
				++$readable;
			}
		}

		return array(
			'pages'       => $pages,
			'stylesheets' => $stylesheets,
			'images'      => $images,
			'components'  => $components,
			'readable'    => $readable,
			'languages'   => array_keys( $languages ),
			'kind'        => self::kind( $pages, $readable, $components ),
		);
	}

	/**
	 * Language codes a folder name is allowed to be.
	 *
	 * A closed list on purpose. Any two letters would call `ui/`, `js/` and
	 * `qa/` languages and quietly merge unrelated pages into one row.
	 *
	 * @var array<int, string>
	 */
	private const LANGUAGE_CODES = array( 'en', 'ru', 'uk', 'zh', 'de', 'fr', 'es', 'it', 'pt', 'pl', 'nl', 'cs', 'sk', 'sv', 'da', 'fi', 'no', 'tr', 'ar', 'he', 'ja', 'ko', 'hi', 'th', 'vi', 'id', 'ro', 'hu', 'bg', 'el', 'ka', 'kk', 'lt', 'lv', 'et', 'sr', 'hr', 'sl' );

	/**
	 * The language folder inside a path, if it has one.
	 *
	 * @param string $path Path relative to the design root.
	 * @return string Two-letter code, or an empty string.
	 */
	public static function language_in( string $path ): string {
		foreach ( explode( '/', trim( str_replace( '\\', '/', $path ), '/' ) ) as $segment ) {
			if ( in_array( strtolower( $segment ), self::LANGUAGE_CODES, true ) ) {
				return strtolower( $segment );
			}
		}

		return '';
	}

	/**
	 * What sort of thing was uploaded, so the screen can say so plainly.
	 *
	 * A React or Vue export has HTML files that contain nothing but an empty
	 * root element: the page is assembled in the browser and there is no
	 * markup on disk to convert. Saying "nothing on this page could be
	 * converted" for that is true and useless. Naming it is what lets the
	 * person do something about it.
	 *
	 * @param array<int, array<string, mixed>> $pages      Indexed pages.
	 * @param int                              $readable   Pages with real markup.
	 * @param int                              $components Component source files found.
	 * @return string One of: static, app, empty.
	 */
	private static function kind( array $pages, int $readable, int $components ): string {
		if ( $readable > 0 ) {
			return 'static';
		}

		if ( array() !== $pages && $components > 0 ) {
			return 'app';
		}

		return array() === $pages && $components > 0 ? 'app' : 'empty';
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
			/*
			 * Decoded, because the screen prints this as text. A page titled
			 * "Reports &amp; Store" was showing the entity itself, and after a
			 * round through the REST API it had become "&amp;amp;".
			 */
			$title = trim( html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		/*
		 * Only the body counts, and only once the parts a browser would run
		 * are out of it. `<section>` alone was too narrow a question: a page
		 * built from <div class="hero"> reported zero sections and sorted to
		 * the bottom, and an empty React root reported zero for the honest
		 * reason and looked exactly the same.
		 */
		$body = $html;

		if ( preg_match( '#<body[^>]*>(.*)</body>#si', $html, $match ) ) {
			$body = $match[1];
		}

		$body    = (string) preg_replace( '#<(script|style|template|noscript)\b[^>]*>.*?</\1>#si', '', $body );
		$body    = (string) preg_replace( '#<!--.*?-->#s', '', $body );
		$text    = trim( (string) preg_replace( '#\s+#u', ' ', wp_strip_all_tags( $body ) ) );
		$words   = '' === $text ? 0 : count( (array) preg_split( '#\s+#u', $text ) );
		$regions = preg_match_all( '#<(section|article|header|footer|main|aside)\b#i', $body );
		$blocks  = preg_match_all( '#<(div|ul|ol|table|form|figure)\b#i', $body );

		return array(
			'file'     => $relative,
			'title'    => '' !== $title ? $title : $relative,
			'bytes'    => strlen( $html ),
			'sections' => $regions,
			'headings' => preg_match_all( '#<h[1-3]\b#i', $body ),
			'words'    => $words,

			/*
			 * An app shell: markup exists, content does not. Fifteen words is
			 * comfortably below a real page's opening paragraph and well above
			 * a "You need JavaScript to run this app" fallback.
			 */
			'shell'    => $words < 15 && $regions < 1 && $blocks < 3,
		);
	}
}
