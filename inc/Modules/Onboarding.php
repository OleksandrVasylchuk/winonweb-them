<?php
/**
 * First-run setup.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;
use Wow\Signal\Support\BrandKit;
use Wow\Signal\Support\DemoContent;
use Wow\Signal\Support\DesignTokens;
use Wow\Signal\Support\StyleVariations;
use WP_Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The four screens between activating the theme and having a site.
 *
 * A block theme activates into an empty page. Everything needed to fix that is
 * already in the box — the page patterns, six palettes, a token system that
 * re-themes the whole site from three colours — but a client has no way of
 * knowing that, and the Site Editor does not tell them. This walks them from
 * activation to a finished-looking site: pick a look, drop in a logo and brand
 * colours, install the starter pages.
 *
 * Deliberately built as plain POST forms. The theme's rule is that everything
 * interactive works without JavaScript, and there is no reason the admin should
 * be the exception — a wizard that fails silently because a plugin threw a
 * console error is worse than no wizard.
 *
 * Every step is optional and every step is reversible: the palette is written
 * to user global styles and can be reset from here or from the Site Editor, and
 * the starter pages carry the import module's ownership meta, so the existing
 * "remove everything this theme added" button takes them out too.
 */
final class Onboarding implements Module {

	/**
	 * Admin page slug.
	 */
	private const PAGE = 'wow-signal-setup';

	/**
	 * Option recording whether setup is still outstanding.
	 */
	private const OPTION = 'wow_signal_setup';

	/**
	 * Nonce action for every form on the screen.
	 */
	private const NONCE = 'wow_signal_setup';

	/**
	 * The steps, in order.
	 *
	 * @var array<int, string>
	 */
	private const STEPS = array( 'welcome', 'style', 'brand', 'content', 'done' );

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
		add_action( 'admin_post_wow_signal_setup', array( $this, 'handle' ) );
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
			__( 'Set up WOW — Signal', 'wow-signal' ),
			__( 'Theme setup', 'wow-signal' ),
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
		<div class="notice notice-info wow-setup-notice">
			<p>
				<strong><?php esc_html_e( 'WOW — Signal is active.', 'wow-signal' ); ?></strong>
				<?php esc_html_e( 'Three steps turn it into a finished site: pick a look, add your logo and colours, install the starter pages.', 'wow-signal' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->step_url( 'welcome' ) ); ?>">
					<?php esc_html_e( 'Set up the theme', 'wow-signal' ); ?>
				</a>
				<a class="button button-link" href="<?php echo esc_url( $this->action_url( 'dismiss' ) ); ?>">
					<?php esc_html_e( 'No thanks, I will do it myself', 'wow-signal' ); ?>
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
			'wow-signal-setup',
			WOW_SIGNAL_URI . '/assets/css/admin-setup.css',
			array(),
			WOW_SIGNAL_VERSION
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
				'id'      => 'wow-signal-setup-help',
				'title'   => __( 'What this changes', 'wow-signal' ),
				'content' =>
					'<p>' . esc_html__( 'Nothing here edits the theme files. The look you pick and the brand colours you enter are written to this site\'s global styles — the same place the Site Editor saves to — so a theme update cannot overwrite them, and you can change or undo any of it later under Appearance → Editor → Styles.', 'wow-signal' ) . '</p>' .
					'<p>' . esc_html__( 'The starter pages are ordinary pages made from the theme\'s own patterns. Edit them, delete them, or remove the whole set again from Appearance → Design import.', 'wow-signal' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wow-signal-setup-a11y',
				'title'   => __( 'Colours and contrast', 'wow-signal' ),
				'content' =>
					'<p>' . esc_html__( 'Your brand colour is not used exactly as entered. It is walked toward white or black until it is legible on every background the theme renders it on, so the palette clears WCAG 2.2 AA whatever colour you start from. The hue is kept; only the lightness moves, and usually not far.', 'wow-signal' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'More help', 'wow-signal' ) . '</strong></p>' .
			'<p><a href="https://www.winonweb.dev/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation and support', 'wow-signal' ) . '</a></p>'
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
			'wow_signal_help',
			__( 'WOW — Signal', 'wow-signal' ),
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
		<div class="wow-setup-widget">
			<?php if ( $pending ) : ?>
				<p>
					<?php esc_html_e( 'The theme has not been set up yet. Three steps and this site stops looking empty.', 'wow-signal' ); ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $this->step_url( 'welcome' ) ); ?>">
						<?php esc_html_e( 'Set up the theme', 'wow-signal' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<ul class="wow-setup-widget__links">
				<li>
					<a href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>">
						<?php esc_html_e( 'Edit the site design', 'wow-signal' ); ?>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'themes.php?page=wow-signal-import' ) ); ?>">
						<?php esc_html_e( 'Import a design', 'wow-signal' ); ?>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( $this->step_url( 'style' ) ); ?>">
						<?php esc_html_e( 'Change the palette', 'wow-signal' ); ?>
					</a>
				</li>
				<li>
					<a href="https://www.winonweb.dev/" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Documentation and support', 'wow-signal' ); ?>
					</a>
				</li>
			</ul>

			<p class="wow-setup-widget__version">
				<?php
				printf(
					/* translators: %s: theme version number. */
					esc_html__( 'Version %s', 'wow-signal' ),
					esc_html( WOW_SIGNAL_VERSION )
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
			wp_die( esc_html__( 'You do not have permission to set up this theme.', 'wow-signal' ) );
		}

		$step = $this->current_step();
		?>
		<div class="wrap wow-setup">
			<?php $this->masthead( $step ); ?>
			<?php $this->flash(); ?>

			<div class="wow-setup__panel">
				<?php
				switch ( $step ) {
					case 'style':
						$this->step_style();
						break;
					case 'brand':
						$this->step_brand();
						break;
					case 'content':
						$this->step_content();
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
			'welcome' => __( 'Start', 'wow-signal' ),
			'style'   => __( 'Look', 'wow-signal' ),
			'brand'   => __( 'Brand', 'wow-signal' ),
			'content' => __( 'Content', 'wow-signal' ),
			'done'    => __( 'Finish', 'wow-signal' ),
		);

		$position = (int) array_search( $step, self::STEPS, true );
		?>
		<div class="wow-setup__masthead">
			<p class="wow-setup__brand">
				<span class="wow-setup__mark" aria-hidden="true">W</span>
				<?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>
			</p>

			<h1><?php esc_html_e( 'Set up the theme', 'wow-signal' ); ?></h1>

			<p class="wow-setup__lede">
				<?php esc_html_e( 'Four short steps. Every one of them is optional, and every one of them can be undone afterwards.', 'wow-signal' ); ?>
			</p>
		</div>

		<ol class="wow-setup__rail">
			<?php foreach ( self::STEPS as $index => $slug ) : ?>
				<li class="wow-setup__rail-step<?php echo $index < $position ? ' is-done' : ''; ?><?php echo $index === $position ? ' is-current' : ''; ?>">
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
		$notice = isset( $_GET['wow-notice'] ) ? sanitize_key( wp_unslash( $_GET['wow-notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'style'        => array( 'success', __( 'The palette is applied. Every colour on the site follows it.', 'wow-signal' ) ),
			'style-reset'  => array( 'success', __( 'Back to the theme\'s own palette.', 'wow-signal' ) ),
			'style-failed' => array( 'error', __( 'That look could not be applied. Pick one of the styles listed, or skip this step.', 'wow-signal' ) ),
			'brand'        => array( 'success', __( 'Your brand colours are in. They were adjusted where they had to be, so every pair still clears WCAG 2.2 AA.', 'wow-signal' ) ),
			'brand-failed' => array( 'error', __( 'That did not look like a colour. Enter it as a hex value, for example #1f6feb.', 'wow-signal' ) ),
			'logo'         => array( 'success', __( 'Logo uploaded and set.', 'wow-signal' ) ),
			'logo-failed'  => array( 'error', __( 'The logo could not be uploaded. Check the file is an image and within the size this server allows.', 'wow-signal' ) ),
			'content'      => array( 'success', __( 'The starter pages are in and the front page is set.', 'wow-signal' ) ),
			'content-none' => array( 'warning', __( 'Nothing was added — those pages already exist on this site.', 'wow-signal' ) ),
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
		<h2><?php esc_html_e( 'What this does', 'wow-signal' ); ?></h2>

		<ul class="wow-setup__list">
			<li><?php esc_html_e( 'Pick one of six palettes, or keep the theme\'s own.', 'wow-signal' ); ?></li>
			<li><?php esc_html_e( 'Upload a logo and give the theme your brand colours. The rest of the palette is worked out from them and checked for contrast.', 'wow-signal' ); ?></li>
			<li><?php esc_html_e( 'Install a home, services and contact page built from the theme\'s patterns, with a menu and a front page.', 'wow-signal' ); ?></li>
		</ul>

		<p class="wow-setup__note">
			<?php esc_html_e( 'Nothing is written to the theme files, so an update cannot overwrite any of it. Everything can be changed later in the Site Editor, and the starter pages can be removed again in one press.', 'wow-signal' ); ?>
		</p>

		<p class="wow-setup__actions">
			<a class="button button-primary button-hero" href="<?php echo esc_url( $this->step_url( 'style' ) ); ?>">
				<?php esc_html_e( 'Start', 'wow-signal' ); ?>
			</a>
			<a class="button button-link" href="<?php echo esc_url( $this->action_url( 'dismiss' ) ); ?>">
				<?php esc_html_e( 'Skip setup', 'wow-signal' ); ?>
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
		<h2><?php esc_html_e( 'Pick a look', 'wow-signal' ); ?></h2>

		<p class="wow-setup__note">
			<?php esc_html_e( 'Each of these is a full palette, not a filter. All of them clear WCAG 2.2 AA on every text pair — that is checked by a script before the theme ships, not by eye.', 'wow-signal' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wow_signal_setup">
			<input type="hidden" name="wow_step" value="style">

			<fieldset class="wow-setup__choices">
				<legend class="screen-reader-text"><?php esc_html_e( 'Style variation', 'wow-signal' ); ?></legend>

				<?php foreach ( $variations as $slug => $variation ) : ?>
					<label class="wow-setup__choice">
						<input type="radio" name="wow_variation" value="<?php echo esc_attr( $slug ); ?>" <?php checked( '' === $slug ); ?>>

						<span class="wow-setup__swatch" aria-hidden="true">
							<?php foreach ( $variation['swatch'] as $colour ) : ?>
								<span style="background:<?php echo esc_attr( $colour ); ?>"></span>
							<?php endforeach; ?>
						</span>

						<span class="wow-setup__choice-text">
							<strong><?php echo esc_html( $variation['title'] ); ?></strong>
							<span><?php echo esc_html( $variation['description'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="wow-setup__actions">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Use this look', 'wow-signal' ); ?>
				</button>
				<a class="button button-link" href="<?php echo esc_url( $this->step_url( 'brand' ) ); ?>">
					<?php esc_html_e( 'Skip this step', 'wow-signal' ); ?>
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
		<h2><?php esc_html_e( 'Your brand', 'wow-signal' ); ?></h2>

		<p class="wow-setup__note">
			<?php esc_html_e( 'Give it a logo and one colour and it will work out the other twelve. Enter two more if your brand has them.', 'wow-signal' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wow_signal_setup">
			<input type="hidden" name="wow_step" value="brand">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wow-logo"><?php esc_html_e( 'Logo', 'wow-signal' ); ?></label>
					</th>
					<td>
						<?php if ( 0 !== $logo ) : ?>
							<p class="wow-setup__logo">
								<?php echo wp_get_attachment_image( $logo, 'medium', false, array( 'alt' => '' ) ); ?>
							</p>
						<?php endif; ?>

						<input type="file" id="wow-logo" name="wow_logo" accept="image/png,image/jpeg,image/webp">
						<p class="description">
							<?php esc_html_e( 'Optional. A transparent PNG about 400 pixels wide works best; JPG and WebP also work. This sets the site logo, which the header already displays.', 'wow-signal' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Light or dark', 'wow-signal' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Light or dark', 'wow-signal' ); ?></legend>
							<label><input type="radio" name="wow_mode" value="dark" checked> <?php esc_html_e( 'Dark — light text on a near-black page', 'wow-signal' ); ?></label><br>
							<label><input type="radio" name="wow_mode" value="light"> <?php esc_html_e( 'Light — dark text on a white page', 'wow-signal' ); ?></label>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wow-accent"><?php esc_html_e( 'Brand colour', 'wow-signal' ); ?></label>
					</th>
					<td>
						<input type="color" id="wow-accent" name="wow_accent" value="#22d3ee">
						<p class="description">
							<?php esc_html_e( 'Buttons, links and highlights. If this exact colour would be hard to read, the theme keeps the hue and moves the lightness until it is.', 'wow-signal' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Secondary colours', 'wow-signal' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Secondary colours', 'wow-signal' ); ?></legend>

							<p>
								<label>
									<input type="checkbox" name="wow_derive" value="1" checked>
									<?php esc_html_e( 'Work them out from my brand colour', 'wow-signal' ); ?>
								</label>
							</p>

							<p>
								<label for="wow-accent-2" class="screen-reader-text"><?php esc_html_e( 'Second brand colour', 'wow-signal' ); ?></label>
								<input type="color" id="wow-accent-2" name="wow_accent_2" value="#a78bfa">

								<label for="wow-accent-3" class="screen-reader-text"><?php esc_html_e( 'Third brand colour', 'wow-signal' ); ?></label>
								<input type="color" id="wow-accent-3" name="wow_accent_3" value="#e879f9">
							</p>

							<p class="description">
								<?php esc_html_e( 'Leave the box ticked and the theme rotates two companions off your brand colour, which is what a one-colour brand wants. Untick it to use the two colours above instead.', 'wow-signal' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wow-heading"><?php esc_html_e( 'Headings', 'wow-signal' ); ?></label>
					</th>
					<td>
						<select id="wow-heading" name="wow_heading">
							<option value=""><?php esc_html_e( 'Keep the theme\'s heading font', 'wow-signal' ); ?></option>
							<option value="sans"><?php esc_html_e( 'Manrope — the theme sans', 'wow-signal' ); ?></option>
							<option value="mono"><?php esc_html_e( 'Monospace — editorial, technical', 'wow-signal' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Only the two families the theme already carries. Adding a third would mean a font file the theme does not ship and a request it does not make.', 'wow-signal' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="wow-setup__actions">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Apply my brand', 'wow-signal' ); ?>
				</button>
				<a class="button button-link" href="<?php echo esc_url( $this->step_url( 'content' ) ); ?>">
					<?php esc_html_e( 'Skip this step', 'wow-signal' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * Step four — starter pages.
	 *
	 * @return void
	 */
	private function step_content(): void {
		$installed = DemoContent::installed();
		?>
		<h2><?php esc_html_e( 'Starter pages', 'wow-signal' ); ?></h2>

		<p class="wow-setup__note">
			<?php esc_html_e( 'A home page, a services page and a contact page, built from the theme\'s own patterns and filled with real copy rather than placeholder text. A menu is created and the home page is set as the front page.', 'wow-signal' ); ?>
		</p>

		<?php if ( $installed ) : ?>
			<p class="wow-setup__note">
				<strong><?php esc_html_e( 'This site already has starter content.', 'wow-signal' ); ?></strong>
				<?php esc_html_e( 'Running it again will not duplicate or overwrite anything.', 'wow-signal' ); ?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wow_signal_setup">
			<input type="hidden" name="wow_step" value="content">

			<p class="wow-setup__actions">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Install the starter pages', 'wow-signal' ); ?>
				</button>
				<a class="button button-link" href="<?php echo esc_url( $this->step_url( 'done' ) ); ?>">
					<?php esc_html_e( 'Skip this step', 'wow-signal' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * Step five — where to go next.
	 *
	 * @return void
	 */
	private function step_done(): void {
		?>
		<h2><?php esc_html_e( 'That is the setup done', 'wow-signal' ); ?></h2>

		<p class="wow-setup__note">
			<?php esc_html_e( 'Everything from here is ordinary WordPress. The pages are pages, the palette is in the Site Editor under Styles, and the patterns are in the block inserter under WOW.', 'wow-signal' ); ?>
		</p>

		<ul class="wow-setup__list">
			<li>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Look at the site', 'wow-signal' ); ?></a>
			</li>
			<li>
				<a href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>"><?php esc_html_e( 'Edit the design in the Site Editor', 'wow-signal' ); ?></a>
			</li>
			<li>
				<a href="<?php echo esc_url( admin_url( 'themes.php?page=wow-signal-import' ) ); ?>"><?php esc_html_e( 'Import a design from an HTML archive', 'wow-signal' ); ?></a>
			</li>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wow_signal_setup">
			<input type="hidden" name="wow_step" value="finish">

			<p class="wow-setup__actions">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Finish', 'wow-signal' ); ?>
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
			wp_die( esc_html__( 'You do not have permission to set up this theme.', 'wow-signal' ) );
		}

		check_admin_referer( self::NONCE );

		/*
		 * $_REQUEST, not $_POST: the wizard's own steps arrive as form posts,
		 * but "skip setup" is a nonced link, and admin-post.php dispatches a
		 * GET to the same action.
		 */
		$step = isset( $_REQUEST['wow_step'] ) ? sanitize_key( wp_unslash( $_REQUEST['wow_step'] ) ) : '';

		switch ( $step ) {
			case 'style':
				$this->save_style();
				break;
			case 'brand':
				$this->save_brand();
				break;
			case 'content':
				$this->save_content();
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
		$slug = isset( $_POST['wow_variation'] ) ? sanitize_key( wp_unslash( $_POST['wow_variation'] ) ) : '';

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
		$filename = isset( $_FILES['wow_logo']['name'] )
			? sanitize_file_name( (string) wp_unslash( $_FILES['wow_logo']['name'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on this line.
			: '';

		if ( '' !== $filename ) {
			$notice = $this->save_logo();
		}

		$accent = isset( $_POST['wow_accent'] ) ? BrandKit::hex( sanitize_text_field( wp_unslash( $_POST['wow_accent'] ) ) ) : null;

		if ( null === $accent ) {
			$this->go( 'brand', '' !== $notice ? $notice : 'brand-failed' );
		}

		$dark = ! isset( $_POST['wow_mode'] ) || 'light' !== sanitize_key( wp_unslash( $_POST['wow_mode'] ) );

		/*
		 * A colour input cannot be left empty, so "no secondary colour" has to
		 * be said explicitly. With the box ticked the two companions are left
		 * out of the input entirely and BrandKit rotates them off the primary —
		 * otherwise a one-colour brand silently inherits the theme's violet and
		 * fuchsia and comes out looking like two brands at once.
		 */
		$derive = isset( $_POST['wow_derive'] );

		$brand = array(
			'mode'     => $dark ? 'dark' : 'light',
			'accent'   => (string) $accent,
			'accent-2' => $derive || ! isset( $_POST['wow_accent_2'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['wow_accent_2'] ) ),
			'accent-3' => $derive || ! isset( $_POST['wow_accent_3'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['wow_accent_3'] ) ),
			'heading'  => isset( $_POST['wow_heading'] ) ? sanitize_key( wp_unslash( $_POST['wow_heading'] ) ) : '',
		);

		$tokens = BrandKit::derive( $brand );

		DesignTokens::apply( $tokens );

		// Gradients and shadows have to follow the palette, or a variation
		// chosen on the previous step leaves its own behind on top of this one.
		StyleVariations::set_presets(
			BrandKit::gradients( $tokens['colors'], $dark ),
			BrandKit::shadows( $tokens['colors'], $dark )
		);

		$this->go( 'content', '' !== $notice ? $notice : 'brand' );
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

		$id = media_handle_upload( 'wow_logo', 0 );

		if ( is_wp_error( $id ) ) {
			return 'logo-failed';
		}

		set_theme_mod( 'custom_logo', (int) $id );

		return '';
	}

	/**
	 * Install the starter pages.
	 *
	 * @return void
	 */
	private function save_content(): void {
		$report = DemoContent::install();

		$this->go( 'done', array() === $report['pages'] ? 'content-none' : 'content' );
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
		wp_safe_redirect( add_query_arg( 'wow-notice', $notice, $this->step_url( $step ) ) );
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
			admin_url( 'admin-post.php?action=wow_signal_setup&wow_step=' . rawurlencode( $step ) ),
			self::NONCE
		);
	}
}
