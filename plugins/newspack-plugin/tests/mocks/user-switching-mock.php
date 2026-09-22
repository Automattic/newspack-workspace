<?php
/**
 * Stand-in for the User Switching plugin's public API, which is not part of
 * the test environment. Answers with the user a test has set as the original
 * account, so the switched-session guard can be exercised without the plugin.
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'current_user_switched' ) ) {
	/**
	 * The user an admin switched from, while a test has one set.
	 *
	 * @return WP_User|false The original user while switched, false otherwise.
	 */
	function current_user_switched() {
		return $GLOBALS['newspack_test_switched_from_user'] ?? false;
	}
}
