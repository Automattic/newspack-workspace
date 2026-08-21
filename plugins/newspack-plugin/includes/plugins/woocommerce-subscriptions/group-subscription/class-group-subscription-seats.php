<?php
/**
 * Group subscription seats: the reader-facing seat count for per-seat groups.
 *
 * A seat is the WooCommerce line-item quantity, so WooCommerce Subscriptions bills
 * price x seats and prorates seat changes itself. This class owns what WooCommerce
 * cannot know: the publisher's seat bounds, the shared field label, and the guards
 * that keep a reader from adding a seat count outside those bounds at checkout.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Seat bounds, field label, and checkout validation for per-seat group subscriptions.
 */
class Group_Subscription_Seats {

	/**
	 * Error code used by validate_quantity()'s WP_Error return.
	 */
	const ERROR_CODE = 'newspack_group_subscription_seats';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}
		\add_filter( 'woocommerce_quantity_input_args', [ __CLASS__, 'quantity_input_args' ], 10, 2 );
		// The stock quantity-input template only turns `product_name` into a
		// screen-reader-only label, so sighted readers see no field description.
		// This prints a visible one, tied to the fixed input_id set below.
		\add_action( 'woocommerce_before_add_to_cart_quantity', [ __CLASS__, 'render_quantity_label' ] );

		// Both registrations, for the same reason Subscriptions_Tiers documents: the
		// validation filter covers WooCommerce's own request handlers (form handler,
		// AJAX, Store API), but not `WC_Cart::add_to_cart()` itself, which the group
		// subscription modal checkout calls directly. The cart-item-data filter runs
		// inside `add_to_cart()` on every path, so it backstops the direct-call route.
		\add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 4 );
		\add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'guard_add_cart_item_data' ], 9, 4 );
		\add_filter( 'woocommerce_update_cart_validation', [ __CLASS__, 'validate_cart_update' ], 10, 4 );
	}

	/**
	 * Get the seat bounds for a per-seat product.
	 *
	 * @param \WC_Product|int $product Product object or ID.
	 *
	 * @return array{min:int,max:int} Minimum seats, and maximum seats (0 = unlimited).
	 */
	public static function get_bounds( $product ) {
		$settings = Group_Subscription_Settings::get_product_settings( $product );
		return [
			'min' => max( 1, (int) $settings['min_seats'] ),
			'max' => max( 0, (int) $settings['max_seats'] ),
		];
	}

	/**
	 * Get the shared label for the seat count field, e.g. "Number of team seats".
	 *
	 * @return string The translated label.
	 */
	public static function get_field_label() {
		/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
		return sprintf( __( 'Number of %s seats', 'newspack-plugin' ), Group_Subscription::get_label_lower( 'singular' ) );
	}

	/**
	 * Get the arguments for rendering the seats field.
	 *
	 * Shared by every checkout context — the product page, the modal, the tier
	 * picker — so the label and bounds cannot drift between them.
	 *
	 * @param \WC_Product|int $product Product object or ID.
	 *
	 * @return array{label:string,min:int,max:int,help:string}|null Field args, or null when the product is not per seat.
	 */
	public static function get_field_args( $product ) {
		if ( ! Group_Subscription_Settings::is_per_seat( $product ) ) {
			return null;
		}
		$bounds = self::get_bounds( $product );
		return [
			'label' => self::get_field_label(),
			'min'   => $bounds['min'],
			'max'   => $bounds['max'],
			'help'  => sprintf(
				/* translators: %s: lowercase singular group label. */
				__( 'Seats include the %s owner. The subscription price is charged for each seat.', 'newspack-plugin' ),
				Group_Subscription::get_label_lower( 'singular' )
			),
		];
	}

	/**
	 * Validate a requested seat count against a product's bounds.
	 *
	 * @param \WC_Product|int $product  Product object or ID.
	 * @param int             $quantity Requested seat count.
	 *
	 * @return true|\WP_Error True when the quantity fits the bounds, a WP_Error otherwise.
	 */
	public static function validate_quantity( $product, $quantity ) {
		$quantity = (int) $quantity;
		$bounds   = self::get_bounds( $product );
		if ( $quantity < $bounds['min'] ) {
			return new \WP_Error(
				self::ERROR_CODE,
				sprintf(
					/* translators: %d: minimum seats. */
					__( 'Choose at least %d seats.', 'newspack-plugin' ),
					$bounds['min']
				),
				[ 'status' => 400 ]
			);
		}
		if ( $bounds['max'] > 0 && $quantity > $bounds['max'] ) {
			return new \WP_Error(
				self::ERROR_CODE,
				sprintf(
					/* translators: %d: maximum seats. */
					__( 'Choose no more than %d seats.', 'newspack-plugin' ),
					$bounds['max']
				),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Constrain WooCommerce's quantity input to a per-seat product's bounds.
	 *
	 * @param array       $args    Quantity input args.
	 * @param \WC_Product $product Product.
	 *
	 * @return array The filtered quantity input args.
	 */
	public static function quantity_input_args( $args, $product ) {
		$field = self::get_field_args( $product );
		if ( ! $field ) {
			return $args;
		}
		// A fixed ID so render_quantity_label()'s <label for="..."> always points
		// at this input. Safe because a per-seat single-product add-to-cart form
		// renders only one quantity field.
		$args['input_id']     = 'quantity';
		$args['min_value']    = $field['min'];
		$args['max_value']    = $field['max'] > 0 ? $field['max'] : '';
		$args['step']         = 1;
		$args['input_value']  = max( (int) ( $args['input_value'] ?? $field['min'] ), $field['min'] );
		$args['product_name'] = $field['label']; // Feeds the template's screen-reader-only label.
		return $args;
	}

	/**
	 * Print a visible label ahead of the quantity input on a per-seat product's
	 * add-to-cart form.
	 */
	public static function render_quantity_label() {
		global $product;
		$field = $product ? self::get_field_args( $product ) : null;
		if ( ! $field ) {
			return;
		}
		printf(
			'<label class="newspack-group-subscription__seats-label" for="quantity">%s</label>',
			esc_html( $field['label'] )
		);
	}

	/**
	 * `woocommerce_add_to_cart_validation` guard.
	 *
	 * Applied by WooCommerce's own request handlers (form handler, AJAX, Store
	 * API) before an item reaches the cart.
	 *
	 * @param bool $passed       Whether the item passed validation so far.
	 * @param int  $product_id   Product being added.
	 * @param int  $quantity     Requested quantity.
	 * @param int  $variation_id Variation being added, if any.
	 *
	 * @return bool Whether the item passed validation.
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed ) {
			return $passed;
		}
		$error = self::get_quantity_error( $variation_id ? $variation_id : $product_id, $quantity );
		if ( null === $error ) {
			return true;
		}
		wc_add_notice( $error, 'error' );
		return false;
	}

	/**
	 * `woocommerce_add_cart_item_data` guard.
	 *
	 * The only hook `WC_Cart::add_to_cart()` itself runs, so it also covers
	 * direct calls that skip WooCommerce's request handlers entirely — notably
	 * the group subscription modal checkout. Throwing is WooCommerce's
	 * documented way for a plugin to abort the add: the cart catches the
	 * exception, queues its message as an error notice, and returns false to
	 * the caller.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product being added.
	 * @param int   $variation_id   Variation being added, if any.
	 * @param int   $quantity       Requested quantity.
	 *
	 * @throws \Exception When the seat count is outside the product's bounds.
	 *
	 * @return array Cart item data, unchanged.
	 */
	public static function guard_add_cart_item_data( $cart_item_data, $product_id, $variation_id = 0, $quantity = 1 ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// The Store API applies this filter outside `WC_Cart::add_to_cart()`'s
			// try/catch, where a throw would surface as a generic 500 instead of a
			// clean cart error. validate_add_to_cart() covers the Store API path.
			return $cart_item_data;
		}
		$error = self::get_quantity_error( $variation_id ? $variation_id : $product_id, $quantity );
		if ( null !== $error ) {
			throw new \Exception( esc_html( $error ) );
		}
		return $cart_item_data;
	}

	/**
	 * `woocommerce_update_cart_validation` guard.
	 *
	 * Runs when a reader changes the quantity of an item already in the cart.
	 *
	 * @param bool   $passed        Whether the item passed validation so far.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values        Cart item values.
	 * @param int    $quantity      Requested quantity.
	 *
	 * @return bool Whether the item passed validation.
	 */
	public static function validate_cart_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}
		$product_id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
		$error      = self::get_quantity_error( $product_id, $quantity );
		if ( null === $error ) {
			return true;
		}
		wc_add_notice( $error, 'error' );
		return false;
	}

	/**
	 * Get the blocking error message for a seat quantity.
	 *
	 * Null when the product is not per seat — flat (`per_team`) products never
	 * enter these guards at all — or when the quantity fits the product's bounds.
	 *
	 * @param \WC_Product|int $product  Product object or ID.
	 * @param int             $quantity Requested quantity.
	 *
	 * @return string|null The error message, or null.
	 */
	private static function get_quantity_error( $product, $quantity ) {
		if ( ! Group_Subscription_Settings::is_per_seat( $product ) ) {
			return null;
		}
		$result = self::validate_quantity( $product, $quantity );
		return is_wp_error( $result ) ? $result->get_error_message() : null;
	}
}
Group_Subscription_Seats::init();
