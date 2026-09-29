<?php
/**
 * WooCommerce Subscriptions zero-total renewal messaging.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Withholds renewal messaging that only makes sense for a subscription someone pays for.
 *
 * A free subscription still renews on every billing cycle: WooCommerce Subscriptions
 * creates a renewal order and completes it on the spot, because nothing is owed. That
 * keeps access from lapsing, which is the point — but it also mails the reader a receipt
 * for $0.00. Readers holding complimentary access never bought anything and are never
 * asked to pay, so the receipt is unexplainable to them.
 *
 * The upcoming-renewal reminder is stopped one step earlier, at scheduling. Subscriptions
 * has refused to send that email for a zero-total subscription since 7.4.0, so what the
 * notification filter removes is the scheduled action that would fire and do nothing.
 */
class Zero_Total_Renewals {
	/**
	 * Reader-facing renewal receipts withheld for a free renewal order.
	 *
	 * The completed-order receipt named in the report is one of several ways the same
	 * $0.00 message reaches a reader. Subscriptions auto-completes a renewal whose
	 * subtotal is zero, which is why a migrated complimentary subscription takes that
	 * path; a renewal that misses the auto-complete rests in `processing` and mails the
	 * receipt from there; and a gifted subscription mails its recipient under ids of its
	 * own.
	 */
	const RENEWAL_RECEIPT_EMAIL_IDS = [
		'customer_completed_renewal_order',
		'customer_processing_renewal_order',
		'recipient_completed_renewal_order',
		'gift_recipient_processing_renewal_order',
	];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		if ( ! WooCommerce_Subscriptions::is_active() ) {
			return;
		}

		add_filter( 'woocommerce_subscription_valid_customer_notification_types', [ __CLASS__, 'skip_renewal_notification' ], 10, 2 );

		foreach ( self::RENEWAL_RECEIPT_EMAIL_IDS as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, [ __CLASS__, 'is_renewal_receipt_enabled' ], 10, 2 );
		}
	}

	/**
	 * Whether an order or subscription costs the reader nothing.
	 *
	 * The recurring total on its own would also match a paying subscription carrying a
	 * limited 100% recurring coupon, which Subscriptions removes once its payment count
	 * runs out — withholding that reader's receipt would hide the renewal that starts
	 * charging them. The pre-discount subtotal is immune to a coupon, and is 0 on every
	 * subscription `migrate-manual-members` creates, so both have to be zero.
	 * `Teams_Migration::subscription_is_paid()` draws the same line. A reader comped with
	 * an unlimited recurring coupon rather than the CLI keeps their $0.00 receipts.
	 *
	 * @param \WC_Abstract_Order $order Order or subscription to test.
	 *
	 * @return bool
	 */
	private static function is_free( $order ) {
		return 0.0 >= (float) $order->get_total() && 0.0 >= (float) $order->get_subtotal();
	}

	/**
	 * Drop the upcoming-renewal reminder from the notifications scheduled for a free subscription.
	 *
	 * Trial-end and expiration notifications stay: those say the reader's access is
	 * about to change, which is true whether or not they pay for it.
	 *
	 * @param array            $notifications Notification types WooCommerce Subscriptions is about to schedule.
	 * @param \WC_Subscription $subscription  The subscription being scheduled.
	 *
	 * @return array
	 */
	public static function skip_renewal_notification( $notifications, $subscription ) {
		if ( ! is_array( $notifications ) || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return $notifications;
		}

		if ( ! self::is_free( $subscription ) ) {
			return $notifications;
		}

		return array_values( array_diff( $notifications, [ 'next_payment' ] ) );
	}

	/**
	 * Whether a renewal receipt should reach the reader.
	 *
	 * @param bool           $enabled Whether the email is enabled.
	 * @param \WC_Order|null $order   The renewal order. WooCommerce also evaluates this
	 *                                filter with no order in hand — on the email settings
	 *                                screen, for one — where the publisher's own setting
	 *                                is the only honest answer.
	 *
	 * @return bool
	 */
	public static function is_renewal_receipt_enabled( $enabled, $order = null ) {
		if ( ! $enabled || ! is_a( $order, 'WC_Order' ) ) {
			return $enabled;
		}

		return ! self::is_free( $order );
	}
}
