<?php
/**
 * Renders one route of a component-source design into a static HTML page.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The step that gives an application the pages it never had on disk.
 *
 * One model call per route. What comes back is written into the design as an
 * ordinary HTML file, and from that moment the design is an ordinary design:
 * the page list finds it, the splitter splits it, the structural converter
 * converts it, the CSS index resolves its classes against the application's
 * own stylesheet, the preview previews it and the build builds it. Nothing
 * downstream of here knows or needs to know that the page was read out of
 * React rather than written by hand.
 *
 * Two properties make that safe to do:
 *
 * - **The file is written, not the site.** A render adds one file inside the
 *   design's own folder in uploads. Removing the design removes it, and
 *   re-rendering overwrites it; no post, menu or attachment is touched until
 *   somebody presses build.
 * - **The reply is treated as hostile text.** Scripts, event handlers,
 *   javascript: URLs and pictures that do not exist are taken out before the
 *   file is written, because the answer to "what does this component render"
 *   arrives as a string from a model and is about to be stored on disk.
 */
final class SourceRenderer {

	/**
	 * Folder inside the design that rendered pages are written to.
	 */
	public const RENDER_DIR = 'qs-rendered';

	/**
	 * Seconds one render may take. A page of components is a long read.
	 */
	private const TIMEOUT = 600;

	/**
	 * Design root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * The project inside it.
	 *
	 * @var SourceProject
	 */
	private SourceProject $project;

	/**
	 * Model and effort, as chosen in the connection settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * The model call that was made, for billing.
	 *
	 * @var array<string, mixed>
	 */
	private array $call = array();

	/**
	 * Set up a renderer for one design.
	 *
	 * @param string               $root    Design root.
	 * @param SourceProject        $project The application inside it.
	 * @param array<string, mixed> $options model, effort.
	 */
	public function __construct( string $root, SourceProject $project, array $options = array() ) {
		$this->root    = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->project = $project;
		$this->options = $options;
	}

	/**
	 * What the model call cost, in the shape the importer bills from.
	 *
	 * @return array<string, mixed>
	 */
	public function call(): array {
		return $this->call;
	}

	/**
	 * Render one route and write the page.
	 *
	 * @param array<string, mixed> $route Route row from {@see SourceProject::routes()}.
	 * @return array<string, mixed>|WP_Error
	 */
	public function render( array $route ) {
		if ( ! ModelGateway::ready() ) {
			$status = ModelGateway::status();

			return new WP_Error(
				'qwerty_soft_no_route',
				sprintf(
					/* translators: %s: why no model can be reached. */
					__( 'Reading an application\'s components needs a model, and there is no way to reach one from here. %s', 'qwerty-soft-signal' ),
					(string) $status['reason']
				),
				array( 'status' => 400 )
			);
		}

		$bundle = $this->project->bundle( $route );

		if ( array() === $bundle['files'] ) {
			return new WP_Error(
				'qwerty_soft_no_source',
				__( 'The component this route points at could not be read from the archive.', 'qwerty-soft-signal' ),
				array( 'status' => 404 )
			);
		}

		$options = array(
			'model'   => (string) ( $this->options['model'] ?? AnthropicClient::DEFAULT_MODEL ),
			'effort'  => (string) ( $this->options['effort'] ?? 'high' ),
			'timeout' => self::TIMEOUT,

			/*
			 * On the command-line route the model can also open the design for
			 * itself. The brief above is bounded and says where it was cut;
			 * this is what lets it go and read the rest of a file it needed
			 * rather than guess at the part it was not shown. The API route
			 * has no filesystem and works from the brief alone, which is why
			 * the brief has to stand on its own either way.
			 */
			'dirs'    => array( $this->root ),
		);

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 30 + self::TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- One model call, bounded by the transport's own timeout.
		}

		$reply = ModelGateway::generate(
			SourcePrompt::system( $this->project->framework() ),
			SourcePrompt::message( $route, $bundle, $this->root ),
			SourcePrompt::schema(),
			$options
		);

		if ( is_wp_error( $reply ) ) {
			$this->call = array(
				'ok'        => false,
				'error'     => $reply->get_error_message(),
				'usage'     => array(),
				'model'     => (string) $options['model'],
				'transport' => ModelGateway::resolve(),
			);

			return $reply;
		}

		$this->call = array(
			'ok'        => true,
			'error'     => '',
			'usage'     => isset( $reply['_usage'] ) && is_array( $reply['_usage'] ) ? $reply['_usage'] : array(),
			'model'     => isset( $reply['_model'] ) && '' !== (string) $reply['_model'] ? (string) $reply['_model'] : (string) $options['model'],
			'transport' => isset( $reply['_transport'] ) ? (string) $reply['_transport'] : 'api',
			'notional'  => isset( $reply['_notional_cost'] ) ? (float) $reply['_notional_cost'] : 0.0,
		);

		$notes   = isset( $reply['notes'] ) && is_array( $reply['notes'] ) ? array_map( 'strval', $reply['notes'] ) : array();
		$title   = isset( $reply['title'] ) ? trim( (string) $reply['title'] ) : '';
		$body    = isset( $reply['html'] ) ? (string) $reply['html'] : '';
		$body    = $this->clean( $body );
		$missing = 0;
		$body    = $this->drop_absent_images( $body, $missing );

		if ( $missing > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of images. */
				_n(
					'%d picture the answer asked for is not in the archive and was left out.',
					'%d pictures the answer asked for are not in the archive and were left out.',
					$missing,
					'qwerty-soft-signal'
				),
				$missing
			);
		}

		$measure = $this->measure( $body );

		/*
		 * A reply with nothing in it is a failed render, not a page. Writing
		 * it would put an empty file where a page should be and the screen
		 * would report a success nobody can use.
		 */
		if ( $measure['words'] < 25 ) {
			return new WP_Error(
				'qwerty_soft_empty_render',
				__( 'The model returned no usable markup for that route. Try again, or raise the care setting in the connection settings.', 'qwerty-soft-signal' ),
				array( 'status' => 502 )
			);
		}

		$title = '' !== $title ? $title : (string) $route['title'];
		$file  = $this->write( $route, $title, $body );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return array(
			'file'     => $file,
			'title'    => $title,
			'route'    => (string) $route['path'],
			'sections' => $measure['sections'],
			'words'    => $measure['words'],
			'notes'    => $notes,
			'sources'  => count( $bundle['files'] ),
		);
	}

	/**
	 * Take out everything a rendered page has no business carrying.
	 *
	 * @param string $html The model's answer.
	 * @return string
	 */
	private function clean( string $html ): string {
		$html = trim( $html );

		// A model that has been asked for HTML sometimes fences it anyway.
		$html = (string) preg_replace( '#^```(?:html)?\s*|\s*```$#i', '', $html );

		// Whole elements, contents included.
		$html = (string) preg_replace( '#<(script|style|iframe|object|embed|noscript)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<(script|style|iframe|object|embed|link|meta)\b[^>]*/?>#i', '', $html );

		// A whole document when only its body was asked for.
		if ( preg_match( '#<body[^>]*>(.*)</body>#is', $html, $found ) ) {
			$html = $found[1];
		}

		$html = (string) preg_replace( '#</?(html|head|body)\b[^>]*>#i', '', $html );

		// Event handlers and javascript: URLs.
		$html = (string) preg_replace( '#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html );
		$html = (string) preg_replace( "#\son[a-z]+\s*=\s*'[^']*'#i", '', $html );
		$html = (string) preg_replace( '#\s(href|src)\s*=\s*"\s*javascript:[^"]*"#i', ' $1="#"', $html );

		return trim( $html );
	}

	/**
	 * Remove images that point at files this design does not have.
	 *
	 * A path the archive cannot supply becomes a broken picture on the built
	 * page, which is worse than no picture: it looks like the import lost
	 * something rather than like the design never had it.
	 *
	 * @param string $html    Cleaned markup.
	 * @param int    $missing Filled with how many were removed.
	 * @return string
	 */
	private function drop_absent_images( string $html, int &$missing ): string {
		$missing = 0;
		$root    = $this->root;

		return (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $found ) use ( $root, &$missing ): string {
				if ( ! preg_match( '#\ssrc\s*=\s*["\']([^"\']+)["\']#i', $found[0], $src ) ) {
					++$missing;

					return '';
				}

				$path = trim( $src[1] );

				if ( preg_match( '#^(https?:)?//#i', $path ) || str_starts_with( $path, 'data:' ) ) {
					++$missing;

					return '';
				}

				$candidate = $root . '/' . ltrim( rawurldecode( $path ), '/' );

				if ( is_file( $candidate ) ) {
					return $found[0];
				}

				++$missing;

				return '';
			},
			$html
		);
	}

	/**
	 * How much of a page came back.
	 *
	 * @param string $html Cleaned markup.
	 * @return array{sections:int,words:int}
	 */
	private function measure( string $html ): array {
		$text = trim( (string) preg_replace( '#\s+#u', ' ', wp_strip_all_tags( $html ) ) );

		return array(
			'sections' => (int) preg_match_all( '#<(section|article|header|footer|main)\b#i', $html ),
			'words'    => '' === $text ? 0 : count( (array) preg_split( '#\s+#u', $text ) ),
		);
	}

	/**
	 * Write the page into the design.
	 *
	 * @param array<string, mixed> $route Route row.
	 * @param string               $title Page title.
	 * @param string               $body  Cleaned body markup.
	 * @return string|WP_Error Path relative to the design root.
	 */
	private function write( array $route, string $title, string $body ) {
		$dir = $this->root . '/' . self::RENDER_DIR;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'qwerty_soft_render_dir',
				__( 'Could not create a folder for the rendered pages inside the design.', 'qwerty-soft-signal' ),
				array( 'status' => 500 )
			);
		}

		$relative = self::RENDER_DIR . '/' . SourceProject::slug( (string) $route['path'] ) . '.html';

		$document = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>"
			. esc_html( $title )
			. "</title>\n<meta name=\"qs-rendered-from\" content=\""
			. esc_attr( (string) $route['component'] . ' — ' . (string) $route['file'] )
			. "\">\n<meta name=\"qs-route\" content=\""
			. esc_attr( (string) $route['path'] )
			. "\">\n</head>\n<body>\n"
			. $body
			. "\n</body>\n</html>\n";

		if ( false === file_put_contents( $this->root . '/' . $relative, $document ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing into the importer's own unpacked design, where WP_Filesystem may be unavailable mid-request.
			return new WP_Error(
				'qwerty_soft_render_write',
				__( 'The page was rendered but could not be written into the design folder.', 'qwerty-soft-signal' ),
				array( 'status' => 500 )
			);
		}

		return $relative;
	}
}
