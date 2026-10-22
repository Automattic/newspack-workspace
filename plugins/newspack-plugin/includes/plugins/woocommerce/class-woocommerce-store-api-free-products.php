<?php
/**
 * Keeps free products out of the WooCommerce Store API.
 *
 * @package Newspack
 */

namespace Newspack;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses to sell a free product through the WooCommerce Store API (`/wc/store/v1`).
 *
 * Publishers grant complimentary access through public $0 products that an admin
 * assigns to readers by hand. The Store API checkout runs neither the reCAPTCHA check
 * nor the rate limit that guard the classic checkout, and a $0 order needs no payment
 * method, so a bot can create subscriptions to such a product at will. The classic
 * checkout is left alone: it keeps both guards.
 *
 * The Store API checks a product when it is added to the cart, and checks every cart
 * item again whenever it validates the cart: on each cart read and at checkout.
 * Rejecting at both means a cart filled some other way is still stopped at checkout.
 */
class WooCommerce_Store_API_Free_Products {
	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ __CLASS__, 'validate_add_to_cart' ], 10, 1 );
		add_action( 'woocommerce_store_api_validate_cart_item', [ __CLASS__, 'validate_cart_item' ], 10, 1 );
	}

	/**
	 * Reject a free product being added to a Store API cart.
	 *
	 * @param \WC_Product $product Product being added.
	 *
	 * @throws RouteException When the product is free.
	 */
	public static function validate_add_to_cart( $product ): void {
		self::reject_free_product( $product );
	}

	/**
	 * Reject a free product in a Store API cart, including at checkout.
	 *
	 * @param \WC_Product $product Product in the cart.
	 *
	 * @throws RouteException When the product is free.
	 */
	public static function validate_cart_item( $product ): void {
		self::reject_free_product( $product );
	}

	/**
	 * Throw the Store API's not-purchasable error for a free product.
	 *
	 * @param mixed $product Product being bought.
	 *
	 * @throws RouteException When the product is free.
	 */
	private static function reject_free_product( $product ): void {
		if ( ! $product instanceof \WC_Product || ! self::is_free( $product ) ) {
			return;
		}

		/**
		 * Filters whether the Store API may sell a free product.
		 *
		 * For a site that sells a free product on purpose through the Checkout block.
		 *
		 * @param bool        $allow   Whether to allow the purchase. Default false.
		 * @param \WC_Product $product The free product.
		 */
		if ( apply_filters( 'newspack_store_api_allow_free_product', false, $product ) ) {
			return;
		}

		// The message travels as JSON and the client renders it, as with WooCommerce's own Store API errors.
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new RouteException(
			'woocommerce_rest_product_not_purchasable',
			sprintf(
				/* translators: %s: product name */
				__( '%s cannot be purchased.', 'newspack-plugin' ),
				$product->get_name()
			),
			400
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Whether a product is free as the publisher configured it.
	 *
	 * A product is free when its active price (a live sale included) and its sign-up fee
	 * are both zero. Both come from the saved product, unfiltered: a subscriber discount, or
	 * a renewal cart repricing a comped line to $0, does not make a paid product free. Name
	 * Your Price products are exempt, because the reader chooses what to pay; if that
	 * plugin is inactive, a leftover `_nyp` flag does not exempt the product.
	 *
	 * @param \WC_Product $product Product to test.
	 *
	 * @return bool
	 */
	public static function is_free( \WC_Product $product ): bool {
		$saved_product = function_exists( 'wc_get_product' ) ? wc_get_product( $product->get_id() ) : false;
		if ( $saved_product instanceof \WC_Product ) {
			$product = $saved_product;
		}
		$price = $product->get_price( 'edit' );
		// An empty price means no price was set, and WooCommerce already refuses to sell it.
		if ( '' === (string) $price || 0 < (float) $price ) {
			return false;
		}
		if ( 0 < (float) $product->get_meta( '_subscription_sign_up_fee' ) ) {
			return false;
		}
		return ! ( class_exists( '\WC_Name_Your_Price_Helpers' ) && \WC_Name_Your_Price_Helpers::is_nyp( $product ) );
	}
}

WooCommerce_Store_API_Free_Products::init();
