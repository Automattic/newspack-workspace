<?php
/**
 * WooCommerce Subscriptions Confirmation class for FTC compliance.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Subscriptions_Confirmation {

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		if ( ! class_exists( '\Newspack\WooCommerce_Subscriptions' ) || ! WooCommerce_Subscriptions::is_active() ) {
			return;
		}
		add_action( 'woocommerce_review_order_before_submit', [ __CLASS__, 'add_subscription_confirmation_checkboxes' ] );
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_subscription_confirmation_checkboxes' ] );
	}

	/**
	 * Check if the cart contains any subscription products.
	 *
	 * @return boolean Returns whether or not the cart contains a subscription product.
	 */
	private static function has_subscription_in_cart() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( \WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Make sure the subscription confirmation or Terms & Conditions checkboxes are checked before checkout can be completed.
	 */
	public static function validate_subscription_confirmation_checkboxes() {
		// Skip validation if we don't have a subscription in the cart, or if we're on the first screen of the modal checkout.
		if ( ! self::has_subscription_in_cart() || ( isset( $_POST['is_validation_only'] ) && '1' === $_POST['is_validation_only'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( Reader_Activation::is_subscription_confirmation_enabled() ) {
			if ( ! isset( $_POST['newspack_subscription_confirmation'] ) || '1' !== $_POST['newspack_subscription_confirmation'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				wc_add_notice( esc_html__( 'You must agree to the subscription terms before proceeding.', 'newspack-plugin' ), 'error' );
			}
		}

		if ( Reader_Activation::is_terms_confirmation_enabled() ) {
			if ( ! isset( $_POST['newspack_subscription_terms_confirmation'] ) || '1' !== $_POST['newspack_subscription_terms_confirmation'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				wc_add_notice( esc_html__( 'You must agree to the Terms & Conditions before proceeding.', 'newspack-plugin' ), 'error' );
			}
		}
	}

	/**
	 * Generate the label for the Terms & Conditions confirmation checkbox.
	 *
	 * @return string Returns the label for the Terms & Conditions confirmation checkbox.
	 */
	public static function generate_terms_confirmation_label() {
		$label = Reader_Activation::get_terms_confirmation_text();
		$url = Reader_Activation::get_terms_confirmation_url();
		if ( $url ) {
			if ( strpos( $label, '{{' ) !== false && strpos( $label, '}}' ) !== false ) {
				// If the text includes {{ }}, replace it with the link.
				$label = str_replace( '{{', '<a target="_blank" href="' . $url . '">', $label );
				$label = str_replace( '}}', '</a>', $label );
			} else {
				// If the text doesn't include {{ }}, link the whole text string.
				$label = '<a target="_blank" href="' . $url . '">' . $label . '</a>';
			}
		}
		return $label;
	}

	/**
	 * Add either the Subscription Confirmation or Terms & Conditions checkbox to the WooCommerce checkout form when enabled and when the cart contains a subscription product.
	 */
	public static function add_subscription_confirmation_checkboxes() {
		if ( ! self::has_subscription_in_cart() ) {
			return;
		}

		if ( Reader_Activation::is_subscription_confirmation_enabled() ) {
			woocommerce_form_field(
				'newspack_subscription_confirmation',
				array(
					'type'     => 'checkbox',
					'class'    => array( 'form-row-wide', 'newspack-subscription-confirmation-checkbox' ),
					'label'    => Reader_Activation::get_subscription_confirmation_text(),
					'required' => true,
				)
			);
		}

		if ( Reader_Activation::is_terms_confirmation_enabled() ) {
			woocommerce_form_field(
				'newspack_subscription_terms_confirmation',
				array(
					'type'     => 'checkbox',
					'class'    => array( 'form-row-wide', 'newspack-subscription-confirmation-checkbox' ),
					'label'    => self::generate_terms_confirmation_label(),
					'required' => true,
				)
			);
		}
	}
}
