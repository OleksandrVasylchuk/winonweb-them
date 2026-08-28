<?php
/**
 * Reads a component-source project inside an unpacked design.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The design when the design is an application.
 *
 * A React or Vue export has no markup on disk: its HTML files hold an empty
 * root element and every page is assembled in the browser. The importer used
 * to report that honestly and stop — "nothing on this page could be
 * converted", once per page — which is true and useless, because the design
 * *is* there, written as components rather than as pages.
 *
 * This class is the deterministic half of reading one. It finds the project,
 * works out which URLs it serves and which component answers each of them,
 * and gathers the source a reader would need to know what one page looks
 * like: the page component, the components it renders, the layout around
 * them, the stylesheets, and the pictures that exist to point at. No model is
 * involved here on purpose — which file is which is a question with a right
 * answer, and paying tokens to guess at it would be both slower and worse.
 *
 * What the model is then asked for is the one thing that genuinely needs
 * judgement: what this component tree renders. See {@see SourceRenderer}.
 */
final class SourceProject {

	/**
	 * Directories that are never part of a design's own source.
	 */
	private const IGNORED_DIRS = array( 'node_modules', 'dist', 'build', '.next', '.nuxt', '.git', 'coverage', '__pycache__', 'vendor' );

	/**
	 * Source extensions, in the order a bare import is resolved against them.
	 */
	private const RESOLUTION_ORDER = array( '.tsx', '.ts', '.jsx', '.js', '.mjs', '.vue', '/index.tsx', '/index.ts', '/index.jsx', '/index.js', '/index.vue' );

	/**
	 * How deep the import graph is followed from a page component.
	 */
	private const IMPORT_DEPTH = 2;

	/**
	 * Most characters of the page's own file that go into a bundle.
	 *
	 * Generous, because a project that keeps nine page components in one file
	 * has to arrive whole: truncating it cuts the last pages off the brief and
	 * the model renders a page it can only see half of.
	 */
	private const MAX_PAGE_CHARS = 120000;

	/**
	 * Most characters of any other source file that goes into a bundle.
	 */
	private const MAX_FILE_CHARS = 24000;

	/**
	 * Most characters a whole bundle may carry.
	 */
	private const MAX_BUNDLE_CHARS = 260000;

	/**
	 * Most characters taken from a data module, which is usually a catalogue.
	 */
	private const MAX_DATA_CHARS = 4000;

	/**
	 * Design root, absolute, forward slashes, no trailing slash.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Project directory, absolute.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Project directory relative to the design root, with a trailing slash.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * What the `@/` style import alias points at, relative to the project.
	 *
	 * @var string
	 */
	private string $alias = 'src';

	/**
	 * Every source file in the project: path relative to the project => size.
	 *
	 * @var array<string, int>
	 */
	private array $files = array();

	/**
	 * Framework: react, vue, or an empty string when it could not be told.
	 *
	 * @var string
	 */
	private string $framework = '';

	/**
	 * Things worth saying about this project that are not errors.
	 *
	 * @var array<int, string>
	 */
	private array $notes = array();

	/**
	 * How many component files this project holds — its size, for the chooser.
	 *
	 * @var int
	 */
	private int $components = 0;

	/**
	 * Private: instances come from find().
	 *
	 * @param string $root Design root.
	 * @param string $dir  Project directory.
	 */
	private function __construct( string $root, string $dir ) {
		$this->root   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->dir    = rtrim( str_replace( '\\', '/', $dir ), '/' );
		$this->prefix = '' === trim( substr( $this->dir, strlen( $this->root ) ), '/' )
			? ''
			: trim( substr( $this->dir, strlen( $this->root ) ), '/' ) . '/';
	}

	/**
	 * Find the application inside an unpacked design, if there is one.
	 *
	 * A handoff archive holds the source and the built copy of the same site,
	 * plus documentation and product data. The one that can be read is the
	 * source tree, so candidates are ranked by how much component source they
	 * actually contain rather than by where they sit in the archive.
	 *
	 * @param string $root Design root.
	 * @return self|null
	 */
	public static function find( string $root ) {
		$projects = self::all( $root );

		return array() === $projects ? null : $projects[0];
	}

	/**
	 * Every application inside an unpacked design, biggest first.
	 *
	 * A handoff package is regularly more than one project: the site, an admin
	 * blueprint, a widget meant to ship as a plugin. Taking only the largest
	 * of them — which is what this used to do, with one `arsort()` and one
	 * `array_key_first()` — meant the rest were dropped without a word, and a
	 * person who had shipped three applications was shown the pages of one and
	 * left to guess where the others went.
	 *
	 * Which of them belongs on this site is not a question the code can
	 * answer: one may be the theme and another a plugin. So all of them are
	 * returned, and the screen asks.
	 *
	 * @param string $root Design root.
	 * @return array<int, self> Projects, most component source first.
	 */
	public static function all( string $root ): array {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$counts = array();

		foreach ( self::walk( $root ) as $path => $size ) {
			unset( $size );

			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array( 'tsx', 'jsx', 'vue' ), true ) ) {
				continue;
			}

			/*
			 * The project is the directory above `src/`, wherever that turns
			 * out to be. Counting by that directory rather than by the file's
			 * own parent keeps `src/pages` and `src/components` on the same
			 * tally instead of competing with each other.
			 */
			$dir = self::project_dir_of( $root, $path );

			// An empty directory is the design root itself: an archive that is only the app.
			$counts[ $dir ] = ( $counts[ $dir ] ?? 0 ) + 1;
		}

		if ( array() === $counts ) {
			return array();
		}

		arsort( $counts );

		$projects = array();

		foreach ( $counts as $dir => $components ) {
			$dir = (string) $dir;

			/*
			 * A directory that is inside one already taken is part of that
			 * project, not another one. Without this a monorepo whose packages
			 * each hold a `src/` would be listed once per package and once
			 * more for the repository around them.
			 */
			if ( self::inside_any( $dir, array_keys( $projects ) ) ) {
				continue;
			}

			$project = new self( $root, '' === $dir ? $root : $root . '/' . $dir );
			$project->load();

			if ( '' === $project->framework() ) {
				continue;
			}

			$project->components = (int) $components;
			$projects[ $dir ]    = $project;
		}

		return array_values( $projects );
	}

	/**
	 * Whether one project directory sits inside another already found.
	 *
	 * @param string            $dir   Directory relative to the design root.
	 * @param array<int,string> $taken Directories already claimed.
	 * @return bool
	 */
	private static function inside_any( string $dir, array $taken ): bool {
		foreach ( $taken as $other ) {
			$other = (string) $other;

			if ( '' === $other ) {
				// The root is everybody's parent; it only wins when it is alone.
				return true;
			}

			if ( $dir === $other || 0 === strpos( $dir . '/', $other . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Which project directory a component file belongs to.
	 *
	 * @param string $root Design root.
	 * @param string $path Path relative to the root.
	 * @return string Directory relative to the root, possibly empty for the root itself.
	 */
	private static function project_dir_of( string $root, string $path ): string {
		unset( $root );

		$segments = explode( '/', $path );
		$index    = array_search( 'src', $segments, true );

		if ( false !== $index ) {
			return implode( '/', array_slice( $segments, 0, (int) $index ) );
		}

		// No src/ folder: the file's own directory's parent is as good a guess as any.
		return implode( '/', array_slice( $segments, 0, max( 0, count( $segments ) - 2 ) ) );
	}

	/**
	 * Walk a directory, skipping the trees no design is written in.
	 *
	 * @param string $dir Absolute directory.
	 * @return array<string, int> Relative path => size in bytes.
	 */
	private static function walk( string $dir ): array {
		$found = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					static function ( $current ): bool {
						return ! $current->isDir() || ! in_array( strtolower( $current->getFilename() ), self::IGNORED_DIRS, true );
					}
				)
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$path = str_replace( '\\', '/', $file->getPathname() );

				$found[ ltrim( substr( $path, strlen( $dir ) ), '/' ) ] = (int) $file->getSize();
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		return $found;
	}

	/**
	 * Read the project: its files, its framework and its import alias.
	 *
	 * @return void
	 */
	private function load(): void {
		foreach ( self::walk( $this->dir ) as $path => $size ) {
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( in_array( $extension, array( 'ts', 'tsx', 'js', 'jsx', 'mjs', 'vue', 'css', 'scss', 'json', 'html' ), true ) ) {
				$this->files[ $path ] = $size;
			}
		}

		$manifest = $this->read( 'package.json' );

		if ( '' !== $manifest ) {
			if ( preg_match( '#"(react|next)"\s*:#', $manifest ) ) {
				$this->framework = 'react';
			} elseif ( preg_match( '#"(vue|nuxt)"\s*:#', $manifest ) ) {
				$this->framework = 'vue';
			}
		}

		if ( '' === $this->framework ) {
			foreach ( array_keys( $this->files ) as $path ) {
				$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

				if ( 'vue' === $extension ) {
					$this->framework = 'vue';
					break;
				}

				if ( in_array( $extension, array( 'tsx', 'jsx' ), true ) ) {
					$this->framework = 'react';
					break;
				}
			}
		}

		$this->alias = $this->read_alias();
	}

	/**
	 * What `@/…` imports point at.
	 *
	 * A tsconfig states it plainly; a Vite config states it in JavaScript, and
	 * the shape of that statement varies enough that it is read with a regular
	 * expression and no great confidence. `src` is the answer often enough to
	 * be the fallback.
	 *
	 * @return string Directory relative to the project.
	 */
	private function read_alias(): string {
		foreach ( array( 'tsconfig.json', 'tsconfig.app.json', 'jsconfig.json' ) as $name ) {
			$config = $this->read( $name );

			if ( '' !== $config && preg_match( '#"@/\*"\s*:\s*\[\s*"\.?/?([^"*]+)/?\*"#', $config, $found ) ) {
				return trim( $found[1], '/' );
			}
		}

		foreach ( array_keys( $this->files ) as $path ) {
			if ( ! preg_match( '#^vite\.config\.[jt]s$#', $path ) ) {
				continue;
			}

			$config = $this->read( $path );

			if ( preg_match( '#["\']@["\']\s*:\s*[^,\n]*?["\']\.?/?([A-Za-z0-9_\-/]+)["\']#', $config, $found ) ) {
				return trim( $found[1], '/' );
			}
		}

		return 'src';
	}

	/**
	 * Read a project file, or an empty string.
	 *
	 * @param string $path Path relative to the project.
	 * @return string
	 */
	private function read( string $path ): string {
		$absolute = $this->dir . '/' . ltrim( $path, '/' );

		if ( ! is_file( $absolute ) ) {
			return '';
		}

		$contents = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A local file the importer unpacked itself.

		return false === $contents ? '' : $contents;
	}

	/**
	 * Which framework this project is written in: react, vue or ''.
	 *
	 * @return string
	 */
	public function framework(): string {
		return $this->framework;
	}

	/**
	 * The project directory, relative to the design root.
	 *
	 * @return string
	 */
	public function relative(): string {
		return rtrim( $this->prefix, '/' );
	}

	/**
	 * How many component files this project holds.
	 *
	 * @return int
	 */
	public function components(): int {
		return $this->components;
	}

	/**
	 * What to call this project on a screen that is listing several.
	 *
	 * The last segment of its own directory, which in a handoff is the name
	 * somebody gave the application rather than the shelf it sits on.
	 *
	 * @return string
	 */
	public function name(): string {
		$relative = $this->relative();

		if ( '' === $relative ) {
			return basename( $this->root );
		}

		return basename( $relative );
	}

	/**
	 * Anything worth saying about what was found.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array_values( array_unique( $this->notes ) );
	}

	/**
	 * The pages this application serves.
	 *
	 * Two kinds come back and the difference decides what can be done with
	 * them. A plain route — `/`, `/products`, `/contact` — is one page and can
	 * be rendered. A route with a parameter in it — `/products/:slug` — is a
	 * template for as many pages as there are products, and rendering it
	 * without deciding which product would produce a page of placeholders, so
	 * it is listed and marked rather than offered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function routes(): array {
		/*
		 * A router that states its routes is the authority on them. The
		 * file-name convention is only consulted when there is no router to
		 * ask — a project that uses one and also happens to keep its page
		 * components in `src/pages/` was otherwise credited with a second,
		 * imaginary set of routes named after its own files: /SitePages,
		 * /HowResolveWorksPage, and a duplicate home page.
		 */
		$routes = $this->declared_routes();
		$routes = array() === $routes ? $this->file_routes() : $routes;

		$seen  = array();
		$final = array();

		foreach ( $routes as $route ) {
			$path = $route['path'];

			if ( isset( $seen[ $path ] ) ) {
				continue;
			}

			$seen[ $path ] = true;
			$final[]       = $route;
		}

		usort(
			$final,
			static function ( array $a, array $b ): int {
				return array( $a['dynamic'] ? 1 : 0, strlen( $a['path'] ) ) <=> array( $b['dynamic'] ? 1 : 0, strlen( $b['path'] ) );
			}
		);

		return $final;
	}

	/**
	 * Routes written out in a router: React Router, wouter, Vue Router.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function declared_routes(): array {
		$routes = array();

		foreach ( array_keys( $this->files ) as $path ) {
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array( 'tsx', 'jsx', 'ts', 'js', 'vue' ), true ) ) {
				continue;
			}

			$code = $this->read( $path );

			if ( ! str_contains( $code, '<Route' ) && ! str_contains( $code, 'createBrowserRouter' ) && ! str_contains( $code, 'createRouter' ) ) {
				continue;
			}

			$imports = $this->imports( $path, $code );

			/*
			 * Three ways the same thing is written, and a project usually uses
			 * more than one of them in the same file:
			 *
			 *   <Route path="/x" component={Page} />
			 *   <Route path="/x" element={<Page />} />
			 *   <Route path="/x">{() => <Page section="a" />}</Route>
			 *
			 * plus the object form that react-router's data APIs take. All
			 * four give the same two facts — a URL and a component name.
			 */
			$patterns = array(
				'#<Route\s+path=["\']([^"\']+)["\'][^>]*?\bcomponent=\{\s*(\w+)#s',
				'#<Route\s+path=["\']([^"\']+)["\'][^>]*?\belement=\{\s*<\s*(\w+)#s',
				'#<Route\s+path=["\']([^"\']+)["\'][^>]*?>\s*\{[^<]{0,200}?<\s*(\w+)#s',
				'#\bpath\s*:\s*["\']([^"\']+)["\'][^}]{0,200}?\bcomponent\s*:\s*\(?\s*\)?\s*=?>?\s*import\([^)]*["\']([^"\']+)["\']#s',
				'#\bpath\s*:\s*["\']([^"\']+)["\'][^}]{0,200}?\belement\s*:\s*<\s*(\w+)#s',
			);

			foreach ( $patterns as $index => $pattern ) {
				if ( ! preg_match_all( $pattern, $code, $matches, PREG_SET_ORDER ) ) {
					continue;
				}

				foreach ( $matches as $match ) {
					$url  = trim( $match[1] );
					$name = trim( $match[2] );

					// The dynamic-import form names a module, not a component.
					$file = 3 === $index
						? $this->resolve_module( $path, $name )
						: ( $imports[ $name ] ?? $this->find_component( $name ) );

					if ( '' === $url || null === $file ) {
						continue;
					}

					$routes[] = $this->route_row( $url, 3 === $index ? $this->component_name( $file ) : $name, $file, $path );
				}
			}
		}

		return $routes;
	}

	/**
	 * Routes implied by where the files sit: Next.js and Nuxt conventions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function file_routes(): array {
		$routes = array();

		foreach ( array_keys( $this->files ) as $path ) {
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array( 'tsx', 'jsx', 'vue' ), true ) ) {
				continue;
			}

			$inner = $path;

			foreach ( array( $this->alias . '/', 'src/' ) as $lead ) {
				if ( str_starts_with( $inner, $lead ) ) {
					$inner = substr( $inner, strlen( $lead ) );
					break;
				}
			}

			$url = '';

			if ( preg_match( '#^app/(.*/)?page\.[jt]sx?$#', $inner, $found ) ) {
				$url = '/' . trim( (string) ( $found[1] ?? '' ), '/' );
			} elseif ( preg_match( '#^pages/(.+)\.(?:[jt]sx|vue)$#', $inner, $found ) ) {
				$name = (string) $found[1];

				if ( str_starts_with( basename( $name ), '_' ) ) {
					continue;
				}

				$name = preg_replace( '#/index$#', '', $name );
				$name = 'index' === $name ? '' : (string) $name;
				$url  = '/' . trim( (string) $name, '/' );
			}

			if ( '' === $url ) {
				continue;
			}

			$routes[] = $this->route_row( $url, $this->component_name( $path ), $path, $path );
		}

		return $routes;
	}

	/**
	 * One row of the route list.
	 *
	 * @param string $url       URL the route answers.
	 * @param string $component Component name.
	 * @param string $file      File the component is in, relative to the project.
	 * @param string $router    File the route was declared in.
	 * @return array<string, mixed>
	 */
	private function route_row( string $url, string $component, string $file, string $router ): array {
		$url     = '/' . ltrim( $url, '/' );
		$url     = '/' !== $url ? rtrim( $url, '/' ) : $url;
		$dynamic = str_contains( $url, ':' ) || str_contains( $url, '[' ) || str_contains( $url, '*' );

		return array(
			'path'      => $url,
			'component' => $component,
			'file'      => $file,
			'router'    => $router,
			'dynamic'   => $dynamic,
			'slug'      => self::slug( $url ),
			'title'     => self::title( $url, $component ),
		);
	}

	/**
	 * A file name for the page this route renders to.
	 *
	 * @param string $url Route URL.
	 * @return string
	 */
	public static function slug( string $url ): string {
		$slug = strtolower( trim( $url, '/' ) );
		$slug = (string) preg_replace( '#[^a-z0-9]+#', '-', $slug );
		$slug = trim( $slug, '-' );

		return '' === $slug ? 'home' : $slug;
	}

	/**
	 * A human title for a route, before the model gives it a better one.
	 *
	 * @param string $url       Route URL.
	 * @param string $component Component name.
	 * @return string
	 */
	private static function title( string $url, string $component ): string {
		if ( '/' === $url ) {
			return __( 'Home', 'qwerty-soft-signal' );
		}

		$words = trim( str_replace( array( '-', '_', '/' ), ' ', trim( $url, '/' ) ) );

		return '' === $words ? $component : ucwords( $words );
	}

	/**
	 * The component name a file most likely exports.
	 *
	 * @param string $file Path relative to the project.
	 * @return string
	 */
	private function component_name( string $file ): string {
		$name = (string) preg_replace( '#\.[^.]+$#', '', basename( $file ) );

		return 'index' === $name ? basename( dirname( $file ) ) : $name;
	}

	/**
	 * Every name a file imports, mapped to the file it comes from.
	 *
	 * Only local imports resolve; a package from node_modules has no file in
	 * the archive and is left out, which is also how the bundle knows not to
	 * try to explain React itself to the model.
	 *
	 * @param string $from Importing file, relative to the project.
	 * @param string $code Its source.
	 * @return array<string, string> Imported name => file relative to the project.
	 */
	private function imports( string $from, string $code ): array {
		$map = array();

		/*
		 * Anchored on a statement boundary rather than on the start of a line:
		 * a file that puts two imports on one line is unusual to write and
		 * ordinary to generate, and the line-anchored version silently found
		 * only the first of them.
		 */
		if ( ! preg_match_all( '#(?:^|[;}\n])\s*import\s+([^;\n]+?)\s+from\s+["\']([^"\']+)["\']#m', $code, $matches, PREG_SET_ORDER ) ) {
			return $map;
		}

		foreach ( $matches as $match ) {
			$file = $this->resolve_module( $from, $match[2] );

			if ( null === $file ) {
				continue;
			}

			$clause = trim( $match[1] );

			// The default export: import Default from a module.
			if ( preg_match( '#^(\w+)\s*(?:,|$)#', $clause, $found ) ) {
				$map[ $found[1] ] = $file;
			}

			// The named exports: import { A, B as C } from a module.
			if ( preg_match( '#\{([^}]*)\}#s', $clause, $found ) ) {
				foreach ( explode( ',', $found[1] ) as $part ) {
					$part = trim( $part );

					if ( '' === $part ) {
						continue;
					}

					$parts = preg_split( '#\s+as\s+#', $part );
					$name  = trim( (string) end( $parts ) );

					if ( '' !== $name && preg_match( '#^\w+$#', $name ) ) {
						$map[ $name ] = $file;
					}
				}
			}
		}

		return $map;
	}

	/**
	 * Resolve one import specifier to a file inside the project.
	 *
	 * @param string $from   Importing file, relative to the project.
	 * @param string $module Specifier as written.
	 * @return string|null File relative to the project, or null when it is not ours.
	 */
	private function resolve_module( string $from, string $module ) {
		$module = trim( $module );

		if ( '' === $module ) {
			return null;
		}

		if ( str_starts_with( $module, '@/' ) ) {
			$base = trim( $this->alias, '/' ) . '/' . substr( $module, 2 );
		} elseif ( str_starts_with( $module, '~/' ) ) {
			$base = trim( $this->alias, '/' ) . '/' . substr( $module, 2 );
		} elseif ( str_starts_with( $module, '.' ) ) {
			$base = self::normalise( dirname( $from ) . '/' . $module );
		} elseif ( str_starts_with( $module, 'src/' ) || str_starts_with( $module, '/src/' ) ) {
			$base = ltrim( $module, '/' );
		} else {
			// A package, a stylesheet from node_modules, or an alias we do not know.
			return null;
		}

		if ( isset( $this->files[ $base ] ) ) {
			return $base;
		}

		foreach ( self::RESOLUTION_ORDER as $suffix ) {
			$candidate = $base . $suffix;

			if ( isset( $this->files[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Find a component by name when no import statement named it.
	 *
	 * @param string $name Component name.
	 * @return string|null File relative to the project.
	 */
	private function find_component( string $name ) {
		foreach ( array_keys( $this->files ) as $path ) {
			if ( $this->component_name( $path ) === $name ) {
				return $path;
			}
		}

		foreach ( array_keys( $this->files ) as $path ) {
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array( 'tsx', 'jsx', 'vue' ), true ) ) {
				continue;
			}

			$code = $this->read( $path );

			if ( preg_match( '#export\s+(?:default\s+)?(?:function|const|class)\s+' . preg_quote( $name, '#' ) . '\b#', $code ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Collapse `a/b/../c` into `a/c`.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalise( string $path ): string {
		$out = array();

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}

			$out[] = $segment;
		}

		return implode( '/', $out );
	}

	/**
	 * Everything a reader needs to know what one route renders.
	 *
	 * The bundle is bounded on purpose and says where it was cut. A catalogue
	 * module of six hundred kilobytes is a shape, not a text: four kilobytes
	 * of it tells the model what a product record looks like, and the rest
	 * would only push the page's own components out of the window.
	 *
	 * @param array<string, mixed> $route One row from routes().
	 * @return array{files:array<int,array{path:string,code:string,truncated:bool}>,ui:array<int,string>,styles:array<int,array{path:string,code:string}>,images:array<int,string>,notes:array<int,string>}
	 */
	public function bundle( array $route ): array {
		$page    = (string) $route['file'];
		$queue   = array( array( $page, 0 ) );
		$router  = (string) ( $route['router'] ?? '' );
		$seen    = array();
		$files   = array();
		$ui      = array();
		$notes   = array();
		$budget  = self::MAX_BUNDLE_CHARS;
		$dropped = 0;

		/*
		 * Other routes' page components are not this page. They are reachable
		 * through the router file, which every page imports, and following
		 * them would put the whole site in a brief about one page of it.
		 */
		$others = array();

		foreach ( $this->routes() as $other ) {
			if ( (string) $other['file'] !== $page ) {
				$others[ (string) $other['file'] ] = true;
			}
		}

		if ( '' !== $router && $router !== $page ) {
			$queue[] = array( $router, 1 );
			unset( $others[ $router ] );
		}

		while ( array() !== $queue ) {
			list( $path, $depth ) = array_shift( $queue );

			if ( '' === $path || isset( $seen[ $path ] ) || ! isset( $this->files[ $path ] ) ) {
				continue;
			}

			if ( isset( $others[ $path ] ) ) {
				continue;
			}

			$seen[ $path ] = true;

			/*
			 * The interface primitives are named, not quoted. A shadcn/ui
			 * button is forty lines of variant plumbing that renders a
			 * <button>, and forty of them would fill the window with what the
			 * reader already knows.
			 */
			if ( self::is_primitive( $path ) ) {
				$ui[] = $path;
				continue;
			}

			$code = $this->read( $path );

			if ( '' === $code ) {
				continue;
			}

			$is_data = self::is_data( $path );

			if ( $is_data ) {
				$limit = self::MAX_DATA_CHARS;
			} elseif ( $path === $page ) {
				$limit = self::MAX_PAGE_CHARS;
			} else {
				$limit = self::MAX_FILE_CHARS;
			}

			$truncated = strlen( $code ) > $limit;

			if ( $truncated ) {
				$code = substr( $code, 0, $limit );
			}

			if ( strlen( $code ) > $budget ) {
				++$dropped;
				continue;
			}

			$budget -= strlen( $code );

			$files[] = array(
				'path'      => $this->prefix . $path,
				'code'      => $code,
				'truncated' => $truncated,
			);

			if ( $depth >= self::IMPORT_DEPTH || $is_data ) {
				continue;
			}

			foreach ( $this->imports( $path, $code ) as $imported ) {
				if ( ! isset( $seen[ $imported ] ) ) {
					$queue[] = array( $imported, $depth + 1 );
				}
			}
		}

		if ( $dropped > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of source files. */
				_n(
					'%d source file was left out of the brief because the page had already filled it.',
					'%d source files were left out of the brief because the page had already filled it.',
					$dropped,
					'qwerty-soft-signal'
				),
				$dropped
			);
		}

		$docs = DesignDocs::digest( $this->root );

		if ( $docs['left'] > 0 ) {
			$notes[] = sprintf(
				/* translators: 1: documents quoted, 2: documents left out. */
				__( '%1$d of the design\'s documents are quoted in the brief; %2$d more were left out for room.', 'qwerty-soft-signal' ),
				count( $docs['files'] ),
				$docs['left']
			);
		}

		return array(
			'files'  => $files,
			'ui'     => array_map( fn( string $path ): string => $this->prefix . $path, $ui ),
			'styles' => $this->styles(),
			'images' => $this->images(),
			'docs'   => $docs['text'],
			'notes'  => $notes,
		);
	}

	/**
	 * Whether a file is one of the interface primitives.
	 *
	 * @param string $path Path relative to the project.
	 * @return bool
	 */
	private static function is_primitive( string $path ): bool {
		return 1 === preg_match( '#(^|/)components/ui/#i', $path );
	}

	/**
	 * Whether a file is a data module rather than a component.
	 *
	 * @param string $path Path relative to the project.
	 * @return bool
	 */
	private static function is_data( string $path ): bool {
		return 1 === preg_match( '#(^|/)(data|fixtures|mock|content)/#i', $path )
			|| 1 === preg_match( '#\.(json)$#i', $path );
	}

	/**
	 * The project's own stylesheets, bounded.
	 *
	 * @return array<int, array{path:string,code:string}>
	 */
	private function styles(): array {
		$styles = array();
		$budget = 40000;

		foreach ( array_keys( $this->files ) as $path ) {
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array( 'css', 'scss' ), true ) ) {
				continue;
			}

			$code = $this->read( $path );

			if ( '' === $code || $budget <= 0 ) {
				continue;
			}

			$code    = substr( $code, 0, $budget );
			$budget -= strlen( $code );

			$styles[] = array(
				'path' => $this->prefix . $path,
				'code' => $code,
			);
		}

		return $styles;
	}

	/**
	 * Pictures in the design, as paths relative to the design root.
	 *
	 * The rendered page has to point at files that exist, or the build imports
	 * nothing and every picture on the page is a broken link. Assets belonging
	 * to the application come first because those are the ones its components
	 * refer to by name.
	 *
	 * @return array<int, string>
	 */
	private function images(): array {
		$assets = array();
		$rest   = array();

		foreach ( self::walk( $this->root ) as $path => $size ) {
			unset( $size );

			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array( 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg' ), true ) ) {
				continue;
			}

			if ( preg_match( '#(^|/)(assets|public|images|img|media|logos?)/#i', $path ) ) {
				$assets[] = $path;
				continue;
			}

			$rest[] = $path;
		}

		sort( $assets );
		sort( $rest );

		return array_slice( array_merge( $assets, $rest ), 0, 220 );
	}
}
