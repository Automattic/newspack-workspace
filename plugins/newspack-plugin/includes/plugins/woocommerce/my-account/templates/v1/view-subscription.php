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

/**
 * Newspack: Render the custom subscription page header.
 *
 * @param WC_Subscription $subscription A subscription object
 */
do_action( 'newspack_subscription_header', $subscription );

/**
 * Gets subscription totals table template.
 * Newspack: Comes before the Subscription Details table.
 *
 * @param WC_Subscription $subscription A subscription object
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
 */
do_action( 'woocommerce_subscription_totals_table', $subscription );

/**
 * Gets subscription details table template.
 * Newspack: Does not show action buttons, which are moved to the header template.
 *
 * @param WC_Subscription $subscription A subscription object
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
 */
do_action( 'woocommerce_subscription_details_table', $subscription );

/**
 * Newspack: Related Orders table becomes the "Billing History" table.
 */
do_action( 'woocommerce_subscription_details_after_subscription_table', $subscription );

/*
 * Newspack: No customer information template (order/order-details-customer.php).
 */
?>

<div class="clear"></div>
