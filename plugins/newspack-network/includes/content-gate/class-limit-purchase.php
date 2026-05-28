<?php
/**
 * Newspack Network purchase limiter for Content Gate subscription products.
 *
 * Prevents users from purchasing a subscription product when they already
 * have an active subscription to a product with the same Network ID on
 * another site in the network.
 *
 * This is the Content Gate equivalent of Woocommerce_Memberships\Limit_Purchase,
 * which works through membership plans. This class works directly with product
 * Network IDs.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Gate;

use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Handles limiting WooCommerce Subscriptions purchases by product Network ID.
 */
class Limit_Purchase {

	/**
	 * Initializer.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_is_purchasable', [ __CLASS__, 'restrict_network_subscriptions' ], 10, 2 );
		add_filter( 'woocommerce_cart_product_cannot_be_purchased_message', [ __CLASS__, 'cart_product_cannot_be_purchased_message' ], 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_network_subscription' ], 10, 2 );
	}

	/**
	 * Restricts subscription purchasing if the user already has an equivalent network subscription.
	 *
	 * @param bool        $purchasable Whether the subscription product is purchasable.
	 * @param \WC_Product $subscription_product The subscription product.
	 * @return bool
	 */
	public static function restrict_network_subscriptions( $purchasable, $subscription_product ) {
		if ( ! is_user_logged_in() ) {
			return $purchasable;
		}
		return self::get_network_equivalent_subscription( $subscription_product ) ? false : $purchasable;
	}

	/**
	 * Given a product, check if the current user has an active subscription on another
	 * network site for a product with the same Network ID.
	 *
	 * @param \WC_Product $product Product data.
	 * @param int|null    $user_id User ID, defaults to the current user.
	 * @return array|void Array with 'site' and 'subscription' keys if found, void otherwise.
	 */
	private static function get_network_equivalent_subscription( \WC_Product $product, $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = self::get_user_id_from_email();
			if ( ! $user_id ) {
				$user_id = get_current_user_id();
			}
		}

		if ( ! $user_id ) {
			return;
		}

		// Only restrict subscription products.
		if ( ! $product->is_type( [ 'subscription', 'subscription_variation', 'variable-subscription' ] ) ) {
			return;
		}

		$network_id = Product_Admin::get_network_id( $product->get_id() );
		if ( empty( $network_id ) ) {
			return;
		}

		return Access::user_has_active_network_subscription_for_network_id( $user_id, $network_id );
	}

	/**
	 * Filters the error message shown when a product can't be added to the cart.
	 *
	 * @param string      $message Message.
	 * @param \WC_Product $product_data Product data.
	 * @return string
	 */
	public static function cart_product_cannot_be_purchased_message( $message, \WC_Product $product_data ) {
		$network_subscription = self::get_network_equivalent_subscription( $product_data );
		if ( $network_subscription ) {
			$message = sprintf(
				/* translators: %s: Site URL */
				__( "You can't buy this subscription because you already have it active on %s", 'newspack-network' ),
				$network_subscription['site']
			);
		}
		return $message;
	}

	/**
	 * Get user from email.
	 *
	 * @return false|int User ID if found by email address, false otherwise.
	 */
	private static function get_user_id_from_email() {
		$billing_email = filter_input( INPUT_POST, 'billing_email', FILTER_SANITIZE_EMAIL );
		if ( $billing_email ) {
			$customer = \get_user_by( 'email', $billing_email );
			if ( $customer ) {
				return $customer->ID;
			}
		}
		return false;
	}

	/**
	 * Validate network subscription for logged out readers.
	 *
	 * @param array     $data   Checkout data.
	 * @param \WP_Error $errors Checkout errors.
	 */
	public static function validate_network_subscription( $data, $errors ) {
		if ( is_user_logged_in() || ! function_exists( 'WC' ) ) {
			return;
		}
		$id_from_email = self::get_user_id_from_email();
		if ( $id_from_email ) {
			$cart_items = WC()->cart->get_cart();
			foreach ( $cart_items as $cart_item ) {
				$product                     = $cart_item['data'];
				$network_active_subscription = self::get_network_equivalent_subscription( $product, $id_from_email );
				if ( $network_active_subscription ) {
					$error_message = __( 'Oops! You already have a subscription on another site in this network that grants you access to this site as well. Please log in using the same email address.', 'newspack-network' );
					$errors->add( 'network_subscription', $error_message );
					break;
				}
			}
		}
	}
}
