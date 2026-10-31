<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed
/**
 * Mocks for WooCommerce Subscriptions 9.4's gift recipient address privacy,
 * and the view-order endpoint check that decides when it applies.
 *
 * Both are global and shared across test classes: reset
 * WCSG_Recipient_Addresses::$withheld and $GLOBALS['newspack_test_wc_endpoint']
 * in set_up() and tear_down().
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	/**
	 * Stub for WooCommerce is_wc_endpoint_url(): the endpoint a test sets.
	 *
	 * @param string $endpoint Endpoint.
	 * @return bool
	 */
	function is_wc_endpoint_url( $endpoint = false ) {
		return $endpoint === ( $GLOBALS['newspack_test_wc_endpoint'] ?? null );
	}
}

if ( ! class_exists( 'WCSG_Recipient_Addresses' ) ) {
	/**
	 * Stub for WooCommerce Subscriptions 9.4's WCSG_Recipient_Addresses: an
	 * order's shipping address is withheld from the users listed for it.
	 */
	class WCSG_Recipient_Addresses {
		/**
		 * Map of order ID => user IDs the shipping address is withheld from.
		 *
		 * @var array
		 */
		public static $withheld = [];

		/**
		 * Whether the order's shipping address is withheld from the user.
		 *
		 * @param object $order   Order.
		 * @param int    $user_id User ID.
		 * @return bool
		 */
		public static function is_shipping_address_withheld( $order, $user_id ) {
			return in_array( (int) $user_id, self::$withheld[ $order->get_id() ] ?? [], true );
		}
	}
}
