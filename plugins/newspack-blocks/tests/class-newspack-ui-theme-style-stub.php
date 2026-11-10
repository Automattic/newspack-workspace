<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Newspack_UI_Theme_Style.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the
 * real adapter class is absent. This stub lets the tests set what it reports.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Newspack_UI_Theme_Style.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Newspack_UI_Theme_Style' ) ) {
	/**
	 * Minimal stub of the plugin's Theme style adapter.
	 */
	class Newspack_UI_Theme_Style {
		/**
		 * Whether the stub reports the Theme style as active. Set by the test.
		 *
		 * @var bool
		 */
		public static bool $stub_active = false;

		/**
		 * The button radius the stub reports. Set by the test.
		 *
		 * @var string
		 */
		public static string $stub_button_radius = '';

		/**
		 * Whether the Theme style is on.
		 *
		 * @return bool
		 */
		public static function is_active(): bool {
			return self::$stub_active;
		}

		/**
		 * The printed button radius.
		 *
		 * @return string
		 */
		public static function get_button_radius(): string {
			return self::$stub_button_radius;
		}
	}
}
