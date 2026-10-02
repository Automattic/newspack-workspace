<?php
/**
 * Stand-in for WooCommerce Subscriptions' loader, which the suite doesn't load.
 *
 * Tests register subscriptions by ID in `$GLOBALS['newspack_network_test_group']['subscriptions']`;
 * any other ID loads as false, as a deleted subscription does.
 *
 * @package Newspack_Network
 */

if ( function_exists( 'wcs_get_subscription' ) ) {
	return;
}

/**
 * Stand-in for wcs_get_subscription().
 *
 * @param int $id Subscription ID.
 * @return object|false
 */
function wcs_get_subscription( $id ) {
	return $GLOBALS['newspack_network_test_group']['subscriptions'][ $id ] ?? false;
}
