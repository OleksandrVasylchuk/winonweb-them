<?php
/**
 * First-run setup.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\BrandKit;
use Qwerty\Soft\Support\DesignTokens;
use Qwerty\Soft\Support\StyleVariations;
use WP_Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The three screens between activating the theme and having a site.
 *
 * A block theme activates into an empty page. Everything needed to fix that is
 * already in the box — six palettes, a token system that re-themes the whole
 * site from three colours — but a client has no way of knowing that, and the
 * Site Editor does not tell them. This walks them from activation to a
 * finished-looking site: pick a look, and drop in a logo and brand colours.
 *
 * Deliberately built as plain POST forms. The theme's rule is that everything
 * interactive works without JavaScript, and there is no reason the admin should
 * be the exception — a wizard that fails silently because a plugin threw a
 * console error is worse than no wizard.
 *
 * Every step is optional and every step is reversible: the palette is written
 * to user global styles and can be reset from here or from the Site Editor.
 */
final class Onboarding implements Module {

	/**
	 * Admin page slug.
	 */
	private const PAGE = 'qwerty-soft-signal-setup';

	/**
	 * Option recording whether setup is still outstanding.
	 */
	private const OPTION = 'qwerty_soft_setup';

	/**
	 * Nonce action for every form on the screen.
	 */
	private const NONCE = 'qwerty_soft_setup';

	/**
	 * The steps, in order.
	 *
	 * @var array<int, string>
	 */
	private const STEPS = array( 'welcome', 'style', 'brand', 'done' );

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'after_switch_theme', array( $this, 'flag' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_qwerty_soft_setup', array( $this, 'handle' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
	}

	/**
	 * May the current user run setup?
	 *
	 * The same capability the Site Editor uses — "may change how this site
	 * looks" is exactly what every step here does.
	 *
	 * @return bool
	 */
	public function may_setup(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Mark setup as outstanding when the theme is activated.
	 *
	 * Only on a first activation: a site that has already been through setup,
	 * or has deliberately dismissed it, should not be nagged again because
	 * somebody switched themes and switched back.
	 *
	 * @return void
	 */
	public function flag(): void {
		if ( '' !== (string) get_option( self::OPTION, '' ) ) {
			return;
		}

		update_option( self::OPTION, 'pending', false );
	}

	/**
	 * Add the screen under Appearance.
	 *
	 * @return void
	 */
	public function add_page(): void {
		$hook = add_theme_page(
			__( 'Set up Qwerty Soft — Signal', 'qwerty-soft-signal' ),
			__( 'Theme setup', 'qwerty-soft-signal' ),
			'edit_theme_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'help' ) );
		}
	}

	/**
	 * The one-line prompt on every other admin screen while setup is pending.
	 *
	 * @return void
	 */
	public function notice(): void {
		if ( 'pending' !== (string) get_option( self::OPTION, '' ) || ! $this->may_setup() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen instanceof WP_Screen && 'appearance_page_' . self::PAGE === $screen->id ) {
			return;
		}

		?>
		<div class="notice notice-info qs-setup-notice">
			<p>
				<strong><?php esc_html_e( 'Qwerty Soft — Signal is active.', 'qwerty-soft-signal' ); ?></strong>
				<?php esc_html_e( 'Two steps turn it into a finished site: pick a look, then add your logo and colours.', 'qwerty-soft-signal' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->step_url( 'welcome' ) ); ?>">
					<?php esc_html_e( 'Set up the theme', 'qwerty-soft-signal' ); ?>
				</a>
				<a class="button button-link" href="<?php echo esc_url( $this->action_url( 'dismiss' ) ); ?>">
					<?php esc_html_e( 'No thanks, I will do it myself', 'qwerty-soft-signal' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Load the screen's stylesheet.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		$ours = 'appearance_page_' . self::PAGE === $hook;

		if ( ! $ours && 'index.php' !== $hook && 'pending' !== (string) get_option( self::OPTION, '' ) ) {
			return;
		}

		wp_enqueue_style(
			'qwerty-soft-signal-setup',
			QSOFT_URI . '/assets/css/admin-setup.css',
			array(),
			QSOFT_VERSION
		);
	}

	/**
	 * Contextual help, so the answers are on the screen that raises the question.
	 *
	 * @return void
	 */
	public function help(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'qwerty-soft-signal-setup-help',
				'title'   => __( 'What this changes', 'qwerty-soft-signal' ),
				'content' =>
					'<p>' . esc_html__( 'Nothing here edits the theme files. The look you pick and the brand colours you enter are written to this site\'s global styles — the same place the Site Editor saves to — so a theme update cannot overwrite them, and you can change or undo any of it later under Appearance → Editor → Styles.', 'qwerty-soft-signal' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'qwerty-soft-signal-setup-a11y',
				'title'   => __( 'Colours and contrast', 'qwerty-soft-signal' ),
				'content' =>
					'<p>' . esc_html__( 'Your brand colour is not used exactly as entered. It is walked toward white or black until it is legible on every background the theme renders it on, so the palette clears WCAG 2.2 AA whatever colour you start from. The hue is kept; only the lightness moves, and usually not far.', 'qwerty-soft-signal' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'More help', 'qwerty-soft-signal' ) . '</strong></p>' .
			'<p><a href="https://qwerty-soft.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation and support', 'qwerty-soft-signal' ) . '</a></p>'
		);
	}

	/**
	 * A short panel on the dashboard, so support is never more than one click away.
	 *
	 * @return void
	 */
	public function dashboard_widget(): void {
		if ( ! $this->may_setup() ) {
			return;
		}

		wp_add_dashboard_widget(
			'qwerty_soft_help',
			__( 'Qwerty Soft — Signal', 'qwerty-soft-signal' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Contents of the dashboard panel.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$pending = 'pending' === (string) get_option( self::OPTION, '' );
		?>
		<div class="qs-setup-widget">
			<?php if ( $pending ) : ?>
				<p>
					<?php esc_html_e( 'The theme has not been set up yet. Two steps and this site stops looking empty.', 'qwerty-soft-signal' ); ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $this->step_url( 'welcome' ) ); ?>">
						<?php esc_html_e( 'Set up the theme', 'qwerty-soft-signal' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<ul class="qs-setup-widget__links">
				<li>
					<a href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>">
						<?php esc_html_e( 'Edit the site design', 'qwerty-soft-signal' ); ?>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'themes.php?page=qwerty-soft-signal-import' ) ); ?>">
						<?php esc_html_e( 'Import a design', 'qwerty-soft-signal' ); ?>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( $this->step_url( 'style' ) ); ?>">
						<?php esc_html_e( 'Change the palette', 'qwerty-soft-signal' ); ?>
					</a>
				</li>
				<li>
					<a href="https://qwerty-soft.com/" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Documentation and support', 'qwerty-soft-signal' ); ?>
					</a>
				</li>
			</ul>

			<p class="qs-setup-widget__version">
				<?php
				printf(
					/* translators: %s: theme version number. */
					esc_html__( 'Version %s', 'qwerty-soft-signal' ),
					esc_html( QSOFT_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}

	// ------------------------------------------------------------ the screen

	/**
	 * Render whichever step the query string asks for.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! $this->may_setup() ) {
			wp_die( esc_html__( 'You do not have permission to set up this theme.', 'qwerty-soft-signal' ) );
		}

		$step = $this->current_step();
		?>
		<div class="wrap qs-setup">
			<?php $this->masthead( $step ); ?>
			<?php $this->flash(); ?>

			<div class="qs-setup__panel">
				<?php
				switch ( $step ) {
					case 'style':
						$this->step_style();
						break;
					case 'brand':
						$this->step_brand();
						break;
					case 'done':
						$this->step_done();
						break;
					default:
						$this->step_welcome();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Heading and progress rail.
	 *
	 * @param string $step Current step.
	 * @return void
	 */
	private function masthead( string $step ): void {
		$labels = array(
			'welcome' => __( 'Start', 'qwerty-soft-signal' ),
			'style'   => __( 'Look', 'qwerty-soft-signal' ),
			'brand'   => __( 'Brand', 'qwerty-soft-signal' ),
			'done'    => __( 'Finish', 'qwerty-soft-signal' ),
		);

		$position = (int) array_search( $step, self::STEPS, true );
		?>
		<div class="qs-setup__masthead">
			<p class="qs-setup__brand">
				<span class="qs-setup__mark" aria-hidden="true">Q</span>
				<?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>
			</p>

			<h1><?php esc_html_e( 'Set up the theme', 'qwerty-soft-signal' ); ?></h1>

			<p class="qs-setup__lede">
				<?php esc_html_e( 'Three short steps. Every one of them is optional, and every one of them can be undone afterwards.', 'qwerty-soft-signal' ); ?>
			</p>
		</div>

		<ol class="qs-setup__rail">
			<?php foreach ( self::STEPS as $index => $slug ) : ?>
				<li class="qs-setup__rail-step<?php echo $index < $position ? ' is-done' : ''; ?><?php echo $index === $position ? ' is-current' : ''; ?>">
					<?php if ( $index === $position ) : ?>
						<span aria-current="step"><?php echo esc_html( $labels[ $slug ] ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $this->step_url( $slug ) ); ?>"><?php echo esc_html( $labels[ $slug ] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Report what the last submission did.
	 *
	 * @return void
	 */
	private function flash(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect result; nothing is changed here.
		$notice = isset( $_GET['qs-notice'] ) ? sanitize_key( wp_unslash( $_GET['qs-notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'style'        => array( 'success', __( 'The palette is applied. Every colour on the site follows it.', 'qwerty-soft-signal' ) ),
			'style-reset'  => array( 'success', __( 'Back to the theme\'s own palette.', 'qwerty-soft-signal' ) ),
			'style-failed' => array( 'error', __( 'That look could not be applied. Pick one of the styles listed, or skip this step.', 'qwerty-soft-signal' ) ),
			'brand'        => array( 'success', __( 'Your brand colours are in. They were adjusted where they had to be, so every pair still clears WCAG 2.2 AA.', 'qwerty-soft-signal' ) ),
			'brand-failed' => array( 'error', __( 'That did not look like a colour. Enter it as a hex value, for example #1f6feb.', 'qwerty-soft-signal' ) ),
			'logo'         => array( 'success', __( 'Logo uploaded and set.', 'qwerty-soft-signal' ) ),
			'logo-failed'  => array( 'error', __( 'The logo could not be uploaded. Check the file is an image and within the size this server allows.', 'qwerty-soft-signal' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Step one — what is about to happen.
	 *
	 * @return void
	 */
	private function step_welcome(): void {
		?>
		<h2><?php esc_html_e( 'What this does', 'qwerty-soft-signal' ); ?></h2>

		<ul class="qs-setup__list">
			<li><?php esc_html_e( 'Pick one of six palettes, or keep the theme\'s own.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Upload a logo and give the theme your brand colours. The rest of the palette is worked out from them and checked for contrast.', 'qwerty-soft-signal' ); ?></li>
		</ul>

		<p class="qs-setup__note">
			<?php esc_html_e( 'Nothing is written to the theme files, so an update cannot overwrite any of it. Everything can be changed later in the Site Editor.', 'qwerty-soft-signal' ); ?>
		</p>

		<p class="qs-setup__actions">
			<a class="button button-primary button-hero" href="<?php echo esc_url( $this->step_url( 'style' ) ); ?>">
				<?php esc_html_e( 'Start', 'qwerty-soft-signal' ); ?>
			</a>
			<a class="button button-link" href="<?php echo esc_url( $this->action_url( 'dismiss' ) ); ?>">
				<?php esc_html_e( 'Skip setup', 'qwerty-soft-signal' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Step two — choose a palette.
	 *
	 * @return void
	 */
	private function step_style(): void {
		$variations = StyleVariations::all();
		?>
		<h2><?php esc_html_e( 'Pick a look', 'qwerty-soft-signal' ); ?></h2>

		<p class="qs-setup__note">
			<?php esc_html_e( 'Each of these is a full palette, not a filter. All of them clear WCAG 2.2 AA on every text pair — that is checked by a script before the theme ships, not by eye.', 'qwerty-soft-signal' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="qwerty_soft_setup">
			<input type="hidden" name="qsoft_step" value="style">

			<fieldset class="qs-setup__choices">
				<legend class="screen-reader-text"><?php esc_html_e( 'Style variation', 'qwerty-soft-signal' ); ?></legend>

				<?php foreach ( $variations as $slug => $variation ) : ?>
					<label class="qs-setup__choice">
						<input type="radio" name="qsoft_variation" value="<?php echo esc_attr( $slug ); ?>" <?php checked( '' === $slug ); ?>>

						<span class="qs-setup__swatch" aria-hidden="true">
							<?php foreach ( $variation['swatch'] as $colour ) : ?>
								<span style="background:<?php echo esc_attr( $colour ); ?>"></span>
							<?php endforeach; ?>
						</span>

						<span class="qs-setup__choice-text">
							<strong><?php echo esc_html( $variation['title'] ); ?></strong>
							<span><?php echo esc_html( $variation['description'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="qs-setup__actions">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Use this look', 'qwerty-soft-signal' ); ?>
				</button>
				<a class="button button-link" href="<?php echo esc_url( $this->step_url( 'brand' ) ); ?>">
					<?php esc_html_e( 'Skip this step', 'qwerty-soft-signal' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * Step three — logo and brand colours.
	 *
	 * @return void
	 */
	private function step_brand(): void {
		$logo = (int) get_theme_mod( 'custom_logo', 0 );
		?>
		<h2><?php esc_html_e( 'Your brand', 'qwerty-soft-signal' ); ?></h2>

		<p class="qs-setup__note">
			<?php esc_html_e( 'Give it a logo and one colour and it will work out the other twelve. Enter two more if your brand has them.', 'qwerty-soft-signal' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="qwerty_soft_setup">
			<input type="hidden" name="qsoft_step" value="brand">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="qs-logo"><?php esc_html_e( 'Logo', 'qwerty-soft-signal' ); ?></label>
					</th>
					<td>
						<?php if ( 0 !== $logo ) : ?>
							<p class="qs-setup__logo">
								<?php echo wp_get_attachment_image( $logo, 'medium', false, array( 'alt' => '' ) ); ?>
							</p>
						<?php endif; ?>

						<input type="file" id="qs-logo" name="qsoft_logo" accept="image/png,image/jpeg,image/webp">
						<p class="description">
							<?php esc_html_e( 'Optional. A transparent PNG about 400 pixels wide works best; JPG and WebP also work. This sets the site logo, which the header already displays.', 'qwerty-soft-signal' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Light or dark', 'qwerty-soft-signal' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Light or dark', 'qwerty-soft-signal' ); ?></legend>
							<label><input type="radio" name="qsoft_mode" value="dark" checked> <?php esc_html_e( 'Dark — light text on a near-black page', 'qwerty-soft-signal' ); ?></label><br>
							<label><input type="radio" name="qsoft_mode" value="light"> <?php esc_html_e( 'Light — dark text on a white page', 'qwerty-soft-signal' ); ?></label>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qs-accent"><?php esc_html_e( 'Brand colour', 'qwerty-soft-signal' ); ?></label>
					</th>
					<td>
						<input type="color" id="qs-accent" name="qsoft_accent" value="#22d3ee">
						<p class="description">
							<?php esc_html_e( 'Buttons, links and highlights. If this exact colour would be hard to read, the theme keeps the hue and moves the lightness until it is.', 'qwerty-soft-signal' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Secondary colours', 'qwerty-soft-signal' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Secondary colours', 'qwerty-soft-signal' ); ?></legend>

							<p>
								<label>
									<input type="checkbox" name="qsoft_derive" value="1" checked>
									<?php esc_html_e( 'Work them out from my brand colour', 'qwerty-soft-signal' ); ?>
								</label>
							</p>

							<p>
								<label for="qs-accent-2" class="screen-reader-text"><?php esc_html_e( 'Second brand colour', 'qwerty-soft-signal' ); ?></label>
								<input type="color" id="qs-accent-2" name="qsoft_accent_2" value="#a78bfa">

								<label for="qs-accent-3" class="screen-reader-text"><?php esc_html_e( 'Third brand colour', 'qwerty-soft-signal' ); ?></label>
								<input type="color" id="qs-accent-3" name="qsoft_accent_3" value="#e879f9">
							</p>

							<p class="description">
								<?php esc_html_e( 'Leave the box ticked and the theme rotates two companions off your brand colour, which is what a one-colour brand wants. Untick it to use the two colours above instead.', 'qwerty-soft-signal' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qs-heading"><?php esc_html_e( 'Headings', 'qwerty-soft-signal' ); ?></label>
					</th>
					<td>
						<select id="qs-heading" name="qsoft_heading">
							<option value=""><?php esc_html_e( 'Keep the theme\'s heading font', 'qwerty-soft-signal' ); ?></option>
							<option value="sans"><?php esc_html_e( 'Manrope — the theme sans', 'qwerty-soft-signal' ); ?></option>
							<option value="mono"><?php esc_html_e( 'Monospace — editorial, technical', 'qwerty-soft-signal' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Only the two families the theme already carries. Adding a third would mean a font file the theme does not ship and a request it does not make.', 'qwerty-soft-signal' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="qs-setup__actions">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Apply my brand', 'qwerty-soft-signal' ); ?>
				</button>
				<a class="button button-link" href="<?php echo esc_url( $this->step_url( 'done' ) ); ?>">
					<?php esc_html_e( 'Skip this step', 'qwerty-soft-signal' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * Step four — where to go next.
	 *
	 * @return void
	 */
	private function step_done(): void {
		?>
		<h2><?php esc_html_e( 'That is the setup done', 'qwerty-soft-signal' ); ?></h2>

		<p class="qs-setup__note">
			<?php esc_html_e( 'Everything from here is ordinary WordPress. The pages are pages, the palette is in the Site Editor under Styles, and the patterns are in the block inserter under Qwerty Soft.', 'qwerty-soft-signal' ); ?>
		</p>

		<ul class="qs-setup__list">
			<li>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Look at the site', 'qwerty-soft-signal' ); ?></a>
			</li>
			<li>
				<a href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>"><?php esc_html_e( 'Edit the design in the Site Editor', 'qwerty-soft-signal' ); ?></a>
			</li>
			<li>
				<a href="<?php echo esc_url( admin_url( 'themes.php?page=qwerty-soft-signal-import' ) ); ?>"><?php esc_html_e( 'Import a design from an HTML archive', 'qwerty-soft-signal' ); ?></a>
			</li>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="qwerty_soft_setup">
			<input type="hidden" name="qsoft_step" value="finish">

			<p class="qs-setup__actions">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Finish', 'qwerty-soft-signal' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	// ----------------------------------------------------------- form handling

	/**
	 * Process a submitted step and send the browser to the next one.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! $this->may_setup() ) {
			wp_die( esc_html__( 'You do not have permission to set up this theme.', 'qwerty-soft-signal' ) );
		}

		check_admin_referer( self::NONCE );

		/*
		 * $_REQUEST, not $_POST: the wizard's own steps arrive as form posts,
		 * but "skip setup" is a nonced link, and admin-post.php dispatches a
		 * GET to the same action.
		 */
		$step = isset( $_REQUEST['qsoft_step'] ) ? sanitize_key( wp_unslash( $_REQUEST['qsoft_step'] ) ) : '';

		switch ( $step ) {
			case 'style':
				$this->save_style();
				break;
			case 'brand':
				$this->save_brand();
				break;
			case 'finish':
				update_option( self::OPTION, 'done', false );
				wp_safe_redirect( admin_url( 'site-editor.php' ) );
				exit;
			case 'dismiss':
				update_option( self::OPTION, 'dismissed', false );
				wp_safe_redirect( admin_url() );
				exit;
		}

		wp_safe_redirect( admin_url( 'themes.php?page=' . self::PAGE ) );
		exit;
	}

	/*
	 * Every method below is reached only from handle(), which calls
	 * check_admin_referer() before it dispatches and dies if the nonce does not
	 * verify. PHPCS cannot see across the call, so it reports each read of
	 * $_POST here as unverified; re-checking the same nonce three more times
	 * would silence the sniff without adding a single guarantee.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle() before dispatch.

	/**
	 * Apply the chosen style variation and move on.
	 *
	 * @return void
	 */
	private function save_style(): void {
		$slug = isset( $_POST['qsoft_variation'] ) ? sanitize_key( wp_unslash( $_POST['qsoft_variation'] ) ) : '';

		if ( ! StyleVariations::apply( $slug ) ) {
			$this->go( 'style', 'style-failed' );
		}

		$this->go( 'brand', '' === $slug ? 'style-reset' : 'style' );
	}

	/**
	 * Derive and apply a palette from the brand colours, and set the logo.
	 *
	 * @return void
	 */
	private function save_brand(): void {
		$notice = '';

		// Only the presence of a filename is read here; the file itself is
		// handled by media_handle_upload(), which does its own validation.
		$filename = isset( $_FILES['qsoft_logo']['name'] )
			? sanitize_file_name( (string) wp_unslash( $_FILES['qsoft_logo']['name'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on this line.
			: '';

		if ( '' !== $filename ) {
			$notice = $this->save_logo();
		}

		$accent = isset( $_POST['qsoft_accent'] ) ? BrandKit::hex( sanitize_text_field( wp_unslash( $_POST['qsoft_accent'] ) ) ) : null;

		if ( null === $accent ) {
			$this->go( 'brand', '' !== $notice ? $notice : 'brand-failed' );
		}

		$dark = ! isset( $_POST['qsoft_mode'] ) || 'light' !== sanitize_key( wp_unslash( $_POST['qsoft_mode'] ) );

		/*
		 * A colour input cannot be left empty, so "no secondary colour" has to
		 * be said explicitly. With the box ticked the two companions are left
		 * out of the input entirely and BrandKit rotates them off the primary —
		 * otherwise a one-colour brand silently inherits the theme's violet and
		 * fuchsia and comes out looking like two brands at once.
		 */
		$derive = isset( $_POST['qsoft_derive'] );

		$brand = array(
			'mode'     => $dark ? 'dark' : 'light',
			'accent'   => (string) $accent,
			'accent-2' => $derive || ! isset( $_POST['qsoft_accent_2'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['qsoft_accent_2'] ) ),
			'accent-3' => $derive || ! isset( $_POST['qsoft_accent_3'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['qsoft_accent_3'] ) ),
			'heading'  => isset( $_POST['qsoft_heading'] ) ? sanitize_key( wp_unslash( $_POST['qsoft_heading'] ) ) : '',
		);

		$tokens = BrandKit::derive( $brand );

		DesignTokens::apply( $tokens );

		// Gradients and shadows have to follow the palette, or a variation
		// chosen on the previous step leaves its own behind on top of this one.
		StyleVariations::set_presets(
			BrandKit::gradients( $tokens['colors'], $dark ),
			BrandKit::shadows( $tokens['colors'], $dark )
		);

		$this->go( 'done', '' !== $notice ? $notice : 'brand' );
	}

	/**
	 * Store an uploaded logo and set it as the site logo.
	 *
	 * @return string Notice key, empty when the upload succeeded.
	 */
	private function save_logo(): string {
		if ( ! current_user_can( 'upload_files' ) ) {
			return 'logo-failed';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_upload( 'qsoft_logo', 0 );

		if ( is_wp_error( $id ) ) {
			return 'logo-failed';
		}

		set_theme_mod( 'custom_logo', (int) $id );

		return '';
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// ------------------------------------------------------------------ paths

	/**
	 * Redirect to a step with a notice.
	 *
	 * @param string $step   Step slug.
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function go( string $step, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'qs-notice', $notice, $this->step_url( $step ) ) );
		exit;
	}

	/**
	 * The step currently being asked for.
	 *
	 * @return string
	 */
	private function current_step(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which read-only screen to draw.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome';

		return in_array( $step, self::STEPS, true ) ? $step : 'welcome';
	}

	/**
	 * URL of one step of the wizard.
	 *
	 * @param string $step Step slug.
	 * @return string
	 */
	private function step_url( string $step ): string {
		return admin_url( 'themes.php?page=' . self::PAGE . '&step=' . rawurlencode( $step ) );
	}

	/**
	 * URL of a nonced one-press action.
	 *
	 * @param string $step Step slug handled by handle().
	 * @return string
	 */
	private function action_url( string $step ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=qwerty_soft_setup&qsoft_step=' . rawurlencode( $step ) ),
			self::NONCE
		);
	}
}
