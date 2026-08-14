<?php
/**
 * Newspack Blocks Tracking Data Events Integration.
 *
 * @package Newspack
 */

namespace Newspack_Blocks\Tracking;

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
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'register_listeners' ] );
	}

	/**
	 * Register listeners.
	 */
	public static function register_listeners() {
		if ( ! method_exists( 'Newspack\Data_Events', 'register_handler' ) ) {
			return;
		}

		/**
		 * Modal Checkout Interaction: Completed Order.
		 *
		 * Both WooCommerce checkout pipelines feed the same Data Events action:
		 * classic checkout fires woocommerce_checkout_order_processed, while
		 * Store API checkouts (the transport express wallets such as Apple Pay
		 * and Google Pay use) fire woocommerce_store_api_checkout_order_processed
		 * instead. Each hook gets a callback matching its exact argument shape.
		 */
		\Newspack\Data_Events::register_listener(
			'woocommerce_checkout_order_processed',
			'modal_checkout_interaction',
			[ __CLASS__, 'order_status_completed' ]
		);
		\Newspack\Data_Events::register_listener(
			'woocommerce_store_api_checkout_order_processed',
			'modal_checkout_interaction',
			[ __CLASS__, 'store_api_order_processed' ]
		);
	}

	/**
	 * Classic checkout listener callback.
	 *
	 * $posted_data and $order are accepted to match the classic hook's
	 * signature and are deliberately unused: the payload is built from the
	 * order ID alone, so both checkout pipelines share one builder.
	 *
	 * @param int            $order_id Order's ID.
	 * @param array|null     $posted_data Posted Data.
	 * @param \WC_Order|null $order Order object.
	 *
	 * @return array|void The event payload; void suppresses the dispatch.
	 */
	public static function order_status_completed( $order_id, $posted_data = null, $order = null ) {
		return self::get_modal_checkout_interaction_data( $order_id );
	}

	/**
	 * Store API checkout listener callback.
	 *
	 * The Store API hook passes a single \WC_Order, unlike the classic hook's
	 * three arguments; only a scalar order ID crosses into the shared payload
	 * builder, so neither callback can misread the other hook's argument list.
	 *
	 * @param \WC_Order $order Order object.
	 *
	 * @return array|void The event payload; void suppresses the dispatch.
	 */
	public static function store_api_order_processed( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		return self::get_modal_checkout_interaction_data( $order->get_id() );
	}

	/**
	 * Build the modal checkout interaction payload for an order, or bail when
	 * the request is not modal-origin.
	 *
	 * Origin detection covers all three modal request shapes: the
	 * modal_checkout request param (classic card), express_payment_type in
	 * $_POST (classic express), and the modal referer carried by Store API
	 * JSON submissions.
	 *
	 * @param int $order_id Order's ID.
	 *
	 * @return array|void The event payload; void suppresses the dispatch.
	 */
	private static function get_modal_checkout_interaction_data( $order_id ) {
		if ( ! \Newspack_Blocks\Modal_Checkout::is_modal_checkout_origin() ) {
			return;
		}

		$data = \Newspack\Data_Events\Utils::get_order_data( $order_id );
		if ( empty( $data ) ) {
			return;
		}

		$product_id = is_array( $data['platform_data']['product_id'] ) ? $data['platform_data']['product_id'][0] : $data['platform_data']['product_id'];

		$data['action']       = self::FORM_SUBMISSION_SUCCESS;
		$data['action_type']  = Checkout_Data::get_action_type( $product_id );
		$data['product_id']   = $product_id;
		$data['product_type'] = Checkout_Data::get_product_type( $product_id );
		$data['recurrence']   = Checkout_Data::get_purchase_recurrence( $product_id );

		return $data;
	}
}
Data_Events::init();
