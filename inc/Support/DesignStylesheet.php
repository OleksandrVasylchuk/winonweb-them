<?php
/**
 * The design's own stylesheet, carried onto the site as Additional CSS.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the archive's CSS as the site's custom CSS, rewritten to survive the move.
 *
 * The converter rebuilds a page out of core blocks and keeps the design's
 * class names on them. That is only half of the job: the rules those classes
 * pointed at are still in the archive. This class reads them, drops what
 * would fight WordPress — the reset, the sticky header, a second `body` —
 * repoints every `url()` at the Media Library, and appends the rest to the
 * global styles' Additional CSS between markers, so reset() can take exactly
 * that slice out again.
 *
 * Nothing is prefixed or layered. Layered CSS loses to the theme's own
 * unlayered rules and would be inert; prefixed selectors would no longer
 * match. The rules land as written and win by source order, which is the
 * point: the site is meant to look like the archive.
 */
final class DesignStylesheet {

	/**
	 * Opens the slice this class owns inside Additional CSS.
	 *
	 * @var string
	 */
	public const START = '/* wow-signal:design-css:start */';

	/**
	 * Closes the slice.
	 *
	 * @var string
	 */
	public const END = '/* wow-signal:design-css:end */';

	/**
	 * Most CSS that is installed, in bytes.
	 *
	 * @var int
	 */
	private const CAP = 307200;

	/**
	 * Largest single file that is read.
	 *
	 * @var int
	 */
	private const MAX_FILE = 2097152;

	/**
	 * At-rules whose block holds further rules rather than declarations.
	 *
	 * @var array<int, string>
	 */
	private const GROUP_RULES = array( 'media', 'supports', 'container', 'layer', 'document', 'scope' );

	/**
	 * At-rules that never come across.
	 *
	 * Fonts are DesignFonts' job; the others either pull remote resources or
	 * are not CSS the site should be running.
	 *
	 * @var array<int, string>
	 */
	private const DROPPED_RULES = array( 'font-face', 'import', 'charset', 'namespace' );

	/**
	 * Declarations a container-like selector is not allowed to set.
	 *
	 * The block layout owns the content width; a design's `.container`
	 * narrowing or centring a block wrapper fights it.
	 *
	 * @var array<int, string>
	 */
	private const LAYOUT_PROPERTIES = array( 'max-width', 'margin-left', 'margin-right', 'margin-inline', 'margin-inline-start', 'margin-inline-end' );

	/**
	 * Counters for the current import.
	 *
	 * @var array{rules:int,dropped:int,unmapped:int}
	 */
	private static array $counts = array(
		'rules'    => 0,
		'dropped'  => 0,
		'unmapped' => 0,
	);

	/**
	 * Read the design's CSS, rewrite it, and append it to Additional CSS.
	 *
	 * @param string                                  $root      Design root directory.
	 * @param array<string, array{id:int,url:string}> $media_map Archive-relative path to imported attachment.
	 * @return array{bytes:int,rules:int,dropped:int,unmapped:int,installed:bool}
	 */
	public static function import( string $root, array $media_map = array() ): array {
		self::$counts = array(
			'rules'    => 0,
			'dropped'  => 0,
			'unmapped' => 0,
		);

		$root   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$pieces = array();

		foreach ( self::gather( $root ) as $sheet ) {
			$pieces[] = self::rewrite( (string) $sheet['css'], (string) $sheet['dir'], $root, $media_map );
		}

		$css = trim( implode( "\n", array_filter( $pieces ) ) );

		if ( strlen( $css ) > self::CAP ) {
			$css = self::prune( $css, self::html_classes( $root ) );
		}

		if ( strlen( $css ) > self::CAP ) {
			$css = self::truncate( $css, self::CAP );
		}

		self::reset();

		$installed = false;

		if ( '' !== $css ) {
			$data     = self::user_data();
			$existing = isset( $data['styles']['css'] ) ? (string) $data['styles']['css'] : '';

			$data['styles']['css'] = trim( $existing . "\n" . self::START . "\n" . $css . "\n" . self::END );

			self::save( $data );

			/*
			 * WordPress filters the global styles post on save and strips
			 * Additional CSS from anyone without `edit_css`. Say so rather
			 * than report bytes that never reached the site.
			 */
			$installed = self::installed();
		}

		return array(
			'bytes'     => $installed ? strlen( $css ) : 0,
			'rules'     => self::$counts['rules'],
			'dropped'   => self::$counts['dropped'],
			'unmapped'  => self::$counts['unmapped'],
			'installed' => $installed,
		);
	}

	/**
	 * Take the design's slice out of Additional CSS, leaving the rest as it was.
	 *
	 * @return bool Whether there was a slice to remove.
	 */
	public static function reset(): bool {
		$data     = self::user_data();
		$existing = isset( $data['styles']['css'] ) ? (string) $data['styles']['css'] : '';

		if ( ! str_contains( $existing, self::START ) ) {
			return false;
		}

		$pattern = '/\s*' . preg_quote( self::START, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\s*/s';
		$kept    = trim( (string) preg_replace( $pattern, "\n", $existing ) );

		if ( '' === $kept ) {
			unset( $data['styles']['css'] );
		} else {
			$data['styles']['css'] = $kept;
		}

		self::save( $data );

		return true;
	}

	/**
	 * Whether a design stylesheet is currently installed.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		$data = self::user_data();

		return isset( $data['styles']['css'] ) && str_contains( (string) $data['styles']['css'], self::START );
	}

	// ----------------------------------------------------------------- gather

	/**
	 * Every stylesheet in the design, each with the directory it resolves URLs from.
	 *
	 * Mirrors DesignTokens::gather_css: all .css files, then the inline
	 * `<style>` blocks of every page, because some exports ship no .css at all.
	 *
	 * @param string $root Design root.
	 * @return array<int, array{css:string,dir:string}>
	 */
	private static function gather( string $root ): array {
		$sheets = array();

		if ( ! is_dir( $root ) ) {
			return $sheets;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		$files = array();
		$pages = array();

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getSize() >= self::MAX_FILE ) {
				continue;
			}

			$name = $file->getFilename();

			if ( 'css' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			} elseif ( 1 === preg_match( '/\.html?$/i', $name ) ) {
				$pages[] = $file->getPathname();
			}
		}

		sort( $files );
		sort( $pages );

		foreach ( $files as $path ) {
			$sheets[] = array(
				'css' => (string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.
				'dir' => self::relative_dir( $path, $root ),
			);
		}

		foreach ( $pages as $path ) {
			$html = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			if ( preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $blocks ) ) {
				$sheets[] = array(
					'css' => implode( "\n", $blocks[1] ),
					'dir' => self::relative_dir( $path, $root ),
				);
			}
		}

		return $sheets;
	}

	/**
	 * The archive-relative directory of a file, forward-slashed, no edges.
	 *
	 * @param string $path Absolute path.
	 * @param string $root Design root.
	 * @return string
	 */
	private static function relative_dir( string $path, string $root ): string {
		$path = str_replace( '\\', '/', $path );
		$rel  = str_starts_with( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : $path;
		$dir  = dirname( $rel );

		return '.' === $dir ? '' : trim( $dir, '/' );
	}

	/**
	 * Every class name used in the design's pages.
	 *
	 * Stands in for the converter's carried set when the stylesheet is too
	 * big: the converter runs page by page after the stylesheet is installed,
	 * so what it will carry is not known yet, but it cannot carry a class the
	 * pages do not use.
	 *
	 * @param string $root Design root.
	 * @return array<string, true>
	 */
	private static function html_classes( string $root ): array {
		$classes  = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 1 !== preg_match( '/\.html?$/i', $file->getFilename() ) || $file->getSize() >= self::MAX_FILE ) {
				continue;
			}

			$html = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local file unpacked by DesignArchive.

			if ( preg_match_all( '/\sclass\s*=\s*["\']([^"\']+)["\']/i', $html, $found ) ) {
				foreach ( $found[1] as $list ) {
					$names = preg_split( '/\s+/', $list );

					foreach ( is_array( $names ) ? $names : array() as $class ) {
						if ( '' !== $class ) {
							$classes[ $class ] = true;
						}
					}
				}
			}
		}

		return $classes;
	}

	// ---------------------------------------------------------------- rewrite

	/**
	 * Rewrite one stylesheet.
	 *
	 * @param string                                  $css  Stylesheet text.
	 * @param string                                  $dir  Its archive-relative directory.
	 * @param string                                  $root Design root.
	 * @param array<string, array{id:int,url:string}> $map  Media map.
	 * @return string
	 */
	private static function rewrite( string $css, string $dir, string $root, array $map ): string {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = str_replace( array( "\r\n", "\r" ), "\n", $css );

		return self::emit( self::parse( $css ), $dir, $root, $map );
	}

	/**
	 * Serialise a parsed tree, rewriting as it goes.
	 *
	 * @param array<int, array<string, mixed>>        $nodes Parsed nodes.
	 * @param string                                  $dir   Archive-relative directory.
	 * @param string                                  $root  Design root.
	 * @param array<string, array{id:int,url:string}> $map   Media map.
	 * @return string
	 */
	private static function emit( array $nodes, string $dir, string $root, array $map ): string {
		$out = array();

		foreach ( $nodes as $node ) {
			switch ( $node['type'] ) {
				case 'statement':
					// @import, @charset, @namespace, @layer names: none come across.
					++self::$counts['dropped'];
					break;

				case 'group':
					$inner = self::emit( (array) $node['children'], $dir, $root, $map );

					if ( '' !== trim( $inner ) ) {
						$out[] = self::scrub( (string) $node['prelude'] ) . '{' . $inner . '}';
					}
					break;

				case 'at':
					if ( in_array( $node['name'], self::DROPPED_RULES, true ) ) {
						++self::$counts['dropped'];
						break;
					}

					// @keyframes, @page, @property and friends: kept whole, pictures repointed.
					$body = self::rewrite_urls( self::scrub( (string) $node['body'] ), $dir, $root, $map, $lost );

					if ( ! $lost ) {
						++self::$counts['rules'];
						$out[] = self::scrub( (string) $node['prelude'] ) . '{' . $body . '}';
					} else {
						++self::$counts['dropped'];
					}
					break;

				case 'rule':
					$rule = self::rule( (string) $node['selector'], (string) $node['body'], $dir, $root, $map );

					if ( '' !== $rule ) {
						$out[] = $rule;
					}
					break;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Rewrite one style rule, or drop it.
	 *
	 * @param string                                  $selector Selector list.
	 * @param string                                  $body     Declaration text.
	 * @param string                                  $dir      Archive-relative directory.
	 * @param string                                  $root     Design root.
	 * @param array<string, array{id:int,url:string}> $map      Media map.
	 * @return string Empty when the rule is dropped.
	 */
	private static function rule( string $selector, string $body, string $dir, string $root, array $map ): string {
		$selector = self::scrub( trim( (string) preg_replace( '/\s+/', ' ', $selector ) ) );

		if ( '' === $selector || str_contains( $selector, '<' ) ) {
			++self::$counts['dropped'];

			return '';
		}

		$root_only = self::is_root_selector( $selector );
		$chrome    = 1 === preg_match( '/(nav|header|topbar|masthead|menu)/i', $selector );
		$container = 1 === preg_match( '/\.(container|wrap|wrapper|inner|content)(?![\w-])/i', $selector );
		$kept      = array();

		foreach ( self::declarations( $body ) as $declaration ) {
			list( $property, $value ) = $declaration;

			$lower = strtolower( $property );
			$check = strtolower( $value );

			// Variables are kept wherever they are declared; on html/body/:root they are all that is kept.
			if ( str_starts_with( $lower, '--' ) ) {
				if ( ! self::is_safe( $check ) ) {
					continue;
				}

				$kept[] = $property . ':' . self::rewrite_urls( $value, $dir, $root, $map, $lost );
				continue;
			}

			if ( $root_only || ! self::is_safe( $check ) ) {
				continue;
			}

			if ( $chrome && 'position' === $lower && 1 === preg_match( '/\b(fixed|sticky)\b/', $check ) ) {
				continue;
			}

			if ( $container && in_array( $lower, self::LAYOUT_PROPERTIES, true ) ) {
				continue;
			}

			if ( $container && 'margin' === $lower && str_contains( $check, 'auto' ) ) {
				continue;
			}

			$lost  = false;
			$value = self::rewrite_urls( self::scrub( $value ), $dir, $root, $map, $lost );

			if ( $lost ) {
				// The picture is not in the archive; a broken url() is worse than none.
				continue;
			}

			$kept[] = $property . ':' . $value;
		}

		if ( array() === $kept ) {
			++self::$counts['dropped'];

			return '';
		}

		// A helper that only hides a bare tag or [hidden] is the browser's own job.
		if ( 1 === count( $kept ) && 1 === preg_match( '/^display\s*:\s*none/i', $kept[0] ) && 1 === preg_match( '/^(?:[a-z][a-z0-9-]*|\[hidden\]|\.no-js(?:\s.*)?)$/i', $selector ) ) {
			++self::$counts['dropped'];

			return '';
		}

		++self::$counts['rules'];

		return $selector . '{' . implode( ';', $kept ) . '}';
	}

	/**
	 * Whether a selector list is nothing but html, body, the universal selector or :root.
	 *
	 * @param string $selector Selector list.
	 * @return bool
	 */
	private static function is_root_selector( string $selector ): bool {
		foreach ( self::split_top( $selector, ',' ) as $part ) {
			$part = trim( (string) preg_replace( '/::?(before|after)\b/i', '', $part ) );

			if ( 1 !== preg_match( '/^(?:html|body|\*|:root)(?:\s+(?:html|body|\*|:root))*$/i', $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is CSS and only CSS.
	 *
	 * @param string $value Lower-cased declaration value.
	 * @return bool
	 */
	private static function is_safe( string $value ): bool {
		return 1 !== preg_match( '/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import|<\/?\s*script/i', $value );
	}

	/**
	 * Strip what can never be CSS, and the one character that can end a <style> element.
	 *
	 * @param string $text Any CSS text.
	 * @return string
	 */
	private static function scrub( string $text ): string {
		$text = (string) preg_replace( '/expression\s*\([^)]*\)|-moz-binding\s*:[^;]*;?|behavior\s*:[^;]*;?/i', '', $text );

		// As an escape it is still the same character to the parser, and harmless to the page.
		return str_replace( '<', '\\3c ', $text );
	}

	/**
	 * Repoint every relative url() at the Media Library.
	 *
	 * @param string                                  $value Declaration value.
	 * @param string                                  $dir   Archive-relative directory the value resolves from.
	 * @param string                                  $root  Design root.
	 * @param array<string, array{id:int,url:string}> $map   Media map.
	 * @param bool|null                               $lost  Set when a url() points at a file the archive does not have.
	 * @return string
	 */
	private static function rewrite_urls( string $value, string $dir, string $root, array $map, ?bool &$lost = null ): string {
		$lost = false;

		if ( ! str_contains( strtolower( $value ), 'url(' ) ) {
			return $value;
		}

		return (string) preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]*)\1\s*\)/i',
			static function ( array $found ) use ( $dir, $root, $map, &$lost ): string {
				$target = trim( $found[2] );

				if ( '' === $target || str_starts_with( $target, '#' ) || 1 === preg_match( '#^(?:https?:)?//|^data:|^blob:#i', $target ) ) {
					return $found[0];
				}

				if ( 1 === preg_match( '#^(?:javascript|vbscript):#i', $target ) ) {
					$lost = true;

					return 'url()';
				}

				$suffix = '';

				if ( 1 === preg_match( '/^([^?#]*)([?#].*)$/', $target, $parts ) ) {
					$target = $parts[1];
					$suffix = $parts[2];
				}

				$resolved = self::resolve( $target, $dir, $map );

				if ( null !== $resolved ) {
					return 'url("' . esc_url_raw( $resolved['url'] ) . '")';
				}

				$relative = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : self::normalise( $dir . '/' . $target );

				if ( '' !== $relative && is_file( $root . '/' . $relative ) ) {
					// In the archive but not in the Media Library (a font, an SVG sprite): left as written.
					++self::$counts['unmapped'];

					return $found[0];
				}

				$lost = true;

				return 'url(' . $found[1] . $target . $suffix . $found[1] . ')';
			},
			$value
		);
	}

	/**
	 * Find a url() target in the media map.
	 *
	 * Same candidates as SiteBuilder::relink_media: the path as written, the
	 * path against the stylesheet's directory, and finally the bare file name.
	 *
	 * @param string                                  $target Path from the url().
	 * @param string                                  $dir    Archive-relative directory.
	 * @param array<string, array{id:int,url:string}> $map    Media map.
	 * @return array{id:int,url:string}|null
	 */
	private static function resolve( string $target, string $dir, array $map ) {
		if ( array() === $map ) {
			return null;
		}

		$candidates = array( ltrim( $target, '/' ) );

		if ( '' !== $dir && ! str_starts_with( $target, '/' ) ) {
			$candidates[] = self::normalise( $dir . '/' . $target );
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $map[ $candidate ] ) ) {
				return $map[ $candidate ];
			}
		}

		$name = basename( $target );

		foreach ( $map as $rel => $attachment ) {
			if ( basename( (string) $rel ) === $name ) {
				return $attachment;
			}
		}

		return null;
	}

	/**
	 * Collapse . and .. inside a relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalise( string $path ): string {
		$out = array();

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop( $out );
				continue;
			}

			$out[] = $part;
		}

		return implode( '/', $out );
	}

	// ------------------------------------------------------------------ parse

	/**
	 * Parse CSS into rules, at-rules and groups.
	 *
	 * A small scanner rather than a grammar: it only has to find the braces
	 * that matter while ignoring the ones inside strings and parentheses.
	 *
	 * @param string $css Comment-free CSS.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse( string $css ): array {
		$nodes  = array();
		$length = strlen( $css );
		$start  = 0;
		$depth  = 0;
		$quote  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
				continue;
			}

			if ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}

			if ( $depth > 0 ) {
				continue;
			}

			if ( ';' === $char ) {
				$text = trim( substr( $css, $start, $i - $start ) );

				if ( '' !== $text ) {
					$nodes[] = array(
						'type' => 'statement',
						'text' => $text,
					);
				}

				$start = $i + 1;
				continue;
			}

			if ( '}' === $char ) {
				// A stray close; whatever preceded it was not a rule.
				$start = $i + 1;
				continue;
			}

			if ( '{' !== $char ) {
				continue;
			}

			$prelude = trim( substr( $css, $start, $i - $start ) );
			$end     = self::matching_brace( $css, $i );
			$body    = substr( $css, $i + 1, max( 0, $end - $i - 1 ) );

			if ( str_starts_with( $prelude, '@' ) ) {
				$name = strtolower( (string) preg_replace( '/^@([a-z-]+).*$/is', '$1', $prelude ) );

				if ( in_array( $name, self::GROUP_RULES, true ) ) {
					$nodes[] = array(
						'type'     => 'group',
						'name'     => $name,
						'prelude'  => $prelude,
						'children' => self::parse( $body ),
					);
				} else {
					$nodes[] = array(
						'type'    => 'at',
						'name'    => $name,
						'prelude' => $prelude,
						'body'    => $body,
					);
				}
			} elseif ( '' !== $prelude ) {
				$nodes[] = array(
					'type'     => 'rule',
					'selector' => $prelude,
					'body'     => $body,
				);
			}

			$i     = $end;
			$start = $end + 1;
		}

		return $nodes;
	}

	/**
	 * Index of the brace closing the one at $open.
	 *
	 * @param string $css  CSS text.
	 * @param int    $open Index of an opening brace.
	 * @return int Index of the close, or the last index when unbalanced.
	 */
	private static function matching_brace( string $css, int $open ): int {
		$length = strlen( $css );
		$depth  = 0;
		$quote  = '';

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return $length - 1;
	}

	/**
	 * Split a declaration block into property/value pairs.
	 *
	 * @param string $body Declaration text.
	 * @return array<int, array{0:string,1:string}>
	 */
	private static function declarations( string $body ): array {
		$out = array();

		foreach ( self::split_top( $body, ';' ) as $declaration ) {
			$declaration = trim( $declaration );

			if ( '' === $declaration || ! str_contains( $declaration, ':' ) ) {
				continue;
			}

			list( $property, $value ) = explode( ':', $declaration, 2 );

			$property = trim( $property );
			$value    = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

			if ( '' === $property || '' === $value || 1 !== preg_match( '/^-{0,2}[a-z][a-z0-9_-]*$/i', $property ) ) {
				continue;
			}

			$out[] = array( $property, $value );
		}

		return $out;
	}

	/**
	 * Split on a separator, ignoring ones inside strings and parentheses.
	 *
	 * @param string $text      Text.
	 * @param string $separator One character.
	 * @return array<int, string>
	 */
	private static function split_top( string $text, string $separator ): array {
		$parts  = array();
		$length = strlen( $text );
		$start  = 0;
		$depth  = 0;
		$quote  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $char === $separator && 0 === $depth ) {
				$parts[] = substr( $text, $start, $i - $start );
				$start   = $i + 1;
			}
		}

		$parts[] = substr( $text, $start );

		return $parts;
	}

	// ------------------------------------------------------------------- size

	/**
	 * Keep only the rules whose selectors name a class the pages use.
	 *
	 * @param string              $css     Rewritten CSS.
	 * @param array<string, true> $classes Class names in use.
	 * @return string
	 */
	private static function prune( string $css, array $classes ): string {
		if ( array() === $classes ) {
			return $css;
		}

		$keep = static function ( string $selector ) use ( $classes ): bool {
			if ( preg_match_all( '/\.(-?[_a-zA-Z][\w-]*)/', $selector, $found ) ) {
				foreach ( $found[1] as $class ) {
					if ( isset( $classes[ $class ] ) ) {
						return true;
					}
				}
			}

			return false;
		};

		$walk = static function ( array $nodes ) use ( &$walk, $keep ): string {
			$out = array();

			foreach ( $nodes as $node ) {
				if ( 'group' === $node['type'] ) {
					$inner = $walk( (array) $node['children'] );

					if ( '' !== $inner ) {
						$out[] = $node['prelude'] . '{' . $inner . '}';
					}
				} elseif ( 'at' === $node['type'] ) {
					$out[] = $node['prelude'] . '{' . $node['body'] . '}';
				} elseif ( 'rule' === $node['type'] && $keep( (string) $node['selector'] ) ) {
					$out[] = $node['selector'] . '{' . $node['body'] . '}';
				}
			}

			return implode( "\n", $out );
		};

		return $walk( self::parse( $css ) );
	}

	/**
	 * Cut CSS at a rule boundary under a byte limit.
	 *
	 * @param string $css   CSS.
	 * @param int    $limit Byte limit.
	 * @return string
	 */
	private static function truncate( string $css, int $limit ): string {
		$out = '';

		foreach ( self::parse( $css ) as $node ) {
			if ( 'group' === $node['type'] ) {
				$inner = self::truncate( (string) self::flatten( (array) $node['children'] ), $limit - strlen( $out ) - strlen( (string) $node['prelude'] ) - 2 );
				$piece = '' === $inner ? '' : $node['prelude'] . '{' . $inner . '}';
			} elseif ( 'statement' === $node['type'] ) {
				$piece = '';
			} else {
				$piece = ( $node['prelude'] ?? $node['selector'] ) . '{' . $node['body'] . '}';
			}

			if ( '' === $piece ) {
				continue;
			}

			if ( strlen( $out ) + strlen( $piece ) + 1 > $limit ) {
				break;
			}

			$out .= ( '' === $out ? '' : "\n" ) . $piece;
		}

		return $out;
	}

	/**
	 * Serialise parsed nodes back to text without changing them.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodes.
	 * @return string
	 */
	private static function flatten( array $nodes ): string {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( 'group' === $node['type'] ) {
				$out[] = $node['prelude'] . '{' . self::flatten( (array) $node['children'] ) . '}';
			} elseif ( 'statement' !== $node['type'] ) {
				$out[] = ( $node['prelude'] ?? $node['selector'] ) . '{' . $node['body'] . '}';
			}
		}

		return implode( "\n", $out );
	}

	// ---------------------------------------------------------------- storage

	/**
	 * The site's current user global styles, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private static function user_data(): array {
		$id   = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post = get_post( $id );
		$data = null === $post ? array() : json_decode( (string) $post->post_content, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['styles'] = isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array();

		return $data;
	}

	/**
	 * Store global styles and drop every cached copy.
	 *
	 * @param array<string, mixed> $data Global styles.
	 * @return void
	 */
	private static function save( array $data ): void {
		if ( array() === $data['styles'] ) {
			unset( $data['styles'] );
		}

		wp_update_post(
			array(
				'ID'           => WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);

		wp_clean_theme_json_cache();
	}
}
