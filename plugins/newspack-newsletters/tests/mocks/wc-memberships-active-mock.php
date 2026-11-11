<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files, WordPress.NamingConventions.PrefixAllGlobals
/**
 * What Woocommerce_Memberships::is_enabled() looks for, plus the restriction
 * checks the integration calls once it is enabled.
 *
 * Require this only from a test that runs in a separate process: once declared,
 * these make Memberships count as active for every later test in the process.
 *
 * Restriction follows the plans registered in `$test_wc_memberships` (see
 * wc-memberships.php). A post whose `_wc_memberships_force_public` meta is
 * 'yes' is public, as Memberships marks it. `$test_wc_memberships_members` maps
 * a user ID to the IDs of the plans that user holds.
 *
 * @package Newspack_Newsletters
 */

if ( ! class_exists( 'WC_Memberships_Loader' ) ) {
	class WC_Memberships_Loader {}
}

function wc_memberships_is_post_content_restricted( $post_id = null ) {
	if ( 'yes' === get_post_meta( $post_id, '_wc_memberships_force_public', true ) ) {
		return false;
	}
	return ! empty( wc_memberships_test_get_post_rules( $post_id ) );
}

function wc_memberships_user_can( $user_id, $action, $target ) {
	global $test_wc_memberships_members;
	$post_id = is_array( $target ) && isset( $target['post'] ) ? $target['post'] : 0;
	$held    = isset( $test_wc_memberships_members[ (int) $user_id ] ) ? (array) $test_wc_memberships_members[ (int) $user_id ] : [];
	foreach ( wc_memberships_test_get_post_rules( $post_id ) as $rule ) {
		if ( in_array( $rule->get_membership_plan()->get_id(), $held, true ) ) {
			return true;
		}
	}
	return false;
}
