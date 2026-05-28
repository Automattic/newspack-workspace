<?php
/**
 * Newspack Network Reader Roles filter.
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * Class to filter the roles that Newspack Plugin uses to determine if a user is a reader.
 */
class Reader_Roles_Filter {

	/**
	 * Initializes the hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'newspack_reader_user_roles', [ __CLASS__, 'filter_user_roles' ] );
	}

	/**
	 * Filters the roles that Newspack Plugin uses to determine if a user is a reader.
	 *
	 * @param string[] $roles Array of user roles.
	 * @return array
	 */
	public static function filter_user_roles( $roles ) {
		$roles[] = NEWSPACK_NETWORK_READER_ROLE;
		return $roles;
	}
}
