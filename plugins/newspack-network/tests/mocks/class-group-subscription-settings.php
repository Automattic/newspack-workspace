<?php
/**
 * Stand-in for newspack-plugin's Group_Subscription_Settings, which the suite doesn't load.
 *
 * Tests set a subscription's settings in `$GLOBALS['newspack_network_test_group']['settings']`.
 *
 * @package Newspack_Network
 */

namespace Newspack;

if ( class_exists( 'Newspack\Group_Subscription_Settings' ) ) {
	return;
}

/**
 * Stand-in for Newspack\Group_Subscription_Settings.
 */
class Group_Subscription_Settings {
	/**
	 * The group settings of a subscription.
	 *
	 * @param object $subscription Subscription.
	 * @return array
	 */
	public static function get_subscription_settings( $subscription ) {
		return $GLOBALS['newspack_network_test_group']['settings'][ $subscription->get_id() ] ?? [ 'enabled' => true ];
	}
}
