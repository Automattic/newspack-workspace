<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Group_Subscription_Seats.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the
 * real \Newspack\Group_Subscription_Seats class is absent. This lightweight stub
 * lets the tests exercise the newspack_has_seats REST field contract in isolation.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Group_Subscription_Seats.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Group_Subscription_Seats' ) ) {
	/**
	 * Minimal stub of the plugin's Group_Subscription_Seats class.
	 */
	class Group_Subscription_Seats {
		/**
		 * Product IDs the stub should report as sold per seat. Set by the test.
		 *
		 * @var int[]
		 */
		public static $stub_per_seat_product_ids = [];

		/**
		 * Seat field args for a product, or null when it is not sold per seat.
		 *
		 * @param int $product_id Product ID to check.
		 * @return array|null
		 */
		public static function get_field_args( $product_id ) {
			return in_array( (int) $product_id, self::$stub_per_seat_product_ids, true )
				? [
					'label' => 'Number of group seats',
					'min'   => 2,
					'max'   => 10,
					'help'  => '',
				]
				: null;
		}
	}
}
