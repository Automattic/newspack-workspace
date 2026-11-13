<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Mock for the WooCommerce Subscriptions Gifting WCSG_Recipient_Addresses class.
 *
 * @package Newspack\Tests
 */

/**
 * Stand-in for the billing half of WCSG_Recipient_Addresses::get_users_subscriptions(),
 * as WooCommerce Subscriptions 9.3.0 ships it. On the billing edit-address endpoint,
 * with no `?subscription=` query arg, it drops every subscription the user received
 * as a gift, so the address form can't overwrite the purchaser's billing details.
 *
 * Defining the class registers nothing. Register it as WooCommerce Subscriptions
 * does, by name at priority 100:
 * add_filter( 'wcs_get_users_subscriptions', 'WCSG_Recipient_Addresses::get_users_subscriptions', 100, 2 ).
 */
if ( ! class_exists( 'WCSG_Recipient_Addresses' ) ) {
	/**
	 * Recipient address handling stub.
	 */
	class WCSG_Recipient_Addresses {
		/**
		 * Drop the gifts the user received while the billing address form is on screen.
		 *
		 * @param array $subscriptions Subscriptions keyed by ID.
		 * @param int   $user_id       User ID.
		 * @return array
		 */
		public static function get_users_subscriptions( $subscriptions, $user_id ) {
			if ( 'billing' !== get_query_var( 'edit-address' ) || isset( $_GET['subscription'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return $subscriptions;
			}
			foreach ( $subscriptions as $subscription_id => $subscription ) {
				if ( (int) WCS_Gifting::get_recipient_user( $subscription ) === (int) $user_id ) {
					unset( $subscriptions[ $subscription_id ] );
				}
			}
			return $subscriptions;
		}
	}
}
