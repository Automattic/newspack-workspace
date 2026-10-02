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
	 * Whether the user can hold a seat.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_eligible_member( $user_id ) {
		return $GLOBALS['newspack_network_test_group']['eligible'][ $user_id ] ?? true;
	}
}
