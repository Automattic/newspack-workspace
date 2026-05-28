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
		 * Modal Checkout Interation: Completed Order.
		 */
		\Newspack\Data_Events::register_listener(
			'woocommerce_checkout_order_processed',
			'modal_checkout_interaction',
			[ __CLASS__, 'order_status_completed' ]
		);
	}

	/**
	 * Send data to GA4.
	 *
	 * @param string    $order_id Order's ID.
	 * @param array     $posted_data Posted Data.
	 * @param \WC_Order $order Order object.
	 */
	public static function order_status_completed( $order_id, $posted_data, $order ) {
		// Check if in a modal checkout; if no, bail.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_modal_checkout = ( isset( $_REQUEST['modal_checkout'] ) ? true : false );
		if ( ! $is_modal_checkout ) {
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
