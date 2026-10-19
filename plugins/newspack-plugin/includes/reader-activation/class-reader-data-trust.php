<?php
/**
 * Reader Data Trust: tracks which stored reader data values the server wrote.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Reader Data Trust Class.
 */
final class Reader_Data_Trust {

	const MARK_META_PREFIX = 'newspack_reader_data_mark_';

	/**
	 * Read the raw value as stored in user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 *
	 * @return string
	 */
	private static function get_stored_value( $user_id, $key ) {
		$value = \get_user_meta( $user_id, Reader_Data::get_meta_key_name( $key ), true );
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Record that the server wrote the key's current stored value.
	 *
	 * The mark is a hash of the raw stored value, so any later change to that
	 * value that does not go through the server voids it.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 */
	public static function set_mark( int $user_id, string $key ): void {
		\update_user_meta( $user_id, self::MARK_META_PREFIX . $key, hash( 'sha256', self::get_stored_value( $user_id, $key ) ) );
	}

	/**
	 * Remove the server mark for a key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 */
	public static function clear_mark( int $user_id, string $key ): void {
		\delete_user_meta( $user_id, self::MARK_META_PREFIX . $key );
	}

	/**
	 * Whether the key's current stored value is the one the server wrote.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 *
	 * @return bool
	 */
	public static function has_valid_mark( int $user_id, string $key ): bool {
		$mark = \get_user_meta( $user_id, self::MARK_META_PREFIX . $key, true );
		if ( ! is_string( $mark ) || '' === $mark ) {
			return false;
		}
		return hash_equals( $mark, hash( 'sha256', self::get_stored_value( $user_id, $key ) ) );
	}
}
