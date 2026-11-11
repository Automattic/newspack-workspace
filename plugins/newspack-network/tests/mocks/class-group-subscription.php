<?php
/**
 * Stand-in for newspack-plugin's Group_Subscription, which the suite doesn't load.
 *
 * Tests set eligibility per user in `$GLOBALS['newspack_network_test_group']['eligible']`.
 *
 * @package Newspack_Network
 */

namespace Newspack;

if ( class_exists( 'Newspack\Group_Subscription' ) ) {
	return;
}

/**
 * Stand-in for Newspack\Group_Subscription.
 */
class Group_Subscription {
	/**
	 * The user meta key holding when a member joined a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return string
	 */
	public static function get_member_joined_meta_key( $subscription_id ) {
		return '_newspack_group_subscription_joined_' . (int) $subscription_id;
	}

	/**
	 * Whether the user can hold a seat.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_eligible_member( $user_id ) {
		return $GLOBALS['newspack_network_test_group']['eligible'][ $user_id ] ?? true;
	}
}
