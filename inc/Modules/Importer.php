<?php
/**
 * The design import screen and the endpoints behind it.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Wow\Signal\Contracts\Module;
use Wow\Signal\Support\AnthropicClient;
use Wow\Signal\Support\BlockMarkupValidator;
use Wow\Signal\Support\ConversionPrompt;
use Wow\Signal\Support\CssIndex;
use Wow\Signal\Support\DesignArchive;
use Wow\Signal\Support\SectionSplitter;
use Wow\Signal\Support\SiteAssembler;
use Wow\Signal\Support\SiteBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an uploaded HTML design into reviewed, editable blocks.
 *
 * The screen is a four-step loop: upload an archive, pick a page, convert its
 * sections one at a time, accept the ones that look right. Conversion is
 * automatic; acceptance is not. Every generated section is validated before it
 * can be previewed and again before it can be saved, and a section the site
 * owner has not looked at never reaches the site.
 *
 * Access is gated on `edit_theme_options` throughout — the capability that
 * already means "may change how this site looks".
 */
final class Importer implements Module {

	/**
	 * REST namespace for every endpoint on this screen.
	 */
	private const NAMESPACE = 'wow-signal/v1';

	/**
	 * Admin page slug.
	 */
	private const PAGE = 'wow-signal-import';

	/**
	 * Option holding the API key.
	 */
	private const OPTION_KEY = 'wow_signal_anthropic_key';

	/**
	 * Option holding the chosen model.
	 */
	private const OPTION_MODEL = 'wow_signal_ai_model';

	/**
	 * Option holding the chosen effort level.
	 */
	private const OPTION_EFFORT = 'wow_signal_ai_effort';

	/**
	 * Conversions allowed per user per hour.
	 */
	private const RATE_LIMIT = 120;

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * The capability required for everything on this screen.
	 *
	 * @return bool
	 */
	public function may_import(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Add the screen under Appearance.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_theme_page(
			__( 'Design import', 'wow-signal' ),
			__( 'Design import', 'wow-signal' ),
			'edit_theme_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the three stored settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'wow_signal_ai',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_key' ),
			)
		);

		register_setting(
			'wow_signal_ai',
			self::OPTION_MODEL,
			array(
				'type'              => 'string',
				'default'           => AnthropicClient::DEFAULT_MODEL,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					$value = is_string( $value ) ? $value : '';

					return array_key_exists( $value, AnthropicClient::models() ) ? $value : AnthropicClient::DEFAULT_MODEL;
				},
			)
		);

		register_setting(
			'wow_signal_ai',
			self::OPTION_EFFORT,
			array(
				'type'              => 'string',
				'default'           => 'high',
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					$value = is_string( $value ) ? $value : '';

					return array_key_exists( $value, AnthropicClient::effort_levels() ) ? $value : 'high';
				},
			)
		);
	}

	/**
	 * Keep an API key out of the database when it is only a placeholder.
	 *
	 * The form shows a masked value; submitting it unchanged must not
	 * overwrite the real key with asterisks.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_key( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value || str_contains( $value, '•' ) || str_starts_with( $value, '****' ) ) {
			return (string) get_option( self::OPTION_KEY, '' );
		}

		// Keys are opaque tokens; anything outside this alphabet is a paste error.
		return (string) preg_replace( '#[^A-Za-z0-9_\-]#', '', $value );
	}

	/**
	 * Load the screen's assets, and only on the screen itself.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( 'appearance_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wow-signal-import',
			WOW_SIGNAL_URI . '/assets/css/admin-import.css',
			array(),
			WOW_SIGNAL_VERSION
		);

		wp_add_inline_style( 'wow-signal-import', $this->preview_styles() );

		wp_enqueue_script(
			'wow-signal-import',
			WOW_SIGNAL_URI . '/assets/js/admin-import.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			WOW_SIGNAL_VERSION,
			true
		);

		wp_set_script_translations( 'wow-signal-import', 'wow-signal', WOW_SIGNAL_DIR . '/languages' );

		wp_add_inline_script(
			'wow-signal-import',
			'window.wowSignalImport = ' . wp_json_encode(
				array(
					'root'      => esc_url_raw( rest_url( self::NAMESPACE ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'hasKey'    => '' !== AnthropicClient::api_key(),
					'keyLocked' => AnthropicClient::key_is_constant(),
					'siteUrl'   => esc_url_raw( admin_url() ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Give the preview pane the theme's own tokens, scoped to itself.
	 *
	 * Without this the preview inherits wp-admin's styling: headings come out
	 * near-black on the dark preview ground (1.2:1) and preset classes like
	 * has-accent-color resolve to nothing, so the editor is shown something
	 * that looks broken and reads nothing like the page they will publish.
	 *
	 * Both generated sheets are flat — variables is a single :root rule and
	 * presets is a list of plain class selectors, with no at-rules and no
	 * nesting — so prefixing every selector is a safe way to contain them.
	 * The explicit resets afterwards stop admin styles for headings, links
	 * and buttons from winning on specificity inside the pane.
	 *
	 * @return string
	 */
	private function preview_styles(): string {
		$scope     = '.wow-import__preview';
		$variables = str_replace( ':root', $scope, wp_get_global_stylesheet( array( 'variables' ) ) );
		$presets   = (string) preg_replace_callback(
			'/(^|\})([^{}]+)\{/',
			static function ( array $rule ) use ( $scope ): string {
				$selectors = array_map(
					static fn( string $selector ): string => $scope . ' ' . trim( $selector ),
					explode( ',', $rule[2] )
				);

				return $rule[1] . implode( ',', $selectors ) . '{';
			},
			wp_get_global_stylesheet( array( 'presets' ) )
		);

		/*
		 * Plain descendant selectors, not :where() — admin styles set colours
		 * on bare element selectors, and a zero-specificity reset would lose
		 * to them.
		 */
		$elements = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'dt', 'dd', 'figcaption', 'blockquote', 'cite', 'th', 'td' );
		$inherit  = implode(
			',',
			array_map( static fn( string $tag ): string => $scope . ' ' . $tag, $elements )
		);

		$resets = $inherit . '{color:inherit;}'
			. $scope . ' a:not(.wp-element-button):not(.wp-block-button__link){color:var(--wp--preset--color--accent,#22d3ee);}'
			. $scope . ' .wp-element-button,' . $scope . ' .wp-block-button__link{'
			. 'background-color:var(--wp--preset--color--accent,#22d3ee);'
			. 'color:var(--wp--preset--color--base,#0a0a18);'
			. 'text-decoration:none;padding:0.7em 1.4em;border-radius:6px;display:inline-block;}';

		return $variables . $presets . $resets;
	}

	/**
	 * Render the screen shell; the rest is built by the script.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! $this->may_import() ) {
			wp_die( esc_html__( 'You do not have permission to import designs.', 'wow-signal' ) );
		}

		$models  = AnthropicClient::models();
		$efforts = AnthropicClient::effort_levels();
		$key     = AnthropicClient::api_key();
		?>
		<div class="wrap wow-import">
			<div class="wow-import__masthead">
				<p class="wow-import__brand">
					<span class="wow-import__mark" aria-hidden="true">W</span>
					<?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>
				</p>

				<h1><?php esc_html_e( 'Design import', 'wow-signal' ); ?></h1>

				<p class="wow-import__lede">
					<?php esc_html_e( 'Upload an HTML design as a ZIP. Each section is converted into editable blocks, one at a time, and nothing is added to your site until you accept it.', 'wow-signal' ); ?>
				</p>
			</div>

			<details class="wow-import__settings" <?php echo '' === $key ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Connection settings', 'wow-signal' ); ?>

					<?php if ( AnthropicClient::key_is_constant() ) : ?>
						<span class="wow-import__badge is-on">
							<?php esc_html_e( 'Key active — from wp-config.php', 'wow-signal' ); ?>
						</span>
					<?php elseif ( '' !== $key ) : ?>
						<span class="wow-import__badge is-on">
							<?php
							printf(
								/* translators: %s: the last four characters of the key. */
								esc_html__( 'Key active — ends in %s', 'wow-signal' ),
								esc_html( substr( $key, -4 ) )
							);
							?>
						</span>
					<?php else : ?>
						<span class="wow-import__badge is-off">
							<?php esc_html_e( 'No key — the free routes still work', 'wow-signal' ); ?>
						</span>
					<?php endif; ?>
				</summary>

				<form method="post" action="options.php">
					<?php settings_fields( 'wow_signal_ai' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wow-key"><?php esc_html_e( 'Anthropic API key', 'wow-signal' ); ?></label>
							</th>
							<td>
								<?php if ( AnthropicClient::key_is_constant() ) : ?>
									<p>
										<strong><?php esc_html_e( 'Set in wp-config.php.', 'wow-signal' ); ?></strong>
										<?php esc_html_e( 'The key is defined as a constant, so it is not stored in the database and cannot be changed here.', 'wow-signal' ); ?>
									</p>
								<?php else : ?>
									<input
										type="password"
										id="wow-key"
										class="wow-import__field"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
										autocomplete="off"
										spellcheck="false"
										placeholder="sk-ant-…"
										value="<?php echo '' !== $key ? esc_attr( str_repeat( '•', 24 ) . substr( $key, -4 ) ) : ''; ?>"
									>
									<p class="description">
										<?php esc_html_e( 'Used only on the server; it is never sent to the browser. For the strongest setup, put it in wp-config.php instead:', 'wow-signal' ); ?>
										<code>define( 'WOW_SIGNAL_ANTHROPIC_KEY', '…' );</code>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wow-model"><?php esc_html_e( 'Model', 'wow-signal' ); ?></label>
							</th>
							<td>
								<select id="wow-model" class="wow-import__field" name="<?php echo esc_attr( self::OPTION_MODEL ); ?>">
									<?php foreach ( $models as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( get_option( self::OPTION_MODEL, AnthropicClient::DEFAULT_MODEL ), $id ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wow-effort"><?php esc_html_e( 'Care taken per section', 'wow-signal' ); ?></label>
							</th>
							<td>
								<select id="wow-effort" class="wow-import__field" name="<?php echo esc_attr( self::OPTION_EFFORT ); ?>">
									<?php foreach ( $efforts as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( get_option( self::OPTION_EFFORT, 'high' ), $id ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Higher settings take longer and cost more per section, and handle complicated layouts better.', 'wow-signal' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save settings', 'wow-signal' ) ); ?>
				</form>
			</details>

			<div id="wow-import-app" class="wow-import__app">
				<noscript><?php esc_html_e( 'This screen needs JavaScript.', 'wow-signal' ); ?></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Register every endpoint the screen calls.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$guard = array( $this, 'may_import' );

		register_rest_route(
			self::NAMESPACE,
			'/designs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_designs' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_design' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/designs/(?P<slug>[a-z0-9-]+)/sections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sections' ),
				'permission_callback' => $guard,
				'args'                => array(
					'file' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/convert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convert_section' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/save',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_markup' ),
				'permission_callback' => $guard,
			)
		);

		/*
		 * The subscription route. No API key involved: the site owner copies
		 * one brief covering the whole page into their own Claude
		 * conversation and pastes the reply back.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/designs/(?P<slug>[a-z0-9-]+)/brief',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'page_brief' ),
				'permission_callback' => $guard,
				'args'                => array(
					'file' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/paste',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept_paste' ),
				'permission_callback' => $guard,
			)
		);

		/*
		 * One action for the whole site. No model involved: the structural
		 * converter is deterministic, so this costs nothing and can be run,
		 * judged and run again.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_site' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_site' ),
				'permission_callback' => $guard,
			)
		);
	}

	/**
	 * Build every page of a design in one pass.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_site( WP_REST_Request $request ) {
		$root = $this->design_root( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		// A whole design is many pages; give it room rather than dying halfway.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Long import, bounded.
		}

		$report = SiteAssembler::build(
			$root,
			DesignArchive::index( $root ),
			array(
				'language' => (string) $request->get_param( 'language' ),
				'publish'  => (bool) $request->get_param( 'publish' ),
			)
		);

		if ( is_wp_error( $report ) ) {
			$report->add_data( array( 'status' => 400 ) );

			return $report;
		}

		return rest_ensure_response( $report );
	}

	/**
	 * Undo everything a previous build created.
	 *
	 * @return WP_REST_Response
	 */
	public function reset_site(): WP_REST_Response {
		return rest_ensure_response( SiteAssembler::reset() );
	}

	/**
	 * Build the one-paste brief for a whole page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function page_brief( WP_REST_Request $request ) {
		$read = $this->read_page( $request );

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		list( $root, $page ) = $read;

		$index = CssIndex::from_directory( $root, $page['styles'] );
		$css   = array();

		foreach ( $page['sections'] as $section ) {
			$css[ (int) $section['position'] ] = $index->rules_for( (string) $section['html'] );
		}

		$brief = ConversionPrompt::page_message(
			$page['sections'],
			$css,
			array(
				'page' => $page['title'],
				'lang' => $page['lang'],
			)
		);

		return rest_ensure_response(
			array(
				'brief'    => $brief,
				'sections' => count( $page['sections'] ),
				'bytes'    => strlen( $brief ),
				'labels'   => array_map(
					static fn( array $section ): string => (string) $section['label'],
					$page['sections']
				),
			)
		);
	}

	/**
	 * Validate a pasted reply and return the same shape /convert does.
	 *
	 * Everything a pasted reply goes through is what an API reply goes
	 * through: the same validator, the same review, the same kses-filtered
	 * preview. Where the markup came from changes nothing about how much it
	 * is trusted, which is not at all.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept_paste( WP_REST_Request $request ) {
		$read = $this->read_page( $request );

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		list( $root, $page ) = $read;

		$parsed = ConversionPrompt::parse_reply( (string) $request->get_param( 'reply' ) );

		if ( is_wp_error( $parsed ) ) {
			$parsed->add_data( array( 'status' => 400 ) );

			return $parsed;
		}

		$expected = count( $page['sections'] );
		$results  = array();

		foreach ( $parsed as $item ) {
			$position  = (int) $item['position'];
			$validator = new BlockMarkupValidator();
			$markup    = (string) $item['markup'];
			$valid     = $validator->check( $markup );

			if ( $valid ) {
				$markup = $this->with_media( $markup, $root, (string) $request->get_param( 'file' ) );
			}

			$results[] = array(
				'position' => $position,
				'label'    => isset( $page['sections'][ $position ] )
					? (string) $page['sections'][ $position ]['label']
					/* translators: %d: section number. */
					: sprintf( __( 'Section %d', 'wow-signal' ), $position + 1 ),
				'valid'    => $valid,
				'errors'   => $validator->errors(),
				'notes'    => $valid ? $validator->review( $markup ) : array(),
				'markup'   => $valid ? $markup : '',
				'summary'  => $item['summary'],
				'editable' => $item['editable'],
				'concerns' => $item['concerns'],
				'preview'  => $valid ? $this->preview( $markup ) : '',
			);
		}

		return rest_ensure_response(
			array(
				'results'  => $results,
				'expected' => $expected,
				'received' => count( $results ),
			)
		);
	}

	/**
	 * Resolve slug + file into a split page, or the error explaining why not.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{0:string,1:array<string,mixed>}|WP_Error
	 */
	private function read_page( WP_REST_Request $request ) {
		$root = $this->design_root( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$file = $this->page_path( $root, (string) $request->get_param( 'file' ) );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return array( $root, SectionSplitter::split( $file ) );
	}

	/**
	 * Every design unpacked so far.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_designs() {
		$base = DesignArchive::base_dir();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$designs = array();
		$entries = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );

		foreach ( $entries ? $entries : array() as $dir ) {
			$index = DesignArchive::index( $dir );

			$designs[] = array(
				'slug'      => basename( $dir ),
				'pages'     => $index['pages'],
				'languages' => $index['languages'],
				'images'    => $index['images'],
			);
		}

		return rest_ensure_response( array( 'designs' => $designs ) );
	}

	/**
	 * Accept an uploaded archive.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_design( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['archive'] ) || ! is_array( $files['archive'] ) ) {
			return new WP_Error( 'wow_signal_no_file', __( 'No file was received.', 'wow-signal' ), array( 'status' => 400 ) );
		}

		$file = $files['archive'];

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'wow_signal_upload', __( 'The upload did not complete. The file may be larger than this server allows.', 'wow-signal' ), array( 'status' => 400 ) );
		}

		/*
		 * is_uploaded_file() is the check that stops a crafted request from
		 * naming an arbitrary server path as the "upload" and having it
		 * unpacked.
		 */
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'wow_signal_upload', __( 'That file did not arrive as an upload.', 'wow-signal' ), array( 'status' => 400 ) );
		}

		$type = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], array( 'zip' => 'application/zip' ) );

		if ( 'zip' !== ( $type['ext'] ?? '' ) ) {
			return new WP_Error( 'wow_signal_not_zip', __( 'Please upload a .zip archive.', 'wow-signal' ), array( 'status' => 400 ) );
		}

		$label  = pathinfo( (string) $file['name'], PATHINFO_FILENAME );
		$result = DesignArchive::unpack( (string) $file['tmp_name'], (string) $label );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$index = DesignArchive::index( $result['path'] );

		return rest_ensure_response(
			array(
				'slug'      => $result['slug'],
				'files'     => $result['files'],
				'bytes'     => $result['bytes'],
				'skipped'   => $result['skipped'],
				'pages'     => $index['pages'],
				'languages' => $index['languages'],
				'images'    => $index['images'],
				'palette'   => CssIndex::from_directory( $result['path'] )->palette_proposal(),
			)
		);
	}

	/**
	 * Resolve a design slug to a directory that is definitely inside the base.
	 *
	 * @param string $slug Design slug.
	 * @return string|WP_Error
	 */
	private function design_root( string $slug ) {
		$base = DesignArchive::base_dir();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$real_base = realpath( $base );
		$real_dir  = realpath( trailingslashit( $base ) . $slug );

		if ( false === $real_base || false === $real_dir ) {
			return new WP_Error( 'wow_signal_unknown_design', __( 'That design is not on this site.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		if ( ! str_starts_with( str_replace( '\\', '/', $real_dir ), str_replace( '\\', '/', $real_base ) ) ) {
			return new WP_Error( 'wow_signal_unknown_design', __( 'That design is not on this site.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		return $real_dir;
	}

	/**
	 * Split one page of a design into sections.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sections( WP_REST_Request $request ) {
		$root = $this->design_root( (string) $request['slug'] );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$file = $this->page_path( $root, (string) $request->get_param( 'file' ) );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$page  = SectionSplitter::split( $file );
		$index = CssIndex::from_directory( $root, $page['styles'] );

		$sections = array();

		foreach ( $page['sections'] as $section ) {
			// The markup and CSS stay on the server; the browser only needs a
			// description of each section to draw the review list.
			$sections[] = array(
				'position' => $section['position'],
				'label'    => $section['label'],
				'tag'      => $section['tag'],
				'id'       => $section['id'],
				'heading'  => $section['heading'],
				'words'    => $section['words'],
				'images'   => $section['images'],
				'links'    => $section['links'],
				'forms'    => $section['forms'],
				'bytes'    => $section['bytes'],
				'excerpt'  => mb_substr( $section['text'], 0, 160 ),
			);
		}

		unset( $index );

		return rest_ensure_response(
			array(
				'title'     => $page['title'],
				'lang'      => $page['lang'],
				'hasHeader' => null !== $page['header'],
				'hasFooter' => null !== $page['footer'],
				'sections'  => $sections,
			)
		);
	}

	/**
	 * Resolve a page path supplied by the browser, refusing anything outside.
	 *
	 * @param string $root Design root.
	 * @param string $file Relative path from the index.
	 * @return string|WP_Error
	 */
	private function page_path( string $root, string $file ) {
		$candidate = realpath( trailingslashit( $root ) . ltrim( str_replace( '\\', '/', $file ), '/' ) );

		if ( false === $candidate ) {
			return new WP_Error( 'wow_signal_no_page', __( 'That page is not in this design.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		$normalised_root = str_replace( '\\', '/', $root );
		$normalised_file = str_replace( '\\', '/', $candidate );

		if ( ! str_starts_with( $normalised_file, $normalised_root ) ) {
			return new WP_Error( 'wow_signal_no_page', __( 'That page is not in this design.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		if ( ! preg_match( '#\.html?$#i', $normalised_file ) ) {
			return new WP_Error( 'wow_signal_no_page', __( 'That is not an HTML page.', 'wow-signal' ), array( 'status' => 400 ) );
		}

		return $candidate;
	}

	/**
	 * Convert a single section into block markup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function convert_section( WP_REST_Request $request ) {
		$limited = $this->hit_rate_limit();

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$root = $this->design_root( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$file = $this->page_path( $root, (string) $request->get_param( 'file' ) );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$position = (int) $request->get_param( 'position' );
		$page     = SectionSplitter::split( $file );

		$section = null;

		foreach ( $page['sections'] as $candidate ) {
			if ( (int) $candidate['position'] === $position ) {
				$section = $candidate;
				break;
			}
		}

		if ( null === $section ) {
			return new WP_Error( 'wow_signal_no_section', __( 'That section is no longer in the page.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		$css = CssIndex::from_directory( $root, $page['styles'] )->rules_for( $section['html'] );

		$reply = AnthropicClient::generate(
			ConversionPrompt::system(),
			ConversionPrompt::message(
				$section,
				$css,
				array(
					'page'     => $page['title'],
					'lang'     => $page['lang'],
					'is_first' => 0 === $position,
				)
			),
			ConversionPrompt::schema(),
			array(
				'model'  => (string) get_option( self::OPTION_MODEL, AnthropicClient::DEFAULT_MODEL ),
				'effort' => (string) get_option( self::OPTION_EFFORT, 'high' ),
			)
		);

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$markup    = isset( $reply['markup'] ) ? (string) $reply['markup'] : '';
		$validator = new BlockMarkupValidator();
		$valid     = $validator->check( $markup );

		if ( $valid ) {
			$markup = $this->with_media( $markup, $root, (string) $request->get_param( 'file' ) );
		}

		return rest_ensure_response(
			array(
				'valid'    => $valid,
				'errors'   => $validator->errors(),
				'notes'    => $valid ? $validator->review( $markup ) : array(),
				'markup'   => $valid ? $markup : '',
				'summary'  => isset( $reply['summary'] ) ? (string) $reply['summary'] : '',
				'editable' => isset( $reply['editable'] ) && is_array( $reply['editable'] ) ? array_map( 'strval', $reply['editable'] ) : array(),
				'concerns' => isset( $reply['concerns'] ) && is_array( $reply['concerns'] ) ? array_map( 'strval', $reply['concerns'] ) : array(),
				'preview'  => $valid ? $this->preview( $markup ) : '',
				'usage'    => isset( $reply['_usage'] ) ? $reply['_usage'] : array(),
			)
		);
	}

	/**
	 * The design's images, imported once per request.
	 *
	 * Importing is idempotent — an image already in the library is found by
	 * its provenance meta — but a page paste validates eight sections in one
	 * request, and walking the archive eight times to learn the same thing
	 * would be waste for nothing.
	 *
	 * @param string $root Design root.
	 * @return array<string, array{id:int,url:string}>
	 */
	private function media_map( string $root ): array {
		static $cache = array();

		if ( ! isset( $cache[ $root ] ) ) {
			$map = SiteBuilder::import_media( $root );

			$cache[ $root ] = is_wp_error( $map ) ? array() : $map;
		}

		return $cache[ $root ];
	}

	/**
	 * Point a converted section's images at the Media Library.
	 *
	 * Done before the preview rather than at save time, so the photographs are
	 * visible while the section is being judged. Reviewing a layout with its
	 * images missing is reviewing a different layout.
	 *
	 * @param string $markup Validated block markup.
	 * @param string $root   Design root.
	 * @param string $file   Page file, relative to the root.
	 * @return string
	 */
	private function with_media( string $markup, string $root, string $file ): string {
		if ( ! str_contains( $markup, 'src="' ) ) {
			return $markup;
		}

		return SiteBuilder::relink_media(
			$markup,
			$this->media_map( $root ),
			(string) dirname( $file )
		);
	}

	/**
	 * Render generated markup for the preview pane.
	 *
	 * Validation has already refused scripts and unknown blocks; wp_kses_post()
	 * on the rendered output is the second, independent pass, so a gap in the
	 * first one still cannot put executable markup on an admin screen.
	 *
	 * @param string $markup Validated block markup.
	 * @return string
	 */
	private function preview( string $markup ): string {
		return wp_kses_post( do_blocks( $markup ) );
	}

	/**
	 * Keep one user from spending an unbounded amount in one sitting.
	 *
	 * @return true|WP_Error
	 */
	private function hit_rate_limit() {
		$key   = 'wow_signal_convert_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error(
				'wow_signal_rate_limit',
				sprintf(
					/* translators: %d: number of conversions allowed per hour. */
					__( 'That is %d conversions in an hour, which is the limit. Wait a little before continuing.', 'wow-signal' ),
					self::RATE_LIMIT
				),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Store accepted markup as a synced pattern or a draft page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_markup( WP_REST_Request $request ) {
		$markup = (string) $request->get_param( 'markup' );
		$title  = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$as     = (string) $request->get_param( 'as' );

		$validator = new BlockMarkupValidator();

		// Re-validated on the way in: the browser is not a trusted courier.
		if ( ! $validator->check( $markup ) ) {
			return new WP_Error(
				'wow_signal_invalid_markup',
				implode( ' ', $validator->errors() ),
				array( 'status' => 400 )
			);
		}

		$title = '' !== $title ? $title : __( 'Imported section', 'wow-signal' );

		if ( 'page' === $as ) {
			/*
			 * The landing template, not the default one. A converted design
			 * opens with its own full-bleed hero carrying the page's h1, and
			 * the default page template would print the post title above it —
			 * two h1s, and every alignfull band boxed inside a constrained
			 * main. This template has neither problem.
			 */
			$id = wp_insert_post(
				array(
					'post_type'     => 'page',
					'post_status'   => 'draft',
					'post_title'    => $title,
					'post_content'  => wp_slash( $markup ),
					'page_template' => 'page-landing',
				),
				true
			);
		} else {
			$id = wp_insert_post(
				array(
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => wp_slash( $markup ),
				),
				true
			);
		}

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return rest_ensure_response(
			array(
				'id'   => (int) $id,
				'as'   => 'page' === $as ? 'page' : 'pattern',
				'edit' => get_edit_post_link( (int) $id, 'raw' ),
			)
		);
	}
}
