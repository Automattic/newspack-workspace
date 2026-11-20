<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Donations.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the
 * real \Newspack\Donations class is absent. This lightweight stub lets the
 * tests exercise the newspack_is_donation REST field contract and the Donate
 * block renderer in isolation.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Donations.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Donations' ) ) {
	/**
	 * Minimal stub of the plugin's Donations class.
	 */
	class Donations {
		/**
		 * Product IDs the stub should report as donations. Set by the test.
		 *
		 * @var int[]
		 */
		public static $stub_donation_product_ids = [];

		/**
		 * Product IDs is_donation_product() was called with, in order.
		 *
		 * @var array
		 */
		public static $stub_calls = [];

		/**
		 * Site-wide donation settings the stub returns. Set by the test.
		 *
		 * @var array
		 */
		public static $stub_donation_settings = [];

		/**
		 * Whether the stub reports the RevEngine platform, which makes the Donate
		 * block treat Name Your Price as available. Set by the test.
		 *
		 * @var bool
		 */
		public static $stub_is_platform_nrh = false;

		/**
		 * Whether the given product ID is a donation product.
		 *
		 * @param int $product_id Product ID to check.
		 * @return bool
		 */
		public static function is_donation_product( $product_id ) {
			self::$stub_calls[] = $product_id;
			return in_array( (int) $product_id, self::$stub_donation_product_ids, true );
		}

		/**
		 * Site-wide donation settings.
		 *
		 * @return array
		 */
		public static function get_donation_settings() {
			return self::$stub_donation_settings;
		}

		/**
		 * Whether the donation platform is RevEngine.
		 *
		 * @return bool
		 */
		public static function is_platform_nrh() {
			return self::$stub_is_platform_nrh;
		}
	}
}
