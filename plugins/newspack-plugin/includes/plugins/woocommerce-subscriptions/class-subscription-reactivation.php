<?php
/**
 * WooCommerce Subscriptions admin reactivation.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an admin undo an expiry or cancellation from the subscription edit screen.
 *
 * WooCommerce Subscriptions treats Cancelled and Expired as final, so its status
 * dropdown never offers Active for them. Rebuilding the subscription by hand instead
 * loses its group members, who are linked to the original subscription's ID.
 *
 * Only manual-renewal subscriptions qualify. Reactivating an auto-renewing one would
 * start charging its saved payment method again.
 */
class Subscription_Reactivation {
	/**
	 * Key of the entry in the "Subscription actions" dropdown.
	 */
	const ORDER_ACTION = 'newspack_reactivate_subscription';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		if ( ! WooCommerce_Subscriptions::is_active() ) {
			return;
		}

		add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_action' ], 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ORDER_ACTION, [ __CLASS__, 'reactivate' ] );
	}

	/**
	 * Whether a subscription can be reactivated.
	 *
	 * Reads the stored renewal setting rather than is_manual(), which is also true on
	 * a staging clone or while the gateway is unavailable. Either would offer the
	 * action on a subscription that charges again once the gateway returns.
	 *
	 * @param mixed $subscription The object to check.
	 * @return bool
	 */
	public static function can_reactivate( $subscription ) {
		return $subscription instanceof \WC_Subscription
			&& $subscription->has_status( [ 'cancelled', 'expired' ] )
			&& $subscription->get_requires_manual_renewal();
	}

	/**
	 * Offer reactivation in the "Subscription actions" dropdown.
	 *
	 * @param array          $actions Order actions, keyed by action.
	 * @param \WC_Order|null $order   The order or subscription being edited.
	 * @return array
	 */
	public static function add_order_action( $actions, $order = null ) {
		if ( self::can_reactivate( $order ) ) {
			$actions[ self::ORDER_ACTION ] = __( 'Reactivate and clear end date', 'newspack-plugin' );
		}
		return $actions;
	}

	/**
	 * Make the subscription Active with no end date.
	 *
	 * WooCommerce runs order actions after the subscription's status and schedule
	 * boxes save, so the form's old status and end date can't overwrite this.
	 *
	 * @param \WC_Subscription $subscription The subscription being edited.
	 */
	public static function reactivate( $subscription ) {
		// The dropdown hides the action, but the form can still submit it.
		if ( ! self::can_reactivate( $subscription ) ) {
			return;
		}

		// Subscriptions restores dates on reactivation only from Pending cancellation.
		$subscription->delete_date( 'end' );
		$subscription->delete_date( 'cancelled' );

		// update_status() refuses Active from an ended status, where set_status() doesn't
		// check. Flagging the change as manual credits the admin in the note it adds.
		$subscription->set_status( 'active', __( 'Reactivated with no end date by admin action.', 'newspack-plugin' ), true );
		$subscription->save();

		// Cancelling can demote the customer's role, and only update_status() restores it.
		wcs_make_user_active( $subscription->get_user_id() );
	}
}
