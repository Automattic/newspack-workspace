<?php
/**
 * WooCommerce Subscriptions Gifting Integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class WooCommerce_Subscriptions_Gifting {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		\add_filter( 'wcsg_new_recipient_account_details_fields', [ __CLASS__, 'new_recipient_fields' ] );
		\add_filter( 'wcsg_require_shipping_address_for_virtual_products', '__return_false' );
		\add_filter( 'default_option_woocommerce_subscriptions_gifting_gifting_checkbox_text', [ __CLASS__, 'default_gifting_checkbox_text' ] );
		\add_filter( 'newpack_reader_activation_reader_is_without_password', [ __CLASS__, 'is_reader_without_password' ], 10, 2 );
		\add_filter( 'wcs_view_subscription_actions', [ __CLASS__, 'view_subscription_actions' ], 12, 3 );
	}

	/**
	 * Check if WooCommerce Subscriptions Gifting is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'WC' ) && class_exists( 'WC_Subscriptions' ) && class_exists( 'WCS_Gifting' );
	}

	/**
	 * Ensure that only billing address fields enabled in Reader Revenue settings
	 * are required for new gift recipient accounts.
	 *
	 * See: https://github.com/woocommerce/woocommerce-subscriptions-gifting/blob/trunk/includes/class-wcsg-recipient-details.php#L275
	 *
	 * @param array $fields Address fields.
	 * @return array
	 */
	public static function new_recipient_fields( $fields ) {
		// Escape hatch to force required shipping address for virtual products.
		if ( apply_filters( 'wcsg_require_shipping_address_for_virtual_products', false ) ) { // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return $fields;
		}
		$required_fields = Donations::get_billing_fields();
		foreach ( $fields as $field_name => $field_config ) {
			if ( 'shipping_' !== substr( $field_name, 0, 9 ) && ! in_array( 'billing_' . $field_name, $required_fields, true ) ) {
				unset( $fields[ $field_name ] );
			}
		}
		return $fields;
	}

	/**
	 * Filters the default text shown for the gifting checkbox during checkout.
	 *
	 * @return string
	 */
	public static function default_gifting_checkbox_text() {
		return __( 'This purchase is a gift', 'newspack' );
	}

	/**
	 * New gift recipients don't yet have a password.
	 *
	 * @param bool $is_reader_without_password True if the reader has not set a password.
	 * @param int  $user_id The user ID.
	 *
	 * @return bool
	 */
	public static function is_reader_without_password( $is_reader_without_password, $user_id ) {
		// wcsg_update_account meta will force the user to update/set account information on login.
		$user_needs_account_update = get_user_meta( $user_id, 'wcsg_update_account', true );
		if ( ! empty( $user_needs_account_update ) ) {
			return 'true' === $user_needs_account_update;
		}
		return $is_reader_without_password;
	}

	/**
	 * Remove actions from the gifted subscription page.
	 *
	 * Only the purchaser of the subscription should see the actions.
	 *
	 * @param array            $actions      Actions.
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $user_id      The user ID.
	 *
	 * @return array
	 */
	public static function view_subscription_actions( $actions, $subscription, $user_id ) {
		if ( function_exists( 'is_account_page' ) && is_account_page() && $subscription->get_customer_id() !== $user_id ) {
			return [];
		}
		return $actions;
	}
}
WooCommerce_Subscriptions_Gifting::init();
