<?php
/**
 * WooCommerce Subscriptions meta class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Subscriptions_Meta {
	const CANCELLATION_REASON_META_KEY             = 'newspack_subscriptions_cancellation_reason';
	const CANCELLATION_REASON_ADMIN_CANCELLED      = 'manually-cancelled';
	const CANCELLATION_REASON_ADMIN_PENDING_CANCEL = 'manually-pending-cancel';
	const CANCELLATION_REASON_USER_CANCELLED       = 'user-cancelled';
	const CANCELLATION_REASON_USER_PENDING_CANCEL  = 'user-pending-cancel';
	const CANCELLATION_REASON_EXPIRED              = 'expired';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		if ( ! WooCommerce_Subscriptions::is_enabled() ) {
			return;
		}

		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'maybe_record_cancelled_subscription_meta' ), 10, 3 );
	}

	/**
	 * Record woo custom field for cancelled subscriptions.
	 *
	 * @param WC_Subscription $subscription  The subscription object.
	 * @param string          $to_status     The status the subscription is changing to.
	 * @param string          $from_status   The status the subscription is changing from.
	 */
	public static function maybe_record_cancelled_subscription_meta( $subscription, $to_status, $from_status ) {
		// We only care about active, cancelled, expired, and pending statuses.
		if ( ! in_array( $to_status, [ 'active', 'cancelled', 'expired', 'pending-cancel' ], true ) || in_array( $from_status, [ 'cancelled', 'expired' ], true ) ) {
			return;
		}

		remove_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'maybe_record_cancelled_subscription_meta' ) );

		$meta_value = $subscription->get_meta( self::CANCELLATION_REASON_META_KEY, true );
		if ( 'active' === $to_status && $meta_value ) {
			$subscription->delete_meta_data( self::CANCELLATION_REASON_META_KEY );
			$subscription->save();
		}
		if ( 'cancelled' === $to_status ) {
			if ( self::CANCELLATION_REASON_USER_PENDING_CANCEL === $meta_value ) {
				$subscription->update_meta_data( self::CANCELLATION_REASON_META_KEY, self::CANCELLATION_REASON_USER_CANCELLED );
			} elseif ( self::CANCELLATION_REASON_ADMIN_PENDING_CANCEL === $meta_value ) {
				$subscription->update_meta_data( self::CANCELLATION_REASON_META_KEY, self::CANCELLATION_REASON_ADMIN_CANCELLED );
			} else {
				$meta_value = is_admin() ? self::CANCELLATION_REASON_ADMIN_CANCELLED : self::CANCELLATION_REASON_USER_CANCELLED;
				$subscription->update_meta_data( self::CANCELLATION_REASON_META_KEY, $meta_value );
			}
			$subscription->save();
		}
		if ( 'expired' === $to_status ) {
			$subscription->update_meta_data( self::CANCELLATION_REASON_META_KEY, self::CANCELLATION_REASON_EXPIRED );
			$subscription->save();
		}
		if ( 'pending-cancel' === $to_status ) {
			$meta_value = is_admin() ? self::CANCELLATION_REASON_ADMIN_PENDING_CANCEL : self::CANCELLATION_REASON_USER_PENDING_CANCEL;
			$subscription->update_meta_data( self::CANCELLATION_REASON_META_KEY, $meta_value );
			$subscription->save();
		}

		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'maybe_record_cancelled_subscription_meta' ), 10, 3 );
	}
}
