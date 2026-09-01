<?php
/**
 * Group subscription seats: the reader-facing seat count for per-seat groups.
 *
 * A seat is the WooCommerce line-item quantity, so WooCommerce Subscriptions bills
 * price x seats and prorates a seat change itself. This class owns what WooCommerce
 * cannot know: the publisher's seat bounds, the shared field label, and the guards
 * that keep a reader from buying a seat count outside those bounds or below the
 * number of people already in their group.
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
		// WooCommerce's variation script rebuilds the quantity input from the chosen
		// variation's own data, discarding the bounds set for the parent.
		\add_filter( 'woocommerce_available_variation', [ __CLASS__, 'available_variation_args' ], 10, 3 );
		// The stock quantity-input template only turns `product_name` into a
		// screen-reader-only label, so sighted readers see no field description.
		\add_action( 'woocommerce_before_add_to_cart_quantity', [ __CLASS__, 'render_quantity_label' ] );

		// Both registrations, for the same reason Subscriptions_Tiers documents: the
		// validation filter covers WooCommerce's own request handlers (form handler,
		// AJAX, Store API), but not `WC_Cart::add_to_cart()` itself, which the group
		// subscription modal checkout calls directly. The cart-item-data filter runs
		// inside `add_to_cart()` on every path, so it backstops the direct-call route.
		\add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 6 );
		\add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'guard_add_cart_item_data' ], 9, 4 );
		\add_filter( 'woocommerce_update_cart_validation', [ __CLASS__, 'validate_cart_update' ], 10, 4 );

		// The modal checkout has no quantity field of its own. This turns one on for
		// per-seat products; newspack-blocks owns the markup and the AJAX round trip.
		\add_filter( 'newspack_blocks_modal_checkout_quantity_field', [ __CLASS__, 'modal_quantity_field' ], 10, 2 );
		\add_filter( 'newspack_blocks_modal_checkout_quantity', [ __CLASS__, 'clamp_modal_quantity' ], 10, 3 );

		\add_filter( 'pre_option_woocommerce_subscriptions_apportion_recurring_price', [ __CLASS__, 'force_recurring_proration' ] );
		\add_filter( 'wcs_is_product_switchable', [ __CLASS__, 'allow_per_seat_switching' ], 10, 3 );
	}

	/**
	 * Let WooCommerce Subscriptions switch a per-seat product whatever its type.
	 *
	 * A seat change is a quantity switch, and WooCommerce Subscriptions runs its
	 * whole switch machinery off one gate, `wcs_is_product_switchable_type()`, which
	 * answers by product type — so a simple subscription sold per seat is refused and
	 * its seat count could never change.
	 *
	 * @param bool             $is_switchable Whether WooCommerce Subscriptions considers the product switchable.
	 * @param \WC_Product|null $product       The product, or its parent when a variation was passed in.
	 * @param \WC_Product|null $variation     The variation, when one was passed in.
	 *
	 * @return bool Whether the product is switchable.
	 */
	public static function allow_per_seat_switching( $is_switchable, $product, $variation = null ) {
		if ( $is_switchable ) {
			return $is_switchable;
		}
		// WooCommerce Subscriptions resolves a variation to its parent before
		// filtering and hands the variation over separately, and per-seat meta
		// lives on the variation for a tiered plan.
		$subject = $variation ? $variation : $product;
		if ( ! $subject ) {
			return $is_switchable;
		}
		if ( ! Group_Subscription_Settings::is_per_seat( $subject ) ) {
			return $is_switchable;
		}
		// The same published test `wcs_is_product_switchable_type()` applies. Per-seat
		// meta survives unpublishing, and the group page's switch link is the plan's
		// own permalink, so without this an owner gets a button to a plan that is no
		// longer there.
		$parent = $variation ? $product : $subject;
		if ( $parent && method_exists( $parent, 'get_status' ) && 'publish' !== $parent->get_status() ) {
			return $is_switchable;
		}
		return true;
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
		$min      = max( 1, (int) $settings['min_seats'] );
		$max      = max( 0, (int) $settings['max_seats'] );
		// Nothing stops a publisher saving a maximum below the minimum, which would
		// leave the product unbuyable at any seat count, with no error naming why.
		if ( $max > 0 && $max < $min ) {
			$max = $min;
		}
		return [
			'min' => $min,
			'max' => $max,
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
	 * The feature check is repeated here rather than left to `init()`: the tier
	 * picker calls this directly, so a site with the feature off would render a
	 * seats field with none of this class's guards registered behind it.
	 *
	 * @param \WC_Product|int $product Product object or ID.
	 *
	 * @return array{label:string,min:int,max:int,help:string}|null Field args, or null when the product is not per seat.
	 */
	public static function get_field_args( $product ) {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return null;
		}
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
	 * @param \WC_Product|int $product    Product object or ID.
	 * @param int             $quantity   Requested seat count.
	 * @param int             $held_seats Seats the buyer already holds on this plan, which the
	 *                                    maximum may not push them below. 0 when they hold none.
	 *
	 * @return true|\WP_Error True when the quantity fits the bounds, a WP_Error otherwise.
	 */
	public static function validate_quantity( $product, $quantity, $held_seats = 0 ) {
		$quantity = (int) $quantity;
		$bounds   = self::get_bounds( $product );
		// A group that already holds more seats than the plan now sells keeps them:
		// the maximum bounds what may be bought, not what has been. Without this a
		// publisher lowering it leaves those owners no submittable seat count at all.
		if ( $bounds['max'] > 0 && (int) $held_seats > $bounds['max'] ) {
			$bounds['max'] = (int) $held_seats;
		}
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
	 * The modal is single-quantity unless this filter returns field args.
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
	 * Vouch for a seat count the product can actually be sold at.
	 *
	 * Bounds are applied in both directions, upwards included: a request for one
	 * seat on a plan that sells no fewer than two is a quantity the guards refuse.
	 *
	 * @param null|int $vouched    The quantity vouched for so far, or null.
	 * @param int      $product_id Product the quantity is for (variation preferred).
	 * @param int      $requested  Requested quantity, at least 1.
	 *
	 * @return null|int A seat count within the product's bounds, or the unchanged incoming value.
	 */
	public static function clamp_modal_quantity( $vouched, $product_id, $requested = 1 ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $vouched;
		}
		$product = \wc_get_product( $product_id );
		if ( ! $product || ! Group_Subscription_Settings::is_per_seat( $product ) ) {
			return $vouched;
		}
		$bounds   = self::get_bounds( $product );
		$quantity = max( (int) $requested, $bounds['min'] );
		// Same exemption validate_quantity() applies: a group above a lowered maximum
		// keeps its seats, so the clamp must not pull the count under the floor the
		// occupancy guard enforces.
		$held = self::get_held_seats( self::get_owned_switch_subscription(), $product );
		$max  = $bounds['max'] > 0 ? max( $bounds['max'], $held ) : 0;
		return $max > 0 ? min( $quantity, $max ) : $quantity;
	}

	/**
	 * Constrain WooCommerce's quantity input to a per-seat product's bounds.
	 *
	 * Scoped to the single-product page: `woocommerce_quantity_input_args` also
	 * fires for every row of the cart table, where a stable input_id would collide
	 * across rows and the bounds would block the cart's own "set quantity to 0 to
	 * remove the item" convention.
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
			// A flat plan's price covers the whole group, so there is one of it to buy.
			if ( self::is_flat_group( $product ) ) {
				$args['max_value']   = 1;
				$args['input_value'] = 1;
			}
			return $args;
		}
		$args['input_id']     = self::get_quantity_input_id( $product );
		$args['min_value']    = $field['min'];
		$args['max_value']    = $field['max'] > 0 ? $field['max'] : '';
		$args['step']         = 1;
		$args['input_value']  = max( (int) ( $args['input_value'] ?? $field['min'] ), $field['min'] );
		$args['product_name'] = $field['label']; // Feeds the template's screen-reader-only label.
		return $args;
	}

	/**
	 * Publish a variation's seat bounds to WooCommerce's variation script.
	 *
	 * The bounds `quantity_input_args()` sets are rendered once, for the parent
	 * product. Choosing a variation makes WooCommerce's own script rewrite the
	 * quantity input from `min_qty`/`max_qty` in the variation's data, so each
	 * variation has to carry its own answer.
	 *
	 * @param array       $data      The variation data WooCommerce hands the script.
	 * @param \WC_Product $product   The parent variable product.
	 * @param \WC_Product $variation The variation.
	 *
	 * @return array The variation data.
	 */
	public static function available_variation_args( $data, $product, $variation ) {
		$field = self::get_field_args( $variation );
		if ( $field ) {
			$data['min_qty'] = $field['min'];
			$data['max_qty'] = $field['max'] > 0 ? $field['max'] : '';
		} elseif ( self::is_flat_group( $variation ) ) {
			$data['max_qty'] = 1;
		}
		return $data;
	}

	/**
	 * Whether a product is a group product sold at one flat price for the group.
	 *
	 * The counterpart to `Group_Subscription_Settings::is_per_seat()`.
	 *
	 * @param \WC_Product|int $product Product object or ID.
	 *
	 * @return bool
	 */
	private static function is_flat_group( $product ) {
		$settings = Group_Subscription_Settings::get_product_settings( $product );
		return ! empty( $settings['enabled'] )
			&& Group_Subscription_Settings::PRICING_MODE_PER_TEAM === $settings['pricing_mode'];
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
	 * (which points its <label for="..."> at it), so the two cannot drift apart.
	 * Keyed by product ID so two per-seat quantity fields on one page never collide.
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
	 * The last two arguments are not always there: WooCommerce's form handler
	 * applies the filter with five and its AJAX handler with three. Registered for
	 * six because WooCommerce Subscriptions' payment carts do send cart item data.
	 *
	 * @param bool  $passed         Whether the item passed validation so far.
	 * @param int   $product_id     Product being added.
	 * @param int   $quantity       Requested quantity.
	 * @param int   $variation_id   Variation being added, if any.
	 * @param array $variations     Variation attributes, if any.
	 * @param array $cart_item_data Cart item data, when the caller has any.
	 *
	 * @return bool Whether the item passed validation.
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
		if ( ! $passed ) {
			return $passed;
		}
		$error = self::get_quantity_error( $variation_id ? $variation_id : $product_id, $quantity, null, $cart_item_data );
		if ( null === $error ) {
			return true;
		}
		wc_add_notice( $error, 'error' );
		return false;
	}

	/**
	 * `woocommerce_add_cart_item_data` guard.
	 *
	 * The only hook `WC_Cart::add_to_cart()` itself runs, so it also covers direct
	 * calls that skip WooCommerce's request handlers — notably the group
	 * subscription modal checkout. Throwing is how a plugin aborts the add: the
	 * cart catches the exception, queues its message as an error notice, and
	 * returns false to the caller.
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
		$error = self::get_quantity_error( $variation_id ? $variation_id : $product_id, $quantity, null, $cart_item_data );
		if ( null !== $error ) {
			throw new \Exception( esc_html( $error ) );
		}
		return $cart_item_data;
	}

	/**
	 * Whether a cart item is one WooCommerce Subscriptions is re-adding to take a
	 * payment for a subscription the reader already has.
	 *
	 * `WCS_Cart_Renewal::setup_cart()` puts the subscription's own line items back
	 * in the cart at their stored quantity — for a renewal, a resubscribe, or the
	 * initial payment on a pending subscription — and that runs the add-to-cart
	 * guards. The seat bounds rule what a reader may choose to buy, and none of
	 * these is a choice, so a group that outgrew a lowered maximum would otherwise
	 * be locked out of paying for the plan it already has.
	 *
	 * The keys are the `$cart_item_key` properties of the classes that set them.
	 *
	 * @param array $cart_item_data Cart item data.
	 *
	 * @return bool
	 */
	private static function is_wcs_payment_cart_item( $cart_item_data ) {
		if ( ! is_array( $cart_item_data ) ) {
			return false;
		}
		foreach ( [ 'subscription_renewal', 'subscription_resubscribe', 'subscription_initial_payment' ] as $key ) {
			if ( ! empty( $cart_item_data[ $key ] ) ) {
				return true;
			}
		}
		return false;
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
		// (see WC_Cart::set_quantity()), not a request for a 0-seat group.
		// Enforcing the seat minimum against it would make the item unremovable.
		if ( 0 === (int) $quantity ) {
			return $passed;
		}
		$product_id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
		// Editing the seat count on the cart page is a plain cart update: the switch
		// it belongs to is recorded on the cart item, not in the request, so it has
		// to be handed over or the occupancy rule would not apply here at all.
		$error = self::get_quantity_error( $product_id, $quantity, (int) ( $values['subscription_switch']['subscription_id'] ?? 0 ) );
		if ( null === $error ) {
			return true;
		}
		wc_add_notice( $error, 'error' );
		return false;
	}

	/**
	 * The seats a subscription already holds on the product being bought.
	 *
	 * Zero unless the subscription's own line item is for that same product.
	 *
	 * @param \WC_Subscription|null $subscription The subscription being switched, if any.
	 * @param \WC_Product|int       $product      Product object or ID being bought.
	 *
	 * @return int
	 */
	private static function get_held_seats( $subscription, $product ) {
		if ( ! $subscription ) {
			return 0;
		}
		$item = Group_Subscription_Settings::get_seat_line_item( $subscription );
		if ( ! $item ) {
			return 0;
		}
		$item_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$product_id      = is_object( $product ) ? $product->get_id() : (int) $product;
		return (int) $item_product_id === (int) $product_id ? max( 0, (int) $item->get_quantity() ) : 0;
	}

	/**
	 * Count the seats a group has already committed.
	 *
	 * Everyone in the group — the owner included — occupies a seat, and so does
	 * every invitation still waiting to be accepted. An expired one holds nothing.
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
	 * store's "prorate recurring price" setting is on — off by default. Every
	 * switch that does not land on a per-seat plan keeps the publisher's setting.
	 *
	 * What decides it is the plan being switched TO, never the one being left.
	 *
	 * `'yes'` rather than `'yes-upgrade'`, so a seat decrease is prorated too: the
	 * owner is credited the unused part of the cycle, which WooCommerce Subscriptions
	 * pays back by moving the next payment date out rather than by refunding.
	 *
	 * @param mixed $value The pre-option value: false unless something has already answered.
	 *
	 * @return mixed 'yes' for a switch onto a per-seat plan, otherwise the value unchanged.
	 */
	public static function force_recurring_proration( $value ) {
		// WooCommerce's settings screen renders this option into the form and saves
		// back whatever it rendered, so answering there would rewrite the publisher's
		// own setting. A switch is priced on the front end, including the modal
		// checkout's admin-ajax requests, which is why those are let past.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value;
		}
		// Two ways to recognise the same switch, because it is priced in two passes.
		// The add-to-cart request still carries `switch-subscription` and the product
		// it is buying; by the time the checkout totals are recalculated those request
		// parameters are gone and the cart item is the only record of either.
		if ( self::get_owned_switch_subscription() && Group_Subscription_Settings::is_per_seat( self::get_requested_product_id() ) ) {
			return 'yes';
		}
		if ( self::cart_has_per_seat_switch( self::get_cart_items() ) ) {
			return 'yes';
		}
		return $value;
	}

	/**
	 * Whether a cart holds a switch onto a per-seat plan.
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
			if ( empty( $cart_item['subscription_switch']['subscription_id'] ) ) {
				continue;
			}
			$product_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 );
			if ( $product_id && Group_Subscription_Settings::is_per_seat( $product_id ) ) {
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
		// `WC_Cart::get_cart()` loads the cart from the session when it has not been
		// loaded yet, so reading it from a `pre_option_` callback that runs early
		// would force that load out of turn. Totals are calculated after the load,
		// which is the pass this branch exists for.
		if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) ) {
			return [];
		}
		return \WC()->cart->get_cart();
	}

	/**
	 * Get the subscription the requester is switching, if it is their own.
	 *
	 * Ownership only: a seat count means nothing against a group somebody else
	 * owns, and answering for one would let a crafted request read back how many
	 * people are in it.
	 *
	 * @param int|null $subscription_id A subscription ID to resolve instead of the request's.
	 *                                  Null reads the request; 0 means there is no switch,
	 *                                  which is not the same thing.
	 *
	 * @return \WC_Subscription|null The subscription being switched, or null.
	 */
	private static function get_owned_switch_subscription( $subscription_id = null ) {
		if ( null === $subscription_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$subscription_id = isset( $_REQUEST['switch-subscription'] ) ? absint( wp_unslash( $_REQUEST['switch-subscription'] ) ) : 0;
		}
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id || ! is_user_logged_in() ) {
			return null;
		}
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			return null;
		}
		return $subscription;
	}

	/**
	 * Get the product a request is buying, variation first.
	 *
	 * WooCommerce's own add-to-cart form posts `add-to-cart` (plus `variation_id`
	 * for a variable product) and the modal checkout posts `product_id`, so all
	 * three are read. Zero when the request is not buying anything.
	 *
	 * @return int Product or variation ID.
	 */
	private static function get_requested_product_id() {
		foreach ( [ 'variation_id', 'product_id', 'add-to-cart' ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product_id = isset( $_REQUEST[ $key ] ) ? absint( wp_unslash( $_REQUEST[ $key ] ) ) : 0;
			if ( $product_id ) {
				return $product_id;
			}
		}
		return 0;
	}

	/**
	 * Get the blocking error message for a seat quantity.
	 *
	 * Null when the product has nothing to do with groups, or when the quantity
	 * fits the product's bounds and the group it is for.
	 *
	 * The product here is the one being bought, so a group switching onto a
	 * per-seat plan is measured against its seats whatever plan it is leaving.
	 *
	 * @param \WC_Product|int $product                Product object or ID.
	 * @param int             $quantity               Requested quantity.
	 * @param int|null        $switch_subscription_id Subscription being switched. Null reads
	 *                                                the request; 0 means no switch at all.
	 * @param array           $cart_item_data         Cart item data, when the caller has any.
	 *
	 * @return string|null The error message, or null.
	 */
	private static function get_quantity_error( $product, $quantity, $switch_subscription_id = null, $cart_item_data = [] ) {
		// Nothing here is a rule about a subscription the reader already owns, so a
		// cart WooCommerce Subscriptions is filling to take payment for one is left
		// alone.
		if ( self::is_wcs_payment_cart_item( $cart_item_data ) ) {
			return null;
		}
		if ( ! Group_Subscription_Settings::is_per_seat( $product ) ) {
			// One flat plan is the whole group, so buying several would bill the group
			// price over and over for capacity the plan's own limit already covers.
			if ( self::is_flat_group( $product ) && (int) $quantity > 1 ) {
				return sprintf(
					/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
					__( 'This %s plan is sold as a single subscription.', 'newspack-plugin' ),
					Group_Subscription::get_label_lower( 'singular' )
				);
			}
			return null;
		}
		$subscription = self::get_owned_switch_subscription( $switch_subscription_id );
		$result       = self::validate_quantity( $product, $quantity, self::get_held_seats( $subscription, $product ) );
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		// Shrinking a group is fine, but not below the people already in it: a seat
		// taken from a member or a pending invitation would leave the group over its
		// own limit. Nobody can be removed on the owner's behalf here.
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
