<?php
/**
 * Compatible reader data mocks for GAM targeting tests.
 *
 * @package Newspack_Ads\Tests
 */

namespace Newspack;

/**
 * Reader activation mock that treats the current user as a reader.
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

/**
 * Reader data mock exposing the API used by GAM targeting.
 */
class Reader_Data {
	/**
	 * Stored reader data values.
	 *
	 * @var array
	 */
	public static $data = [];

	/**
	 * Get reader data.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Optional data key.
	 * @return mixed
	 */
	public static function get_data( $user_id, $key = '' ) {
		return $key ? ( self::$data[ $key ] ?? false ) : self::$data;
	}

	/**
	 * Whether a reader data boolean is true.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Data key.
	 * @return bool
	 */
	public static function get_bool( int $user_id, string $key ): bool {
		$value = self::get_data( $user_id, $key );
		return is_string( $value ) && (bool) json_decode( $value );
	}
}
