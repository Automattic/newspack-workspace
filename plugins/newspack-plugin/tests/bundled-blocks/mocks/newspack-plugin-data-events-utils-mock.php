<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FileComment.Missing

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test mock deliberately impersonates the plugin's \Newspack\Data_Events\Utils.
namespace Newspack\Data_Events;

if ( ! class_exists( __NAMESPACE__ . '\Utils' ) ) {
	/**
	 * Fixture-driven mock of Newspack\Data_Events\Utils (newspack-plugin), used
	 * when newspack-plugin is not loaded in the test environment.
	 */
	class Utils {
		/**
		 * Order-data fixtures keyed by order ID. Set by tests.
		 *
		 * @var array
		 */
		public static $order_data_fixtures = [];

		/**
		 * Return the fixture for an order ID, or an empty array like the real
		 * method does for an unknown order.
		 *
		 * @param int $order_id Order ID.
		 *
		 * @return array
		 */
		public static function get_order_data( $order_id ) {
			return self::$order_data_fixtures[ $order_id ] ?? [];
		}
	}
}
