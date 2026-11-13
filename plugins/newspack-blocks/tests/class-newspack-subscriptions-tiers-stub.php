<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Subscriptions_Tiers.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the
 * real class is absent. This stub returns the tiers a test sets, so the
 * Checkout Button's tier checks can run in isolation. It leaves out
 * render_form(), so the modal picker still skips itself as it does without
 * newspack-plugin.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Subscriptions_Tiers.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Subscriptions_Tiers' ) ) {
	/**
	 * Minimal stub of the plugin's Subscriptions_Tiers class.
	 */
	class Subscriptions_Tiers {
		/**
		 * Every tier, by frequency. Set by the test.
		 *
		 * @var array
		 */
		public static $stub_tiers = [];

		/**
		 * The tiers a reader can buy, by frequency. Set by the test.
		 *
		 * @var array
		 */
		public static $stub_purchasable_tiers = [];

		/**
		 * Every tier, by frequency.
		 *
		 * @param \WC_Product|null $product Product.
		 * @return array
		 */
		public static function get_tiers_by_frequency( $product = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			return self::$stub_tiers;
		}

		/**
		 * The tiers a reader can buy, by frequency.
		 *
		 * @param \WC_Product|null $product Product.
		 * @return array
		 */
		public static function get_purchasable_tiers( $product = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			return self::$stub_purchasable_tiers;
		}
	}
}
