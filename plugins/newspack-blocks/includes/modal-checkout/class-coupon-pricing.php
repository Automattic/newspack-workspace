<?php
/**
 * Newspack Blocks Modal Checkout coupon pricing.
 *
 * @package Newspack
 */

namespace Newspack_Blocks\Modal_Checkout;

/**
 * Prices a product's variations against a Checkout Button block's attached coupon.
 *
 * The variation picker is rendered server-side once per parent product and shared by
 * every Checkout Button targeting it, so the discount cannot be baked into that
 * markup: which coupon applies is only known once a button is clicked. This exposes
 * the numbers so the picker can be annotated per open.
 *
 * Deliberately never touches WC()->cart. This runs in a REST request while the reader
 * may have a cart of their own, and building one here to price a coupon would clobber
 * it. WC_Coupon::get_discount_amount() is cart-free and already accounts for the
 * discount type, including the types WooCommerce Subscriptions registers.
 */
final class Coupon_Pricing {

	/**
	 * Discount types whose discount survives into renewal orders.
	 *
	 * Mirrors WC_Subscriptions_Coupon::$recurring_coupons, which is private. Every
	 * other type — including plain `percent` and `fixed_cart` — discounts the initial
	 * payment only, so the picker has to say so rather than implying the lower price
	 * recurs.
	 *
	 * @var string[]
	 */
	const RECURRING_DISCOUNT_TYPES = [ 'recurring_fee', 'recurring_percent' ];

	/**
	 * Register the REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-blocks/v1',
			'/checkout-button/coupon-pricing',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_pricing' ],
				// Read-only, and every value returned is derived from prices already
				// public on the storefront.
				'permission_callback' => '__return_true',
				'args'                => [
					'product_id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return 0 < (int) $value;
						},
					],
					'coupon'     => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Price each of a product's purchasable variations against a coupon.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_pricing( $request ) {
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Coupon' ) ) {
			return new \WP_Error(
				'newspack_blocks_woocommerce_inactive',
				__( 'WooCommerce is not available.', 'newspack-blocks' ),
				[ 'status' => 501 ]
			);
		}

		$product = wc_get_product( $request->get_param( 'product_id' ) );
		if ( ! $product ) {
			return new \WP_Error(
				'newspack_blocks_product_not_found',
				__( 'Product not found.', 'newspack-blocks' ),
				[ 'status' => 404 ]
			);
		}

		// Decode entities so a literal code (e.g. one containing "&") still matches,
		// mirroring Modal_Checkout::maybe_auto_apply_coupon().
		$coupon_code = html_entity_decode( (string) $request->get_param( 'coupon' ), ENT_QUOTES );
		$declined    = [
			'coupon'     => $coupon_code,
			'applies'    => false,
			'recurs'     => false,
			'variations' => [],
		];

		if ( '' === $coupon_code || ! function_exists( 'wc_coupons_enabled' ) || ! \wc_coupons_enabled() ) {
			return new \WP_REST_Response( $declined, 200 );
		}

		$coupon = new \WC_Coupon( $coupon_code );
		if ( ! $coupon->get_id() || ! self::is_coupon_live( $coupon ) ) {
			return new \WP_REST_Response( $declined, 200 );
		}

		$recurs     = in_array( $coupon->get_discount_type(), self::RECURRING_DISCOUNT_TYPES, true );
		$variations = [];
		$applies    = false;

		foreach ( self::get_priceable_products( $product ) as $child ) {
			$pricing = self::price_product( $child, $coupon, $recurs );
			if ( $pricing['applies'] ) {
				$applies = true;
			}
			$variations[ (string) $child->get_id() ] = $pricing;
		}

		return new \WP_REST_Response(
			[
				'coupon'     => $coupon->get_code(),
				'applies'    => $applies,
				'recurs'     => $recurs,
				'variations' => $variations,
			],
			200
		);
	}

	/**
	 * Whether a coupon is live at all, independent of any product.
	 *
	 * Per-user usage limits are deliberately not checked: the reader may not be
	 * logged in yet, so the answer would be wrong as often as right. This reports
	 * "would apply", not "will apply" — the checkout totals stay authoritative.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 *
	 * @return bool
	 */
	private static function is_coupon_live( $coupon ) {
		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() < time() ) {
			return false;
		}
		$limit = (int) $coupon->get_usage_limit();
		if ( 0 < $limit && (int) $coupon->get_usage_count() >= $limit ) {
			return false;
		}
		return true;
	}

	/**
	 * The products the picker offers: a variable product's priced children, or the
	 * product itself when there is nothing to choose between.
	 *
	 * @param \WC_Product $product Parent product.
	 *
	 * @return \WC_Product[]
	 */
	private static function get_priceable_products( $product ) {
		$children = method_exists( $product, 'get_children' ) ? $product->get_children() : [];
		if ( empty( $children ) ) {
			return [ $product ];
		}
		$products = [];
		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child && '' !== (string) $child->get_price() ) {
				$products[] = $child;
			}
		}
		return $products;
	}

	/**
	 * Whether the coupon's product restrictions admit this product.
	 *
	 * Deferring to is_valid_for_product() alone would be wrong: it early-returns
	 * false for any type outside wc_get_product_coupon_types() — notably
	 * `fixed_cart`, which is a cart-level type validated through is_valid_for_cart()
	 * instead. That would report every fixed-cart coupon as inapplicable, so
	 * cart-level types get their include/exclude lists evaluated directly.
	 *
	 * @param \WC_Coupon  $coupon  Coupon.
	 * @param \WC_Product $product Product.
	 *
	 * @return bool
	 */
	private static function is_allowed_for_product( $coupon, $product ) {
		if ( function_exists( 'wc_get_product_coupon_types' ) && $coupon->is_type( wc_get_product_coupon_types() ) ) {
			return (bool) $coupon->is_valid_for_product( $product );
		}

		$parent_id    = $product->get_parent_id();
		$product_ids  = array_filter( [ $product->get_id(), $parent_id ] );
		$category_ids = wc_get_product_cat_ids( $parent_id ? $parent_id : $product->get_id() );

		$included = $coupon->get_product_ids();
		$in_cats  = $coupon->get_product_categories();
		if ( ( $included || $in_cats )
			&& ! array_intersect( $product_ids, $included )
			&& ! array_intersect( $category_ids, $in_cats ) ) {
			return false;
		}
		if ( array_intersect( $product_ids, $coupon->get_excluded_product_ids() ) ) {
			return false;
		}
		if ( array_intersect( $category_ids, $coupon->get_excluded_product_categories() ) ) {
			return false;
		}
		if ( $coupon->get_exclude_sale_items() && $product->is_on_sale() ) {
			return false;
		}
		return true;
	}

	/**
	 * The discount a coupon takes off a single item priced at $price.
	 *
	 * WC_Coupon::get_discount_amount() cannot be used here: it only resolves
	 * `percent` without cart context. Its `fixed_cart` branch is gated on a cart
	 * item plus WC()->cart->subtotal_ex_tax, and the types WooCommerce
	 * Subscriptions adds are resolved by a filter that needs the cart too — so
	 * both quietly return 0, reporting a real discount as no discount.
	 *
	 * Computing it per type is exact here because the modal checkout cart holds
	 * exactly one line at quantity 1, which is what makes a cart-level discount
	 * unambiguous: all of it lands on this item.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @param float      $price  The item's price.
	 *
	 * @return float The discount, never more than the price.
	 */
	private static function calculate_discount( $coupon, $price ) {
		$amount = (float) $coupon->get_amount();
		if ( 0 >= $amount || 0 >= $price ) {
			return 0.0;
		}
		switch ( $coupon->get_discount_type() ) {
			case 'percent':
			case 'recurring_percent':
				return min( $price, $price * $amount / 100 );
			case 'fixed_product':
			case 'recurring_fee':
			case 'fixed_cart':
				return min( $price, $amount );
			default:
				// Sign-up-fee and renewal-only types leave the price on offer alone.
				return 0.0;
		}
	}

	/**
	 * Price a single variation against the coupon.
	 *
	 * @param \WC_Product $child  Variation, or a childless product.
	 * @param \WC_Coupon  $coupon Coupon.
	 * @param bool        $recurs Whether the discount survives into renewals.
	 *
	 * @return array{applies:bool,regular_html:string,first_html:string,recurring_html:string}
	 */
	private static function price_product( $child, $coupon, $recurs ) {
		$price    = (float) $child->get_price();
		$regular  = self::price_string( $child, $price );
		$declined = [
			'applies'        => false,
			'regular_html'   => $regular,
			'first_html'     => '',
			'recurring_html' => '',
		];

		if ( ! self::is_allowed_for_product( $coupon, $child ) ) {
			return $declined;
		}

		/*
		 * The modal checkout cart is always exactly this one item at quantity 1 —
		 * process_checkout_request() empties the cart before adding it — so spend
		 * limits can be compared against this price exactly.
		 *
		 * Cast before comparing: get_minimum_amount()/get_maximum_amount() return
		 * wc_format_decimal( 0 ) when unset, and that string can be "0.00", which is
		 * truthy. Testing truthiness would reject every positively priced product.
		 */
		$minimum = (float) $coupon->get_minimum_amount();
		if ( 0 < $minimum && $price < $minimum ) {
			return $declined;
		}
		$maximum = (float) $coupon->get_maximum_amount();
		if ( 0 < $maximum && $price > $maximum ) {
			return $declined;
		}

		$discount = self::calculate_discount( $coupon, $price );
		if ( 0 >= $discount ) {
			return $declined;
		}
		$discounted = max( 0, $price - $discount );

		return [
			'applies'        => true,
			'regular_html'   => $regular,

			/*
			 * A recurring discount is the price from here on, so it reads as a rate.
			 * A one-off discount applies to the first payment only, so it is a bare
			 * amount paired with recurring_html — which carries the whole qualifier,
			 * already translated, so the frontend bundle never assembles copy.
			 */
			'first_html'     => $recurs ? self::price_string( $child, $discounted ) : wc_price( $discounted ),
			'recurring_html' => $recurs ? '' : sprintf(
				/*
				 * translators: %s: the undiscounted recurring price, e.g. "$10.00 / month".
				 * The leading comma separates this from the discounted first payment it
				 * follows, reading as "$8.00, then $10.00 / month" — keep or replace it
				 * with whatever punctuation the locale expects.
				 */
				__( ', then %s', 'newspack-blocks' ),
				$regular
			),
		];
	}

	/**
	 * Format a price the way the picker's cards do.
	 *
	 * @param \WC_Product $child  Product.
	 * @param float       $amount Amount.
	 *
	 * @return string
	 */
	private static function price_string( $child, $amount ) {
		if ( function_exists( 'wcs_price_string' ) && $child->get_meta( '_subscription_period' ) ) {
			return wcs_price_string(
				[
					'recurring_amount'      => $amount,
					'subscription_period'   => $child->get_meta( '_subscription_period' ),
					'subscription_interval' => $child->get_meta( '_subscription_period_interval' ),
				]
			);
		}
		return wc_price( $amount );
	}
}
add_action( 'rest_api_init', [ __NAMESPACE__ . '\\Coupon_Pricing', 'register_routes' ] );
