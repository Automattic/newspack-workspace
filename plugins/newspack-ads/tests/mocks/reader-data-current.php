<?php
/**
 * Reader-data API fixture for current Newspack versions.
 *
 * @package Newspack\Tests
 */

namespace Newspack;

/**
 * Reader activation fixture.
 */
class Reader_Activation {
	/**
	 * Whether the user is a reader.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public static function is_user_reader( $user ) {
		return $user instanceof \WP_User;
	}
}

/**
 * Reader data fixture with active subscription support.
 */
class Reader_Data {
	/**
	 * The user's active subscriptions.
	 *
	 * @var array
	 */
	public static $active_subscriptions = [];

	/**
	 * Reader boolean values.
	 *
	 * @var array
	 */
	public static $bool_values = [];

	/**
	 * Get a reader boolean.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Data key.
	 * @return bool
	 */
	public static function get_bool( $user_id, $key ) {
		return ! empty( self::$bool_values[ $user_id ][ $key ] );
	}

	/**
	 * Get the user's active subscriptions.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_active_subscriptions( $user_id ) {
		return self::$active_subscriptions;
	}
}
