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
use Wow\Signal\Support\BlockConverter;
use Wow\Signal\Support\BlockMarkupValidator;
use Wow\Signal\Support\ClaudeCli;
use Wow\Signal\Support\ConversionPrompt;
use Wow\Signal\Support\CssIndex;
use Wow\Signal\Support\DesignArchive;
use Wow\Signal\Support\DesignTokens;
use Wow\Signal\Support\ImportSession;
use Wow\Signal\Support\ModelGateway;
use Wow\Signal\Support\SectionSplitter;
use Wow\Signal\Support\SiteAssembler;
use Wow\Signal\Support\SiteBuilder;
use Wow\Signal\Support\SmartConverter;
use Wow\Signal\Support\Spend;

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
	 * The window the limit is counted over, in seconds.
	 */
	private const WINDOW = HOUR_IN_SECONDS;

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

		register_setting(
			'wow_signal_ai',
			ModelGateway::OPTION_TRANSPORT,
			array(
				'type'              => 'string',
				'default'           => 'auto',
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					$value = is_string( $value ) ? $value : '';

					return array_key_exists( $value, ModelGateway::transports() ) ? $value : 'auto';
				},
			)
		);

		register_setting(
			'wow_signal_ai',
			ClaudeCli::OPTION_BINARY,
			array(
				'type'              => 'string',
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_binary' ),
			)
		);
	}

	/**
	 * Keep the stored CLI path to something that is actually a file.
	 *
	 * The value is only ever handed to proc_open() as argv[0], never to a
	 * shell, so shell metacharacters have nothing to escape into. The check
	 * that matters is the plain one: an administrator who mistypes the path
	 * should be told now rather than by a failed conversion later.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_binary( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		// A changed path invalidates whatever the last probe concluded.
		ClaudeCli::forget();

		if ( '' === $value ) {
			return '';
		}

		$value = str_replace( '\\', '/', $value );

		if ( ! is_file( $value ) ) {
			add_settings_error(
				ClaudeCli::OPTION_BINARY,
				'wow_signal_cli_missing',
				__( 'There is no file at that path, so it was not saved. Leave the field empty to let the theme look for the claude command itself.', 'wow-signal' )
			);

			return '';
		}

		return $value;
	}

	/**
	 * Keep an API key out of the database when it is only a placeholder.
	 *
	 * The form shows a masked value; submitting it unchanged must not
	 * overwrite the real key with bullets. An emptied field, on the other
	 * hand, means "remove the key" — otherwise a stored key could never be
	 * taken out again.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_key( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		if ( str_contains( $value, '•' ) || str_contains( $value, '*' ) ) {
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
					'summary'   => $this->import_summary(),

					/*
					 * What is known without running anything. Whether the
					 * command actually works is asked for separately, once the
					 * screen is up, so a broken install cannot hold up the
					 * page load.
					 */
					'transport' => ModelGateway::preference(),
					'cliFound'  => '' !== ClaudeCli::binary(),
					'canSpawn'  => ClaudeCli::can_spawn(),
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

		// The :root variables are already scoped into the pane above, so no literal fallbacks are needed.
		$resets = $inherit . '{color:inherit;}'
			. $scope . ' a:not(.wp-element-button):not(.wp-block-button__link){color:var(--wp--preset--color--accent);}'
			. $scope . ' .wp-element-button,' . $scope . ' .wp-block-button__link{'
			. 'background-color:var(--wp--preset--color--accent);'
			. 'color:var(--wp--preset--color--base);'
			. 'text-decoration:none;'
			. 'padding:var(--wp--preset--spacing--30) var(--wp--preset--spacing--50);'
			. 'border-radius:var(--wp--custom--radius--sm);display:inline-block;}';

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

		/*
		 * Nothing here runs the binary. Finding the file and asking php.ini
		 * whether processes may be started are both free; actually running
		 * `claude --version` is not, and an admin page load is the wrong
		 * place to spend twenty seconds discovering a broken install. The
		 * live verdict comes from the /model endpoint once the screen is up.
		 */
		$transports = ModelGateway::transports();
		$cli_path   = ClaudeCli::binary();
		$can_spawn  = ClaudeCli::can_spawn();
		?>
		<div class="wrap wow-import">
			<div class="wow-import__masthead">
				<p class="wow-import__brand">
					<span class="wow-import__mark" aria-hidden="true">W</span>
					<?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>
				</p>

				<h1><?php esc_html_e( 'Design import', 'wow-signal' ); ?></h1>

				<p class="wow-import__lede">
					<?php esc_html_e( 'Upload an HTML design as a ZIP. Build the whole site in one press as drafts, or convert sections one at a time and accept only the ones you like. Everything an import adds can be removed again in one step.', 'wow-signal' ); ?>
				</p>
			</div>

			<details class="wow-import__settings" <?php echo '' === $key && '' === $cli_path ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Connection settings', 'wow-signal' ); ?>

					<?php if ( '' !== $cli_path && $can_spawn ) : ?>
						<span class="wow-import__badge is-on">
							<?php esc_html_e( 'Claude Code found on this machine', 'wow-signal' ); ?>
						</span>
					<?php endif; ?>

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
					<?php elseif ( '' === $cli_path ) : ?>
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
								<label for="wow-transport"><?php esc_html_e( 'How to reach the model', 'wow-signal' ); ?></label>
							</th>
							<td>
								<select id="wow-transport" class="wow-import__field" name="<?php echo esc_attr( ModelGateway::OPTION_TRANSPORT ); ?>">
									<?php foreach ( $transports as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( ModelGateway::preference(), $id ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<p class="description">
									<?php esc_html_e( 'Two routes lead to the same place. On your own machine, Claude Code is already signed in to your subscription, so rebuilding a design as many times as it takes adds nothing to a bill. On a client\'s hosting there is no such binary and PHP is usually barred from starting one, so the API is what works there. Neither is needed for the structural import, which never leaves the server.', 'wow-signal' ); ?>
								</p>

								<?php if ( ! $can_spawn ) : ?>
									<p class="description">
										<strong><?php esc_html_e( 'This server does not allow PHP to start other programs, so only the API route can work here.', 'wow-signal' ); ?></strong>
									</p>
								<?php elseif ( '' !== $cli_path ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: path to the claude binary. */
											esc_html__( 'Found at %s.', 'wow-signal' ),
											'<code>' . esc_html( $cli_path ) . '</code>'
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wow-cli"><?php esc_html_e( 'Path to the claude command', 'wow-signal' ); ?></label>
							</th>
							<td>
								<?php if ( ClaudeCli::binary_is_constant() ) : ?>
									<p>
										<strong><?php esc_html_e( 'Set in wp-config.php.', 'wow-signal' ); ?></strong>
										<?php esc_html_e( 'The path is defined as a constant and cannot be changed here.', 'wow-signal' ); ?>
									</p>
								<?php else : ?>
									<input
										type="text"
										id="wow-cli"
										class="wow-import__field"
										name="<?php echo esc_attr( ClaudeCli::OPTION_BINARY ); ?>"
										autocomplete="off"
										spellcheck="false"
										placeholder="<?php echo esc_attr( 'Windows' === PHP_OS_FAMILY ? 'C:/Users/you/.local/bin/claude.exe' : '/usr/local/bin/claude' ); ?>"
										value="<?php echo esc_attr( (string) get_option( ClaudeCli::OPTION_BINARY, '' ) ); ?>"
									>
									<p class="description">
										<?php esc_html_e( 'Only needed when the command is somewhere the web server cannot find on its own. Leave it empty and the theme looks along PATH. Remember that the web server runs as its own user: the binary has to be one that user may execute, and signed in as that user.', 'wow-signal' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
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
										<?php esc_html_e( 'Used only on the server; it is never sent to the browser. Clear the field and save to remove the stored key. For the strongest setup, put it in wp-config.php instead:', 'wow-signal' ); ?>
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

		/*
		 * The slug and file arguments are shared by every route that reads a
		 * design, and are checked here before any callback sees them.
		 */
		$slug_arg = array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_slug' ),
		);

		$file_arg = array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_file' ),
		);

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
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'purge_designs' ),
					'permission_callback' => $guard,
				),
			)
		);

		/*
		 * What a build would make of one page, section by section, before
		 * anything is created. Same converter, same images, no side effects
		 * beyond importing the pictures — which the build would do anyway.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/designs/(?P<slug>[a-z0-9-]+)/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview_page' ),
				'permission_callback' => $guard,
				'args'                => array(
					'slug' => $slug_arg,
					'file' => $file_arg,
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
					'slug' => $slug_arg,
					'file' => $file_arg,
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
				'args'                => array(
					'slug'     => $slug_arg,
					'file'     => $file_arg,
					'position' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
					'refine'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		/*
		 * Which route to a model this machine can take, checked afresh. The
		 * settings screen asks on load and again whenever somebody changes the
		 * path to the binary, because the honest answer to "will this work"
		 * is only ever found by running it.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/model',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'model_status' ),
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
		 * Two things the screen owns and must be able to put down: the work it
		 * has banked for a page, and the running total of what it has spent.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/session',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'forget_session' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/spend',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'reset_spend' ),
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
					'slug' => $slug_arg,
					'file' => $file_arg,
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
				'args'                => array(
					'slug'  => $slug_arg,
					'file'  => $file_arg,
					'reply' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
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
				'args'                => array(
					'slug'     => $slug_arg,
					'language' => array(
						'type'    => 'string',
						'default' => '',
					),
					'publish'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		/*
		 * The same build, one request per step. A twelve-page design used to
		 * be one request that had to finish inside whatever timeout the host
		 * set; now the browser asks for each page in turn and can show how
		 * far it has got.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/build/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_start' ),
				'permission_callback' => $guard,
				'args'                => array(
					'slug'         => $slug_arg,
					'language'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'publish'      => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'keep_archive' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'includes'     => array(
						'type'    => 'object',
						'default' => array(),
					),

					/*
					 * Off by default, and deliberately so. A build that leaves
					 * these alone converts entirely offline: no key needed, no
					 * money spent, no network. Turning them on is a choice the
					 * build screen makes the person state, with the cost of it
					 * on screen before the press.
					 */
					'smart'        => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'refine'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/build/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_step' ),
				'permission_callback' => $guard,
				'args'                => array(
					'job'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'key'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'page', 'chrome', 'finish' ),
					),
					'file' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/build/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'publish_pages' ),
				'permission_callback' => $guard,
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
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

		/*
		 * What a previous import left on the site. The clean-up panel needs
		 * this without a design loaded — after a reload, or after the archive
		 * itself was deleted — or there is no way back.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => $guard,
			)
		);
	}

	/**
	 * A design slug as unpacked by DesignArchive: short, ASCII, no dots.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_slug( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,80}$/i', $value );
	}

	/**
	 * A page path from the design index: relative, no traversal, ends in .html.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_file( $value ): bool {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 512 ) {
			return false;
		}

		$value = str_replace( '\\', '/', $value );

		if ( str_contains( $value, "\0" ) || str_contains( $value, '../' ) || str_starts_with( $value, '/' ) || preg_match( '#^[a-z]:#i', $value ) ) {
			return false;
		}

		return 1 === preg_match( '#\.html?$#i', $value );
	}

	/**
	 * Counts of content a previous import created that is still on the site.
	 *
	 * @return WP_REST_Response
	 */
	public function summary(): WP_REST_Response {
		return rest_ensure_response( $this->import_summary() );
	}

	/**
	 * What a previous import left behind, as counts.
	 *
	 * @return array{pages:int,parts:int,menus:int,media:int,fonts:int,archive:array{count:int,bytes:int}}
	 */
	private function import_summary(): array {
		$zero = array(
			'pages' => 0,
			'parts' => 0,
			'menus' => 0,
			'media' => 0,
			'fonts' => 0,
		);

		if ( method_exists( SiteAssembler::class, 'summary' ) ) {
			$summary = SiteAssembler::summary();

			foreach ( $zero as $key => $unused ) {
				$zero[ $key ] = (int) ( $summary[ $key ] ?? 0 );
			}
		}

		// The unpacked designs are on disk, not on the site, and are counted apart.
		$zero['archive'] = DesignArchive::footprint();

		return $zero;
	}

	/**
	 * Show one page the way a build would make it, before building anything.
	 *
	 * Each section comes back twice: the design's own markup and the blocks it
	 * becomes, both kses-filtered, with images pointing at the Media Library —
	 * the unpacked design is not web-accessible by design, so its own paths
	 * would show nothing. Cached briefly per file version; a re-upload lands
	 * under a new slug and misses the cache on its own.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_page( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' );
		$file = (string) $request->get_param( 'file' );
		$root = $this->design_root( $slug );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$path = $this->page_path( $root, $file );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$key    = 'wow_signal_preview_' . md5( $slug . '|' . $file . '|' . (int) filemtime( $path ) . '|' . WOW_SIGNAL_VERSION );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		// What start() does before reading any page, so the preview sees the same files.
		SiteBuilder::materialise_data_uris( $root );

		$media    = $this->media_map( $root );
		$preview  = SiteAssembler::preview( $root, $file, $media );
		$page_dir = (string) dirname( $file );
		$sections = array();

		foreach ( $preview['sections'] as $section ) {
			$markup = (string) $section['markup'];

			$sections[] = array(
				'position'      => (int) $section['position'],
				'label'         => (string) $section['label'],
				'original_html' => $this->original_html( (string) $section['html'], $media, $page_dir ),
				'preview_html'  => '' !== $markup ? $this->preview( $markup ) : '',
				'convertible'   => '' !== $markup,
				'concerns'      => array_values( (array) $section['concerns'] ),
			);
		}

		$payload = array(
			'title'    => (string) $preview['title'],
			'file'     => $file,
			'sections' => $sections,
			'notes'    => array_values( (array) $preview['notes'] ),
		);

		set_transient( $key, $payload, 10 * MINUTE_IN_SECONDS );

		return rest_ensure_response( $payload );
	}

	/**
	 * A design section's own markup, made safe to show on an admin screen.
	 *
	 * @param string                                  $html     Section markup from the splitter.
	 * @param array<string, array{id:int,url:string}> $media    Imported media map.
	 * @param string                                  $page_dir Directory of the page within the design.
	 * @return string
	 */
	private function original_html( string $html, array $media, string $page_dir ): string {
		// The splitter has already dropped these; belt and braces before kses.
		$html = (string) preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', '', $html );

		return wp_kses_post( SiteBuilder::relink_media( $html, $media, $page_dir ) );
	}

	/**
	 * Begin a stepwise build: everything the pages depend on, in one request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_start( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' );
		$root = $this->design_root( $slug );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fonts and media for a whole design, bounded.
		}

		$includes = $request->get_param( 'includes' );

		$smart = (bool) $request->get_param( 'smart' );

		$job = SiteAssembler::start(
			$root,
			DesignArchive::index( $root ),
			array(
				'language' => (string) $request->get_param( 'language' ),
				'publish'  => (bool) $request->get_param( 'publish' ),
				'includes' => is_array( $includes ) ? $includes : array(),
				'smart'    => $smart,
				'refine'   => $smart && (bool) $request->get_param( 'refine' ),
				'model'    => (string) get_option( self::OPTION_MODEL, AnthropicClient::DEFAULT_MODEL ),
				'effort'   => (string) get_option( self::OPTION_EFFORT, 'high' ),
			)
		);

		if ( is_wp_error( $job ) ) {
			$job->add_data( array( 'status' => 400 ) );

			return $job;
		}

		$steps = array();

		foreach ( $job['pages'] as $page ) {
			$steps[] = array(
				'key'   => 'page',
				'file'  => (string) $page['file'],
				'title' => (string) ( $page['title'] ?? $page['file'] ),
			);
		}

		$steps[] = array( 'key' => 'chrome' );
		$steps[] = array( 'key' => 'finish' );

		// Tokens, fonts and media are two steps' worth of work already behind us.
		$job['slug']         = $slug;
		$job['keep_archive'] = (bool) $request->get_param( 'keep_archive' );
		$job['completed']    = array();
		$job['done']         = 2;
		$job['total']        = 2 + count( $steps );

		$id = ImportSession::start_job( $job );

		return rest_ensure_response(
			array(
				'job'    => $id,
				'steps'  => $steps,
				'done'   => $job['done'],
				'total'  => $job['total'],

				/*
				 * Whether the model is in the loop, which the browser needs to
				 * know: a guided page takes a call per section rather than a
				 * few milliseconds, and the progress it shows should say so
				 * instead of looking stalled.
				 */
				'smart'  => ! empty( $job['smart'] ),
				'refine' => ! empty( $job['refine'] ),
			)
		);
	}

	/**
	 * Which route to a model this machine can take, and why.
	 *
	 * @return WP_REST_Response
	 */
	public function model_status(): WP_REST_Response {
		return rest_ensure_response( ModelGateway::status( true ) );
	}

	/**
	 * Run one step of a build the browser is driving.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_step( WP_REST_Request $request ) {
		$job = ImportSession::job( (string) $request->get_param( 'job' ) );

		if ( null === $job ) {
			return new WP_Error(
				'wow_signal_no_job',
				__( 'That build is no longer running. Start it again.', 'wow-signal' ),
				array( 'status' => 404 )
			);
		}

		// The design must still be where the job left it.
		$root = $this->design_root( (string) ( $job['slug'] ?? '' ) );

		if ( is_wp_error( $root ) ) {
			ImportSession::end_job();

			return $root;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			/*
			 * A structural page is milliseconds. A guided one is a model call
			 * per section, each of which can take a minute at high effort, so
			 * the ceiling has to be the length of the slowest page rather than
			 * of the fastest.
			 */
			set_time_limit( empty( $job['smart'] ) ? 60 : 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- One page, bounded.
		}

		$key  = (string) $request->get_param( 'key' );
		$file = (string) $request->get_param( 'file' );

		$progress = static function ( array $job ): array {
			return array(
				'done'  => (int) $job['done'],
				'total' => (int) $job['total'],
			);
		};

		if ( 'page' === $key ) {
			if ( ! $this->validate_file( $file ) ) {
				return new WP_Error( 'wow_signal_no_page', __( 'That page is not in this design.', 'wow-signal' ), array( 'status' => 404 ) );
			}

			$result = SiteAssembler::page( $job, $file );

			$job['completed'][ 'page:' . $file ] = true;
			$job['done']                         = 2 + count( $job['completed'] );

			ImportSession::update_job( $job );

			if ( is_wp_error( $result ) ) {
				$result->add_data( array_merge( array( 'status' => 400 ), $progress( $job ) ) );

				return $result;
			}

			return rest_ensure_response( array_merge( $progress( $job ), array( 'result' => $result ) ) );
		}

		if ( 'chrome' === $key ) {
			$result = SiteAssembler::chrome( $job );

			$job['completed']['chrome'] = true;
			$job['done']                = 2 + count( $job['completed'] );

			ImportSession::update_job( $job );

			return rest_ensure_response( array_merge( $progress( $job ), array( 'result' => $result ) ) );
		}

		$report = SiteAssembler::finish( $job );

		$job['completed']['finish'] = true;
		$job['done']                = (int) $job['total'];

		if ( is_wp_error( $report ) ) {
			ImportSession::update_job( $job );
			$report->add_data( array_merge( array( 'status' => 400 ), $progress( $job ) ) );

			return $report;
		}

		/*
		 * The design has done its job. Unless asked to keep it for another
		 * run, the unpacked copy goes — it is the largest thing an import
		 * leaves in uploads and nothing on the site refers to it.
		 */
		$archive_removed = false;

		if ( empty( $job['keep_archive'] ) ) {
			$archive_removed = DesignArchive::remove( $root );
		}

		ImportSession::end_job();

		return rest_ensure_response(
			array_merge(
				$progress( $job ),
				array(
					'result'          => $report,
					'archive_removed' => $archive_removed,
					'summary'         => $this->import_summary(),
				)
			)
		);
	}

	/**
	 * Publish pages a build created.
	 *
	 * Only pages carrying the import's own meta are touched; any other ID in
	 * the list is ignored rather than published.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function publish_pages( WP_REST_Request $request ): WP_REST_Response {
		$ids = $request->get_param( 'ids' );

		return rest_ensure_response(
			array(
				'pages' => SiteAssembler::publish( is_array( $ids ) ? $ids : array() ),
			)
		);
	}

	/**
	 * Delete every unpacked design from uploads.
	 *
	 * @return WP_REST_Response
	 */
	public function purge_designs(): WP_REST_Response {
		$removed = DesignArchive::purge();

		// Banked conversions refer to files that no longer exist.
		ImportSession::forget();
		ImportSession::end_job();

		return rest_ensure_response(
			array(
				'removed' => $removed,
				'archive' => DesignArchive::footprint(),
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

			$result = array(
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
			);

			/*
			 * A pasted section is banked exactly like a generated one: it
			 * cost a conversation turn, and a closed tab should not lose it.
			 */
			if ( $valid ) {
				ImportSession::remember(
					(string) $request->get_param( 'slug' ),
					(string) $request->get_param( 'file' ),
					$position,
					$result
				);
			}

			$result['preview'] = $valid ? $this->preview( $markup ) : '';
			$results[]         = $result;
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
			// A folder no route could address (an old percent-encoded name) is not offered.
			if ( ! self::validate_slug( basename( $dir ) ) ) {
				continue;
			}

			$index = DesignArchive::index( $dir );

			$designs[] = array(
				'slug'      => basename( $dir ),

				/*
				 * Each page carries what a guided pass over it would cost
				 * through the API, so the figure is on screen before the press
				 * rather than on an invoice afterwards. Per page rather than
				 * per design because a multilingual archive holds the same
				 * site several times over, and a build converts one language
				 * of it — a total for the whole archive would be three times
				 * the truth.
				 */
				'pages'     => $this->priced( $index['pages'] ),
				'languages' => $index['languages'],
				'images'    => $index['images'],
			);
		}

		return rest_ensure_response(
			array(
				'designs' => $designs,
				'archive' => DesignArchive::footprint(),
			)
		);
	}

	/**
	 * The same page rows, each carrying what a guided pass over it would cost.
	 *
	 * @param array<int, array<string, mixed>> $pages Page rows from the index.
	 * @return array<int, array<string, mixed>> The rows, with an `estimate` in dollars.
	 */
	private function priced( array $pages ): array {
		$model = (string) get_option( self::OPTION_MODEL, AnthropicClient::DEFAULT_MODEL );

		foreach ( $pages as $index => $page ) {
			$sections = max( 1, (int) ( $page['sections'] ?? 1 ) );

			/*
			 * A guided prompt carries the section, the structural conversion
			 * of it and the resolved-CSS brief, which together run to roughly
			 * twice the page's own markup — hence the doubling. One call per
			 * section, so the markup is divided between them for the input
			 * and the reply is counted once per call: Spend::estimate() prices
			 * exactly one reply.
			 */
			$pages[ $index ]['estimate'] = Spend::estimate(
				(int) ( (int) ( $page['bytes'] ?? 0 ) * 2 / $sections ),
				$model
			) * $sections;
		}

		return $pages;
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
		// An empty slug would resolve to the base itself and "build" every design.
		if ( ! $this->validate_slug( $slug ) ) {
			return new WP_Error( 'wow_signal_unknown_design', __( 'That design is not on this site.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		$base = DesignArchive::base_dir();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$real_base = realpath( $base );
		$real_dir  = realpath( trailingslashit( $base ) . $slug );

		if ( false === $real_base || false === $real_dir || ! is_dir( $real_dir ) ) {
			return new WP_Error( 'wow_signal_unknown_design', __( 'That design is not on this site.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		if ( ! $this->is_inside( $real_dir, $real_base ) ) {
			return new WP_Error( 'wow_signal_unknown_design', __( 'That design is not on this site.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		return $real_dir;
	}

	/**
	 * Whether a resolved path is strictly inside a base directory.
	 *
	 * A plain prefix test would accept `/designs-evil` as being inside
	 * `/designs`, and the base itself as being inside itself. Comparing with
	 * a trailing separator on both sides rules both out.
	 *
	 * @param string $path Resolved absolute path.
	 * @param string $base Resolved absolute base directory.
	 * @return bool
	 */
	private function is_inside( string $path, string $base ): bool {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );

		return $path !== $base && str_starts_with( $path . '/', $base . '/' );
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

		$model = (string) get_option( self::OPTION_MODEL, AnthropicClient::DEFAULT_MODEL );
		$held  = ImportSession::recall( (string) $request['slug'], (string) $request->get_param( 'file' ) );

		foreach ( $page['sections'] as $section ) {
			/*
			 * The prompt for a section is its own markup plus the CSS rules
			 * that match it — the same two pieces convert_section() sends. Its
			 * size is therefore a real basis for a cost estimate rather than an
			 * average, and it is measured here so the screen can total it up
			 * before anybody presses anything.
			 */
			$prompt_chars = strlen( $section['html'] ) + strlen( $index->rules_for( $section['html'] ) );

			// The markup and CSS stay on the server; the browser only needs a
			// description of each section to draw the review list.
			$sections[] = array(
				'estimate' => Spend::estimate( $prompt_chars, $model ),
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

		/*
		 * Anything already converted for this page is handed back with the
		 * section list, so reopening the screen resumes rather than restarts.
		 * Previews are not stored — they are rebuilt here from the markup that
		 * is, which costs nothing and keeps the stored record small.
		 */
		$resume = array();

		foreach ( $held as $position => $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$result['preview']            = ! empty( $result['valid'] ) && isset( $result['markup'] )
				? $this->preview( (string) $result['markup'] )
				: '';
			$resume[ (string) $position ] = $result;
		}

		return rest_ensure_response(
			array(
				'title'     => $page['title'],
				'lang'      => $page['lang'],
				'hasHeader' => null !== $page['header'],
				'hasFooter' => null !== $page['footer'],
				'sections'  => $sections,
				'resume'    => $resume,
				'spend'     => Spend::totals(),
				'limit'     => $this->rate_limit_status(),
				'model'     => $model,
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
		if ( ! $this->validate_file( $file ) ) {
			return new WP_Error( 'wow_signal_no_page', __( 'That page is not in this design.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		$candidate = realpath( trailingslashit( $root ) . ltrim( str_replace( '\\', '/', $file ), '/' ) );

		if ( false === $candidate || ! is_file( $candidate ) ) {
			return new WP_Error( 'wow_signal_no_page', __( 'That page is not in this design.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		if ( ! $this->is_inside( $candidate, $root ) ) {
			return new WP_Error( 'wow_signal_no_page', __( 'That page is not in this design.', 'wow-signal' ), array( 'status' => 404 ) );
		}

		$normalised_file = str_replace( '\\', '/', $candidate );

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

		/*
		 * Only a request that will actually reach a model counts against the
		 * hour. With no route configured the conversion still happens, offline
		 * and free, and spending an allowance on it would mean a site with no
		 * key could run out of free structural conversions.
		 */
		if ( ModelGateway::ready() ) {
			$limited = $this->hit_rate_limit();

			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		}

		$model = (string) get_option( self::OPTION_MODEL, AnthropicClient::DEFAULT_MODEL );
		$css   = CssIndex::from_directory( $root, $page['styles'] );

		$converter = new BlockConverter();
		$converter->use_design( DesignTokens::section_backgrounds( $root ), DesignTokens::extract( $root )['colors'], $css );

		$smart = new SmartConverter(
			$root,
			$converter,
			$css,
			array(
				'model'  => $model,
				'effort' => (string) get_option( self::OPTION_EFFORT, 'high' ),
				'refine' => (bool) $request->get_param( 'refine' ),
			)
		);

		$smart->for_page( (string) $request->get_param( 'file' ) );

		$conversion = $smart->convert(
			$section,
			0 === $position,
			array(
				'page' => $page['title'],
				'lang' => $page['lang'],
			)
		);

		$markup    = (string) $conversion['markup'];
		$validator = new BlockMarkupValidator();
		$valid     = $validator->check( $markup );

		if ( $valid ) {
			$markup = $this->with_media( $markup, $root, (string) $request->get_param( 'file' ) );
		}

		$concerns = array_map( 'strval', (array) $conversion['concerns'] );

		/*
		 * Nothing was sent anywhere, because there was nowhere to send it. The
		 * conversion below is real and usable; it is just not the one the
		 * button implied, and saying so here is the difference between "the
		 * model saw no problems" and "no model was asked".
		 */
		if ( ! empty( $conversion['unavailable'] ) ) {
			$status = ModelGateway::status();

			$concerns[] = sprintf(
				/* translators: %s: why no model could be reached. */
				__( 'This is the structural conversion — no model was asked, because none can be reached from here. %s', 'wow-signal' ),
				(string) $status['reason']
			);
		}

		/*
		 * Every call the conversion made, billed one by one. A guided
		 * conversion is one call, a reviewed one is two, and a call that
		 * failed is charged for nothing but is still worth saying out loud —
		 * the section fell back to the structural conversion, and the person
		 * looking at it should know that is what they are looking at.
		 */
		$usage     = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);
		$billed    = $model;
		$cost      = 0.0;
		$transport = ModelGateway::resolve();
		$spend     = Spend::totals();

		foreach ( $smart->calls() as $call ) {
			if ( empty( $call['ok'] ) ) {
				$concerns[] = sprintf(
					/* translators: %s: the reason the model could not be reached. */
					__( 'The model could not be reached, so this is the structural conversion: %s', 'wow-signal' ),
					(string) ( $call['error'] ?? '' )
				);

				continue;
			}

			$call_usage = is_array( $call['usage'] ?? null ) ? $call['usage'] : array();
			$billed     = (string) ( $call['model'] ?? $model );
			$transport  = (string) ( $call['transport'] ?? $transport );
			$billable   = ModelGateway::is_billable( $transport );

			$usage['input_tokens']  += (int) ( $call_usage['input_tokens'] ?? 0 ) + (int) ( $call_usage['cache_read_input_tokens'] ?? 0 ) + (int) ( $call_usage['cache_creation_input_tokens'] ?? 0 );
			$usage['output_tokens'] += (int) ( $call_usage['output_tokens'] ?? 0 );

			if ( $billable ) {
				$cost += Spend::cost( $call_usage, $billed );
			}

			$spend = Spend::record( $call_usage, $billed, $billable );
		}

		if ( 'api' === $transport && ! Spend::knows( $billed ) ) {
			$concerns[] = sprintf(
				/* translators: %s: model ID. */
				__( 'This reply came from %s, which has no known price — its cost is recorded as zero.', 'wow-signal' ),
				$billed
			);
		}

		$result = array(
			'valid'     => $valid,
			'errors'    => $validator->errors(),
			'notes'     => $valid ? $validator->review( $markup ) : array(),
			'markup'    => $valid ? $markup : '',
			'summary'   => (string) $conversion['summary'],
			'editable'  => array_map( 'strval', (array) $conversion['editable'] ),
			'concerns'  => array_values( array_unique( $concerns ) ),
			'changed'   => array_map( 'strval', (array) $conversion['changed'] ),
			'source'    => (string) $conversion['source'],
			'transport' => $transport,
			'usage'     => $usage,
			'model'     => $billed,
			'cost'      => $cost,
		);

		/*
		 * The reply is banked before it is returned. A conversion that has been
		 * paid for should survive the browser it was requested from.
		 */
		ImportSession::remember(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'file' ),
			$position,
			$result
		);

		$result['preview'] = $valid ? $this->preview( $markup ) : '';
		$result['spend']   = $spend;
		$result['limit']   = $this->rate_limit_status();

		return rest_ensure_response( $result );
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
		$status = $this->rate_limit_status();

		if ( $status['remaining'] < 1 ) {
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

		$remaining = max( 1, self::WINDOW - ( time() - $status['started'] ) );

		set_transient(
			$this->rate_limit_key(),
			array(
				'count'   => $status['used'] + 1,
				'started' => $status['started'],
			),
			$remaining
		);

		return true;
	}

	/**
	 * The transient holding this user's conversion count.
	 *
	 * @return string
	 */
	private function rate_limit_key(): string {
		return 'wow_signal_convert_' . get_current_user_id();
	}

	/**
	 * How much of the hourly allowance is left, and when it comes back.
	 *
	 * The limit worked before this existed, but silently: the only way to learn
	 * about it was to run into it mid-run. The window start is kept inside the
	 * stored value rather than read off the transient's expiry, because a site
	 * with a persistent object cache does not expose that expiry at all.
	 *
	 * @return array{limit:int,used:int,remaining:int,started:int,resets_in:int}
	 */
	private function rate_limit_status(): array {
		$stored = get_transient( $this->rate_limit_key() );

		// Before 1.2.0 the transient held a bare count and no window start.
		$used    = is_array( $stored ) ? (int) ( $stored['count'] ?? 0 ) : (int) $stored;
		$started = is_array( $stored ) ? (int) ( $stored['started'] ?? 0 ) : 0;

		// A window that has run out starts afresh, whatever it held.
		if ( 0 === $used || $started < 1 || time() - $started >= self::WINDOW ) {
			$used    = 0;
			$started = time();
		}

		$elapsed = max( 0, time() - $started );

		return array(
			'limit'     => self::RATE_LIMIT,
			'used'      => $used,
			'remaining' => max( 0, self::RATE_LIMIT - $used ),
			'started'   => $started,
			'resets_in' => max( 0, self::WINDOW - $elapsed ),
		);
	}

	/**
	 * Throw away the conversions banked for the current page.
	 *
	 * @return WP_REST_Response
	 */
	public function forget_session(): WP_REST_Response {
		ImportSession::forget();

		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/**
	 * Put the running spend total back to zero.
	 *
	 * This clears the record of what was spent; it does not refund anything,
	 * and the wording on the screen says so.
	 *
	 * @return WP_REST_Response
	 */
	public function reset_spend(): WP_REST_Response {
		Spend::reset();

		return rest_ensure_response( array( 'spend' => Spend::totals() ) );
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
