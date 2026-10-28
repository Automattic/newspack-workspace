<?php
/**
 * Subscriptions Change Payment Gateway integration for the Modal Checkout.
 *
 * @package Newspack
 */

namespace Newspack_Blocks\Modal_Checkout;

use Newspack_Blocks\Modal_Checkout;


/**
 * Change Payment Gateway integration class.
 */
final class Change_Payment_Gateway {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'remove_filters' ] );
		add_filter( 'newspack_modal_checkout_success_text', [ __CLASS__, 'get_success_text' ] );
		add_filter( 'newspack_modal_checkout_data', [ __CLASS__, 'get_checkout_data' ] );
	}

	/**
	 * Remove some WooCommerce Subscriptions filters for the modal checkout.
	 */
	public static function remove_filters() {
		if ( ! Modal_Checkout::is_modal_checkout() ) {
			return;
		}

		// Remove the custom return URL filter so it renders modal checkout's
		// "Thank You" template.
		remove_filter( 'woocommerce_get_return_url', 'WC_Subscriptions_Change_Payment_Gateway::get_return_url', 11 );
	}

	/**
	 * Use WooCommerce Subscriptions' success notice for the change payment
	 * method form.
	 *
	 * @param string $text The success text.
	 *
	 * @return string The success text.
	 */
	public static function get_success_text( $text ) {
		if ( ! isset( $_GET['action_type'] ) || 'change_payment_method' !== $_GET['action_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $text;
		}

		$notices = wc_get_notices( 'success' );
		wc_clear_notices();

		return implode(
			'<br>',
			array_map(
				function( $notice ) {
					return $notice['notice'];
				},
				$notices
			)
		);
	}

	/**
	 * Modify the checkout data for the change payment method action.
	 *
	 * @param array $data The checkout data.
	 *
	 * @return array The checkout data.
	 */
	public static function get_checkout_data( $data ) {
		if ( $data['action_type'] !== 'change_payment_method' ) {
			return $data;
		}

		// The original order may contain gate or prompt post IDs, which are not
		// relevant for this action and should be removed to avoid confusion.
		unset( $data['gate_post_id'] );
		unset( $data['prompt_post_id'] );

		return $data;
	}
}
Change_Payment_Gateway::init();
