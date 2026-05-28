<?php
/**
 * Stubs for WooCommerce Memberships functions used in tests.
 *
 * @package Newspack_Network
 */

if ( ! function_exists( 'wc_memberships_get_user_membership' ) ) {
	/**
	 * Stub for wc_memberships_get_user_membership.
	 *
	 * @param mixed $user_id_or_membership User ID or membership post ID.
	 * @param mixed $plan_id              Optional plan ID.
	 * @return null
	 */
	function wc_memberships_get_user_membership( $user_id_or_membership = null, $plan_id = null ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		return null;
	}
}
if ( ! function_exists( 'wc_memberships_create_user_membership' ) ) {
	/**
	 * Stub for wc_memberships_create_user_membership.
	 *
	 * @param array $args Membership args.
	 * @return null
	 */
	function wc_memberships_create_user_membership( $args = [] ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		return null;
	}
}
