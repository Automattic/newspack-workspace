<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Minimal stand-in for \Newspack\Reader_Activation, which lives in
 * newspack-plugin and is not loaded by this plugin's test bootstrap.
 *
 * PHPUnit discovers and requires every test file once per process, so this
 * class, once defined, is available to every test that runs afterward in the
 * same run—not just the test file that needs it. It implements every
 * member newspack-network itself calls (grep 'Reader_Activation::' under
 * includes/), so a later test exercising one of those other call sites finds
 * a working stand-in instead of a fatal missing-method error.
 *
 * @package Newspack_Network
 */

namespace Newspack;

if ( ! class_exists( '\Newspack\Reader_Activation' ) ) {
	/**
	 * Stand-in for the real class, which lives in newspack-plugin.
	 *
	 * Mirrors get_reader_roles()'s default and filter, so
	 * Newspack_Network\Reader_Roles_Filter (already initialized by this
	 * plugin) extends it with NEWSPACK_NETWORK_READER_ROLE the same way it
	 * does when newspack-plugin is active. is_user_reader() mirrors the real
	 * method's meta flag, role fallback, and restricted-roles filter.
	 */
	class Reader_Activation {
		/**
		 * The user meta key the real class uses to flag a reader account.
		 *
		 * @var string
		 */
		const READER = 'np_reader';

		/**
		 * The user meta key the real class uses to record how an account registered.
		 *
		 * @var string
		 */
		const REGISTRATION_METHOD = 'np_reader_registration_method';

		/**
		 * Get the reader roles.
		 *
		 * @return string[]
		 */
		public static function get_reader_roles() {
			return \apply_filters( 'newspack_reader_user_roles', [ 'subscriber', 'customer' ] );
		}

		/**
		 * Whether the user is a reader.
		 *
		 * @param \WP_User|int $user User object or ID.
		 * @return bool
		 */
		public static function is_user_reader( $user ) {
			if ( ! is_a( $user, 'WP_User' ) ) {
				$user = \get_user_by( 'id', $user );
			}
			if ( ! $user ) {
				return false;
			}

			$is_reader = (bool) \get_user_meta( $user->ID, self::READER, true );
			if ( ! $is_reader ) {
				$is_reader = (bool) array_intersect( self::get_reader_roles(), $user->roles );
			}

			$restricted_roles = \apply_filters( 'newspack_reader_restricted_roles', [ 'administrator', 'editor' ] );
			if ( $is_reader && array_intersect( $restricted_roles, $user->roles ) ) {
				$is_reader = false;
			}

			return (bool) \apply_filters( 'newspack_is_user_reader', $is_reader, $user );
		}
	}
}
