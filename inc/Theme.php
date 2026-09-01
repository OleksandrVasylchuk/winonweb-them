<?php
/**
 * Theme container.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft;

use Qwerty\Soft\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the theme modules exactly once per request.
 */
final class Theme {

	/**
	 * Singleton instance.
	 *
	 * @var Theme|null
	 */
	private static ?Theme $instance = null;

	/**
	 * Booted modules, keyed by class name.
	 *
	 * @var array<class-string<Module>, Module>
	 */
	private array $modules = array();

	/**
	 * Guard against a double boot.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Get the shared instance.
	 *
	 * @return Theme
	 */
	public static function instance(): Theme {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Instantiate and register every module.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->module_classes() as $class_name ) {
			$module = new $class_name();

			if ( ! $module instanceof Module ) {
				continue;
			}

			$this->modules[ $class_name ] = $module;
			$module->register();
		}
	}

	/**
	 * Fetch a booted module.
	 *
	 * @param string $class_name Module class name.
	 * @phpstan-param class-string<Module> $class_name
	 * @return Module|null
	 */
	public function module( string $class_name ): ?Module {
		return $this->modules[ $class_name ] ?? null;
	}

	/**
	 * The module list, in boot order.
	 *
	 * @return array<int, class-string<Module>>
	 */
	private function module_classes(): array {
		$classes = array(
			Modules\Setup::class,
			Modules\Assets::class,
			Modules\Blocks::class,
			Modules\DesignBlocks::class,
			Modules\DesignOptions::class,
			Modules\DesignTypes::class,
			Modules\Patterns::class,
			Modules\Markup::class,
			Modules\Accessibility::class,
			Modules\Security::class,
			Modules\Performance::class,
			Modules\Cleanup::class,
			Modules\Seo::class,
			Modules\ContactForm::class,
			Modules\Credit::class,
			Modules\Branding::class,
			Modules\Onboarding::class,
			Modules\Handbook::class,
			Modules\Translations::class,
			Modules\Importer::class,
			Modules\SiteHealth::class,
			Modules\Updates::class,
		);

		// WooCommerce support is inert unless the plugin is actually active.
		if ( class_exists( 'WooCommerce' ) ) {
			$classes[] = Modules\WooCommerce::class;
		} else {
			Modules\WooCommerce::hide_shop_templates();
		}

		/**
		 * Filter the theme module list.
		 *
		 * Lets a child theme drop a module (for example Branding) or append
		 * its own without editing the parent.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, class-string<Module>> $classes Module class names.
		 */
		$classes = (array) apply_filters( 'qwerty_soft/modules', $classes );

		return array_values( array_filter( $classes, 'class_exists' ) );
	}
}
