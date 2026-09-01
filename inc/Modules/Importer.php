<?php
/**
 * The design import screen and the endpoints behind it.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\AnthropicClient;
use Qwerty\Soft\Support\BlockConverter;
use Qwerty\Soft\Support\BlockMarkupValidator;
use Qwerty\Soft\Support\BlockRepair;
use Qwerty\Soft\Support\ClaudeCli;
use Qwerty\Soft\Support\ConversionPrompt;
use Qwerty\Soft\Support\BuildRunner;
use Qwerty\Soft\Support\CssIndex;
use Qwerty\Soft\Support\DesignArchive;
use Qwerty\Soft\Support\DesignDocs;
use Qwerty\Soft\Support\DesignStylesheet;
use Qwerty\Soft\Support\DesignTokens;
use Qwerty\Soft\Support\ImportLog;
use Qwerty\Soft\Support\ImportSession;
use Qwerty\Soft\Support\ModelGateway;
use Qwerty\Soft\Support\SectionSplitter;
use Qwerty\Soft\Support\SiteAssembler;
use Qwerty\Soft\Support\SiteBuilder;
use Qwerty\Soft\Support\SiteOptions;
use Qwerty\Soft\Support\SmartConverter;
use Qwerty\Soft\Support\SourceProject;
use Qwerty\Soft\Support\SourceRenderer;
use Qwerty\Soft\Support\Spend;

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
	private const NAMESPACE = 'qwerty-soft-signal/v1';

	/**
	 * Admin page slug.
	 */
	private const PAGE = 'qwerty-soft-signal-import';

	/**
	 * Option holding the API key.
	 */
	private const OPTION_KEY = 'qwerty_soft_anthropic_key';

	/**
	 * Option holding the chosen model.
	 */
	private const OPTION_MODEL = 'qwerty_soft_ai_model';

	/**
	 * Option holding the chosen effort level.
	 */
	private const OPTION_EFFORT = 'qwerty_soft_ai_effort';

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

		// A build the server finishes on its own, one step per cron tick.
		BuildRunner::boot();
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
			__( 'Design import', 'qwerty-soft-signal' ),
			__( 'Design import', 'qwerty-soft-signal' ),
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
			'qwerty_soft_ai',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_key' ),
			)
		);

		register_setting(
			'qwerty_soft_ai',
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
			'qwerty_soft_ai',
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
			'qwerty_soft_ai',
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
			'qwerty_soft_ai',
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
				'qwerty_soft_cli_missing',
				__( 'There is no file at that path, so it was not saved. Leave the field empty to let the theme look for the claude command itself.', 'qwerty-soft-signal' )
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
			'qwerty-soft-signal-import',
			QSOFT_URI . '/assets/css/admin-import.css',
			array(),
			QSOFT_VERSION
		);

		wp_add_inline_style( 'qwerty-soft-signal-import', $this->preview_styles() );

		wp_enqueue_script(
			'qwerty-soft-signal-import',
			QSOFT_URI . '/assets/js/admin-import.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			QSOFT_VERSION,
			true
		);

		wp_set_script_translations( 'qwerty-soft-signal-import', 'qwerty-soft-signal', QSOFT_DIR . '/languages' );

		wp_add_inline_script(
			'qwerty-soft-signal-import',
			'window.qwertySoftImport = ' . wp_json_encode(
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
		$scope     = '.qs-import__preview';
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
			wp_die( esc_html__( 'You do not have permission to import designs.', 'qwerty-soft-signal' ) );
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
		<div class="wrap qs-import">
			<div class="qs-import__masthead">
				<div class="qs-import__masthead-body">
					<p class="qs-import__brand">
						<span class="qs-import__mark" aria-hidden="true">Q</span>
						<?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>
					</p>

					<h1><?php esc_html_e( 'Design import', 'qwerty-soft-signal' ); ?></h1>

					<p class="qs-import__lede">
						<?php esc_html_e( 'A design as a ZIP becomes a WordPress site: pages, menu, header, footer. Nothing is one-way — everything an import adds can be taken back out in one press.', 'qwerty-soft-signal' ); ?>
					</p>
				</div>

				<?php
				/*
				 * The three steps, stated once so the screen explains itself
				 * before it asks for anything. They are a description of the
				 * page below rather than a control: nothing here is clickable,
				 * because a numbered list somebody can press is a promise the
				 * screen would then have to keep in both directions.
				 */
				$qsoft_steps = array(
					array(
						__( 'Upload', 'qwerty-soft-signal' ),
						__( 'The archive, however deeply it is boxed.', 'qwerty-soft-signal' ),
					),
					array(
						__( 'Choose', 'qwerty-soft-signal' ),
						__( 'Which pages, which language, how carefully.', 'qwerty-soft-signal' ),
					),
					array(
						__( 'Build', 'qwerty-soft-signal' ),
						__( 'On the server. Close the tab if you like.', 'qwerty-soft-signal' ),
					),
				);
				?>

				<ol class="qs-import__masthead-steps">
					<?php foreach ( $qsoft_steps as $qsoft_index => $qsoft_step ) : ?>
						<li>
							<span class="qs-import__masthead-num" aria-hidden="true"><?php echo esc_html( (string) ( $qsoft_index + 1 ) ); ?></span>
							<span class="qs-import__masthead-step">
								<strong><?php echo esc_html( $qsoft_step[0] ); ?></strong>
								<span><?php echo esc_html( $qsoft_step[1] ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>

			<details class="qs-import__settings" <?php echo '' === $key && '' === $cli_path ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Connection settings', 'qwerty-soft-signal' ); ?>

					<?php if ( '' !== $cli_path && $can_spawn ) : ?>
						<span class="qs-import__badge is-on">
							<?php esc_html_e( 'Claude Code found on this machine', 'qwerty-soft-signal' ); ?>
						</span>
					<?php endif; ?>

					<?php if ( AnthropicClient::key_is_constant() ) : ?>
						<span class="qs-import__badge is-on">
							<?php esc_html_e( 'Key active — from wp-config.php', 'qwerty-soft-signal' ); ?>
						</span>
					<?php elseif ( '' !== $key ) : ?>
						<span class="qs-import__badge is-on">
							<?php
							printf(
								/* translators: %s: the last four characters of the key. */
								esc_html__( 'Key active — ends in %s', 'qwerty-soft-signal' ),
								esc_html( substr( $key, -4 ) )
							);
							?>
						</span>
					<?php elseif ( '' === $cli_path ) : ?>
						<span class="qs-import__badge is-off">
							<?php esc_html_e( 'No key — the free routes still work', 'qwerty-soft-signal' ); ?>
						</span>
					<?php endif; ?>
				</summary>

				<form method="post" action="options.php">
					<?php settings_fields( 'qwerty_soft_ai' ); ?>

					<?php if ( '' !== $cli_path && $can_spawn && '' === $key && ! AnthropicClient::key_is_constant() ) : ?>
						<p class="qs-import__hint">
							<?php esc_html_e( 'Nothing here needs setting on this machine: Claude Code is installed and signed in, and the defaults below are the ones to want. These fields are for a client\'s hosting, where there is no such command and an API key is the only way to reach a model.', 'qwerty-soft-signal' ); ?>
						</p>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="qs-transport"><?php esc_html_e( 'How to reach the model', 'qwerty-soft-signal' ); ?></label>
							</th>
							<td>
								<select id="qs-transport" class="qs-import__field" name="<?php echo esc_attr( ModelGateway::OPTION_TRANSPORT ); ?>">
									<?php foreach ( $transports as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( ModelGateway::preference(), $id ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<p class="description">
									<?php esc_html_e( 'Two routes lead to the same place. On your own machine, Claude Code is already signed in to your subscription, so rebuilding a design as many times as it takes adds nothing to a bill. On a client\'s hosting there is no such binary and PHP is usually barred from starting one, so the API is what works there. Neither is needed for the structural import, which never leaves the server.', 'qwerty-soft-signal' ); ?>
								</p>

								<?php if ( ! $can_spawn ) : ?>
									<p class="description">
										<strong><?php esc_html_e( 'This server does not allow PHP to start other programs, so only the API route can work here.', 'qwerty-soft-signal' ); ?></strong>
									</p>
								<?php elseif ( '' !== $cli_path ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: path to the claude binary. */
											esc_html__( 'Found at %s.', 'qwerty-soft-signal' ),
											'<code>' . esc_html( $cli_path ) . '</code>'
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php
					/*
					 * Where the claude command is, asked only when it matters:
					 * when the theme could not find one, or when somebody has
					 * already pointed at one by hand. A machine where the probe
					 * found it needs no path, and a field that only ever
					 * confirms what is already working is a field to remove.
					 */
					$qsoft_show_cli = '' === $cli_path || '' !== (string) get_option( ClaudeCli::OPTION_BINARY, '' ) || ClaudeCli::binary_is_constant();
					?>

					<?php if ( $qsoft_show_cli ) : ?>
						<tr>
							<th scope="row">
								<label for="qs-cli"><?php esc_html_e( 'Path to the claude command', 'qwerty-soft-signal' ); ?></label>
							</th>
							<td>
								<?php if ( ClaudeCli::binary_is_constant() ) : ?>
									<p>
										<strong><?php esc_html_e( 'Set in wp-config.php.', 'qwerty-soft-signal' ); ?></strong>
										<?php esc_html_e( 'The path is defined as a constant and cannot be changed here.', 'qwerty-soft-signal' ); ?>
									</p>
								<?php else : ?>
									<input
										type="text"
										id="qs-cli"
										class="qs-import__field"
										name="<?php echo esc_attr( ClaudeCli::OPTION_BINARY ); ?>"
										autocomplete="off"
										spellcheck="false"
										placeholder="<?php echo esc_attr( 'Windows' === PHP_OS_FAMILY ? 'C:/Users/you/.local/bin/claude.exe' : '/usr/local/bin/claude' ); ?>"
										value="<?php echo esc_attr( (string) get_option( ClaudeCli::OPTION_BINARY, '' ) ); ?>"
									>
									<p class="description">
										<?php esc_html_e( 'Only needed when the command is somewhere the web server cannot find on its own. Leave it empty and the theme looks along PATH. Remember that the web server runs as its own user: the binary has to be one that user may execute, and signed in as that user.', 'qwerty-soft-signal' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
						<tr>
							<th scope="row">
								<label for="qs-key"><?php esc_html_e( 'Anthropic API key', 'qwerty-soft-signal' ); ?></label>
							</th>
							<td>
								<?php if ( AnthropicClient::key_is_constant() ) : ?>
									<p>
										<strong><?php esc_html_e( 'Set in wp-config.php.', 'qwerty-soft-signal' ); ?></strong>
										<?php esc_html_e( 'The key is defined as a constant, so it is not stored in the database and cannot be changed here.', 'qwerty-soft-signal' ); ?>
									</p>
								<?php else : ?>
									<input
										type="password"
										id="qs-key"
										class="qs-import__field"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
										autocomplete="off"
										spellcheck="false"
										placeholder="sk-ant-…"
										value="<?php echo '' !== $key ? esc_attr( str_repeat( '•', 24 ) . substr( $key, -4 ) ) : ''; ?>"
									>
									<p class="description">
										<?php esc_html_e( 'Used only on the server; it is never sent to the browser. Clear the field and save to remove the stored key. For the strongest setup, put it in wp-config.php instead:', 'qwerty-soft-signal' ); ?>
										<code>define( 'QSOFT_ANTHROPIC_KEY', '…' );</code>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="qs-model"><?php esc_html_e( 'Model', 'qwerty-soft-signal' ); ?></label>
							</th>
							<td>
								<select id="qs-model" class="qs-import__field" name="<?php echo esc_attr( self::OPTION_MODEL ); ?>">
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
								<label for="qs-effort"><?php esc_html_e( 'Care taken per section', 'qwerty-soft-signal' ); ?></label>
							</th>
							<td>
								<select id="qs-effort" class="qs-import__field" name="<?php echo esc_attr( self::OPTION_EFFORT ); ?>">
									<?php foreach ( $efforts as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( get_option( self::OPTION_EFFORT, 'high' ), $id ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Higher settings take longer and cost more per section, and handle complicated layouts better.', 'qwerty-soft-signal' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save settings', 'qwerty-soft-signal' ) ); ?>
				</form>
			</details>

			<div id="qs-import-app" class="qs-import__app">
				<noscript><?php esc_html_e( 'This screen needs JavaScript.', 'qwerty-soft-signal' ); ?></noscript>
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

		/*
		 * The design's own stylesheet, served to the preview panes.
		 *
		 * The unpacked design is not reachable over HTTP by design, so the
		 * preview cannot link to its CSS files — this hands over the same
		 * compiled, url()-rewritten stylesheet the build installs, which is
		 * what makes the left-hand pane look like the archive instead of like
		 * a column of unstyled text.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/designs/(?P<slug>[a-z0-9-]+)/stylesheet',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'design_stylesheet' ),
				'permission_callback' => $guard,
				'args'                => array( 'slug' => $slug_arg ),
			)
		);

		/*
		 * What the importer is doing, and has done. Polled by the screen every
		 * couple of seconds while anything is running, which is also what
		 * makes a long import legible: an hour of work reads as an hour of
		 * sentences rather than as a bar that has not moved.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'read_log' ),
				'permission_callback' => $guard,
				'args'                => array(
					'since' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		/*
		 * The site owner's own instructions for one design. Stored inside the
		 * design and read back into every brief, above whatever the handoff
		 * wrote about itself.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/designs/(?P<slug>[a-z0-9-]+)/instructions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_instructions' ),
					'permission_callback' => $guard,
					'args'                => array( 'slug' => $slug_arg ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'write_instructions' ),
					'permission_callback' => $guard,
					'args'                => array(
						'slug'  => $slug_arg,
						'notes' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		/*
		 * A design that is an application rather than a set of pages. The
		 * first route says what it is and which URLs it serves; the second
		 * reads one of them and writes the page it renders into the design,
		 * after which it is an ordinary page and every route above works on
		 * it unchanged.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/designs/(?P<slug>[a-z0-9-]+)/source',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'read_source' ),
				'permission_callback' => $guard,
				'args'                => array(
					'slug'    => $slug_arg,

					// Which application in the archive, when it holds more than one.
					'project' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/designs/(?P<slug>[a-z0-9-]+)/render',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'render_route' ),
				'permission_callback' => $guard,
				'args'                => array(
					'slug'    => $slug_arg,
					'route'   => array(
						'type'     => 'string',
						'required' => true,
					),

					// The application the route belongs to, when there is a choice.
					'project' => array(
						'type'    => 'string',
						'default' => '',
					),
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
					 * The screens the design ships for running the site — a
					 * translation queue, a settings panel, a customer download
					 * area. Left out unless asked for, because they are real
					 * pages that almost nobody importing a design wants.
					 */
					'utility'      => array(
						'type'    => 'boolean',
						'default' => false,
					),

					/*
					 * Files this build must leave alone: the versions of a page
					 * that lost the address to another version of itself.
					 */
					'exclude'      => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
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

					/*
					 * Whether the server finishes this build on its own. A
					 * corrected, reviewed build of a real handoff runs for an
					 * hour, and an hour is longer than a tab stays open.
					 */
					'unattended'   => array(
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
			'/build/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_resume' ),
				'permission_callback' => $guard,
			)
		);

		/*
		 * Put back the blocks a site is missing.
		 *
		 * The blocks an import generates are files in the theme, and the theme
		 * is the one part of a WordPress site that gets replaced wholesale: a
		 * redeploy, a copied database, a directory cleaned out by hand. When
		 * they go, every page says "your site doesn't include support for this
		 * block" while the content sits safely in the database. Rebuilding them
		 * needs no model and takes under a second, so it is a button rather
		 * than a rebuild.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/plugins/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_plugin' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
				},
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/blocks/repair',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'blocks_repair' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/build/stop',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_stop' ),
				'permission_callback' => $guard,
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
		/*
		 * Every key the clean-up panel prints, `blocks` included. It used to
		 * be left out here while `SiteAssembler::summary()` counted it
		 * correctly, so the screen said "0 section blocks" on a site with two
		 * hundred of them — and said it directly above the button that
		 * deletes them.
		 */
		$zero = array(
			'pages'  => 0,
			'parts'  => 0,
			'menus'  => 0,
			'media'  => 0,
			'fonts'  => 0,
			'blocks' => 0,
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

		$key    = 'qwerty_soft_preview_' . md5( $slug . '|' . $file . '|' . (int) filemtime( $path ) . '|' . QSOFT_VERSION );
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
	 * The steps a build runs, in the order it runs them.
	 *
	 * The chrome first, then the home page, then the rest, then the front page
	 * and the links between everything. Named in one place because two
	 * endpoints hand the list out: the one that starts a build, and the one a
	 * tab that only watches asks for its log.
	 *
	 * The order here has to be the order BuildRunner actually takes. A list
	 * that promised the header last while the build made it first would put
	 * every row of the progress display against the wrong step, and the one
	 * thing this screen is for is saying where a long build has got to.
	 *
	 * @param array<int, array<string, mixed>> $pages Pages the build will make, home first.
	 * @return array<int, array<string, string>>
	 */
	private static function build_steps( array $pages ): array {
		$steps = array( array( 'key' => 'chrome' ) );

		foreach ( $pages as $page ) {
			$steps[] = array(
				'key'   => 'page',
				'file'  => (string) ( $page['file'] ?? '' ),
				'title' => (string) ( $page['title'] ?? ( $page['file'] ?? '' ) ),
			);
		}

		$steps[] = array( 'key' => 'finish' );

		return $steps;
	}

	/**
	 * The work a build has already done by the time it reports for the first time.
	 *
	 * Reading the design's own colours, type and spacing, and importing its
	 * fonts and pictures, both happen inside the request that starts a build.
	 * They cost real seconds and they are counted in the total, so they are
	 * described here rather than left as an unexplained head start.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function prep_steps(): array {
		return array(
			array(
				'key'   => 'tokens',
				'title' => __( 'Colours, type and spacing read from the design', 'qwerty-soft-signal' ),
			),
			array(
				'key'   => 'media',
				'title' => __( 'Fonts and pictures imported', 'qwerty-soft-signal' ),
			),
		);
	}

	/**
	 * Refuse to build a site nobody would be able to edit.
	 *
	 * A wrapped import without ACF Pro still produces correct pages — the
	 * design's markup, its stylesheet, its words — and not one of them can be
	 * changed afterwards: every section reads "Unsupported" in the editor and
	 * the footer has no screen to be edited from. That is a site to rebuild
	 * rather than a site to work on.
	 *
	 * So it is refused before the work rather than discovered after it. An
	 * hour of building is a poor way to learn that a plugin is missing.
	 *
	 * @return WP_Error|null The refusal, or null when the build may proceed.
	 */
	private function needs_acf(): ?WP_Error {
		if ( ! SiteAssembler::wrapping() || SiteOptions::editable() ) {
			return null;
		}

		return new WP_Error(
			'qwerty_soft_needs_acf',
			__( 'This build needs ACF Pro, which is not active. Every section becomes a block with editable fields, and ACF Pro is what provides those fields — without it the pages would be built correctly and could not be edited afterwards. Install and activate ACF Pro, then build.', 'qwerty-soft-signal' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Begin a stepwise build: everything the pages depend on, in one request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_start( WP_REST_Request $request ) {
		$blocked = $this->needs_acf();

		if ( null !== $blocked ) {
			return $blocked;
		}

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

				// The admin and utility screens, only when they were asked for.
				'utility'  => (bool) $request->get_param( 'utility' ),

				// The versions of a page the screen decided against.
				'exclude'  => (array) $request->get_param( 'exclude' ),
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

		$steps = self::build_steps( $job['pages'] );

		// Tokens, fonts and media are two steps' worth of work already behind us.
		$job['slug']         = $slug;
		$job['keep_archive'] = (bool) $request->get_param( 'keep_archive' );
		$job['completed']    = array();
		$job['done']         = 2;
		$job['total']        = 2 + count( $steps );

		/*
		 * Who finishes this build: the tab that started it, or the server. A
		 * guided build of a real handoff is an hour of model calls, and an
		 * hour is longer than a person will sit on one screen.
		 */
		$unattended        = (bool) $request->get_param( 'unattended' );
		$job['unattended'] = $unattended;
		$job['user']       = get_current_user_id();

		$id = ImportSession::start_job( $job );

		ImportLog::clear();
		ImportLog::add(
			'build',
			sprintf(
				/* translators: 1: number of pages, 2: number of images. */
				__( 'Build started: %1$d pages to make, %2$d images already imported.', 'qwerty-soft-signal' ),
				count( $job['pages'] ),
				(int) ( $job['report']['media'] ?? 0 )
			)
		);

		/*
		 * The journal earlier imports left behind — see Lessons. Said once at
		 * the start so the person watching knows the build is not starting
		 * from a cold start, and the same paragraph reaches the model that
		 * reviews each section.
		 */
		$learned = \Qwerty\Soft\Support\Lessons::brief();

		if ( '' !== $learned ) {
			ImportLog::add( 'build', $learned );
		}

		if ( $unattended ) {
			ImportLog::add( 'build', __( 'This build runs on the server. You can close this tab; it will keep going.', 'qwerty-soft-signal' ) );
			BuildRunner::schedule( (int) $job['user'] );
		}

		return rest_ensure_response(
			array(
				'job'        => $id,
				'steps'      => $steps,

				/*
				 * The two steps that are already behind us by the time this
				 * replies. They are counted in the total, so leaving them out
				 * of the list is what made a build open on "2 of 9 done" with
				 * seven rows to show for it.
				 */
				'prep'       => self::prep_steps(),
				'done'       => $job['done'],
				'total'      => $job['total'],

				// Whether the browser should drive the steps or just watch.
				'unattended' => $unattended,

				/*
				 * Whether the model is in the loop, which the browser needs to
				 * know: a guided page takes a call per section rather than a
				 * few milliseconds, and the progress it shows should say so
				 * instead of looking stalled.
				 */
				'smart'      => ! empty( $job['smart'] ),
				'refine'     => ! empty( $job['refine'] ),
			)
		);
	}

	/**
	 * Which route to a model this machine can take, and why.
	 *
	 * @return WP_REST_Response
	 */
	public function model_status(): WP_REST_Response {
		$status = ModelGateway::status( true );

		/*
		 * What the screen needs to know before somebody presses Build, sent
		 * with the status it already asks for rather than in a request of its
		 * own. A wrapped import turns every section into a block with editable
		 * fields, and ACF Pro is what provides those fields — so a site without
		 * it can be built and then not edited, which is the one outcome worth
		 * preventing before the work rather than reporting after it.
		 */
		$status['acf'] = SiteOptions::editable();

		/*
		 * And how many blocks this site refers to but cannot draw. Counted
		 * here because the screen already asks for this every few seconds, and
		 * because a site in that state looks broken in a way that gives no clue
		 * what to press: the pages are intact, every section says the block is
		 * unsupported, and the fix is one button that needs no model at all.
		 */
		$status['missing_blocks'] = count( BlockRepair::missing() );

		return rest_ensure_response( $status );
	}

	/**
	 * The pages a running build has already made, keyed by step.
	 *
	 * Read back from the site rather than trusted from the job: a page can be
	 * published, renamed or deleted while the build is still running, and a
	 * link that 404s is worse than no link.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return array<string, array<string, mixed>>
	 */
	private static function made_so_far( array $job ): array {
		$made = array();

		foreach ( (array) ( $job['routes'] ?? array() ) as $file => $route ) {
			$id = (int) ( $route['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$status = get_post_status( $id );

			if ( false === $status || 'trash' === $status ) {
				continue;
			}

			$made[ 'page:' . $file ] = array(
				'id'        => $id,
				'title'     => (string) ( $route['title'] ?? '' ),
				'status'    => (string) $status,
				'sections'  => (int) ( $route['sections'] ?? 0 ),
				'link'      => 'publish' === $status
					? (string) get_permalink( $id )
					: (string) get_preview_post_link( $id ),
				'edit_link' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			);
		}

		return $made;
	}

	/**
	 * The last finished build's report, checked against the site as it is now.
	 *
	 * The report was written when the build ended and the site has been
	 * editable ever since: a page may have been published, or removed by the
	 * clean-up button. So every row is read back before it is shown, and a
	 * report whose pages have all gone is no report at all — which is also how
	 * pressing "Delete everything this import added" makes this disappear
	 * without anything having to tell it.
	 *
	 * @return array<string, mixed>|null
	 */
	private function last_built(): ?array {
		$stored = ImportSession::last_report();

		if ( null === $stored ) {
			/*
			 * No report kept, but the pages themselves are proof enough. This
			 * is what a screen sees after a build that finished in another
			 * tab, or before this theme learned to write the report down: the
			 * list read back off the site, so "what happened to my import" is
			 * never answered with an empty upload form.
			 */
			$pages = SiteAssembler::imported_pages();

			if ( array() === $pages ) {
				return null;
			}

			return array(
				'at'     => 0,
				'read'   => true,
				'report' => array(
					'pages'    => $pages,
					'concerns' => array(),
				),
			);
		}

		$report = is_array( $stored['report'] ?? null ) ? $stored['report'] : array();
		$pages  = array();

		foreach ( (array) ( $report['pages'] ?? array() ) as $page ) {
			$id     = (int) ( $page['id'] ?? 0 );
			$status = $id > 0 ? get_post_status( $id ) : false;

			if ( false === $status || 'trash' === $status ) {
				continue;
			}

			$page['status'] = $status;
			$pages[]        = $page;
		}

		if ( array() === $pages ) {
			ImportSession::forget_report();

			return null;
		}

		$report['pages'] = $pages;

		return array(
			'at'     => (int) ( $stored['at'] ?? 0 ),
			'report' => $report,
		);
	}

	/**
	 * Carry a stalled server build on now, in this request.
	 *
	 * The screen offers this when a build has gone quiet for longer than a
	 * step may be silent. Waiting is the wrong advice at that point: the
	 * common cause is a site whose WP-Cron never runs, where the watchdog can
	 * book ticks all day and none of them happen. One step is run here
	 * instead, and it books its own next tick before starting — so this either
	 * restarts a build that then continues on its own, or advances one that
	 * cannot, one press at a time.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_resume() {
		$job = ImportSession::running();

		if ( null === $job || ! empty( $job['completed']['finish'] ) ) {
			return new WP_Error(
				'qwerty_soft_no_job',
				__( 'There is no unfinished build on this site to continue.', 'qwerty-soft-signal' ),
				array( 'status' => 404 )
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// One step, the same ceiling the browser-driven build runs a page under.
			set_time_limit( empty( $job['smart'] ) ? 120 : 1800 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- One step, bounded by the transport's own timeouts.
		}

		ImportLog::add( 'build', __( 'Continued by hand from the import screen.', 'qwerty-soft-signal' ) );

		$more = BuildRunner::resume( get_current_user_id() );
		$job  = ImportSession::running();

		return rest_ensure_response(
			array(
				'done'     => null === $job ? 0 : (int) ( $job['done'] ?? 0 ),
				'total'    => null === $job ? 0 : (int) ( $job['total'] ?? 0 ),
				'finished' => null === $job || ! empty( $job['completed']['finish'] ),
				'more'     => $more,
			)
		);
	}

	/**
	 * Rebuild the generated blocks this site refers to but no longer has.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function blocks_repair() {
		$report = BlockRepair::run();

		if ( '' === (string) $report['root'] && array() !== (array) $report['missing'] ) {
			return new WP_Error(
				'qwerty_soft_no_design',
				__( 'The design these pages were built from is no longer unpacked, so the blocks cannot be rebuilt from it. Upload the archive again and build.', 'qwerty-soft-signal' ),
				array( 'status' => 409 )
			);
		}

		return rest_ensure_response(
			array(
				'written' => (int) $report['written'],
				'pages'   => (int) $report['pages'],
				'missing' => array_values( (array) $report['missing'] ),
			)
		);
	}

	/**
	 * Stop a build, keeping everything it has made and can continue from.
	 *
	 * The button used to be a lie. It set a flag in the tab's own state and
	 * drew a stopped panel, while the build carried on running on cron —
	 * so somebody who pressed Cancel and then Build again ended up with two
	 * workers, and somebody who just closed the tab never knew it was still
	 * going.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_stop() {
		if ( ! BuildRunner::stop( get_current_user_id() ) ) {
			return new WP_Error(
				'qwerty_soft_no_job',
				__( 'There is no build running to stop.', 'qwerty-soft-signal' ),
				array( 'status' => 404 )
			);
		}

		$job = ImportSession::running();

		return rest_ensure_response(
			array(
				'stopped' => true,
				'done'    => null === $job ? 0 : (int) ( $job['done'] ?? 0 ),
				'total'   => null === $job ? 0 : (int) ( $job['total'] ?? 0 ),
			)
		);
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
				'qwerty_soft_no_job',
				__( 'That build is no longer running. Start it again.', 'qwerty-soft-signal' ),
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
				return new WP_Error( 'qwerty_soft_no_page', __( 'That page is not in this design.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
			}

			ImportLog::add(
				'build',
				sprintf(
					/* translators: 1: page file, 2: step number, 3: steps in total. */
					__( 'Building %1$s — step %2$d of %3$d…', 'qwerty-soft-signal' ),
					$file,
					(int) $job['done'] + 1,
					(int) $job['total']
				)
			);

			$started = microtime( true );
			$result  = SiteAssembler::page( $job, $file );

			$job['completed'][ 'page:' . $file ] = true;
			$job['done']                         = 2 + count( $job['completed'] );

			ImportSession::update_job( $job );

			if ( is_wp_error( $result ) ) {
				ImportLog::add(
					'build',
					sprintf(
						/* translators: 1: page file, 2: the reason. */
						__( '%1$s was not built: %2$s', 'qwerty-soft-signal' ),
						$file,
						$result->get_error_message()
					)
				);

				$result->add_data( array_merge( array( 'status' => 400 ), $progress( $job ) ) );

				return $result;
			}

			ImportLog::add(
				'build',
				sprintf(
					/* translators: 1: page title, 2: number of sections, 3: seconds taken. */
					__( 'Built “%1$s” — %2$d sections, %3$ds.', 'qwerty-soft-signal' ),
					(string) ( $result['title'] ?? $file ),
					(int) ( $result['sections'] ?? 0 ),
					(int) round( microtime( true ) - $started )
				),
				array( 'id' => (int) ( $result['id'] ?? 0 ) )
			);

			return rest_ensure_response( array_merge( $progress( $job ), array( 'result' => $result ) ) );
		}

		if ( 'chrome' === $key ) {
			ImportLog::add( 'build', __( 'Building the menu, the header and the footer…', 'qwerty-soft-signal' ) );

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

		ImportLog::add(
			'build',
			sprintf(
				/* translators: %d: number of pages. */
				_n( 'The build is done: %d page is on the site.', 'The build is done: %d pages are on the site.', count( (array) ( $report['pages'] ?? array() ) ), 'qwerty-soft-signal' ),
				count( (array) ( $report['pages'] ?? array() ) )
			)
		);

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
		$result = DesignArchive::purge();

		// Banked conversions refer to files that no longer exist.
		ImportSession::forget();
		ImportSession::end_job();

		return rest_ensure_response(
			array(
				'removed' => (int) $result['removed'],

				/*
				 * What would not go, named. Reporting only the successes is
				 * how a folder the web server may not delete came to sit in
				 * uploads while the screen said everything was removed.
				 */
				'failed'  => array_values( $result['failed'] ),
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
		$blocked = $this->needs_acf();

		if ( null !== $blocked ) {
			return $blocked;
		}

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
					: sprintf( __( 'Section %d', 'qwerty-soft-signal' ), $position + 1 ),
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

			/*
			 * An empty folder is not a design. One is left behind whenever a
			 * removal deleted the files but could not delete the directory,
			 * and while the list still offered it there was no way to be rid
			 * of it: every button worked on something that was already gone.
			 */
			if ( array() === $index['pages'] && 0 === (int) $index['images'] && 0 === (int) $index['components'] ) {
				DesignArchive::remove( $dir );

				continue;
			}

			$designs[] = array(
				'slug'       => basename( $dir ),

				/*
				 * Each page carries what a guided pass over it would cost
				 * through the API, so the figure is on screen before the press
				 * rather than on an invoice afterwards. Per page rather than
				 * per design because a multilingual archive holds the same
				 * site several times over, and a build converts one language
				 * of it — a total for the whole archive would be three times
				 * the truth.
				 */
				'pages'      => $this->priced( $index['pages'] ),
				'languages'  => $index['languages'],
				'images'     => $index['images'],
				'components' => $index['components'],
				'readable'   => $index['readable'],
				'kind'       => $index['kind'],
				'diagnosis'  => $this->diagnosis( $index ),
				'needs'      => \Qwerty\Soft\Support\DesignNeeds::scan( $dir ),
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
	 * The design's compiled stylesheet, as CSS.
	 *
	 * Sent as text/css so a preview iframe can link to it. Cached per design
	 * for the length of a session: compiling a Tailwind build is a second of
	 * work and the preview asks for it once per pane.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function design_stylesheet( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' );
		$root = $this->design_root( $slug );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		/*
		 * A page is styled by the stylesheets it links. Asked for one, the
		 * compile follows its `<link>` tags; asked for the design, it takes
		 * everything. The difference matters on a developer handoff, where
		 * five projects sit side by side and each has a full stylesheet that
		 * redefines `:root`, `body` and `.card` — swept together they
		 * overwrite one another and every preview came out wearing the wrong
		 * project.
		 */
		$file  = (string) $request->get_param( 'page' );
		$pages = array();

		if ( '' !== $file ) {
			$path = $this->page_path( $root, $file );

			if ( is_string( $path ) ) {
				$pages[] = $path;
			}
		}

		$key = 'qwerty_soft_css_' . md5( $root . '|' . implode( '|', $pages ) );
		$css = get_transient( $key );

		if ( ! is_string( $css ) ) {
			// For a frame, so the design's own html and body rules come too.
			$css = DesignStylesheet::compile( $root, $this->media_map( $root ), true, $pages );

			set_transient( $key, $css, HOUR_IN_SECONDS );
		}

		/*
		 * Handed over as data rather than as a text/css body: the preview
		 * writes it into an iframe as a <style>, which needs no second
		 * request, no nonce in a URL and no argument with the REST layer
		 * about content types.
		 */
		return rest_ensure_response(
			array(
				'css'   => $css,
				'bytes' => strlen( $css ),
			)
		);
	}

	/**
	 * The running account of what the importer is doing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function read_log( WP_REST_Request $request ): WP_REST_Response {
		$since = (int) $request->get_param( 'since' );
		$job   = ImportSession::running();

		/*
		 * The poll is also the watchdog.
		 *
		 * WP-Cron deletes an event when it fires, so a step that takes the
		 * process down with it leaves an unattended build with nothing
		 * scheduled to carry it — frozen at "2 of 7", no error, no end. This
		 * screen asks for its log every few seconds, which makes it the one
		 * thing reliably looking; if it finds a running build and an empty
		 * queue, it books the tick that went missing.
		 *
		 * The diagnosis is taken first, because booking a tick does not undo
		 * the silence that earned it: read afterwards, every build the
		 * watchdog had just fixed would be reported as stopped.
		 */
		$stall   = BuildRunner::diagnose( get_current_user_id() );
		$revived = BuildRunner::revive( get_current_user_id() );

		if ( $revived ) {
			ImportLog::add( 'build', __( 'The build had stopped without finishing a step; it was picked up again from where it stood.', 'qwerty-soft-signal' ) );
		}

		return rest_ensure_response(
			array(
				'lines'   => ImportLog::since( $since ),

				/*
				 * The state of the job the log is about, so the screen can
				 * show progress and know when to stop asking — including for
				 * a build that is being run by the server rather than by this
				 * tab.
				 */
				'job'     => null === $job ? null : array(
					'id'         => (string) ( $job['id'] ?? '' ),
					'done'       => (int) ( $job['done'] ?? 0 ),
					'total'      => (int) ( $job['total'] ?? 0 ),
					'unattended' => ! empty( $job['unattended'] ),
					'finished'   => ! empty( $job['completed']['finish'] ),

					/*
					 * Which step is being worked on, when it was claimed and
					 * when it last said anything.
					 *
					 * A tab that did not start the build knows none of this,
					 * and without it the panel is a bar and a number that both
					 * sit still for a quarter of an hour at a time — which is
					 * indistinguishable from a build that has died. With it,
					 * the right row is marked as working and carries a clock
					 * that moves.
					 */
					'current'    => (string) ( $job['working'] ?? '' ),
					'started'    => (int) ( $job['started'] ?? 0 ),
					'beat'       => BuildRunner::last_sign_of_life( $job ),

					/*
					 * The same list the browser gets when it starts a build
					 * itself. A reloaded tab, and a tab watching a build the
					 * server is running, have never seen that reply — without
					 * this they can draw a bar and a number but not the one
					 * thing worth looking at, which is which page is being
					 * made and which are already done.
					 */
					'prep'       => self::prep_steps(),
					'steps'      => self::build_steps( is_array( $job['pages'] ?? null ) ? $job['pages'] : array() ),
					'completed'  => array_keys( is_array( $job['completed'] ?? null ) ? $job['completed'] : array() ),

					/*
					 * Every page that already exists, with somewhere to go.
					 *
					 * A page is on the site the moment its own step ends — a
					 * build of fourteen pages has thirteen real, readable
					 * drafts while it is still working on the last. Holding
					 * the links back until the end made an hour-long build
					 * feel like an hour of nothing, when in fact the home page
					 * was ready in the first four minutes.
					 */
					'made'       => self::made_so_far( $job ),

					/*
					 * A build that has gone quiet, described well enough for
					 * the screen to say what happened and offer to carry it
					 * on. Null on the poll that revived it: that one is fixed
					 * already, and the log line says so.
					 */
					'stall'      => $revived ? null : $stall,

					/*
					 * Stopped by hand, which is not the same as stalled and
					 * needs saying differently: nothing went wrong, somebody
					 * pressed a button, and the work is waiting rather than
					 * stuck. The screen offers to continue it either way.
					 */
					'stopped'    => ! empty( $job['stopped'] ),
				),

				/*
				 * What the last build made, once there is no build running.
				 *
				 * A build ends by deleting its own job, which used to take the
				 * report with it: reload the screen a minute after an hour's
				 * work and it offered an empty upload form, as though nothing
				 * had happened. Served only when nothing is running, because a
				 * build in progress has a panel of its own.
				 */
				'built'   => null === $job ? $this->last_built() : null,

				'running' => null !== $job,
			)
		);
	}

	/**
	 * Read the site owner's instructions for a design.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function read_instructions( WP_REST_Request $request ) {
		$root = $this->design_root( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		return rest_ensure_response( array( 'notes' => DesignDocs::notes( $root ) ) );
	}

	/**
	 * Save, or clear, the site owner's instructions for a design.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function write_instructions( WP_REST_Request $request ) {
		$root = $this->design_root( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$notes = (string) $request->get_param( 'notes' );

		if ( ! DesignDocs::save_notes( $root, $notes ) ) {
			return new WP_Error(
				'qwerty_soft_notes',
				__( 'The instructions could not be written into the design folder.', 'qwerty-soft-signal' ),
				array( 'status' => 500 )
			);
		}

		ImportLog::add(
			'read',
			'' === trim( $notes )
				? __( 'Your instructions for this design were cleared.', 'qwerty-soft-signal' )
				: __( 'Your instructions for this design were saved and will lead every brief.', 'qwerty-soft-signal' )
		);

		return rest_ensure_response( array( 'notes' => DesignDocs::notes( $root ) ) );
	}

	/**
	 * What application this design is, and which pages it serves.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function read_source( WP_REST_Request $request ) {
		$root = $this->design_root( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$projects = SourceProject::all( $root );

		if ( array() === $projects ) {
			return rest_ensure_response(
				array(
					'found'    => false,
					'projects' => array(),
					'routes'   => array(),
				)
			);
		}

		/*
		 * Which of them the screen is asking about. A handoff can hold three
		 * applications — the site, an admin panel, a widget meant to ship as a
		 * plugin — and only a person knows which one belongs on this site, so
		 * the choice is theirs and the default is simply the largest.
		 */
		$wanted  = (string) $request->get_param( 'project' );
		$project = $projects[0];

		foreach ( $projects as $candidate ) {
			if ( $candidate->relative() === $wanted ) {
				$project = $candidate;
				break;
			}
		}

		$routes   = $project->routes();
		$rendered = $this->rendered_pages( $root );

		foreach ( $routes as $index => $route ) {
			$file = SourceRenderer::RENDER_DIR . '/' . SourceProject::slug( (string) $route['path'] ) . '.html';

			$routes[ $index ]['rendered'] = isset( $rendered[ $file ] ) ? $rendered[ $file ] : null;
		}

		/*
		 * Every project, described well enough to choose between them without
		 * opening the archive: what it is called, where it sits, what it is
		 * written in, how big it is and how many pages it would produce.
		 */
		$listed = array();

		foreach ( $projects as $candidate ) {
			$listed[] = array(
				'dir'        => $candidate->relative(),
				'name'       => $candidate->name(),
				'framework'  => $candidate->framework(),
				'components' => $candidate->components(),
				'routes'     => count( $candidate->routes() ),
				'chosen'     => $candidate->relative() === $project->relative(),
			);
		}

		return rest_ensure_response(
			array(
				'found'     => true,
				'framework' => $project->framework(),
				'project'   => $project->relative(),
				'projects'  => $listed,
				'routes'    => $routes,
				'notes'     => $project->notes(),
				'model'     => ModelGateway::status(),
			)
		);
	}

	/**
	 * Which routes already have a page written for them.
	 *
	 * @param string $root Design root.
	 * @return array<string, array<string, mixed>> Relative file => page row.
	 */
	private function rendered_pages( string $root ): array {
		$pages = array();

		foreach ( DesignArchive::index( $root )['pages'] as $page ) {
			if ( str_starts_with( (string) $page['file'], SourceRenderer::RENDER_DIR . '/' ) ) {
				$pages[ (string) $page['file'] ] = $page;
			}
		}

		return $pages;
	}

	/**
	 * Read one route's components and write the page they render.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function render_route( WP_REST_Request $request ) {
		$root = $this->design_root( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$projects = SourceProject::all( $root );

		if ( array() === $projects ) {
			return new WP_Error(
				'qwerty_soft_no_source',
				__( 'There is no application source in this design to read.', 'qwerty-soft-signal' ),
				array( 'status' => 404 )
			);
		}

		// The same project the screen is listing routes for, not whichever is biggest.
		$chosen  = (string) $request->get_param( 'project' );
		$project = $projects[0];

		foreach ( $projects as $candidate ) {
			if ( $candidate->relative() === $chosen ) {
				$project = $candidate;
				break;
			}
		}

		$wanted = (string) $request->get_param( 'route' );
		$route  = null;

		foreach ( $project->routes() as $candidate ) {
			if ( (string) $candidate['path'] === $wanted ) {
				$route = $candidate;
				break;
			}
		}

		if ( null === $route ) {
			return new WP_Error(
				'qwerty_soft_no_route_row',
				__( 'That route is not one this application serves.', 'qwerty-soft-signal' ),
				array( 'status' => 404 )
			);
		}

		// Reading components always reaches a model, so it always counts against the hour.
		$limited = $this->hit_rate_limit();

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$renderer = new SourceRenderer(
			$root,
			$project,
			array(
				'model'  => (string) get_option( self::OPTION_MODEL, AnthropicClient::DEFAULT_MODEL ),
				'effort' => (string) get_option( self::OPTION_EFFORT, 'high' ),
			)
		);

		ImportLog::add(
			'render',
			sprintf(
				/* translators: 1: route path, 2: component name. */
				__( 'Reading %1$s out of its components (%2$s)…', 'qwerty-soft-signal' ),
				(string) $route['path'],
				(string) $route['component']
			)
		);

		$started = microtime( true );
		$result  = $renderer->render( $route );
		$call    = $renderer->call();
		$spend   = Spend::totals();

		if ( is_wp_error( $result ) ) {
			ImportLog::add(
				'render',
				sprintf(
					/* translators: 1: route path, 2: the reason. */
					__( 'Could not read %1$s: %2$s', 'qwerty-soft-signal' ),
					(string) $route['path'],
					$result->get_error_message()
				)
			);
		} else {
			ImportLog::add(
				'render',
				sprintf(
					/* translators: 1: route, 2: sections, 3: words, 4: seconds taken. */
					__( 'Read %1$s — %2$d sections, %3$d words, %4$ds.', 'qwerty-soft-signal' ),
					(string) $route['path'],
					(int) $result['sections'],
					(int) $result['words'],
					(int) round( microtime( true ) - $started )
				),
				array( 'file' => (string) $result['file'] )
			);
		}

		/*
		 * A call that was made is billed whether or not its answer was any
		 * use. A failed render that quietly cost a dollar and reported only
		 * the failure would make the running total on this screen a lie.
		 */
		if ( ! empty( $call['ok'] ) ) {
			$usage     = is_array( $call['usage'] ?? null ) ? $call['usage'] : array();
			$billed    = (string) ( $call['model'] ?? '' );
			$transport = (string) ( $call['transport'] ?? 'api' );
			$spend     = Spend::record( $usage, $billed, ModelGateway::is_billable( $transport ) );
		}

		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();

			// Keep whatever status the failure chose; the total rides along with it.
			$result->add_data( array_merge( array( 'status' => 500 ), $data, array( 'spend' => $spend ) ) );

			return $result;
		}

		$result['spend'] = $spend;
		$result['limit'] = $this->rate_limit_status();

		return rest_ensure_response( $result );
	}

	/**
	 * Install and activate one plugin the advisor recommended.
	 *
	 * The list is closed on purpose: this endpoint installs what
	 * DesignNeeds recommends and nothing else, always from wordpress.org's
	 * own package address. It is one button on the screen, not a package
	 * manager.
	 *
	 * @param WP_REST_Request $request key: which recommendation to act on.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_plugin( WP_REST_Request $request ) {
		$known = array(
			'woocommerce' => 'woocommerce/woocommerce.php',
		);

		$key = (string) $request->get_param( 'key' );

		if ( ! isset( $known[ $key ] ) ) {
			return new WP_Error( 'qwerty_soft_unknown_plugin', __( 'That is not a plugin this screen offers.', 'qwerty-soft-signal' ), array( 'status' => 400 ) );
		}

		$plugin = $known[ $key ];

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! is_file( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$done     = $upgrader->install( 'https://downloads.wordpress.org/plugin/' . $key . '.latest-stable.zip' );

			if ( true !== $done ) {
				return new WP_Error(
					'qwerty_soft_install_failed',
					sprintf(
						/* translators: %s: what the installer said. */
						__( 'The install did not finish: %s', 'qwerty-soft-signal' ),
						is_wp_error( $done ) ? $done->get_error_message() : implode( ' ', (array) $upgrader->skin->get_upgrade_messages() )
					),
					array( 'status' => 500 )
				);
			}
		}

		if ( ! is_plugin_active( $plugin ) ) {
			$activated = activate_plugin( $plugin );

			if ( is_wp_error( $activated ) ) {
				$activated->add_data( array( 'status' => 500 ) );

				return $activated;
			}
		}

		/*
		 * WooCommerce leaves its tables and pages for the next admin load and
		 * launches in coming-soon mode. The next admin load is now, and a
		 * shop the importer is about to fill should not greet the studio with
		 * a countdown page.
		 */
		if ( 'woocommerce' === $key ) {
			if ( class_exists( '\WC_Install' ) ) {
				\WC_Install::install();
			}

			update_option( 'woocommerce_coming_soon', 'no' );
			flush_rewrite_rules();
		}

		return rest_ensure_response(
			array(
				'key'       => $key,
				'installed' => true,
				'active'    => is_plugin_active( $plugin ),
			)
		);
	}

	/**
	 * Say in one sentence what this design is, when it is not what was expected.
	 *
	 * Returns an empty string for an ordinary static design — there is nothing
	 * to explain and a banner over a working import is noise. It speaks up for
	 * the case that used to produce "Nothing on this page could be converted"
	 * for every page in turn: an archive whose HTML is a React or Vue shell,
	 * where the markup only exists after a browser has run the app.
	 *
	 * @param array<string, mixed> $index Result of DesignArchive::index().
	 * @return string
	 */
	private function diagnosis( array $index ): string {
		if ( 'static' === ( $index['kind'] ?? '' ) ) {
			return '';
		}

		$components = (int) ( $index['components'] ?? 0 );

		if ( 'app' === ( $index['kind'] ?? '' ) ) {
			return sprintf(
				/* translators: %d: number of component source files found. */
				_n(
					'This archive is a JavaScript application, not a static design: its HTML holds an empty root element and the pages are assembled in the browser. The markup to convert does not exist on disk — %d component file does, but reading components is not something the structural converter can do. When the application names its pages in a router, they are listed below and Claude reads each one into an ordinary page; failing that, open the site in a browser, save each finished page as HTML, and upload those.',
					'This archive is a JavaScript application, not a static design: its HTML holds an empty root element and the pages are assembled in the browser. The markup to convert does not exist on disk — %d component files do, but reading components is not something the structural converter can do. When the application names its pages in a router, they are listed below and Claude reads each one into an ordinary page; failing that, open the site in a browser, save each finished page as HTML, and upload those.',
					$components,
					'qwerty-soft-signal'
				),
				$components
			);
		}

		return __( 'No HTML page was found in that archive. A design the importer can read is a folder of .html files with their stylesheets and images beside them.', 'qwerty-soft-signal' );
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
			return new WP_Error( 'qwerty_soft_no_file', __( 'No file was received.', 'qwerty-soft-signal' ), array( 'status' => 400 ) );
		}

		$file = $files['archive'];

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error(
				'qwerty_soft_upload',
				sprintf(
					/* translators: %s: the server's upload size limit, e.g. "128 MB". */
					__( 'The upload did not complete. This server accepts files up to %s; a larger archive needs upload_max_filesize and post_max_size raised in php.ini.', 'qwerty-soft-signal' ),
					size_format( wp_max_upload_size() )
				),
				array( 'status' => 400 )
			);
		}

		/*
		 * Reading a thousand-file archive is minutes of work. The default
		 * limit killed it part-way and left a half-written folder behind.
		 */
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Refused in safe mode; the unpack still runs.
		}

		/*
		 * is_uploaded_file() is the check that stops a crafted request from
		 * naming an arbitrary server path as the "upload" and having it
		 * unpacked.
		 */
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'qwerty_soft_upload', __( 'That file did not arrive as an upload.', 'qwerty-soft-signal' ), array( 'status' => 400 ) );
		}

		$type = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], array( 'zip' => 'application/zip' ) );

		if ( 'zip' !== ( $type['ext'] ?? '' ) ) {
			return new WP_Error( 'qwerty_soft_not_zip', __( 'Please upload a .zip archive.', 'qwerty-soft-signal' ), array( 'status' => 400 ) );
		}

		$label  = pathinfo( (string) $file['name'], PATHINFO_FILENAME );
		$result = DesignArchive::unpack( (string) $file['tmp_name'], (string) $label );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$index = DesignArchive::index( $result['path'] );
		$docs  = DesignDocs::digest( $result['path'], 40000 );

		/*
		 * The account starts here, with the upload, and runs to the end of the
		 * build. Everything a person would want to know afterwards about what
		 * the importer did with their archive is written as it happens.
		 */
		ImportLog::clear();
		ImportLog::add(
			'unpack',
			sprintf(
				/* translators: 1: file count, 2: size on disk. */
				__( 'Unpacked %1$d files, %2$s.', 'qwerty-soft-signal' ),
				(int) $result['files'],
				size_format( (int) $result['bytes'] )
			),
			array(
				'files' => (int) $result['files'],
				'bytes' => (int) $result['bytes'],
			)
		);

		if ( ! empty( $result['nested'] ) ) {
			ImportLog::add(
				'unpack',
				sprintf(
					/* translators: %d: number of archives. */
					_n( 'Opened %d archive found inside the design.', 'Opened %d archives found inside the design.', (int) $result['nested'], 'qwerty-soft-signal' ),
					(int) $result['nested']
				)
			);
		}

		/*
		 * Everything the unpacker refused, said out loud.
		 *
		 * These reasons were collected on every upload and thrown away on
		 * every upload, which is how an archive holding an entire website came
		 * to be skipped in silence: the page list looked plausible, the log
		 * looked clean, and the only trace was a .zip left on disk that
		 * nothing would ever open. A line nobody reads is still a line
		 * somebody can be pointed at.
		 */
		foreach ( array_slice( (array) $result['skipped'], 0, 12 ) as $qsoft_reason ) {
			ImportLog::add( 'unpack', (string) $qsoft_reason );
		}

		$qsoft_more = count( (array) $result['skipped'] ) - 12;

		if ( $qsoft_more > 0 ) {
			ImportLog::add(
				'unpack',
				sprintf(
					/* translators: %d: how many more entries were skipped. */
					_n( 'And %d more entry was skipped.', 'And %d more entries were skipped.', $qsoft_more, 'qwerty-soft-signal' ),
					$qsoft_more
				)
			);
		}

		if ( array() !== $docs['files'] ) {
			ImportLog::add(
				'read',
				sprintf(
					/* translators: 1: number of documents, 2: the first one's name. */
					_n( 'Read %1$d document that came with the design, starting with %2$s.', 'Read %1$d documents that came with the design, starting with %2$s.', count( $docs['files'] ), 'qwerty-soft-signal' ),
					count( $docs['files'] ),
					basename( (string) $docs['files'][0] )
				),
				array( 'files' => $docs['files'] )
			);
		}

		ImportLog::add(
			'read',
			sprintf(
				/* translators: 1: pages found, 2: images found. */
				__( 'Indexed the design: %1$d pages, %2$d images.', 'qwerty-soft-signal' ),
				count( $index['pages'] ),
				(int) $index['images']
			)
		);

		return rest_ensure_response(
			array(
				'slug'       => $result['slug'],
				'files'      => $result['files'],
				'bytes'      => $result['bytes'],
				'skipped'    => $result['skipped'],
				'dropped'    => $result['dropped'],
				'pages'      => $index['pages'],
				'languages'  => $index['languages'],
				'images'     => $index['images'],
				'components' => $index['components'],
				'readable'   => $index['readable'],
				'kind'       => $index['kind'],
				'diagnosis'  => $this->diagnosis( $index ),
				'needs'      => \Qwerty\Soft\Support\DesignNeeds::scan( $result['path'] ),
				'palette'    => CssIndex::from_directory( $result['path'] )->palette_proposal(),
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
			return new WP_Error( 'qwerty_soft_unknown_design', __( 'That design is not on this site.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
		}

		$base = DesignArchive::base_dir();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$real_base = realpath( $base );
		$real_dir  = realpath( trailingslashit( $base ) . $slug );

		if ( false === $real_base || false === $real_dir || ! is_dir( $real_dir ) ) {
			return new WP_Error( 'qwerty_soft_unknown_design', __( 'That design is not on this site.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
		}

		if ( ! $this->is_inside( $real_dir, $real_base ) ) {
			return new WP_Error( 'qwerty_soft_unknown_design', __( 'That design is not on this site.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
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
			return new WP_Error( 'qwerty_soft_no_page', __( 'That page is not in this design.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
		}

		$candidate = realpath( trailingslashit( $root ) . ltrim( str_replace( '\\', '/', $file ), '/' ) );

		if ( false === $candidate || ! is_file( $candidate ) ) {
			return new WP_Error( 'qwerty_soft_no_page', __( 'That page is not in this design.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
		}

		if ( ! $this->is_inside( $candidate, $root ) ) {
			return new WP_Error( 'qwerty_soft_no_page', __( 'That page is not in this design.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
		}

		$normalised_file = str_replace( '\\', '/', $candidate );

		if ( ! preg_match( '#\.html?$#i', $normalised_file ) ) {
			return new WP_Error( 'qwerty_soft_no_page', __( 'That is not an HTML page.', 'qwerty-soft-signal' ), array( 'status' => 400 ) );
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
			return new WP_Error( 'qwerty_soft_no_section', __( 'That section is no longer in the page.', 'qwerty-soft-signal' ), array( 'status' => 404 ) );
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
				__( 'This is the structural conversion — no model was asked, because none can be reached from here. %s', 'qwerty-soft-signal' ),
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
					__( 'The model could not be reached, so this is the structural conversion: %s', 'qwerty-soft-signal' ),
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
				__( 'This reply came from %s, which has no known price — its cost is recorded as zero.', 'qwerty-soft-signal' ),
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
				'qwerty_soft_rate_limit',
				sprintf(
					/* translators: %d: number of conversions allowed per hour. */
					__( 'That is %d conversions in an hour, which is the limit. Wait a little before continuing.', 'qwerty-soft-signal' ),
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
		return 'qwerty_soft_convert_' . get_current_user_id();
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
				'qwerty_soft_invalid_markup',
				implode( ' ', $validator->errors() ),
				array( 'status' => 400 )
			);
		}

		$title = '' !== $title ? $title : __( 'Imported section', 'qwerty-soft-signal' );

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
					'page_template' => BlockConverter::faithful() ? 'page-design' : 'page-landing',

					/*
					 * The same ownership mark SiteAssembler puts on the pages
					 * it builds. Undo deletes what carries this mark and
					 * nothing else, so a page saved from a pasted or
					 * CLI-produced reply was unreachable by it forever: the
					 * import could be run again and again, and "Delete what
					 * was built so far" removed nothing while reporting
					 * success. Measured on a real install: nine imported
					 * pages, zero marks.
					 */
					'meta_input'    => array( SiteAssembler::OWNED_META => 'markup:page' ),
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

					// Marked for the same reason the page above is.
					'meta_input'   => array( SiteAssembler::OWNED_META => 'markup:pattern' ),
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
