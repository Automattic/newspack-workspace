<?php
/**
 * Stand-in for WooCommerce Subscriptions' WCS_Gifting, which the suite doesn't load.
 *
 * Tests set a subscription's raw recipient meta in
 * `$GLOBALS['newspack_network_test_group']['recipients']`, keyed by subscription ID.
 *
 * @package Newspack_Network
 */

if ( class_exists( 'WCS_Gifting' ) ) {
	return;
}

/**
 * Stand-in for WCS_Gifting.
 */
class WCS_Gifting {
	/**
	 * The recipient meta as stored, like the real method returns it.
	 *
	 * @param object $subscription Subscription.
	 * @return string
	 */
	public static function get_recipient_user( $subscription ) {
		return (string) ( $GLOBALS['newspack_network_test_group']['recipients'][ $subscription->get_id() ] ?? '' );
	}

	/**
	 * Whether the subscription has a recipient.
	 *
	 * @param object $subscription Subscription.
	 * @return bool
	 */
	public static function is_gifted_subscription( $subscription ) {
		return ! empty( $GLOBALS['newspack_network_test_group']['recipients'][ $subscription->get_id() ] );
	}
}
