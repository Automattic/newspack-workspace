<?php
/**
 * Stand-in for WooCommerce Subscriptions' loader, which the suite doesn't load.
 *
 * Tests register subscriptions by ID in `$GLOBALS['newspack_network_test_group']['subscriptions']`;
 * any other ID loads as false, as a deleted subscription does. The declaration sits inside the
 * guard because PHP declares a top-level function at compile time, before any early return runs.
 *
 * @package Newspack_Network
 */

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * Stand-in for wcs_get_subscription().
	 *
	 * @param int $id Subscription ID.
	 * @return object|false
	 */
	function wcs_get_subscription( $id ) {
		return $GLOBALS['newspack_network_test_group']['subscriptions'][ $id ] ?? false;
	}
}

if ( ! function_exists( 'wcs_get_orders_with_meta_query' ) ) {
	/**
	 * Stand-in for wcs_get_orders_with_meta_query(): records the query in
	 * `$GLOBALS['newspack_network_test_group']['order_queries']` and returns the IDs a
	 * test lists in `$GLOBALS['newspack_network_test_group']['order_query_ids']`.
	 *
	 * @param array $args Query arguments.
	 * @return int[]
	 */
	function wcs_get_orders_with_meta_query( $args ) {
		$GLOBALS['newspack_network_test_group']['order_queries'][] = $args;
		return $GLOBALS['newspack_network_test_group']['order_query_ids'] ?? [];
	}
}
