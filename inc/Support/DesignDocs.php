<?php
/**
 * Reads the written part of a handoff: what the design says about itself.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The documentation that comes with a design, ranked and cut to size.
 *
 * A handoff is not only markup. It arrives with a START-HERE, a PRD, a route
 * inventory, a UI design system, a page of content decisions — the answers to
 * every question the markup itself cannot settle: which colours are the
 * brand's, which of two headings is the real one, what a page is for, what was
 * left deliberately unfinished. All of it was being unpacked and then ignored,
 * which meant the importer worked out from the HTML alone things the archive
 * had already written down.
 *
 * Two problems make this less obvious than "read the markdown":
 *
 * - **Volume.** One handoff here carries 61 markdown files and 474 KB, most of
 *   it a change register repeated in three folders. Sent whole it would crowd
 *   the design's own source out of the window.
 * - **Value is not uniform.** A design system and a content-decisions note are
 *   worth more per byte than a QA sign-off sheet or a governance standard.
 *
 * So: rank by what the file is called, drop duplicates by content, take the
 * best of it up to a budget, and say what was left out.
 */
final class DesignDocs {

	/**
	 * The file the site owner's own instructions are kept in.
	 *
	 * Written into the design rather than into an option, so it travels with
	 * the design it is about, is removed when that design is removed, and is
	 * read by the same code that reads everything else the handoff wrote.
	 *
	 * @var string
	 */
	public const NOTES_FILE = 'qs-instructions.md';

	/**
	 * File-name fragments, best first. Earlier groups are quoted more fully.
	 *
	 * @var array<int, array<int, string>>
	 */
	private const RANKS = array(
		// What the site is and how it should look.
		array( 'start-here', 'start_here', 'readme', 'prd', 'brief', 'design-system', 'design_system', 'ui-design', 'style-guide', 'styleguide', 'brand', 'content', 'copy', 'route-inventory', 'routes', 'sitemap', 'pages' ),

		// How it was built and what was decided.
		array( 'handoff', 'implementation', 'architecture', 'guide', 'spec', 'decisions', 'notes', 'overview', 'structure', 'data', 'integration' ),

		// Everything else worth a look once the above has been read.
		array( 'todo', 'backlog', 'review', 'plan', 'summary', 'report' ),
	);

	/**
	 * File names that are process paperwork, not design.
	 *
	 * @var array<int, string>
	 */
	private const IGNORED = array(
		'changelog',
		'change-register',
		'change_register',
		'history',
		'governance',
		'qa',
		'acceptance',
		'license',
		'licence',
		'contributing',
		'code-of-conduct',
		'security',
		'node_modules',

		/*
		 * Machine output that happens to be a text file. A checksum list is
		 * forty kilobytes of hexadecimal and outranked everything a handoff
		 * actually wrote about itself, purely by sitting at the top level.
		 */
		'sha256',
		'sha1',
		'md5sum',
		'checksum',
		'sums.txt',
		'manifest',
		'requirements',
		'robots',
	);

	/**
	 * Most characters taken from one document.
	 */
	private const MAX_FILE_CHARS = 12000;

	/**
	 * Documents worth reading at all, in file size.
	 */
	private const MAX_DOC_BYTES = 400000;

	/**
	 * Find the design's documentation and quote as much as the budget allows.
	 *
	 * @param string $root   Design root.
	 * @param int    $budget Characters the digest may take.
	 * @return array{text:string,files:array<int,string>,left:int}
	 */
	public static function digest( string $root, int $budget = 40000 ): array {
		$root  = rtrim( str_replace( '\\', '/', $root ), '/' );
		$found = self::collect( $root );

		/*
		 * The site owner's own instructions go first and are never cut. They
		 * are the one document written by somebody who has seen both the
		 * design and the site it has to become, so they outrank anything the
		 * handoff shipped with itself.
		 */
		$notes = self::notes( $root );

		if ( '' !== $notes ) {
			$parts   = array( "## Instructions from the site owner — these take precedence over everything below\n\n" . $notes );
			$files   = array( self::NOTES_FILE );
			$budget -= strlen( $notes );
		} else {
			$parts = array();
			$files = array();
		}

		if ( array() === $found ) {
			return array(
				'text'  => implode( '', $parts ),
				'files' => $files,
				'left'  => 0,
			);
		}

		$seen = array();
		$left = 0;

		foreach ( $found as $document ) {
			$body = self::read( $document['path'] );

			if ( '' === trim( $body ) ) {
				continue;
			}

			/*
			 * The same document filed in three folders is one document. A
			 * handoff that keeps its docs beside the source and again under
			 * /documentation would otherwise spend the budget three times on
			 * the same words.
			 */
			$fingerprint = md5( preg_replace( '#\s+#u', ' ', $body ) ?? $body );

			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;

			$allowance = min( self::MAX_FILE_CHARS, $budget );

			if ( $allowance < 400 ) {
				++$left;
				continue;
			}

			$cut  = strlen( $body ) > $allowance;
			$body = $cut ? substr( $body, 0, $allowance ) : $body;

			$parts[] = '## ' . $document['relative'] . ( $cut ? ' (first part only)' : '' ) . "\n\n" . trim( $body );
			$files[] = $document['relative'];
			$budget -= strlen( $body );

			if ( $budget <= 0 ) {
				break;
			}
		}

		$left += max( 0, count( $found ) - count( $files ) - ( count( $found ) - count( $seen ) ) );

		return array(
			'text'  => implode( "\n\n", $parts ),
			'files' => $files,
			'left'  => $left,
		);
	}

	/**
	 * Whether a file reads as writing rather than as output.
	 *
	 * The name check catches the checksum lists that call themselves so. This
	 * catches the ones that do not: a wall of hashes, a column of paths, a
	 * dump of identifiers — text by extension, worth nothing in a brief.
	 *
	 * @param string $body File contents.
	 * @return bool
	 */
	private static function is_prose( string $body ): bool {
		if ( '' === $body ) {
			return false;
		}

		$lines = (array) preg_split( '#\r?\n#', $body, 200 );
		$lines = array_values( array_filter( $lines, static fn( $line ): bool => '' !== trim( (string) $line ) ) );

		if ( array() === $lines ) {
			return false;
		}

		$machine = 0;

		foreach ( $lines as $line ) {
			$line = (string) $line;

			// A hash, a hash and a path, or a single long unbroken token.
			if ( preg_match( '#^[0-9a-f]{16,}(\s|$)#i', trim( $line ) ) || preg_match( '#^\S{60,}$#', trim( $line ) ) ) {
				++$machine;
			}
		}

		return $machine / count( $lines ) < 0.5;
	}

	/**
	 * The site owner's own instructions for this design, if they wrote any.
	 *
	 * @param string $root Design root.
	 * @return string
	 */
	public static function notes( string $root ): string {
		$path = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/' . self::NOTES_FILE;

		if ( ! is_file( $path ) ) {
			return '';
		}

		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A local file this class wrote itself.

		return false === $body ? '' : trim( substr( $body, 0, self::MAX_FILE_CHARS ) );
	}

	/**
	 * Write, or clear, the site owner's instructions for this design.
	 *
	 * @param string $root  Design root.
	 * @param string $notes What they wrote; an empty string removes the file.
	 * @return bool
	 */
	public static function save_notes( string $root, string $notes ): bool {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		if ( ! is_dir( $root ) ) {
			return false;
		}

		$path  = $root . '/' . self::NOTES_FILE;
		$notes = trim( substr( $notes, 0, self::MAX_FILE_CHARS ) );

		if ( '' === $notes ) {
			return ! is_file( $path ) || unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- Inside the importer's own folder, where WP_Filesystem may be unavailable mid-request.
		}

		return false !== file_put_contents( $path, $notes . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
	}

	/**
	 * Every readable document in the design, best first.
	 *
	 * @param string $root Design root.
	 * @return array<int, array{path:string,relative:string,rank:int,bytes:int}>
	 */
	private static function collect( string $root ): array {
		$found = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$extension = strtolower( $file->getExtension() );

				if ( ! in_array( $extension, array( 'md', 'markdown', 'txt' ), true ) ) {
					continue;
				}

				$path     = str_replace( '\\', '/', $file->getPathname() );
				$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
				$rank     = self::rank_of( $relative );

				if ( null === $rank || $file->getSize() > self::MAX_DOC_BYTES ) {
					continue;
				}

				$found[] = array(
					'path'     => $path,
					'relative' => $relative,
					'rank'     => $rank,
					'bytes'    => (int) $file->getSize(),
				);
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		usort(
			$found,
			static function ( array $a, array $b ): int {
				// Best rank first; within a rank, the shallower file wins, then the larger.
				return array( $a['rank'], substr_count( $a['relative'], '/' ), -$a['bytes'] )
					<=> array( $b['rank'], substr_count( $b['relative'], '/' ), -$b['bytes'] );
			}
		);

		return $found;
	}

	/**
	 * How valuable a document is, by what it is called.
	 *
	 * @param string $relative Path inside the design.
	 * @return int|null Rank, or null when it is paperwork.
	 */
	private static function rank_of( string $relative ): ?int {
		$name = strtolower( $relative );

		// The owner's own notes are added first, by hand; not again here.
		if ( self::NOTES_FILE === $relative ) {
			return null;
		}

		foreach ( self::IGNORED as $fragment ) {
			if ( str_contains( $name, $fragment ) ) {
				return null;
			}
		}

		foreach ( self::RANKS as $rank => $fragments ) {
			foreach ( $fragments as $fragment ) {
				if ( str_contains( $name, $fragment ) ) {
					return $rank;
				}
			}
		}

		// Unnamed but present: read after everything that named itself.
		return count( self::RANKS );
	}

	/**
	 * Read a document, without its own front matter noise.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function read( string $path ): string {
		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A local file the importer unpacked itself.

		if ( false === $body ) {
			return '';
		}

		// Markdown front matter is metadata for a build, not writing about the design.
		$body = (string) preg_replace( '#\A---\r?\n.*?\r?\n---\r?\n#s', '', $body );
		$body = trim( $body );

		return self::is_prose( $body ) ? $body : '';
	}
}
