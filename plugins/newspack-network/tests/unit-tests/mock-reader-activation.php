<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Minimal stand-in for \Newspack\Reader_Activation, which lives in
 * newspack-plugin and is not loaded by this plugin's test bootstrap.
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
	 * does when newspack-plugin is active.
	 */
	class Reader_Activation {
		/**
		 * Get the reader roles.
		 *
		 * @return string[]
		 */
		public static function get_reader_roles() {
			return \apply_filters( 'newspack_reader_user_roles', [ 'subscriber', 'customer' ] );
		}
	}
}
