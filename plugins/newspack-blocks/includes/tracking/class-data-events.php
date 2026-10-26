<?php
/**
 * Newspack Blocks Tracking Data Events Integration.
 *
 * @package Newspack
 */

namespace Newspack_Blocks\Tracking;

use Newspack_Blocks\Modal_Checkout;
use Newspack_Blocks\Modal_Checkout\Checkout_Data;

/**
 * Tracking Data Events Class.
 */
final class Data_Events {

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION_SUCCESS = 'form_submission_success';

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION_FAILURE = 'form_submission_failure';

	/**
	 * Order meta key recording that the order's modal checkout interaction was
	 * dispatched. An order can reach a paid status more than once (processing
	 * then completed, or completed again after a refund), and each purchase
	 * must be reported once.
	 */
	const INTERACTION_DISPATCHED_META_KEY = '_newspack_modal_checkout_interaction_dispatched';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'register_listeners' ] );

		// Inert without WooCommerce Subscriptions: nothing applies these filters.
		foreach ( [ 'subscription', 'parent', 'renewal_order', 'resubscribe_order' ] as $copy_type ) {
			add_filter( "wc_subscriptions_{$copy_type}_data", [ __CLASS__, 'exclude_order_meta_from_subscription_copy' ] );
		}
	}

	/**
	 * Keep the modal checkout marker and the dispatched flag out of the order
	 * meta WooCommerce Subscriptions copies.
	 *
	 * Subscriptions copies a parent order's meta to the subscription, and the
	 * subscription's meta to every renewal and resubscribe order. A copied
	 * marker would report each renewal as a new modal checkout purchase, and a
	 * copied flag would suppress a resubscribe that really was one.
	 *
	 * @param array $data Meta to copy, keyed by meta key.
	 *
	 * @return array
	 */
	public static function exclude_order_meta_from_subscription_copy( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		unset( $data[ Modal_Checkout::ORDER_META_KEY ], $data[ self::INTERACTION_DISPATCHED_META_KEY ] );
		return $data;
	}

	/**
	 * Register listeners.
	 */
	public static function register_listeners() {
		if ( ! method_exists( 'Newspack\Data_Events', 'register_handler' ) ) {
			return;
		}

		/**
		 * Modal Checkout Interaction: Order Paid.
		 *
		 * The purchase is reported when the order first reaches a paid status,
		 * not when it is created: both checkout pipelines create the order, fire
		 * their order-processed hooks, and only then try the payment, on every
		 * submission. A paid status also arrives from places with no modal
		 * signal in the request (a 3D Secure return, a gateway webhook, an
		 * admin marking a cheque paid), so modal origin is read from the order.
		 */
		\Newspack\Data_Events::register_listener(
			'woocommerce_order_status_changed',
			'modal_checkout_interaction',
			[ __CLASS__, 'order_status_changed' ]
		);
	}

	/**
	 * Order status change listener callback.
	 *
	 * Dispatches when a modal checkout order is paid for the first time. A
	 * declined payment never gets there, and a payment retried on the same
	 * order dispatches once, when it succeeds.
	 *
	 * @param int            $order_id    Order ID.
	 * @param string         $status_from Previous status.
	 * @param string         $status_to   New status.
	 * @param \WC_Order|null $order       Order object. Anything else is ignored
	 *                                    rather than trusted.
	 *
	 * @return array|void The event payload; void suppresses the dispatch.
	 */
	public static function order_status_changed( $order_id, $status_from = '', $status_to = '', $order = null ) {
		if ( ! is_a( $order, 'WC_Order' ) || ! $order->is_paid() ) {
			return;
		}
		if ( ! Modal_Checkout::is_modal_checkout_order( $order ) || $order->get_meta( self::INTERACTION_DISPATCHED_META_KEY ) ) {
			return;
		}

		$data = self::get_modal_checkout_interaction_data( $order );
		if ( empty( $data ) ) {
			return;
		}

		$order->update_meta_data( self::INTERACTION_DISPATCHED_META_KEY, time() );
		$order->save_meta_data();

		return $data;
	}

	/**
	 * Build the modal checkout interaction payload for an order.
	 *
	 * @param \WC_Order $order Order object.
	 *
	 * @return array|void The event payload; void suppresses the dispatch.
	 */
	private static function get_modal_checkout_interaction_data( $order ) {
		$data = \Newspack\Data_Events\Utils::get_order_data( $order->get_id() );

		// A renewal is not a modal checkout purchase, though WooCommerce
		// Subscriptions can copy the order marker onto it from its subscription.
		if ( empty( $data ) || ! empty( $data['is_renewal'] ) ) {
			return;
		}

		$product_id = is_array( $data['platform_data']['product_id'] ) ? $data['platform_data']['product_id'][0] : $data['platform_data']['product_id'];

		$data['action']       = self::FORM_SUBMISSION_SUCCESS;
		$data['action_type']  = Checkout_Data::get_action_type( $product_id, $order );
		$data['product_id']   = $product_id;
		$data['product_type'] = Checkout_Data::get_product_type( $product_id );
		$data['recurrence']   = Checkout_Data::get_purchase_recurrence( $product_id );

		return $data;
	}
}
Data_Events::init();
