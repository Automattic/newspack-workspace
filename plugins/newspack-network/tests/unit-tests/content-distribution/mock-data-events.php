<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Controllable mock of the Newspack Data Events class.
 *
 * The real class lives in newspack-plugin, which is not loaded in this test
 * suite. Defined here so tests can exercise code paths guarded by
 * class_exists( 'Newspack\Data_Events' ) and control the dispatch() return value.
 *
 * Guarded so it does not collide with the ad-hoc mock evaluated at runtime in
 * sibling tests; those only rely on dispatch() returning a truthy value, which
 * the default preserves.
 *
 * @package Newspack_Network
 */

namespace Newspack;

if ( ! class_exists( 'Newspack\Data_Events' ) ) {
	/**
	 * Mock Data Events class.
	 */
	class Data_Events {
		/**
		 * Value returned by dispatch(). Set to a WP_Error to simulate a failed dispatch.
		 *
		 * @var mixed
		 */
		public static $mock_dispatch_return = true;

		/**
		 * Mock dispatch.
		 *
		 * @param string $action_name   Action name.
		 * @param array  $data          Data.
		 * @param bool   $use_client_id Whether to use the client ID.
		 *
		 * @return mixed
		 */
		public static function dispatch( $action_name, $data = [], $use_client_id = true ) {
			return self::$mock_dispatch_return;
		}

		/**
		 * Mock is_action_registered.
		 *
		 * @param string $action_name Action name.
		 *
		 * @return bool
		 */
		public static function is_action_registered( $action_name ) {
			return true;
		}
	}
}
