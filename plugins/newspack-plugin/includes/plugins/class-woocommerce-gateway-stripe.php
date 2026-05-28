<?php
/**
 * WooCommerce Gateway Stripe integration class.
 * https://wordpress.org/plugins/woocommerce-gateway-stripe
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class WooCommerce_Gateway_Stripe {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'wc_stripe_settings', [ __CLASS__, 'disable_express_checkout_by_default' ] );
		add_filter( 'pre_update_option_woocommerce_stripe_settings', [ __CLASS__, 'disable_link_by_default' ], 11, 2 );

		add_filter( 'wc_stripe_generate_payment_request', [ __CLASS__, 'add_payment_request_metadata' ], 10, 2 );
		add_filter( 'wc_stripe_intent_metadata', [ __CLASS__, 'add_intent_metadata' ], 10, 2 );

		/*
		 * NPPM-2761: Prevent Stripe plugin from re-stamping _stripe_customer_id on
		 * WooPayments subscriptions during renewal.
		 *
		 * Three complementary guards cover the write paths used by the Stripe plugin:
		 *
		 *   1. update_post_metadata filter — intercepts raw update_post_meta() calls to
		 *      wp_postmeta (the legacy and data-sync storage paths). Only registered when
		 *      both WCS and the Stripe plugin are active to avoid hook overhead on sites
		 *      where neither dependency is present. The class_exists/function_exists check
		 *      is deferred to plugins_loaded because WordPress loads plugins alphabetically:
		 *      newspack-plugin loads before woocommerce-gateway-stripe and woocommerce-
		 *      subscriptions, so the symbols are not yet defined at Newspack include time.
		 *      NOTE: this filter fires only for wp_postmeta writes; it does NOT intercept
		 *      HPOS-only writes to wc_orders_meta, which is why guard #2 is also required.
		 *
		 *   2. woocommerce_before_subscription_object_save action — strips the meta from
		 *      in-memory state before WC CRUD commits to whichever store is authoritative,
		 *      covering both HPOS and legacy storage paths.
		 *
		 *   3. wcs_renewal_order_created filter — clears the meta from the freshly-created
		 *      renewal order (and the parent subscription in-memory) before payment
		 *      processing begins, self-healing stale values that pre-date the fix.
		 */
		// Priority 20 ensures this runs after WC_Stripe and woocommerce-subscriptions
		// have completed their own plugins_loaded callbacks (both register at default priority 10).
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_register_post_meta_guard' ], 20 );
		add_action( 'woocommerce_before_subscription_object_save', [ __CLASS__, 'maybe_strip_stripe_customer_id_before_save' ] );
		add_filter( 'wcs_renewal_order_created', [ __CLASS__, 'clear_stripe_customer_id_on_renewal' ], 10, 2 );
	}

	/**
	 * Conditionally register the update_post_metadata filter once all plugins
	 * have loaded their main files. See NPPM-2761 init() comment block for the
	 * load-order rationale.
	 */
	public static function maybe_register_post_meta_guard() {
		if ( class_exists( 'WC_Stripe' ) && function_exists( 'wcs_is_subscription' ) ) {
			add_filter( 'update_post_metadata', [ __CLASS__, 'maybe_block_stripe_customer_id_post_meta_update' ], 10, 3 );
		}
	}

	/**
	 * Add metadata to a Stripe transaction.
	 *
	 * @param array    $post_data Payment request data.
	 * @param WC_Order $order Order being processed.
	 */
	public static function add_payment_request_metadata( $post_data, $order ) {
		if ( isset( $post_data['metadata'] ) ) {
			$post_data['metadata'] = self::add_intent_metadata(
				$post_data['metadata'],
				$order
			);
		}
		return $post_data;
	}

	/**
	 * Add metadata to a Stripe transaction.
	 *
	 * @param array    $metadata Array of keyed metadata values.
	 * @param WC_Order $order Order being processed.
	 *
	 * @return array Array of keyed metadata values.
	 */
	public static function add_intent_metadata( $metadata, $order ) {
		// Skip orders with multiple products.
		if ( $order->get_item_count() > 1 ) {
			return $metadata;
		}

		$order_item = array_values( $order->get_items() )[0];
		if ( ! $order_item ) {
			return $metadata;
		}
		$product_id = $order_item->get_product_id();

		// Product name.
		$metadata['Product'] = $order_item->get_name();

		// Transaction type.
		$metadata['Transaction Type'] = 'Regular Purchase';
		if ( Donations::is_donation_product( $product_id ) ) {
			$metadata['Transaction Type'] = 'Donation';
		}
		if ( function_exists( 'wcs_order_contains_parent' ) && \wcs_order_contains_parent( $order ) ) {
			$metadata['Transaction Type'] = 'Subscription';
		}
		if ( function_exists( 'wcs_order_contains_subscription' ) && \wcs_order_contains_subscription( $order, 'renewal' ) ) {
			$metadata['Transaction Type'] = 'Subscription Renewal';
		}

		// Membership type (name of the membership plan associated with the product ID).
		$plan = null;
		// Try to get the plan name from the `woocommerce-memberships-for-teams` plugin.
		if (
			method_exists( '\SkyVerge\WooCommerce\Memberships\Teams\Product', 'get_membership_plan_id' ) &&
			function_exists( 'wc_memberships_get_membership_plan' )
		) {
			$plan_id = \SkyVerge\WooCommerce\Memberships\Teams\Product::get_membership_plan_id( wc_get_product( $product_id ) );
			if ( $plan_id ) {
				$plan = \wc_memberships_get_membership_plan( $plan_id );
			}
		}
		// Otherwise, get the plan name from the `woocommerce-memberships` plugin.
		if ( ! $plan && function_exists( 'wc_memberships_get_membership_plans' ) ) {
			$plans = array_filter(
				\wc_memberships_get_membership_plans(),
				function( $plan ) use ( $product_id ) {
					$product_ids = $plan->get_product_ids();
					return in_array( $product_id, $product_ids );
				}
			);
			if ( ! empty( $plans ) ) {
				$plan = array_values( $plans )[0];
			}
		}
		if ( $plan ) {
			$metadata['Membership Type'] = $plan->get_name();
		}

		// Add subscription data.
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) && function_exists( 'wcs_order_contains_renewal' ) ) {
			$related_subscriptions = \wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'any' ] );
			if ( ! empty( $related_subscriptions ) ) {
				// In theory, there should be just one subscription per renewal.
				$subscription = reset( $related_subscriptions );
				// Add subscription ID to any renewal.
				$metadata['subscription_id'] = $subscription->get_id();
				// `subscription_status` is redundant with `Transaction Type` for legacy reasons.
				if ( \wcs_order_contains_renewal( $order ) ) {
					$metadata['subscription_status'] = 'renewed';
				} else {
					$metadata['subscription_status'] = 'created';
				}
			}
		}

		return $metadata;
	}

	/**
	 * Disable Stripe Express Checkout feature flag.
	 *
	 * @param array $flag Stripe Express Checkout feature flag.
	 * @param array $old_settings Old Stripe Express Checkout feature flag.
	 * @return string
	 */
	public static function disable_express_checkout_feature_flag( $flag, $old_settings ) {
		/**
		 * If the Stripe Express Checkout feature flag is empty, it means this is a new install.
		 * Save the settings as 'no' to prevent the Stripe Express Checkout from being enabled.
		 */
		if ( empty( $old_settings ) && 'yes' === $flag ) {
			$flag = 'no';
		}
		return $flag;
	}

	/**
	 * Disable Apple/Google Pay by default.
	 *
	 * @param array $settings Stripe settings config.
	 * @return array
	 */
	public static function disable_express_checkout_by_default( $settings ) {
		if ( isset( $settings['payment_request']['default'] ) && 'yes' === $settings['payment_request']['default'] ) {
			$settings['payment_request']['default'] = 'no';
		}
		return $settings;
	}

	/**
	 * Disable Stripe Link by default. This is a workaround for undoing enabling link by default for new installs.
	 *
	 * @param string $settings The new value of the option.
	 * @param string $old_settings The old value of the option.
	 */
	public static function disable_link_by_default( $settings, $old_settings ) {
		if (
			! isset( $old_settings['upe_checkout_experience_accepted_payments'] ) && // Old setting is empty, so this is a new install.
			isset( $settings['upe_checkout_experience_accepted_payments'] ) && // New setting has been set.
			is_array( $settings['upe_checkout_experience_accepted_payments'] ) && // New setting is an array.
			in_array( 'link', $settings['upe_checkout_experience_accepted_payments'], true ) // New setting contains 'link'.
		) {
			$settings['upe_checkout_experience_accepted_payments'] = array_diff( $settings['upe_checkout_experience_accepted_payments'], [ 'link' ] );
		}
		return $settings;
	}

	/**
	 * Check whether a subscription is managed by a WooPayments gateway.
	 *
	 * WooPayments registers its main gateway as 'woocommerce_payments'. Split UPE
	 * local-payment-method variants follow the pattern 'woocommerce_payments_{variant}'
	 * (e.g. 'woocommerce_payments_sepa', 'woocommerce_payments_klarna').
	 *
	 * This is the single decision point for all three _stripe_customer_id guards.
	 * Centralising the predicate here ensures a future change to gateway IDs or
	 * polarity only needs to be made in one place.
	 *
	 * @param \WC_Subscription $subscription The subscription to check.
	 * @return bool True when the subscription's payment method is a WooPayments gateway.
	 */
	private static function is_woopayments_subscription( \WC_Subscription $subscription ): bool {
		return str_starts_with( (string) $subscription->get_payment_method(), 'woocommerce_payments' );
	}

	/**
	 * Block raw update_post_meta() calls that would re-stamp _stripe_customer_id
	 * onto a WooPayments subscription.
	 *
	 * This covers the legacy path in abstract-wc-stripe-payment-gateway.php that
	 * calls update_post_meta( $subscription_id, '_stripe_customer_id', ... )
	 * unconditionally, without checking the subscription's payment method.
	 *
	 * Returning a non-null value from the update_{meta_type}_metadata filter
	 * short-circuits the write.
	 *
	 * Note: this filter fires only for wp_postmeta writes (legacy and data-sync paths).
	 * It does NOT intercept writes to wc_orders_meta on HPOS-only sites; those are
	 * covered by the woocommerce_before_subscription_object_save guard instead.
	 *
	 * @param mixed  $check     The value to return. Null allows the write; true blocks it.
	 * @param int    $object_id Post ID being written to.
	 * @param string $meta_key  Meta key being written.
	 * @return mixed Null to allow, true to block.
	 */
	public static function maybe_block_stripe_customer_id_post_meta_update( $check, $object_id, $meta_key ) {
		if ( null !== $check ) {
			return $check; // Respect earlier filter short-circuits.
		}
		if ( '_stripe_customer_id' !== $meta_key ) {
			return $check;
		}
		// Use wcs_is_subscription() rather than get_post_type() here: on HPOS sites with
		// data sync disabled, subscriptions are not stored in wp_posts and get_post_type()
		// would return false, silently bypassing this guard.
		// Both wcs_is_subscription() and wcs_get_subscription() are guaranteed to exist
		// because this filter is only registered when function_exists( 'wcs_is_subscription' ).
		if ( ! \wcs_is_subscription( $object_id ) ) {
			return $check;
		}
		$subscription = \wcs_get_subscription( $object_id );
		if ( $subscription && self::is_woopayments_subscription( $subscription ) ) {
			return true; // Short-circuit: prevent the write.
		}
		return $check;
	}

	/**
	 * Strip _stripe_customer_id from a WooPayments subscription's in-memory meta
	 * before it is saved via WC CRUD.
	 *
	 * This covers the three WC CRUD paths in the Stripe plugin that call
	 * $subscription->update_meta_data( '_stripe_customer_id', ... ) + save()
	 * without checking the subscription's payment method:
	 *  - maybe_update_source_on_subscription_order() (trait:628)
	 *  - update_failing_payment_method() (trait:693)
	 *  - set_customer_id_for_subscription() (upe-gateway:3021)
	 *
	 * Fires on the woocommerce_before_subscription_object_save action, which is
	 * dispatched by WC_Abstract_Order::save() before the data store write.
	 *
	 * @param \WC_Subscription $subscription The subscription about to be saved.
	 */
	public static function maybe_strip_stripe_customer_id_before_save( $subscription ) {
		if ( ! self::is_woopayments_subscription( $subscription ) ) {
			return;
		}
		$subscription->delete_meta_data( '_stripe_customer_id' );
	}

	/**
	 * Clear stale _stripe_customer_id from a renewal order and its parent
	 * subscription when the subscription uses WooPayments.
	 *
	 * Hooked on wcs_renewal_order_created, which is an apply_filters hook in WCS.
	 * Fires after the renewal order is created but before payment processing
	 * begins, intercepting the window where WooPayments reads _stripe_customer_id
	 * from the renewal order and would encounter an invalid Stripe customer.
	 *
	 * For the renewal order, $renewal_order->save() writes to whichever store is
	 * authoritative (HPOS or wp_posts). The delete_post_meta() call is a
	 * belt-and-braces scrub for legacy CPT rows and data-sync rows; on HPOS-only
	 * sites (data sync disabled) it is intentionally a no-op against the canonical
	 * wc_orders_meta row, which is already cleaned by save().
	 *
	 * @param \WC_Order        $renewal_order The renewal order just created.
	 * @param \WC_Subscription $subscription  The parent subscription.
	 * @return \WC_Order The renewal order, passed through for the filter chain.
	 */
	public static function clear_stripe_customer_id_on_renewal( $renewal_order, $subscription ) {
		if ( ! self::is_woopayments_subscription( $subscription ) ) {
			return $renewal_order;
		}

		// Clear from the renewal order — this is what WooPayments reads during payment processing.
		if ( $renewal_order->meta_exists( '_stripe_customer_id' ) ) {
			$renewal_order->delete_meta_data( '_stripe_customer_id' );
			$renewal_order->save();
			delete_post_meta( $renewal_order->get_id(), '_stripe_customer_id' );
		}

		// Clean the subscription's in-memory meta and wp_postmeta (root source of the stale value).
		// We intentionally do NOT call $subscription->save() here: wcs_renewal_order_created is an
		// apply_filters hook, and triggering a full WC CRUD save on the subscription mid-filter can
		// cause unexpected side-effects (e.g. status transitions, retry scheduling). The
		// woocommerce_before_subscription_object_save guard will strip _stripe_customer_id from
		// HPOS when the subscription is next saved naturally by WCS.
		//
		// Stale-window caveat (HPOS-only sites, data sync disabled): delete_post_meta() only
		// touches wp_postmeta, which is not the canonical store on these sites. Until the next
		// natural save, a freshly-loaded subscription object would still return the stale value
		// from wc_orders_meta. This is acceptable: the only consumer known to cause NPPM-2761
		// reads _stripe_customer_id from the renewal ORDER (fully cleaned above), not from the
		// parent subscription.
		if ( $subscription->meta_exists( '_stripe_customer_id' ) ) {
			$subscription->delete_meta_data( '_stripe_customer_id' );
			delete_post_meta( $subscription->get_id(), '_stripe_customer_id' );
		}

		return $renewal_order;
	}
}
WooCommerce_Gateway_Stripe::init();
