<?php
/**
 * Reads the data a design page's templates were meant to be filled with.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * Finds JS object and array literals next to a page and resolves `{{ }}` paths against them.
 *
 * A design page writes `<sc-for list="{{ posts }}" as="p">` and keeps the posts
 * in a script — a `const POSTS = [ {…} ]` in the page, in a sibling `.js`, or a
 * `return [ {…} ]` inside the component that renders the page. None of it is
 * ever executed here: the literal is bracket-matched out of the source and
 * parsed by a small tolerant reader that understands exactly the JSON-like
 * subset a data table is written in. Anything else — a call, a regex, an
 * identifier, a template string with `${}` — becomes null and is simply not
 * data the page can be filled with.
 */
final class TemplateData {

	/**
	 * Largest script file read, in bytes.
	 */
	private const MAX_FILE = 1048576;

	/**
	 * Most literals parsed from one design; protects against a bundle full of them.
	 */
	private const MAX_LITERALS = 400;

	/**
	 * Deepest nesting the reader follows.
	 */
	private const MAX_DEPTH = 48;

	/**
	 * Most sibling `.js` files scanned as a fallback.
	 */
	private const MAX_SIBLINGS = 24;

	/**
	 * Named values: variable name to parsed literal.
	 *
	 * @var array<string, mixed>
	 */
	private array $vars = array();

	/**
	 * Literals with no usable name, such as `return [ … ]`, in discovery order.
	 *
	 * @var array<int, mixed>
	 */
	private array $anonymous = array();

	/**
	 * Every script source scanned, concatenated, for alias lookups.
	 *
	 * @var string
	 */
	private string $source = '';

	/**
	 * Names already bound by schema, so the same name always gets the same data.
	 *
	 * @var array<string, mixed>
	 */
	private array $bound = array();

	/**
	 * Collect the data reachable from a parsed page.
	 *
	 * Inline scripts come first, then each `.js` the page references, then any
	 * other `.js` beside the page. Paths are resolved against the page and kept
	 * inside the design root; a reference that escapes it is ignored.
	 *
	 * @param DOMDocument $dom      Parsed page, before its scripts are stripped.
	 * @param string      $page_dir Directory of the page file.
	 * @param string      $root     Design root the page was unpacked into.
	 * @return self
	 */
	public static function from_page( DOMDocument $dom, string $page_dir, string $root ): self {
		$data  = new self();
		$xpath = new DOMXPath( $dom );
		$files = array();

		foreach ( $xpath->query( '//script' ) as $script ) {
			if ( ! $script instanceof DOMElement ) {
				continue;
			}

			$src = trim( $script->getAttribute( 'src' ) );

			if ( '' === $src ) {
				$data->scan( (string) $script->textContent );
				continue;
			}

			$path = self::local_script( $src, $page_dir, $root );

			if ( '' !== $path ) {
				$files[ $path ] = true;
			}
		}

		foreach ( self::sibling_scripts( $page_dir ) as $path ) {
			$files[ $path ] = true;
		}

		foreach ( array_keys( $files ) as $path ) {
			$size = filesize( $path );

			if ( false === $size || $size > self::MAX_FILE ) {
				continue;
			}

			$data->scan( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
		}

		return $data;
	}

	/**
	 * Read one script's literals from source text.
	 *
	 * @param string $js JavaScript source.
	 * @return self
	 */
	public static function from_source( string $js ): self {
		$data = new self();
		$data->scan( $js );

		return $data;
	}

	/**
	 * Named values found so far.
	 *
	 * @return array<string, mixed>
	 */
	public function vars(): array {
		return $this->vars;
	}

	/**
	 * Whether anything at all was found.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->vars && array() === $this->anonymous;
	}

	/**
	 * Resolve a `{{ }}` expression against a stack of scopes.
	 *
	 * Scopes are searched innermost first — the last one in the array wins —
	 * and the variables of the design come after all of them. A name that no
	 * scope knows is bound by shape when `$keys` says which fields the page
	 * reads from it: a page reading `{{ p.title }}` and `{{ p.img }}` inside
	 * `<sc-for list="{{ grid }}">` is filled from the one array whose items
	 * carry those fields, even though `grid` itself was computed at runtime.
	 *
	 * @param string                            $expr      Expression between the braces.
	 * @param array<int, array<string, mixed>>  $scopes    Loop scopes, outermost first.
	 * @param array<string, array<int, string>> $keys      Field names the page reads per root name.
	 * @param bool                              $want_list Whether a list is wanted, as for a loop.
	 * @return array{0: bool, 1: mixed} Found flag and value.
	 */
	public function resolve( string $expr, array $scopes, array $keys = array(), bool $want_list = false ): array {
		$path = self::path( $expr );

		if ( null === $path ) {
			return array( false, null );
		}

		$root  = array_shift( $path );
		$value = null;
		$found = false;

		for ( $i = count( $scopes ) - 1; $i >= 0; $i-- ) {
			if ( array_key_exists( $root, $scopes[ $i ] ) ) {
				$value = $scopes[ $i ][ $root ];
				$found = true;
				break;
			}
		}

		if ( ! $found && array_key_exists( $root, $this->vars ) ) {
			$value = $this->vars[ $root ];
			$found = true;
		}

		if ( ! $found ) {
			$value = $this->bind( $root, $keys[ $root ] ?? array(), $want_list );
			$found = null !== $value;
		}

		if ( ! $found ) {
			return array( false, null );
		}

		foreach ( $path as $segment ) {
			if ( is_array( $value ) ) {
				if ( array_key_exists( $segment, $value ) ) {
					$value = $value[ $segment ];
					continue;
				}

				if ( 'length' === $segment ) {
					$value = count( $value );
					continue;
				}

				return array( false, null );
			}

			if ( is_string( $value ) && 'length' === $segment ) {
				$value = mb_strlen( $value );
				continue;
			}

			return array( false, null );
		}

		return array( true, $value );
	}

	/**
	 * The names the page reads off each root, from its raw markup.
	 *
	 * @param string $html Page or fragment markup.
	 * @return array<string, array<int, string>> Root name to the set of first-level fields.
	 */
	public static function fields( string $html ): array {
		$fields = array();

		if ( 1 > preg_match_all( '/\{\{\s*([A-Za-z_$][\w$]*)\s*(?:\.\s*([A-Za-z_$][\w$]*))?/u', $html, $matches, PREG_SET_ORDER ) ) {
			return $fields;
		}

		foreach ( $matches as $match ) {
			$root = $match[1];

			if ( ! isset( $fields[ $root ] ) ) {
				$fields[ $root ] = array();
			}

			if ( isset( $match[2] ) && '' !== $match[2] && ! in_array( $match[2], $fields[ $root ], true ) ) {
				$fields[ $root ][] = $match[2];
			}
		}

		return $fields;
	}

	/**
	 * JavaScript truthiness of a parsed value.
	 *
	 * @param mixed $value Parsed value.
	 * @return bool
	 */
	public static function truthy( $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		if ( is_string( $value ) ) {
			return '' !== $value;
		}

		return (bool) $value;
	}

	/**
	 * Text a value prints as, or null when it has no sensible text form.
	 *
	 * @param mixed $value Parsed value.
	 * @return string|null
	 */
	public static function text( $value ): ?string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return null;
	}

	/**
	 * Break an expression into its path segments, or null when it is not a plain path.
	 *
	 * Filters after a pipe are ignored. Anything that is not identifiers joined
	 * by dots and literal subscripts — a call, an operator, a parenthesis — is
	 * refused outright, so there is no way to reach past the data.
	 *
	 * @param string $expr Expression between the braces.
	 * @return array<int, string>|null
	 */
	private static function path( string $expr ): ?array {
		$expr = trim( $expr );

		if ( 1 === preg_match( '/^(.*?)\s*(?<!\|)\|(?!\|)/su', $expr, $match ) ) {
			$expr = trim( $match[1] );
		}

		if ( '' === $expr || strlen( $expr ) > 200 ) {
			return null;
		}

		$pattern = '/^([A-Za-z_$][\w$]*)((?:\s*\.\s*[A-Za-z_$][\w$]*|\s*\[\s*(?:\d+|\'[^\'\]]*\'|"[^"\]]*")\s*\])*)$/';

		if ( 1 !== preg_match( $pattern, $expr, $match ) ) {
			return null;
		}

		$path = array( $match[1] );

		if ( 1 <= preg_match_all( '/\.\s*([A-Za-z_$][\w$]*)|\[\s*(?:(\d+)|\'([^\'\]]*)\'|"([^"\]]*)")\s*\]/', $match[2], $parts, PREG_SET_ORDER ) ) {
			foreach ( $parts as $part ) {
				if ( isset( $part[1] ) && '' !== $part[1] ) {
					$path[] = $part[1];
				} elseif ( isset( $part[2] ) && '' !== $part[2] ) {
					$path[] = $part[2];
				} elseif ( isset( $part[3] ) && '' !== $part[3] ) {
					$path[] = $part[3];
				} elseif ( isset( $part[4] ) ) {
					$path[] = $part[4];
				} else {
					$path[] = '0';
				}
			}
		}

		return $path;
	}

	/**
	 * Find data for a name no scope or variable provides.
	 *
	 * First by alias — `posts: FALLBACK_POSTS` or `posts = FALLBACK_POSTS`
	 * somewhere in the scripts — then by shape, scoring every array of objects
	 * by how many of the fields the page reads from the name its items have.
	 *
	 * @param string             $name      Root name from the expression.
	 * @param array<int, string> $keys      Fields the page reads from it.
	 * @param bool               $want_list Whether a list is wanted.
	 * @return mixed Null when nothing fits.
	 */
	private function bind( string $name, array $keys, bool $want_list ) {
		if ( ! array_key_exists( $name, $this->bound ) ) {
			$this->bound[ $name ] = $this->alias( $name ) ?? $this->by_shape( $keys );
		}

		$value = $this->bound[ $name ];

		if ( null === $value ) {
			return null;
		}

		if ( $want_list ) {
			return self::is_list_of_records( $value ) ? $value : null;
		}

		// A scalar read from a list name means its first entry: the featured post.
		return self::is_list_of_records( $value ) ? $value[0] : $value;
	}

	/**
	 * The variable a name is assigned from, when that variable is known.
	 *
	 * @param string $name Root name.
	 * @return mixed
	 */
	private function alias( string $name ) {
		$pattern = '/(?<![\w$.])' . preg_quote( $name, '/' ) . '\s*[:=]\s*([A-Za-z_$][\w$]*)\s*[,;}\n\r]/';

		if ( 1 > preg_match_all( $pattern, $this->source, $matches ) ) {
			return null;
		}

		foreach ( $matches[1] as $alias ) {
			if ( $alias !== $name && array_key_exists( $alias, $this->vars ) && is_array( $this->vars[ $alias ] ) ) {
				return $this->vars[ $alias ];
			}
		}

		return null;
	}

	/**
	 * The array of records whose items best carry the given fields.
	 *
	 * @param array<int, string> $keys Fields the page reads.
	 * @return array<int, mixed>|null
	 */
	private function by_shape( array $keys ): ?array {
		if ( count( $keys ) < 2 ) {
			return null;
		}

		$best  = null;
		$score = 0;

		foreach ( $this->candidates() as $candidate ) {
			$hits = count( array_intersect( $keys, array_keys( $candidate[0] ) ) );

			if ( $hits >= 2 && $hits * 2 >= count( $keys ) && $hits > $score ) {
				$best  = $candidate;
				$score = $hits;
			}
		}

		return $best;
	}

	/**
	 * Every list of records in the data, one level deep.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function candidates(): array {
		$lists = array();

		foreach ( array_merge( array_values( $this->vars ), $this->anonymous ) as $value ) {
			if ( self::is_list_of_records( $value ) ) {
				$lists[] = $value;
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $inner ) {
				if ( self::is_list_of_records( $inner ) ) {
					$lists[] = $inner;
				}
			}
		}

		return $lists;
	}

	/**
	 * Whether a value is a non-empty list whose first item is a keyed record.
	 *
	 * @param mixed $value Parsed value.
	 * @return bool
	 */
	private static function is_list_of_records( $value ): bool {
		return is_array( $value )
			&& array() !== $value
			&& array_is_list( $value )
			&& is_array( $value[0] )
			&& ! array_is_list( $value[0] );
	}

	/**
	 * A `<script src>` on disk, inside the design root, or an empty string.
	 *
	 * @param string $src      Attribute value.
	 * @param string $page_dir Directory of the page.
	 * @param string $root     Design root.
	 * @return string
	 */
	private static function local_script( string $src, string $page_dir, string $root ): string {
		if ( 1 === preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $src ) ) {
			return '';
		}

		$src = (string) preg_replace( '/[?#].*$/', '', $src );
		$src = rawurldecode( $src );

		if ( 1 !== preg_match( '/\.m?js$/i', $src ) ) {
			return '';
		}

		$base = '/' === substr( $src, 0, 1 ) ? $root : $page_dir;
		$path = realpath( $base . '/' . ltrim( $src, '/' ) );
		$top  = realpath( $root );

		if ( false === $path || false === $top || ! is_file( $path ) ) {
			return '';
		}

		$top = rtrim( str_replace( '\\', '/', $top ), '/' ) . '/';

		return str_starts_with( str_replace( '\\', '/', $path ), $top ) ? $path : '';
	}

	/**
	 * The `.js` files beside a page, smallest first.
	 *
	 * @param string $page_dir Directory of the page.
	 * @return array<int, string>
	 */
	private static function sibling_scripts( string $page_dir ): array {
		$entries = is_dir( $page_dir ) ? scandir( $page_dir ) : false;

		if ( ! is_array( $entries ) ) {
			return array();
		}

		$files = array();

		foreach ( $entries as $entry ) {
			if ( 1 === preg_match( '/\.m?js$/i', $entry ) && is_file( $page_dir . '/' . $entry ) ) {
				$files[] = $page_dir . '/' . $entry;
			}
		}

		return array_slice( $files, 0, self::MAX_SIBLINGS );
	}

	/**
	 * Pull every literal assignment out of one script.
	 *
	 * @param string $js Source text.
	 * @return void
	 */
	private function scan( string $js ): void {
		if ( '' === trim( $js ) ) {
			return;
		}

		$this->source .= "\n" . $js;

		$pattern = '/(?:'
			. '(?:^|[;\s{(,])(?:export\s+)?(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?=[\[{])'
			. '|(?:window|globalThis|self|exports|module\.exports)\.([A-Za-z_$][\w$]*)\s*=\s*(?=[\[{])'
			. '|^[ \t]*([A-Za-z_$][\w$]*)\s*=\s*(?=[\[{])'
			. '|\breturn\s*(?=[\[{])'
			. ')/m';

		if ( 1 > preg_match_all( $pattern, $js, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return;
		}

		$seen = 0;

		foreach ( $matches as $match ) {
			if ( ++$seen > self::MAX_LITERALS ) {
				break;
			}

			$name = '';

			foreach ( array( 1, 2, 3 ) as $group ) {
				if ( isset( $match[ $group ] ) && -1 !== $match[ $group ][1] && '' !== $match[ $group ][0] ) {
					$name = $match[ $group ][0];
					break;
				}
			}

			$start = $match[0][1] + strlen( $match[0][0] );
			$value = $this->read_value( $js, $start, 0 );

			if ( null === $value || ! is_array( $value[0] ) ) {
				continue;
			}

			if ( '' === $name ) {
				$this->anonymous[] = $value[0];
			} elseif ( ! array_key_exists( $name, $this->vars ) ) {
				$this->vars[ $name ] = $value[0];
			}
		}
	}

	/**
	 * Read one value starting at an offset.
	 *
	 * @param string $js    Source text.
	 * @param int    $pos   Offset to start at.
	 * @param int    $depth Current nesting.
	 * @return array{0: mixed, 1: int}|null Value and the offset after it; null when unreadable.
	 */
	private function read_value( string $js, int $pos, int $depth ): ?array {
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}

		$pos = self::skip_space( $js, $pos );
		$len = strlen( $js );

		if ( $pos >= $len ) {
			return null;
		}

		$char = $js[ $pos ];

		if ( '{' === $char ) {
			return $this->read_object( $js, $pos + 1, $depth + 1 );
		}

		if ( '[' === $char ) {
			return $this->read_array( $js, $pos + 1, $depth + 1 );
		}

		$value = null;
		$next  = null;

		if ( '"' === $char || "'" === $char || '`' === $char ) {
			$read = self::read_string( $js, $pos );

			if ( null === $read ) {
				return null;
			}

			list( $value, $next ) = $read;
		} elseif ( 1 === preg_match( '/\G-?(?:0[xX][0-9a-fA-F]+|(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)n?/', $js, $match, 0, $pos ) ) {
			$value = self::number( $match[0] );
			$next  = $pos + strlen( $match[0] );
		} elseif ( 1 === preg_match( '/\G(true|false|null|undefined|NaN|Infinity)\b/', $js, $match, 0, $pos ) ) {
			$value = 'true' === $match[1] ? true : ( 'false' === $match[1] ? false : null );
			$next  = $pos + strlen( $match[0] );
		}

		/*
		 * A primitive followed by anything but a separator is the start of an
		 * expression — `'a' + b`, `x.y`, `1 === i`. So is anything that was
		 * not a primitive at all. Either way the value is not data.
		 */
		if ( null !== $next ) {
			$after = self::skip_space( $js, $next );

			if ( $after >= $len || in_array( $js[ $after ], array( ',', ']', '}' ), true ) ) {
				return array( $value, $next );
			}
		}

		$end = self::skip_expression( $js, $pos );

		return null === $end ? null : array( null, $end );
	}

	/**
	 * Read an object literal; the cursor is just after its `{`.
	 *
	 * @param string $js    Source text.
	 * @param int    $pos   Offset after the opening brace.
	 * @param int    $depth Current nesting.
	 * @return array{0: array<string, mixed>, 1: int}|null
	 */
	private function read_object( string $js, int $pos, int $depth ): ?array {
		$object = array();
		$len    = strlen( $js );

		while ( true ) {
			$pos = self::skip_space( $js, $pos );

			if ( $pos >= $len ) {
				return null;
			}

			if ( '}' === $js[ $pos ] ) {
				return array( $object, $pos + 1 );
			}

			if ( ',' === $js[ $pos ] ) {
				++$pos;
				continue;
			}

			// Spread, getters, shorthand methods and computed keys are not data.
			if ( '...' === substr( $js, $pos, 3 ) || '[' === $js[ $pos ] ) {
				$pos = self::skip_expression( $js, $pos );

				if ( null === $pos ) {
					return null;
				}

				continue;
			}

			$key = null;

			if ( '"' === $js[ $pos ] || "'" === $js[ $pos ] ) {
				$read = self::read_string( $js, $pos );

				if ( null === $read ) {
					return null;
				}

				list( $key, $pos ) = $read;
			} elseif ( 1 === preg_match( '/\G[A-Za-z_$][\w$]*|\G\d+(?:\.\d+)?/', $js, $match, 0, $pos ) ) {
				$key  = $match[0];
				$pos += strlen( $match[0] );
			} else {
				return null;
			}

			$pos = self::skip_space( $js, $pos );

			if ( $pos >= $len ) {
				return null;
			}

			if ( ':' !== $js[ $pos ] ) {
				// A shorthand method, getter or shorthand property: code, not data.
				$pos = self::skip_expression( $js, $pos );

				if ( null === $pos ) {
					return null;
				}

				continue;
			}

			$read = $this->read_value( $js, $pos + 1, $depth );

			if ( null === $read ) {
				return null;
			}

			list( $value, $pos ) = $read;

			if ( ! is_string( $key ) ) {
				$key = (string) $key;
			}

			if ( '__proto__' !== $key ) {
				$object[ $key ] = $value;
			}
		}
	}

	/**
	 * Read an array literal; the cursor is just after its `[`.
	 *
	 * @param string $js    Source text.
	 * @param int    $pos   Offset after the opening bracket.
	 * @param int    $depth Current nesting.
	 * @return array{0: array<int, mixed>, 1: int}|null
	 */
	private function read_array( string $js, int $pos, int $depth ): ?array {
		$list = array();
		$len  = strlen( $js );

		while ( true ) {
			$pos = self::skip_space( $js, $pos );

			if ( $pos >= $len ) {
				return null;
			}

			if ( ']' === $js[ $pos ] ) {
				return array( $list, $pos + 1 );
			}

			if ( ',' === $js[ $pos ] ) {
				++$pos;
				continue;
			}

			if ( '...' === substr( $js, $pos, 3 ) ) {
				$pos = self::skip_expression( $js, $pos );

				if ( null === $pos ) {
					return null;
				}

				continue;
			}

			$read = $this->read_value( $js, $pos, $depth );

			if ( null === $read ) {
				return null;
			}

			$list[] = $read[0];
			$pos    = $read[1];
		}
	}

	/**
	 * Read a quoted or template string; the cursor is on its opening quote.
	 *
	 * A template string with `${}` inside is an expression and reads as null.
	 *
	 * @param string $js  Source text.
	 * @param int    $pos Offset of the quote.
	 * @return array{0: string|null, 1: int}|null
	 */
	private static function read_string( string $js, int $pos ): ?array {
		$quote = $js[ $pos ];
		$len   = strlen( $js );
		$out   = '';
		$expr  = false;

		for ( $i = $pos + 1; $i < $len; $i++ ) {
			$char = $js[ $i ];

			if ( $char === $quote ) {
				return array( $expr ? null : $out, $i + 1 );
			}

			if ( '`' === $quote && '$' === $char && '{' === ( $js[ $i + 1 ] ?? '' ) ) {
				$expr = true;
				continue;
			}

			if ( "\n" === $char && '`' !== $quote ) {
				return null;
			}

			if ( '\\' !== $char ) {
				$out .= $char;
				continue;
			}

			++$i;

			if ( $i >= $len ) {
				return null;
			}

			$escaped = $js[ $i ];

			switch ( $escaped ) {
				case 'n':
					$out .= "\n";
					break;
				case 't':
					$out .= "\t";
					break;
				case 'r':
					$out .= "\r";
					break;
				case 'b':
					$out .= "\x08";
					break;
				case 'f':
					$out .= "\f";
					break;
				case 'v':
					$out .= "\v";
					break;
				case '0':
					$out .= "\0";
					break;
				case "\n":
					break;
				case "\r":
					if ( "\n" === ( $js[ $i + 1 ] ?? '' ) ) {
						++$i;
					}
					break;
				case 'x':
					if ( 1 === preg_match( '/\G[0-9a-fA-F]{2}/', $js, $hex, 0, $i + 1 ) ) {
						$out .= self::codepoint( (int) hexdec( $hex[0] ) );
						$i   += 2;
					}
					break;
				case 'u':
					if ( 1 === preg_match( '/\G\{([0-9a-fA-F]{1,6})\}/', $js, $hex, 0, $i + 1 ) ) {
						$out .= self::codepoint( (int) hexdec( $hex[1] ) );
						$i   += strlen( $hex[0] );
					} elseif ( 1 === preg_match( '/\G[0-9a-fA-F]{4}/', $js, $hex, 0, $i + 1 ) ) {
						$code = (int) hexdec( $hex[0] );
						$i   += 4;

						// A surrogate pair encodes one character beyond the BMP.
						if ( $code >= 0xD800 && $code <= 0xDBFF && 1 === preg_match( '/\G\\\\u([dD][c-fC-F][0-9a-fA-F]{2})/', $js, $low, 0, $i + 1 ) ) {
							$code = 0x10000 + ( ( $code - 0xD800 ) << 10 ) + ( (int) hexdec( $low[1] ) - 0xDC00 );
							$i   += 6;
						}

						$out .= self::codepoint( $code );
					}
					break;
				default:
					$out .= $escaped;
			}
		}

		return null;
	}

	/**
	 * UTF-8 for one code point.
	 *
	 * @param int $code Unicode code point.
	 * @return string
	 */
	private static function codepoint( int $code ): string {
		$char = mb_chr( $code, 'UTF-8' );

		return false === $char ? '' : $char;
	}

	/**
	 * Turn a JS numeric token into a PHP number.
	 *
	 * @param string $token Matched token.
	 * @return int|float
	 */
	private static function number( string $token ) {
		$token = rtrim( $token, 'n' );

		if ( 1 === preg_match( '/^-?0[xX]/', $token ) ) {
			$value = hexdec( ltrim( $token, '-' ) );

			return '-' === $token[0] ? -$value : $value;
		}

		$value = (float) $token;

		return ( floor( $value ) === $value && abs( $value ) < PHP_INT_MAX && ! str_contains( $token, '.' ) && ! str_contains( strtolower( $token ), 'e' ) )
			? (int) $value
			: $value;
	}

	/**
	 * Advance past whitespace and comments.
	 *
	 * @param string $js  Source text.
	 * @param int    $pos Offset.
	 * @return int
	 */
	private static function skip_space( string $js, int $pos ): int {
		$len = strlen( $js );

		while ( $pos < $len ) {
			$char = $js[ $pos ];

			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char || "\f" === $char || "\v" === $char ) {
				++$pos;
				continue;
			}

			if ( '/' === $char && '/' === ( $js[ $pos + 1 ] ?? '' ) ) {
				$end = strpos( $js, "\n", $pos );
				$pos = false === $end ? $len : $end + 1;
				continue;
			}

			if ( '/' === $char && '*' === ( $js[ $pos + 1 ] ?? '' ) ) {
				$end = strpos( $js, '*/', $pos + 2 );
				$pos = false === $end ? $len : $end + 2;
				continue;
			}

			break;
		}

		return $pos;
	}

	/**
	 * Advance past one expression, up to the `,` `]` or `}` that ends it.
	 *
	 * Brackets, strings, comments and regex literals are balanced so a comma
	 * inside a call or a bracket inside a string does not end the value early.
	 *
	 * @param string $js  Source text.
	 * @param int    $pos Offset where the expression starts.
	 * @return int|null Offset of the terminator, or null if the source ends first.
	 */
	private static function skip_expression( string $js, int $pos ): ?int {
		$len   = strlen( $js );
		$depth = 0;
		$last  = '';

		while ( $pos < $len ) {
			$char = $js[ $pos ];

			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$read = self::read_string( $js, $pos );

				if ( null === $read ) {
					return null;
				}

				$pos  = $read[1];
				$last = 'x';
				continue;
			}

			if ( '/' === $char && in_array( $js[ $pos + 1 ] ?? '', array( '/', '*' ), true ) ) {
				$next = self::skip_space( $js, $pos );

				if ( $next === $pos ) {
					++$pos;
				} else {
					$pos = $next;
				}

				continue;
			}

			if ( '/' === $char && ( '' === $last || str_contains( '(,=:[!&|?{};+-*%<>~^', $last ) ) ) {
				$end = self::skip_regex( $js, $pos );

				if ( null === $end ) {
					return null;
				}

				$pos  = $end;
				$last = 'x';
				continue;
			}

			if ( '(' === $char || '[' === $char || '{' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char || '}' === $char ) {
				if ( 0 === $depth ) {
					return $pos;
				}

				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				return $pos;
			}

			if ( ! ctype_space( $char ) ) {
				$last = $char;
			}

			++$pos;
		}

		return null;
	}

	/**
	 * Advance past a regex literal; the cursor is on its opening slash.
	 *
	 * @param string $js  Source text.
	 * @param int    $pos Offset of the slash.
	 * @return int|null Offset after the closing slash and flags.
	 */
	private static function skip_regex( string $js, int $pos ): ?int {
		$len   = strlen( $js );
		$class = false;

		for ( $i = $pos + 1; $i < $len; $i++ ) {
			$char = $js[ $i ];

			if ( '\\' === $char ) {
				++$i;
				continue;
			}

			if ( "\n" === $char ) {
				return null;
			}

			if ( $class ) {
				$class = ']' !== $char;
				continue;
			}

			if ( '[' === $char ) {
				$class = true;
				continue;
			}

			if ( '/' === $char ) {
				++$i;

				while ( $i < $len && ctype_alpha( $js[ $i ] ) ) {
					++$i;
				}

				return $i;
			}
		}

		return null;
	}
}
