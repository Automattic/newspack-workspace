<?php
/**
 * Newspack Network Site Role handling.
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * Class to handle the Site Role option
 */
class Site_Role {

	/**
	 * The option name where the role is stored
	 *
	 * @var string
	 */
	const OPTION_NAME = 'newspack_network_site_role';

	const HUB_ROLE  = 'hub';
	const NODE_ROLE = 'node';

	/**
	 * Gets the site role
	 *
	 * @return ?string
	 */
	public static function get() {
		return get_option( self::OPTION_NAME );
	}

	/**
	 * Checks if the site role is "hub"
	 *
	 * @return boolean
	 */
	public static function is_hub() {
		return self::HUB_ROLE === self::get();
	}

	/**
	 * Checks if the site role is "node"
	 *
	 * @return boolean
	 */
	public static function is_node() {
		return self::NODE_ROLE === self::get();
	}

	/**
	 * Sets site role as "node".
	 *
	 * @return boolean True if the option was saved.
	 */
	public static function set_as_node() {
		return update_option( self::OPTION_NAME, self::NODE_ROLE );
	}

	/**
	 * Validates a value to be used as the site role
	 *
	 * @param string $role The new value to be used as the site role.
	 * @return string|bool
	 */
	public static function sanitize( $role ) {
		return in_array( $role, [ 'hub', 'node' ], true ) ? $role : false;
	}

	/**
	 * Checks if the site has a role
	 *
	 * @return boolean
	 */
	public static function has_role() {
		return self::is_hub() || self::is_node();
	}
}
