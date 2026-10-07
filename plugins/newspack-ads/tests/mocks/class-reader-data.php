<?php
/**
 * Stand-in for newspack-plugin's Reader_Data, which the suite doesn't load.
 *
 * Tests set the stored values, JSON-encoded as the real class stores them, in
 * `Reader_Data::$data`.
 *
 * @package Newspack_Ads\Tests
 */

namespace Newspack;

if ( class_exists( 'Newspack\Reader_Data' ) ) {
	return;
}

/**
 * Stand-in for Newspack\Reader_Data exposing the API GAM targeting uses.
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
