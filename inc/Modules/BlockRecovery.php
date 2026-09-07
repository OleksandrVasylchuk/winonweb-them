<?php
/**
 * Putting the site back together after something replaced the theme folder.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\BlockRepair;
use Qwerty\Soft\Support\ShopKit;

defined( 'ABSPATH' ) || exit;

/**
 * A theme update deletes this site's own sections. This notices and rebuilds them.
 *
 * The blocks an import generates are files under `blocks/design/`, inside the
 * theme, and they are deliberately left out of the release ZIP: they belong to
 * one site, and shipping them would put one client's copy into the next
 * client's install. That exclusion is exactly why an update destroys them.
 * WordPress updates a theme by deleting its folder and unpacking the new one —
 * `Theme_Upgrader::upgrade()` passes `clear_destination => true` — so
 * everything the new package does not carry is removed. Every section of every
 * page then reads "your site doesn't include support for this block", with the
 * words still safe in the database and nothing on screen to say why.
 *
 * The same goes for the shop half. `shop-kit/` ships folded and is unfolded
 * into `templates/` and `inc/Modules/` on the one site whose design had a shop
 * in it; the unfolded copies are excluded from the ZIP for the same reason, and
 * removed by the same update. That failure is quieter — WooCommerce's own
 * templates take over — and worth catching for the same reason.
 *
 * {@see BlockRepair} could always put the blocks back, and the import screen
 * has offered it. What was missing is that somebody had to know to go there:
 * the screen an editor never opens, on a site that looks broken everywhere
 * else. So the moment is caught instead. An update, or a switch back to this
 * theme, raises a flag; the next admin page reads it, rebuilds what it can, and
 * says what happened. Where the rebuild cannot run — the design it was built
 * from is no longer unpacked — the notice stays up until somebody acts on it,
 * because that site is broken and silence would be a lie.
 *
 * The work is never done in the upgrade's own request. Files are still being
 * moved there, and a rebuild reading them would read them half written.
 */
final class BlockRecovery implements Module {

	/**
	 * Set when the theme folder has just been replaced.
	 *
	 * Autoloaded on purpose: it is read on every admin request and is only
	 * present between an update and the next admin page anybody opens.
	 *
	 * @var string
	 */
	public const CHECK = 'qwerty_soft_recover_check';

	/**
	 * What the last recovery found, kept for the notice.
	 *
	 * Held rather than shown once. A recovery that could not run leaves a site
	 * whose every page is broken, and a notice that scrolls past once is not
	 * how somebody finds out about that.
	 *
	 * @var string
	 */
	public const REPORT = 'qwerty_soft_recover_report';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'after_switch_theme', array( $this, 'flag' ) );
		add_action( 'admin_init', array( $this, 'recover' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Raise the flag when the upgrade that just finished was this theme's.
	 *
	 * @param mixed                $upgrader    The upgrader, unused.
	 * @param array<string, mixed> $hook_extra  What was upgraded.
	 * @return void
	 */
	public function after_upgrade( $upgrader, $hook_extra ): void {
		unset( $upgrader );

		if ( ! is_array( $hook_extra ) || 'theme' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$themes = isset( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ? $hook_extra['themes'] : array();

		/*
		 * A single-theme upgrade names the theme; a bulk one names all of
		 * them. An upgrade that names none is one whose shape this does not
		 * recognise, and looking is cheaper than missing it.
		 */
		if ( array() !== $themes && ! in_array( get_template(), $themes, true ) ) {
			return;
		}

		$this->flag();
	}

	/**
	 * Ask the next admin page to look at the blocks.
	 *
	 * @return void
	 */
	public function flag(): void {
		update_option( self::CHECK, 1, true );
	}

	/**
	 * Rebuild what the theme lost, once, on the first admin page after it did.
	 *
	 * @return void
	 */
	public function recover(): void {
		if ( 1 !== (int) get_option( self::CHECK, 0 ) ) {
			return;
		}

		/*
		 * Only somebody who could have done this, and could act on the answer.
		 * A subscriber opening wp-admin must not set a rebuild running, and
		 * must not clear the flag before an administrator has seen it.
		 */
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// Never inside another upgrade: those files are still being moved.
		if ( wp_doing_ajax() || wp_doing_cron() || defined( 'DOING_AUTOSAVE' ) ) {
			return;
		}

		delete_option( self::CHECK );
		delete_option( self::REPORT );

		$missing = BlockRepair::missing();
		$shop    = $this->shop_lost();

		if ( array() === $missing && ! $shop ) {
			return;
		}

		$report = array(
			'rebuilt' => 0,
			'left'    => array(),
			'design'  => true,
			'shop'    => false,
		);

		if ( array() !== $missing ) {
			$run = BlockRepair::run();

			$report['rebuilt'] = (int) $run['written'];
			$report['left']    = array_values( (array) $run['missing'] );

			/*
			 * Whether the design was there at all. "Could not be rebuilt"
			 * has two very different answers behind it — the archive is no
			 * longer unpacked, or it is and the rebuild still fell short —
			 * and telling somebody to re-upload an archive that is already
			 * on the server sends them the wrong way.
			 */
			$report['design'] = '' !== (string) $run['root'];
		}

		if ( $shop ) {
			$report['shop'] = ! is_wp_error( ShopKit::install() );
		}

		update_option( self::REPORT, $report, false );
	}

	/**
	 * Whether this site had the shop half and no longer has it.
	 *
	 * Recorded rather than guessed at. A site running WooCommerce that never
	 * imported a shop design must not be handed seven shop templates it never
	 * asked for, and the filesystem alone cannot tell the two apart.
	 *
	 * The record is not enough on its own, because `SiteAssembler::reset()`
	 * empties a site without folding the shop half back up: wipe a shop site,
	 * import a design with no shop in it, and the record still says this was
	 * once a shop. So the shop itself has to still be here — no WooCommerce,
	 * no shop templates.
	 *
	 * @return bool
	 */
	private function shop_lost(): bool {
		return 1 === (int) get_option( ShopKit::INSTALLED, 0 )
			&& class_exists( 'WooCommerce' )
			&& ShopKit::available()
			&& ! ShopKit::installed();
	}

	/**
	 * Say what happened, and keep saying it while the site is still broken.
	 *
	 * @return void
	 */
	public function notice(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$report = get_option( self::REPORT, array() );

		if ( ! is_array( $report ) || array() === $report ) {
			return;
		}

		$left = isset( $report['left'] ) && is_array( $report['left'] ) ? $report['left'] : array();

		if ( array() !== $left ) {
			$why = empty( $report['design'] )
				? __( 'The design they were built from is no longer unpacked on this server, so they could not be rebuilt: upload the archive again and press Build.', 'qwerty-soft-signal' )
				: __( 'They could not all be rebuilt from the design that is on the server. Open Design import and press Build.', 'qwerty-soft-signal' );

			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
				esc_html__( 'This site’s own sections are missing.', 'qwerty-soft-signal' ),
				esc_html(
					sprintf(
						/* translators: %d: how many sections. */
						_n(
							'Updating the theme removed %d section built for this site.',
							'Updating the theme removed %d sections built for this site.',
							count( $left ),
							'qwerty-soft-signal'
						),
						count( $left )
					)
				),
				esc_html( $why . ' ' . __( 'The words themselves are still in the database.', 'qwerty-soft-signal' ) ),
				esc_url( admin_url( 'themes.php?page=qwerty-soft-signal-import' ) ),
				esc_html__( 'Open Design import', 'qwerty-soft-signal' )
			);

			return;
		}

		$said = array();

		if ( (int) ( $report['rebuilt'] ?? 0 ) > 0 ) {
			$said[] = sprintf(
				/* translators: %d: how many sections. */
				_n(
					'Updating the theme removed %d section built for this site; it has been rebuilt.',
					'Updating the theme removed %d sections built for this site; they have been rebuilt.',
					(int) $report['rebuilt'],
					'qwerty-soft-signal'
				),
				(int) $report['rebuilt']
			);
		}

		if ( ! empty( $report['shop'] ) ) {
			$said[] = __( 'The shop templates were put back too.', 'qwerty-soft-signal' );
		}

		// Nothing was rebuilt and nothing is missing: there is nothing to say.
		if ( array() === $said ) {
			delete_option( self::REPORT );

			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( implode( ' ', $said ) )
		);

		delete_option( self::REPORT );
	}
}
