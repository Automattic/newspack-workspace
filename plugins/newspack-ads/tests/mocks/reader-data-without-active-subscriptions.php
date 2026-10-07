<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Reader data stand-ins with get_bool() but not get_active_subscriptions(),
 * for GAM targeting tests that run in their own process.
 *
 * @package Newspack_Ads\Tests
 */

namespace Newspack;

/**
 * Reader activation stand-in that treats any user as a reader.
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
 * Reader data stand-in without get_active_subscriptions().
 */
class Reader_Data {
	/**
	 * Boolean values by user ID and key.
	 *
	 * @var array
	 */
	public static $bool_values = [];

	/**
	 * Whether a reader data boolean is true.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Data key.
	 * @return bool
	 */
	public static function get_bool( $user_id, $key ) {
		return ! empty( self::$bool_values[ $user_id ][ $key ] );
	}
}
