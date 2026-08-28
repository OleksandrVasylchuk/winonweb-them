<?php
/**
 * Optional studio credit in the footer.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use WP_Customize_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a single Customizer checkbox controlling the "Design by Qwerty Soft" line.
 *
 * The credit is opt-in: a fresh install shows nothing. The client turns it on
 * or off with one checkbox and never has to touch the footer template.
 */
final class Credit implements Module {

	/**
	 * Theme mod storing the client's choice.
	 */
	public const SETTING = 'qwerty_soft_show_credit';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'customize_register', array( $this, 'customize' ) );
	}

	/**
	 * Register the Customizer section, setting and control.
	 *
	 * @param WP_Customize_Manager $wp_customize Customizer manager.
	 * @return void
	 */
	public function customize( WP_Customize_Manager $wp_customize ): void {
		$wp_customize->add_section(
			'qwerty_soft_credit',
			array(
				'title'       => __( 'Footer credit', 'qwerty-soft-signal' ),
				'description' => __( 'Show a small "Design by Qwerty Soft" line at the bottom of every page.', 'qwerty-soft-signal' ),
				'priority'    => 200,
			)
		);

		$wp_customize->add_setting(
			self::SETTING,
			array(
				'default'           => false,
				'type'              => 'theme_mod',
				'capability'        => 'edit_theme_options',
				'transport'         => 'refresh',
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
			)
		);

		$wp_customize->add_control(
			self::SETTING,
			array(
				'section' => 'qwerty_soft_credit',
				'label'   => __( 'Show the designer credit', 'qwerty-soft-signal' ),
				'type'    => 'checkbox',
			)
		);
	}

	/**
	 * Sanitise the checkbox value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_checkbox( $value ): bool {
		return (bool) $value;
	}

	/**
	 * Whether the credit should render.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$enabled = (bool) get_theme_mod( self::SETTING, false );

		/**
		 * Filter whether the studio credit renders.
		 *
		 * Lets a site force the credit on or off in code, for example when a
		 * licence requires attribution.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Current state.
		 */
		return (bool) apply_filters( 'qwerty_soft/show_credit', $enabled );
	}
}
