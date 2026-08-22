<?php
/**
 * Group subscription seats: the reader-facing seat count for per-seat groups.
 *
 * A seat is the WooCommerce line-item quantity, so WooCommerce Subscriptions bills
 * price x seats and works out the proration of a seat change itself. This class owns
 * what WooCommerce cannot know: the publisher's seat bounds, the shared field label,
 * the guards that keep a reader from buying a seat count outside those bounds or
 * below the number of people already in their group, and the one WooCommerce
 * Subscriptions setting a seat change cannot do without.
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
		// This prints a visible one, tied to the input_id quantity_input_args() sets.
		\add_action( 'woocommerce_before_add_to_cart_quantity', [ __CLASS__, 'render_quantity_label' ] );

		// Both registrations, for the same reason Subscriptions_Tiers documents: the
		// validation filter covers WooCommerce's own request handlers (form handler,
		// AJAX, Store API), but not `WC_Cart::add_to_cart()` itself, which the group
		// subscription modal checkout calls directly. The cart-item-data filter runs
		// inside `add_to_cart()` on every path, so it backstops the direct-call route.
		\add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 4 );
		\add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'guard_add_cart_item_data' ], 9, 4 );
		\add_filter( 'woocommerce_update_cart_validation', [ __CLASS__, 'validate_cart_update' ], 10, 4 );

		// The modal checkout has no quantity field of its own. This turns one on for
		// per-seat products; newspack-blocks owns the markup and the AJAX round trip.
		\add_filter( 'newspack_blocks_modal_checkout_quantity_field', [ __CLASS__, 'modal_quantity_field' ], 10, 2 );

		// Seats are the only reason the modal checkout carries a quantity at all, so
		// anything that isn't sold per seat is bought exactly once.
		\add_filter( 'newspack_blocks_modal_checkout_quantity', [ __CLASS__, 'clamp_modal_quantity' ], 10, 2 );

		// Seats bought mid-cycle have to be paid for from the day they are added,
		// which is WooCommerce Subscriptions' recurring-price proration.
		\add_filter( 'pre_option_woocommerce_subscriptions_apportion_recurring_price', [ __CLASS__, 'force_recurring_proration' ] );
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
	 * Turn on the modal checkout's quantity field for a per-seat product.
	 *
	 * The modal is single-quantity unless this filter returns field args, so a
	 * flat (per-team) product — or any product this class knows nothing about —
	 * must get the incoming value back untouched, in case another consumer of
	 * the filter has already answered for it.
	 *
	 * @param null|array      $args    Field args from an earlier callback, or null.
	 * @param \WC_Product|int $product The product in the cart.
	 *
	 * @return null|array The seat field args, or the unchanged incoming value.
	 */
	public static function modal_quantity_field( $args, $product ) {
		$field = self::get_field_args( $product );
		return $field ? $field : $args;
	}

	/**
	 * Hold the modal checkout to one item for anything not sold per seat.
	 *
	 * A quantity in the modal checkout means seats, and only a per-seat product
	 * has any. Without this, a request carrying a seat count — a group owner
	 * switching from a per-seat tier to a flat one, or a crafted URL — would buy
	 * that many of a product whose price covers the whole group, billing the flat
	 * price N times. The bounds guards can't catch it: they only ever run against
	 * a per-seat product's own minimum and maximum.
	 *
	 * @param int $quantity   Requested quantity, at least 1.
	 * @param int $product_id Product the quantity is for (variation preferred).
	 *
	 * @return int The requested quantity for a per-seat product, 1 otherwise.
	 */
	public static function clamp_modal_quantity( $quantity, $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $quantity;
		}
		// A product that can't be resolved can't be shown to sell seats, so it is
		// treated like any other single-item purchase.
		$product = \wc_get_product( $product_id );
		if ( ! $product || ! Group_Subscription_Settings::is_per_seat( $product ) ) {
			return 1;
		}
		return $quantity;
	}

	/**
	 * Constrain WooCommerce's quantity input to a per-seat product's bounds.
	 *
	 * Scoped to the single-product page: `woocommerce_quantity_input_args` also
	 * fires for every row of the cart table (`cart/cart.php`), which renders one
	 * quantity input per cart item on a single page. Applying the same bounds
	 * and a stable input_id there would collide across rows (duplicate DOM ids)
	 * and would block the cart's own "set quantity to 0 to remove the item"
	 * convention, since it isn't this product's add-to-cart form.
	 *
	 * @param array       $args    Quantity input args.
	 * @param \WC_Product $product Product.
	 *
	 * @return array The filtered quantity input args.
	 */
	public static function quantity_input_args( $args, $product ) {
		if ( ! is_product() ) {
			return $args;
		}
		$field = self::get_field_args( $product );
		if ( ! $field ) {
			return $args;
		}
		// A stable, product-specific ID so render_quantity_label()'s
		// <label for="..."> always points at this exact input, without colliding
		// with another per-seat product's quantity field also rendered on the
		// same page (e.g. a grouped product's per-child quantity inputs).
		$args['input_id']     = self::get_quantity_input_id( $product );
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
			'<label class="newspack-group-subscription__seats-label" for="%1$s">%2$s</label>',
			esc_attr( self::get_quantity_input_id( $product ) ),
			esc_html( $field['label'] )
		);
	}

	/**
	 * Build the quantity input's DOM id for a per-seat product.
	 *
	 * Shared by quantity_input_args() (which sets it) and render_quantity_label()
	 * (which points its <label for="..."> at it), so the two can never drift out
	 * of sync. Keyed by product ID so multiple per-seat quantity fields on one
	 * page — however unlikely in practice — never collide.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string
	 */
	private static function get_quantity_input_id( $product ) {
		return 'newspack-group-subscription-seats-quantity-' . ( $product ? (int) $product->get_id() : 0 );
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
		// A posted quantity of 0 is WooCommerce's signal to remove the cart item
		// entirely (see WC_Cart::set_quantity()), not a request for a 0-seat
		// group. Enforcing the seat minimum against it would trap the reader:
		// they could never get below the minimum to remove the item this way.
		if ( 0 === (int) $quantity ) {
			return $passed;
		}
		$product_id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
		// Editing the seat count on the cart page is a plain cart update: the switch
		// it belongs to is recorded on the cart item, not in the request, so it has
		// to be handed over or the occupancy rule would not apply here at all.
		$error = self::get_quantity_error( $product_id, $quantity, $values['subscription_switch']['subscription_id'] ?? 0 );
		if ( null === $error ) {
			return true;
		}
		wc_add_notice( $error, 'error' );
		return false;
	}

	/**
	 * Count the seats a group has already committed.
	 *
	 * Everyone in the group — the owner included — occupies a seat, and so does
	 * every invitation still waiting to be accepted: the seat is being held for
	 * whoever it was sent to. An expired invitation holds nothing.
	 *
	 * @param \WC_Subscription|int $subscription Subscription object or ID.
	 *
	 * @return int The number of occupied seats.
	 */
	public static function get_occupancy( $subscription ): int {
		return Group_Subscription::get_member_count( $subscription )
			+ count( Group_Subscription_Invite::get_invites( $subscription, false ) );
	}

	/**
	 * Force WooCommerce Subscriptions' recurring-price proration on for a per-seat
	 * group switch.
	 *
	 * A seat added mid-cycle has to be paid for from the day it is added, and
	 * WooCommerce Subscriptions only charges for the rest of the cycle when the
	 * store's "prorate recurring price" setting is on. A publisher who leaves it
	 * off — the default — would give away the remainder of the cycle on every seat
	 * increase, so it is turned on for per-seat group switches alone. Every other
	 * switch keeps the publisher's own setting.
	 *
	 * @param mixed $value The pre-option value: false unless something has already answered.
	 *
	 * @return mixed 'yes' for a per-seat group switch, otherwise the value unchanged.
	 */
	public static function force_recurring_proration( $value ) {
		// Two ways to recognise the same switch, because it is priced in two passes.
		// The add-to-cart request still carries `switch-subscription`; by the time the
		// checkout totals are recalculated those request parameters are long gone and
		// the only remaining record of the switch is on the cart item.
		if ( self::get_per_seat_switch_subscription() || self::cart_has_per_seat_switch( self::get_cart_items() ) ) {
			return 'yes';
		}
		return $value;
	}

	/**
	 * Whether a cart holds a switch of a per-seat group subscription.
	 *
	 * Takes the cart contents rather than reading them, so the rule can be
	 * exercised without a WooCommerce session.
	 *
	 * @param array $cart_items Cart contents, in the shape `WC_Cart::get_cart()` returns.
	 *
	 * @return bool
	 */
	public static function cart_has_per_seat_switch( $cart_items ) {
		foreach ( $cart_items as $cart_item ) {
			$subscription_id = $cart_item['subscription_switch']['subscription_id'] ?? 0;
			if ( ! $subscription_id ) {
				continue;
			}
			$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
			if ( $subscription && Group_Subscription_Settings::is_per_seat( $subscription ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the current cart's contents.
	 *
	 * There is no cart on an admin, cron or REST request, so an empty one stands
	 * in for "nothing to look at".
	 *
	 * @return array Cart contents.
	 */
	private static function get_cart_items() {
		if ( ! function_exists( 'WC' ) || ! \WC() || ! \WC()->cart ) {
			return [];
		}
		return \WC()->cart->get_cart();
	}

	/**
	 * Get the per-seat subscription being switched, if any.
	 *
	 * Reads the switch request by default, and takes a subscription ID instead for
	 * the places where the request no longer carries one. Either way only the
	 * requester's own subscription counts: a seat count means nothing against a
	 * group somebody else owns, and answering for one would let a crafted request
	 * read back how many people are in it.
	 *
	 * @param int $subscription_id A subscription ID to resolve instead of the request's.
	 *
	 * @return \WC_Subscription|null The subscription being switched, or null.
	 */
	private static function get_per_seat_switch_subscription( $subscription_id = 0 ) {
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$subscription_id = isset( $_REQUEST['switch-subscription'] ) ? absint( wp_unslash( $_REQUEST['switch-subscription'] ) ) : 0;
		}
		if ( ! $subscription_id || ! is_user_logged_in() ) {
			return null;
		}
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			return null;
		}
		return Group_Subscription_Settings::is_per_seat( $subscription ) ? $subscription : null;
	}

	/**
	 * Get the blocking error message for a seat quantity.
	 *
	 * Null when the product is not per seat — flat (`per_team`) products never
	 * enter these guards at all — or when the quantity fits the product's bounds
	 * and the group it is for.
	 *
	 * @param \WC_Product|int $product                Product object or ID.
	 * @param int             $quantity               Requested quantity.
	 * @param int             $switch_subscription_id Subscription being switched, when the request does not name one.
	 *
	 * @return string|null The error message, or null.
	 */
	private static function get_quantity_error( $product, $quantity, $switch_subscription_id = 0 ) {
		if ( ! Group_Subscription_Settings::is_per_seat( $product ) ) {
			return null;
		}
		$result = self::validate_quantity( $product, $quantity );
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		// Shrinking a group is fine, but not below the people already in it: seats
		// are what capacity is measured in, so a seat taken away from a member or a
		// pending invitation leaves the group over its own limit. Nobody can be
		// removed on their behalf here, so the reader is asked to do it first.
		$subscription = self::get_per_seat_switch_subscription( $switch_subscription_id );
		if ( $subscription ) {
			$occupancy = self::get_occupancy( $subscription );
			if ( (int) $quantity < $occupancy ) {
				return sprintf(
					/* translators: 1: lowercase singular group label, 2: number of occupied seats. */
					__( 'This %1$s has %2$d seats in use. Remove members or cancel invitations before reducing seats below that.', 'newspack-plugin' ),
					Group_Subscription::get_label_lower( 'singular' ),
					$occupancy
				);
			}
		}
		return null;
	}
}
Group_Subscription_Seats::init();
