<?php
/**
 * Custom template for the single Subscription page.
 * Shows the details of a particular subscription on the account page.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

wc_print_notices();

$is_group_member_subscription = Group_Subscription::is_group_subscription( $subscription )
	&& Group_Subscription::user_is_member( get_current_user_id(), $subscription );
$hide_totals                  = $is_group_member_subscription || Zero_Total_Renewals::is_free( $subscription );
// A $0 subscription can still have paid orders behind it, such as a sign-up fee or a
// switch from a paid plan, so only a complimentary one drops its billing history.
$hide_history = $is_group_member_subscription || Complimentary_Access::is_complimentary_order( $subscription );

/**
 * Newspack: Render the custom subscription page header.
 *
 * @param WC_Subscription $subscription A subscription object
 */
do_action( 'newspack_subscription_header', $subscription );

// Group members and readers on a $0 subscription don't pay for it, so the
// financial totals are noise. Keep the details table, which surfaces a group's
// manager to contact.
if ( ! $hide_totals ) {
	/**
	 * Gets subscription totals table template.
	 *
	 * @param WC_Subscription $subscription A subscription object
	 */
	do_action( 'woocommerce_subscription_totals_table', $subscription );
}

/**
 * Gets subscription details table template.
 * Newspack: Does not show action buttons, which are moved to the header template.
 *
 * @param WC_Subscription $subscription A subscription object
 */
do_action( 'woocommerce_subscription_details_table', $subscription );

if ( ! $hide_history ) {
	/**
	 * Newspack: Related Orders table becomes the "Billing History" table.
	 */
	do_action( 'woocommerce_subscription_details_after_subscription_table', $subscription );
}

/*
 * Newspack: No customer information template (order/order-details-customer.php).
 */
?>

<div class="clear"></div>
