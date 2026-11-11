<?php
/**
 * Stand-in for newspack-plugin's Reader_Activation, which the suite doesn't load.
 *
 * @package Newspack_Ads\Tests
 */

namespace Newspack;

if ( class_exists( 'Newspack\Reader_Activation' ) ) {
	return;
}

/**
 * Stand-in for Newspack\Reader_Activation that treats every user as a reader.
 */
class Reader_Activation {
	/**
	 * Determine whether a user is a reader.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public static function is_user_reader( $user ) {
		return true;
	}
}
