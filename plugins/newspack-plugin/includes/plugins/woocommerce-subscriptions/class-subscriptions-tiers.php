<?php
/**
 * Subscription tiers functionality for WooCommerce Subscriptions.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Subscription_Products\Purchase_Option;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Subscriptions_Tiers {
	/**
	 * Switch subscription links rendered in the page.
	 *
	 * @var array
	 */
	private static $switch_subscription_links = [];

	/**
	 * Initialize hooks.
	 */
	public static function init_hooks() {
		add_filter( 'woocommerce_subscriptions_switch_link_text', [ __CLASS__, 'switch_link_text' ], 11, 3 );
		add_filter( 'woocommerce_subscriptions_switch_link_text', [ __CLASS__, 'cache_switch_subscription_link_data' ], 10, 4 );
		add_action( 'wp_footer', [ __CLASS__, 'print_switch_subscription_link_modal' ] );

		// Order button text.
		add_filter( 'wcs_place_subscription_order_text', [ __CLASS__, 'order_button_text' ], 9 );
		add_filter( 'woocommerce_order_button_text', [ __CLASS__, 'order_button_text' ], 20 );
		add_filter( 'option_woocommerce_subscriptions_order_button_text', [ __CLASS__, 'order_button_text' ], 9 );

		add_filter( 'newspack_blocks_modal_checkout_quantity', [ __CLASS__, 'vouch_switch_quantity' ], 10, 3 );

		// Link-triggered modal rendering.
		add_action( 'wp_footer', [ __CLASS__, 'print_modal' ] );
		add_filter( 'newspack_popups_assess_has_disabled_popups', [ __CLASS__, 'disable_popups' ] );

		// Server-side backstop preventing a switch to the subscription the reader
		// already owns (NPPM-2952). The front-end guard is the primary defense.
		// Registered on both filters because they cover different entry points:
		// `woocommerce_add_to_cart_validation` is applied by WooCommerce's request
		// handlers (form handler, AJAX, Store API, session restore) but NOT by
		// `WC_Cart::add_to_cart()` itself, which `Modal_Checkout` calls directly —
		// `woocommerce_add_cart_item_data` runs inside `add_to_cart()` on every
		// path and covers those direct calls, at priority 9 so the request is
		// rejected just before WooCommerce Subscriptions consumes the same switch
		// params (priority 10). Both no-op unless the switch targets a product the
		// current user's own subscription already holds.
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'prevent_switch_to_same_subscription' ], 10, 4 );
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'prevent_switch_to_same_subscription_cart_item_data' ], 9, 3 );

		// Unhook Upgrade/Downgrade switch direction text.
		add_action(
			'init',
			function() {
				remove_filter( 'woocommerce_cart_item_subtotal', [ 'WC_Subscriptions_Switcher', 'add_cart_item_switch_direction' ], 10 );
			}
		);
	}

	/**
	 * Switch link text.
	 *
	 * @param string                 $text    The text of the switch subscription link.
	 * @param int                    $item_id The ID of the item.
	 * @param \WC_Order_Item_Product $item    The order line item data.
	 *
	 * @return string The text of the switch subscription link.
	 */
	public static function switch_link_text( $text, $item_id, $item ) {
		if ( Donations::is_donation_product( $item->get_product_id() ) ) {
			return __( 'Edit donation', 'newspack-plugin' );
		}
		return __( 'Change subscription', 'newspack-plugin' );
	}

	/**
	 * Get the URL for the subscription upgrade modal.
	 *
	 * @param string|null $title The title of the subscription upgrade modal.
	 *
	 * @return string The URL for the subscription upgrade modal.
	 */
	public static function get_upgrade_subscription_url( $title = null ) {
		/**
		 * Filters the URL for the subscription upgrade modal.
		 *
		 * @param string      $url   The URL for the subscription upgrade modal.
		 * @param string|null $title The title of the subscription upgrade modal.
		 */
		return apply_filters( 'newspack_subscriptions_upgrade_subscription_url', add_query_arg( self::get_upgrade_subscription_query_param(), $title ?? 1, home_url() ), $title );
	}

	/**
	 * Get the URL query parameter that triggers the subscription upgrade modal.
	 *
	 * @return string The URL query parameter.
	 */
	public static function get_upgrade_subscription_query_param() {
		/**
		 * Filters the URL query parameter that triggers the subscription upgrade modal.
		 *
		 * @param string $query_param The URL query parameter.
		 */
		return apply_filters( 'newspack_subscriptions_upgrade_subscription_query_param', 'upgrade-subscription' );
	}

	/**
	 * Get the URL for triggering the tiers modal for a given product.
	 *
	 * @return string The URL for triggering the tiers modal.
	 */
	public static function get_tiers_modal_query_param() {
		/**
		 * Filters the URL query parameter that triggers the tiers modal.
		 *
		 * @param string $query_param The URL query parameter.
		 */
		return apply_filters( 'newspack_subscriptions_purchase_product_query_param', 'tiers-modal' );
	}

	/**
	 * Store switch subscription links in memory so we can render the modal later.
	 *
	 * @param string                 $text         The text of the switch subscription link.
	 * @param int                    $item_id      The ID of the item.
	 * @param \WC_Order_Item_Product $item         The order line item data.
	 * @param \WC_Subscription       $subscription The subscription.
	 *
	 * @return string The text of the switch subscription link.
	 */
	public static function cache_switch_subscription_link_data( $text, $item_id, $item, $subscription ) {
		self::$switch_subscription_links[ $item_id ] = [
			'item_id'      => $item_id,
			'item'         => $item,
			'subscription' => $subscription,
		];
		return $text;
	}

	/**
	 * Vouch for the quantity a switch is carrying over from the subscription it
	 * is changing.
	 *
	 * The modal checkout adds the chosen product at a quantity of one unless a
	 * plugin vouches for another, so a tier change on a multi-quantity line item
	 * would otherwise rewrite it down to one.
	 *
	 * Only a quantity matching the line item being switched is vouched for, which
	 * is what makes it safe to honour from a request that needs no nonce. Raising
	 * the count is a seat change, and belongs to whoever sells seats.
	 *
	 * @param null|int $vouched    The quantity vouched for so far, or null.
	 * @param int      $product_id Product the quantity is for (variation preferred).
	 * @param int      $requested  Requested quantity, at least 1.
	 *
	 * @return null|int The carried-over quantity, or the unchanged incoming value.
	 */
	public static function vouch_switch_quantity( $vouched, $product_id, $requested = 1 ) {
		if ( null !== $vouched || ! is_user_logged_in() || ! function_exists( 'wcs_get_subscription' ) ) {
			return $vouched;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reads only, and answers nothing for a subscription the requester does not own.
		$subscription_id = isset( $_REQUEST['switch-subscription'] ) ? absint( wp_unslash( $_REQUEST['switch-subscription'] ) ) : 0;
		$item_id         = isset( $_REQUEST['item'] ) ? absint( wp_unslash( $_REQUEST['item'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $subscription_id || ! $item_id ) {
			return $vouched;
		}
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			return $vouched;
		}
		$line_item = $subscription->get_item( $item_id, false );
		if ( ! $line_item ) {
			return $vouched;
		}
		return max( 1, (int) $line_item->get_quantity() ) === (int) $requested ? (int) $requested : $vouched;
	}

	/**
	 * Register a switch modal for a subscription line item.
	 *
	 * WooCommerce Subscriptions' own switch links fire
	 * `woocommerce_subscriptions_switch_link_text`, so they get a modal for free
	 * via `cache_switch_subscription_link_data()`. A screen that prints its own
	 * switch link — the group page's "Change seats" — never fires that filter, so
	 * it calls this to record the same entry the footer reads.
	 *
	 * @param int                    $item_id      The ID of the item.
	 * @param \WC_Order_Item_Product $item         The order line item data.
	 * @param \WC_Subscription       $subscription The subscription.
	 */
	public static function register_switch_modal( $item_id, $item, $subscription ) {
		self::cache_switch_subscription_link_data( '', $item_id, $item, $subscription );
	}

	/**
	 * Print modals for switch subscription links rendered in the page.
	 */
	public static function print_switch_subscription_link_modal() {
		if ( empty( self::$switch_subscription_links ) ) {
			return;
		}
		if ( ! function_exists( 'wcs_is_product_switchable_type' ) ) {
			return;
		}
		foreach ( self::$switch_subscription_links as $switch_data ) {
			// The canonical ID, so a tiered plan is judged on the variation the reader
			// holds: per-seat meta lives on the variation, and asking about the parent
			// would print no modal behind a switch link this class already rendered.
			$switchable_id = function_exists( 'wcs_get_canonical_product_id' )
				? wcs_get_canonical_product_id( $switch_data['item'] )
				: $switch_data['item']['product_id'];
			if ( ! wcs_is_product_switchable_type( $switchable_id ) ) {
				continue;
			}
			$product = wc_get_product( $switch_data['item']['product_id'] );
			// A product deleted since the purchase has no tiers to switch between.
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			// Reset per iteration: a product that resolves to no parent must not
			// inherit the previous link's modal.
			$parent_product  = null;
			$parent_products = \WC_Subscriptions_Product::get_visible_grouped_parent_product_ids( $product );
			if ( ! empty( $parent_products ) ) {
				$parent_product = wc_get_product( reset( $parent_products ) );
			} elseif ( $product->is_type( [ 'variable', 'variable-subscription' ] ) && Subscription_Products::offers_subscription( $product ) ) {
				$parent_product = $product;
			} elseif ( $product->get_parent_id() ) {
				$parent_product = wc_get_product( $product->get_parent_id() );
			} elseif ( Subscription_Products::offers_subscription( $product ) ) {
				// A simple subscription is the only tier it offers, and reaching here
				// means something declared it switchable (a per-seat group plan does).
				// A simple product sold on plans offers one tier per plan.
				$parent_product = $product;
			}
			if ( ! $parent_product ) {
				continue;
			}
			$label = __( 'Change subscription', 'newspack-plugin' );
			$title = null; // Reset per iteration, so a donation's title cannot carry to the next link.
			if ( Donations::is_donation_product( $parent_product->get_id() ) ) {
				$title = __( 'Edit donation', 'newspack-plugin' );
				$label = __( 'Confirm donation', 'newspack-plugin' );
			}
			self::render_modal( $parent_product, $title ?? $label, $label, $switch_data );
		}
	}

	/**
	 * Get the primary subscription tier product.
	 *
	 * @return \WC_Product|null Product or null if no product is set.
	 */
	public static function get_primary_subscription_tier_product() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = get_option( 'newspack_subscriptions_primary_subscription_tier_product' );
		if ( ! $product ) {
			return null;
		}
		return wc_get_product( $product );
	}

	/**
	 * Set the primary subscription tier product.
	 *
	 * @param \WC_Product|null $product Product.
	 */
	public static function set_primary_subscription_tier_product( $product ) {
		update_option( 'newspack_subscriptions_primary_subscription_tier_product', $product ? $product->get_id() : '' );
	}

	/**
	 * Get all subscription products that are eligible for tier configuration.
	 *
	 * @param array $types Product types to filter by.
	 *
	 * @return \WC_Product[] Products.
	 */
	public static function get_tier_eligible_products( $types = [ 'grouped', 'variable-subscription' ] ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$products = wc_get_products(
			[
				'type'  => $types,
				'limit' => -1,
			]
		);

		// Plan-based products are plain simple/variable products, so no type list finds
		// them. Only admit ones sold subscription-only: this list also feeds the
		// countdown banner and gifting-prompt product pickers, whose forms post only a
		// product ID, no plan. WooCommerce applies a forced product's default plan in
		// the cart, so those product-only forms still start a subscription for a
		// subscription-only product. A product also sold one-time needs its plan
		// posted explicitly, which only the tiers modal (this list's other consumer) does.
		// They count as subscriptions, so only a caller asking for a subscription type
		// gets them, in the same statuses the type query above returns.
		$ids = array_map( fn( $product ) => $product->get_id(), $products );
		$candidates = array_intersect( (array) $types, [ 'subscription', 'variable-subscription' ] ) ? Subscription_Products::find_products() : [];
		foreach ( $candidates as $candidate ) {
			if ( $candidate->is_type( [ 'subscription', 'variable-subscription' ] ) || in_array( $candidate->get_id(), $ids, true ) ) {
				continue;
			}
			// Eligibility is the product's configuration, whoever is asking.
			if ( ! Subscription_Products::is_sold_both_ways( $candidate ) ) {
				$products[] = $candidate;
			}
		}

		// Filter out donation products.
		$products = array_filter(
			$products,
			function( $product ) {
				return ! Donations::is_donation_product( $product->get_id() );
			}
		);

		// Filter out grouped products that don't have any subscription products. Only
		// legacy children count: a grouped form cannot post a plan for a plan-based
		// child, so get_tiers_by_frequency() leaves those out, and a grouped product
		// holding nothing else would open an empty modal.
		$products = array_filter(
			$products,
			function( $product ) {
				if ( $product->is_type( 'grouped' ) ) {
					$children = $product->get_children();
					foreach ( $children as $child ) {
						$child = wc_get_product( $child );
						if ( ! $child ) {
							continue;
						}
						if ( $child->is_type( 'subscription' ) || $child->is_type( 'variable-subscription' ) ) {
							return true;
						}
					}
					return false;
				}
				return true;
			}
		);

		return array_values( $products );
	}

	/**
	 * Get the frequency of a product.
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return string Frequency.
	 */
	public static function get_frequency( $product ) {
		// A plan-based product carries its schedule on the plan applied to this
		// instance; its own `_subscription_*` data does not exist until checkout.
		$option = Subscription_Products::get_instance_option( $product );
		if ( $option && Purchase_Option::KIND_PLAN === $option->kind ) {
			return $option->get_frequency();
		}

		$period = $product->get_meta( '_subscription_period', true );
		if ( empty( $period ) ) {
			$period = 'once';
		}
		$interval = $product->get_meta( '_subscription_period_interval', true );
		if ( empty( $interval ) ) {
			$interval = 1;
		}
		return $period . '_' . $interval;
	}

	/**
	 * Get tiered products by frequency given a grouped or
	 * variable subscription product.
	 *
	 * If no product is provided, it will use all
	 * non-donation subscription products.
	 *
	 * A product sold on subscription plans contributes one tier per plan and
	 * product (or variation), each an instance with that plan applied. Two plans
	 * sharing a billing period get a bucket each, the second keyed with a suffix
	 * (`month_1_2`), and a plan never shares a bucket with a legacy product: every
	 * bucket is bought on a single plan field, or on none.
	 *
	 * @param \WC_Product|null $product       Optional product.
	 * @param bool|null        $sort_by_price Whether to sort by price.
	 *
	 * @return array<string, \WC_Product[]> Product tiers by frequency.
	 */
	public static function get_tiers_by_frequency( $product = null, $sort_by_price = null ) {
		if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wcs_user_has_subscription' ) ) {
			return [];
		}

		if ( empty( $product ) ) {
			$products = wc_get_products(
				[
					'type'  => [ 'subscription', 'variable-subscription' ],
					'limit' => -1,
				]
			);
			// Plan-based products are plain simple/variable products, so no type query finds them.
			foreach ( Subscription_Products::find_products( [ 'status' => 'publish' ] ) as $candidate ) {
				if ( ! $candidate->is_type( [ 'subscription', 'variable-subscription' ] ) ) {
					$products[] = $candidate;
				}
			}
			$sort_by_price = $sort_by_price ?? true;
		} elseif ( $product->is_type( 'grouped' ) ) {
			$products = $product->get_children();
			$sort_by_price = $sort_by_price ?? false;
		} elseif ( Subscription_Products::offers_subscription( $product ) ) {
			$products = [ $product ];
			$sort_by_price = $sort_by_price ?? true;
		}

		if ( empty( $products ) ) {
			return [];
		}

		// A grouped form carries one plan field per parent product, so it cannot post
		// a plan for a plan-based child.
		$from_grouped      = $product instanceof \WC_Product && $product->is_type( 'grouped' );
		$selected_products = [];

		foreach ( $products as $candidate ) {
			if ( is_int( $candidate ) ) {
				$candidate = wc_get_product( $candidate );
			}

			// A deleted grouped child leaves an ID that no longer resolves.
			if ( ! $candidate instanceof \WC_Product || ! Subscription_Products::offers_subscription( $candidate ) ) {
				continue;
			}

			if ( $candidate->get_status() === 'private' ) {
				continue;
			}

			if ( $candidate->is_type( [ 'subscription', 'variable-subscription' ] ) ) {
				// Extract the variations if it's a variable subscription product.
				if ( $candidate->is_type( 'variable-subscription' ) ) {
					$variations = $candidate->get_available_variations();
					foreach ( $variations as $variation ) {
						$selected_products[] = new \WC_Product_Variation( $variation['variation_id'] );
					}
				} else {
					$selected_products[] = $candidate;
				}
				continue;
			}

			// Skip rather than sell the child once.
			if ( $from_grouped ) {
				continue;
			}

			$available = $candidate->is_type( 'variable' )
				? array_map( 'intval', wp_list_pluck( $candidate->get_available_variations(), 'variation_id' ) )
				: null;
			foreach ( Subscription_Products::get_purchase_options( $candidate ) as $option ) {
				// Tiers sell subscriptions; the one-time option is not a tier.
				if ( Purchase_Option::KIND_PLAN !== $option->kind ) {
					continue;
				}
				if ( null !== $available && ! in_array( $option->product_id, $available, true ) ) {
					continue;
				}
				$instance = Subscription_Products::get_option_product( $option );
				// Never sell a plan the form cannot post: without its field, WooCommerce
				// would charge once instead of starting a subscription.
				if ( $instance && self::get_plan_field( $instance ) ) {
					$selected_products[] = $instance;
				}
			}
		}

		$products_by_frequency = [];
		$bucket_fields         = [];
		foreach ( $selected_products as $selected ) {
			$frequency = self::get_frequency( $selected );
			if ( ! $frequency ) {
				continue;
			}
			// Legacy products post no plan field, so they bucket by frequency alone.
			$field  = self::get_plan_field( $selected );
			$posted = $field ? (string) array_key_first( $field ) . '=' . reset( $field ) : '';
			$bucket = $frequency;
			$suffix = 2;
			while ( isset( $bucket_fields[ $bucket ] ) && $bucket_fields[ $bucket ] !== $posted ) {
				$bucket = $frequency . '_' . $suffix;
				++$suffix;
			}
			$bucket_fields[ $bucket ]           = $posted;
			$products_by_frequency[ $bucket ][] = $selected;
		}

		if ( $sort_by_price ) {
			foreach ( $products_by_frequency as $frequency => $products ) {
				usort(
					$products,
					function( $a, $b ) {
						return intval( $a->get_price() ) <=> intval( $b->get_price() );
					}
				);
				$products_by_frequency[ $frequency ] = $products;
			}
		}

		return $products_by_frequency;
	}

	/**
	 * Find the tier the current user is actively subscribed to, if any.
	 *
	 * A subscription counts as "current" when it holds one of the tier products
	 * and is in one of the statuses we treat as owned:
	 * {@see WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES} (`active` or
	 * `pending-cancel`). This must stay in sync with the status set used to
	 * decide switch eligibility in
	 * {@see WooCommerce_Subscriptions::get_user_subscription()}. If the two
	 * diverge, a switch can be offered for a subscription that is never flagged
	 * as "current" — which drops the "Current" badge and the front-end guard
	 * that stops a reader switching to the subscription they already own
	 * (NPPM-2952).
	 *
	 * @param array<string, \WC_Product[]> $tiers   Tier products grouped by frequency.
	 * @param int|null                     $user_id Optional user ID. Defaults to the current user.
	 *
	 * @return array The current frequency (string|null), tier product
	 *               (\WC_Product|null) and subscription (\WC_Subscription|null),
	 *               or a triple of nulls when the user owns none of the tiers.
	 */
	public static function get_current_tier( $tiers, $user_id = null ) {
		$none = [ null, null, null ];
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return $none;
		}
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return $none;
		}
		$user_subscriptions = wcs_get_users_subscriptions( $user_id );
		foreach ( $tiers as $frequency => $products ) {
			foreach ( $products as $product ) {
				// A plan-based product sits in every plan's bucket, so holding it is
				// not enough: it is current only in the bucket of the plan held.
				$option = Subscription_Products::get_instance_option( $product );
				$plan   = $option && Purchase_Option::KIND_PLAN === $option->kind ? $option : null;
				foreach ( $user_subscriptions as $subscription ) {
					if (
						$subscription->has_product( $product->get_id() )
						&& $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES )
						// `wcs_get_users_subscriptions` is filtered (e.g. group
						// subscriptions inject subs the user is only a member of, owned
						// by someone else); only a subscription the user owns is their
						// "current" tier — matching the ownership test the switch
						// backstop applies.
						&& (int) $subscription->get_user_id() === (int) $user_id
					) {
						if ( $plan && ! self::subscription_is_on_plan( $subscription, $plan ) ) {
							continue;
						}
						return [ $frequency, $product, $subscription ];
					}
				}
			}
		}
		return $none;
	}

	/**
	 * Whether a subscription holds the plan option's product on that plan.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param Purchase_Option  $option       Plan option.
	 *
	 * @return bool
	 */
	private static function subscription_is_on_plan( $subscription, Purchase_Option $option ) {
		foreach ( $subscription->get_items() as $item ) {
			$item_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			if ( (int) $item_product_id === $option->product_id && self::line_item_is_on_plan( $subscription, $item, $option ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a subscription line item was bought on the plan option's plan. An item
	 * with no recorded plan falls back to the subscription's billing schedule, which
	 * cannot tell apart two plans sharing one, but beats matching every plan.
	 *
	 * @param \WC_Subscription       $subscription Subscription the item belongs to.
	 * @param \WC_Order_Item_Product $item         Line item.
	 * @param Purchase_Option        $option       Plan option.
	 *
	 * @return bool
	 */
	private static function line_item_is_on_plan( $subscription, $item, Purchase_Option $option ) {
		$plan_key = Subscription_Products::get_purchased_plan_key( $item );
		if ( '' !== $plan_key ) {
			return $plan_key === $option->plan_key;
		}
		return $subscription->get_billing_period() . '_' . max( 1, (int) $subscription->get_billing_interval() ) === $option->get_frequency();
	}

	/**
	 * The request field that buys a tier on its plan, as `[ field => value ]`, or an
	 * empty array for a tier that is not a plan instance.
	 *
	 * @param \WC_Product $product Tier product.
	 *
	 * @return array<string, string>
	 */
	private static function get_plan_field( $product ) {
		$option = Subscription_Products::get_instance_option( $product );
		return $option && Purchase_Option::KIND_PLAN === $option->kind ? $option->get_plan_request_args() : [];
	}

	/**
	 * Labels for plan buckets that share a period and interval with another bucket
	 * (`month_1` and `month_1_2`), which would otherwise both read "Monthly". Each
	 * names what sets its plan apart: its price when the buckets' prices differ, its
	 * length, trial and sign-up fee; a bucket nothing sets apart gets its position.
	 *
	 * @param array $tiers       Tiers by bucket.
	 * @param array $plan_fields Bucket => the field that buys its plan.
	 *
	 * @return array<string, string> Bucket => label, for the plan buckets that need one.
	 */
	private static function get_plan_bucket_labels( $tiers, $plan_fields ) {
		$groups = [];
		foreach ( array_keys( $tiers ) as $bucket ) {
			$groups[ implode( '_', array_slice( explode( '_', $bucket ), 0, 2 ) ) ][] = $bucket;
		}
		$labels = [];
		foreach ( $groups as $buckets ) {
			if ( count( $buckets ) < 2 ) {
				continue;
			}
			$prices = [];
			foreach ( $buckets as $bucket ) {
				$prices[ $bucket ] = min( array_map( fn( $product ) => (float) $product->get_price(), $tiers[ $bucket ] ) );
			}
			$prices_differ = count( array_unique( $prices ) ) > 1;
			$seen          = [];
			foreach ( $buckets as $position => $bucket ) {
				if ( empty( $plan_fields[ $bucket ] ) ) {
					$seen[] = WooCommerce_Subscriptions::get_frequency_label( $bucket );
					continue;
				}
				$option = Subscription_Products::get_instance_option( reset( $tiers[ $bucket ] ) );
				$parts  = [];
				if ( $prices_differ ) {
					$parts[] = self::format_amount( $prices[ $bucket ] );
				}
				if ( $option && $option->length ) {
					/* translators: %s: number of payments before the subscription ends. */
					$parts[] = sprintf( _n( '%s payment', '%s payments', $option->length, 'newspack-plugin' ), number_format_i18n( $option->length ) );
				}
				if ( $option && $option->trial_length ) {
					// WooCommerce Subscriptions' period string carries the number only when it is above one.
					$period  = function_exists( 'wcs_get_subscription_period_strings' ) ? wcs_get_subscription_period_strings( $option->trial_length, $option->trial_period ) : $option->trial_period;
					$trial   = $option->trial_length > 1 && function_exists( 'wcs_get_subscription_period_strings' ) ? $period : number_format_i18n( $option->trial_length ) . ' ' . $period;
					/* translators: %s: trial length, e.g. "7 days". */
					$parts[] = sprintf( __( '%s free trial', 'newspack-plugin' ), $trial );
				}
				if ( $option && $option->sign_up_fee > 0 ) {
					/* translators: %s: sign-up fee amount. */
					$parts[] = sprintf( __( '%s sign-up fee', 'newspack-plugin' ), self::format_amount( $option->sign_up_fee ) );
				}
				$label = WooCommerce_Subscriptions::get_frequency_label( $bucket );
				if ( $parts ) {
					$label .= ' · ' . implode( ', ', $parts );
				}
				if ( in_array( $label, $seen, true ) ) {
					/* translators: 1: frequency label, 2: the option's position among those sharing the label. */
					$label = sprintf( __( '%1$s (%2$s)', 'newspack-plugin' ), $label, number_format_i18n( $position + 1 ) );
				}
				$seen[]            = $label;
				$labels[ $bucket ] = $label;
			}
		}
		return $labels;
	}

	/**
	 * An amount in the store currency, as plain text.
	 *
	 * @param float $amount Amount.
	 *
	 * @return string
	 */
	private static function format_amount( $amount ) {
		return function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : number_format_i18n( $amount, 2 );
	}

	/**
	 * Get product title.
	 *
	 * @param \WC_Product $product                   Product.
	 * @param bool        $show_variation_attributes Whether the product title should include the variation attributes.
	 *
	 * @return string Product title.
	 */
	private static function get_product_title( $product, $show_variation_attributes = false ) {
		$product_name = $product->get_title();
		if ( $product->is_type( 'variation' ) && $show_variation_attributes ) {
			// An "Any <attribute>" variation stores that attribute as an empty
			// string, which would print as "Plan ()" or "Plan (, Annual)".
			$attributes = implode( ', ', array_filter( $product->get_variation_attributes(), 'strlen' ) );
			if ( '' !== $attributes ) {
				$product_name = sprintf( '%s (%s)', $product_name, $attributes );
			}
		}
		return $product_name;
	}

	/**
	 * Whether there's only 1 item per frequency.
	 *
	 * @param array $tiers Tiers.
	 * @return bool
	 */
	private static function is_single_tier( $tiers ) {
		foreach ( $tiers as $frequency ) {
			if ( count( $frequency ) !== 1 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the given tiers are all "name your price" products. A plan tier never
	 * is: the name-your-price card reads its schedule from legacy subscription meta,
	 * which a plan product does not have.
	 *
	 * @param array $tiers Tiers.
	 *
	 * @return bool
	 */
	private static function is_nyp( $tiers ) {
		foreach ( $tiers as $frequency ) {
			foreach ( $frequency as $product ) {
				if ( $product->get_meta( '_nyp' ) !== 'yes' || self::get_plan_field( $product ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Render a subscription product card.
	 *
	 * @param \WC_Product $product                   Product.
	 * @param bool        $show_variation_attributes Whether the card should render the product variation attributes.
	 * @param bool        $current                   Whether the product should have the "current" badge.
	 * @param bool        $selected                  Whether the product should be checked.
	 * @param int         $seats_ceiling             Seats the current plan already holds, so its radio advertises the same widened maximum as the seats field. 0 for every card but the current one.
	 */
	private static function render_product_card( $product, $show_variation_attributes = false, $current = false, $selected = false, $seats_ceiling = 0 ) {
		// A name-your-price product has no fixed price to print — get_price() is
		// empty, so wcs_price_string() would render a bare "/ month" — and the
		// amount is carried by the form's own input instead.
		$is_nyp = class_exists( '\WC_Name_Your_Price_Helpers' )
			? \WC_Name_Your_Price_Helpers::is_nyp( $product->get_id() )
			: 'yes' === $product->get_meta( '_nyp' );
		$price  = '';
		if ( ! $is_nyp ) {
			if ( function_exists( 'wcs_price_string' ) ) {
				// A plan instance's schedule is its plan's. Its price is still read from
				// the product, where WooCommerce applies the plan's price and any
				// price filters (subscriber discounts among them).
				$option  = Subscription_Products::get_instance_option( $product );
				$is_plan = $option && Purchase_Option::KIND_PLAN === $option->kind;
				$price   = wcs_price_string(
					[
						'recurring_amount'      => $product->get_price(),
						'subscription_period'   => $is_plan ? $option->period : $product->get_meta( '_subscription_period' ),
						'subscription_interval' => $is_plan ? $option->interval : $product->get_meta( '_subscription_period_interval' ),
					]
				);
			} else {
				$price = $product->get_price_html();
			}
		}

		/**
		 * Hides product descriptions in subscription tier displays.
		 * Useful for cleaner checkout experiences.
		 *
		 * @constant NEWSPACK_DISABLE_SUBSCRIPTION_DESCRIPTION
		 * @type     bool
		 * @default  Subscription descriptions shown
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_DISABLE_SUBSCRIPTION_DESCRIPTION', true );
		 */
		$should_render_description = ! defined( 'NEWSPACK_DISABLE_SUBSCRIPTION_DESCRIPTION' ) || ! NEWSPACK_DISABLE_SUBSCRIPTION_DESCRIPTION;
		$description               = $product->get_description();

		// Each tier publishes its own seat bounds so the form's single seats field can
		// follow whichever one is checked. A tier with no attributes here sells no
		// seats, which is what tells the field to hide and stop submitting.
		$seats = Group_Subscription_Seats::get_field_args( $product );

		// The plan the reader already holds carries the same widened ceiling the seats
		// field uses: a group that outgrew a since-lowered maximum keeps the seats it
		// pays for. The client clamp reads this radio, not the field, so without the
		// match it would pull those seats back down to the plan's raw maximum on load.
		// Only the current plan has a ceiling (render_form() sets it only when staying
		// on plan), and only a per-seat, bounded tier has a maximum to raise.
		if ( $seats && $current && $seats_ceiling > 0 && $seats['max'] > 0 ) {
			$seats['max'] = max( $seats['max'], $seats_ceiling );
		}

		?>
		<label class="newspack-ui__input-card <?php echo $current ? esc_attr( 'current' ) : ''; ?>">
			<?php if ( $current ) : ?>
				<span class="newspack-ui__badge newspack-ui__badge--primary"><?php _e( 'Current', 'newspack-plugin' ); ?></span>
			<?php endif; ?>
			<input type="radio" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>"<?php echo $seats ? ' data-per-seat="1" data-seats-min="' . esc_attr( $seats['min'] ) . '" data-seats-max="' . esc_attr( $seats['max'] > 0 ? $seats['max'] : '' ) . '"' : ''; ?> <?php echo esc_attr( $selected ? 'checked' : '' ); ?>>
			<strong><?php echo esc_html( self::get_product_title( $product, $show_variation_attributes ) ); ?></strong>
			<?php if ( $should_render_description && $description ) : ?>
				<span class="newspack-ui__helper-text"><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
			<?php if ( $price ) : ?>
				<span class="newspack-ui__helper-text"><?php echo $price; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render a "name your price" product card.
	 *
	 * @param \WC_Product $product             Product.
	 * @param bool        $current             Whether this is the currently owned product.
	 * @param array|null  $switch_subscription Switch subscription data or null.
	 */
	public static function render_nyp_product_card( $product, $current = false, $switch_subscription = null ) {
		// Name-your-price tiers are legacy subscription products only, so their schedule is in their meta.
		$symbol    = get_woocommerce_currency_symbol();
		$currency  = get_woocommerce_currency();
		$value     = $product->get_price();
		$frequency = $product->get_meta( '_subscription_period' );
		$interval  = $product->get_meta( '_subscription_period_interval' );

		if ( $switch_subscription ) {
			$base_product   = wc_get_product( $switch_subscription['item']['product_id'] );
			$base_frequency = $base_product->get_meta( '_subscription_period' );
			$base_interval  = $base_product->get_meta( '_subscription_period_interval' );
			$base_amount    = $switch_subscription['item']['line_total'] / $base_interval;

			// Get the direct conversion multiplier from base frequency to target frequency.
			$multiplier = self::get_frequency_conversion_multiplier( $base_frequency, $frequency );

			if ( $current ) {
				$value = $base_amount * $multiplier;
			} else {
				$value = max( ceil( $base_amount * $multiplier * $interval ), $value );
			}
		}
		?>
		<input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>">
		<p>
			<label for="nyp_amount"><?php _e( 'Amount', 'newspack-plugin' ); ?></label>
			<div class="newspack-ui__currency-input">
				<span class="newspack-ui__currency-input__currency"><?php echo esc_html( $symbol ); ?></span>
				<input type="number" name="price" id="nyp_amount" value="<?php echo esc_attr( $value ); ?>" data-original-value="<?php echo esc_attr( $value ); ?>" data-currency="<?php echo esc_attr( $currency ); ?>" data-price-decimals="<?php echo esc_attr( wc_get_price_decimals() ); ?>" data-frequency="<?php echo esc_attr( $frequency ); ?>" class="<?php echo esc_attr( $current ? 'current' : '' ); ?>">
			</div>
		</p>
		<?php
	}

	/**
	 * Render existing subscription info.
	 *
	 * @param \WC_Product      $product            Product.
	 * @param \WC_Subscription $subscription       Subscription.
	 * @param bool             $render_button      Whether to render the button.
	 */
	public static function render_existing_subscription_info( $product, $subscription, $render_button = true ) {
		$url = $subscription->get_view_order_url();
		$label = __( 'View Subscription', 'newspack-plugin' );
		?>
		<div class="newspack-ui__notice newspack-ui__notice--warning">
			<span class="newspack-ui__notice__content">
				<?php
				printf(
					/* translators: %s: subscription product name */
					esc_html__( 'You currently have a subscription: %s. If you’d like to make changes, you can manage it from your subscription page.', 'newspack-plugin' ),
					wp_kses_post( '<strong>' . self::get_product_title( $product, true ) . '</strong>' )
				);
				?>
				<?php if ( ! $render_button ) : ?>
					<br/>
					<a href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
						<?php echo esc_html( $label ); ?> →
					</a>
				<?php endif; ?>
			</span>
		</div>
		<?php if ( $render_button ) : ?>
			<a class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render frequency form control.
	 *
	 * Up until 3 frequencies, we render buttons.
	 * After that, we render a select control.
	 *
	 * A frequency sold on a subscription plan renders as a radio posting that plan's
	 * field instead of a button, so the reader's choice of plan is what the form
	 * posts, with or without JavaScript. With any plan on offer the tabs are kept
	 * whatever the count, since a select cannot post a plan.
	 *
	 * @param array  $frequencies       Frequencies.
	 * @param string $current_frequency Current frequency.
	 * @param bool   $is_form_control     Whether to treat it as a form input.
	 * @param array  $plan_fields       Frequency => the `[ field => value ]` that buys its plan.
	 * @param array  $labels            Frequency => label, for a frequency that needs more
	 *                                  than its period and interval to tell it apart.
	 */
	public static function render_frequency_control( $frequencies, $current_frequency, $is_form_control = false, array $plan_fields = [], array $labels = [] ) {
		if ( $is_form_control ) :
			?>
			<div class="newspack-ui__segmented-control__form-control">
				<label><?php _e( 'Frequency', 'newspack-plugin' ); ?></label>
				<?php
		endif;
		if ( count( $frequencies ) <= 3 || ! empty( $plan_fields ) ) :
			// Every tab a radio: announce them as one named choice.
			$is_radiogroup = ! array_diff( $frequencies, array_keys( array_filter( $plan_fields ) ) );
			?>
			<div class="newspack-ui__segmented-control__tabs"<?php echo $is_radiogroup ? ' role="radiogroup" aria-label="' . esc_attr__( 'Frequency', 'newspack-plugin' ) . '"' : ''; ?>>
				<?php foreach ( $frequencies as $frequency ) : ?>
					<?php
					if ( ! empty( $plan_fields[ $frequency ] ) ) :
						$field_name = (string) array_key_first( $plan_fields[ $frequency ] );
						?>
					<label class="newspack-ui__button newspack-ui__button--small <?php echo esc_attr( $frequency === $current_frequency ? 'selected' : '' ); ?>">
						<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $plan_fields[ $frequency ][ $field_name ] ); ?>"<?php checked( $frequency, $current_frequency ); ?>>
						<?php echo esc_html( $labels[ $frequency ] ?? WooCommerce_Subscriptions::get_frequency_label( $frequency ) ); ?>
					</label>
					<?php else : ?>
					<button type="button" class="newspack-ui__button newspack-ui__button--small <?php echo esc_attr( $frequency === $current_frequency ? 'selected' : '' ); ?>">
						<?php echo esc_html( WooCommerce_Subscriptions::get_frequency_label( $frequency ) ); ?>
					</button>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<select>
				<?php foreach ( $frequencies as $i => $frequency ) : ?>
					<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $frequencies[ $i ], $current_frequency ); ?>>
						<?php echo esc_html( WooCommerce_Subscriptions::get_frequency_label( $frequency ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php
		endif;
		if ( $is_form_control ) {
			echo '</div>'; // Close the form control div.
		}
	}

	/**
	 * Render subscription tiers form.
	 *
	 * @param \WC_Product $product      Optional product.
	 * @param string|null $title        Optional title.
	 * @param string|null $button_label Optional button label.
	 * @param array|null  $switch_data  Switch subscription data or null.
	 */
	public static function render_form( $product = null, $title = null, $button_label = null, $switch_data = null ) {
		$tiers = self::get_tiers_by_frequency( $product );
		if ( empty( $tiers ) ) {
			return;
		}

		$is_single_tier = self::is_single_tier( $tiers );
		$is_nyp         = $is_single_tier && self::is_nyp( $tiers ); // Only treat as NYP form if there's only 1 tier.

		// The field that buys each plan-based bucket on its plan. Without it WooCommerce
		// falls back to the product's default, a one-time charge for a product sold
		// both ways, so a plan bucket must always post it. get_tiers_by_frequency()
		// keeps every bucket on a single field, and drops any tier that has none.
		$plan_fields = [];
		foreach ( $tiers as $frequency => $tier_products ) {
			$plan_field = self::get_plan_field( reset( $tier_products ) );
			if ( $plan_field ) {
				$plan_fields[ $frequency ] = $plan_field;
			}
		}

		$frequencies       = array_keys( $tiers );
		$current_frequency = null;
		$current_product   = null;
		$user_subscription = null;
		if ( is_user_logged_in() ) {
			[ $current_frequency, $current_product, $user_subscription ] = self::get_current_tier( $tiers );
		}

		// The line item being switched, when this form is a switch modal.
		$line_product = $switch_data ? $switch_data['item']->get_product() : null;

		if ( ! $switch_data ) {
			$current_frequency = $frequencies[0];
		} elseif ( ! $current_product ) {
			// The reader's plan matched none of the offered tiers. The case that
			// prompted this is a plan the publisher retired by setting it to Private
			// (kept out of the tiers so nobody new may buy in), but a product dropped
			// from the group, a trashed product, or a variation mismatch lands here
			// too, so don't narrow this to the Private status. The billing period
			// is still known from the line item being switched away from, so open
			// the modal on that period, or failing that on the first one, rather
			// than on none: a modal with no period selected renders as an empty box.
			$line_frequency    = $line_product ? self::get_frequency( $line_product ) : null;
			$current_frequency = $line_frequency && isset( $tiers[ $line_frequency ] ) ? $line_frequency : $frequencies[0];
		}

		if ( $switch_data && $current_product ) {
			$selected_product = $current_product;
		} else {
			$selected_product = $tiers[ $current_frequency ][0];
		}

		// The seats the reader already pays for. Every switch has to carry it: the
		// modal checkout adds the new product at a quantity of one, so a tier change
		// on a multi-seat line item would silently rewrite it down to a single seat.
		$line_quantity = $switch_data ? max( 1, (int) $switch_data['item']->get_quantity() ) : null;

		// Seat bounds belong to the tier being bought, not to the reader's current
		// one: two per-seat tiers can sell different minimums, and a flat tier sells
		// no seats at all. Every product here is concrete — get_tiers_by_frequency()
		// expands a variable subscription into its variations, which is where
		// per-seat meta lives for those.
		$line_seats     = $line_product ? Group_Subscription_Seats::get_field_args( $line_product ) : null;
		$selected_seats = $selected_product ? Group_Subscription_Seats::get_field_args( $selected_product ) : null;

		// The field is rendered whenever any offered tier sells seats, and hidden
		// (and disabled, so it submits nothing) while a flat tier is selected — that
		// way picking a per-seat tier brings it back without a round trip.
		$seats_field = $selected_seats;
		if ( ! $seats_field ) {
			foreach ( $tiers as $tier_products ) {
				foreach ( $tier_products as $tier_product ) {
					$seats_field = Group_Subscription_Seats::get_field_args( $tier_product );
					if ( $seats_field ) {
						break 2;
					}
				}
			}
		}

		// One page can hold several of these forms -- one modal per switch link -- so
		// the input's id has to be unique or the labels all point at the first one.
		$seats_input_id = 'newspack-group-seats-' . ( $switch_data
			? 'item-' . absint( $switch_data['item_id'] )
			: 'product-' . ( $product ? absint( $product->get_id() ) : 0 ) );

		// A group can never shrink below the people already in it, so the field's floor
		// is whichever is higher: the plan's minimum, or the seats in use. Enforced on
		// the server either way (see Group_Subscription_Seats::get_quantity_error()).
		$seats_floor = $switch_data ? Group_Subscription_Seats::get_occupancy( $switch_data['subscription'] ) : 0;

		// A group that already holds more seats than its own plan now sells keeps them:
		// the maximum bounds what may be bought, not what has been. Only on the plan
		// they already hold — moving to a different tier is buying that tier, and its
		// maximum binds.
		$line_product_id  = $line_product ? $line_product->get_id() : 0;
		$staying_on_plan  = $line_product_id && $selected_product && $line_product_id === $selected_product->get_id();
		$seats_ceiling    = $staying_on_plan && $line_seats && $line_quantity ? $line_quantity : 0;

		// Start from the seats the reader already pays for when that line sells
		// seats, otherwise from the tier's own minimum — then hold it inside the
		// selected tier's bounds, which a differently-priced tier may narrow.
		$seats_original = $line_seats && $line_quantity ? $line_quantity : '';
		$seats_value    = null;
		if ( $seats_field ) {
			$seats_field['min'] = max( $seats_field['min'], $seats_floor );
			if ( $seats_field['max'] > 0 ) {
				$seats_field['max'] = max( $seats_field['max'], $seats_ceiling, $seats_field['min'] );
			}
			$seats_value = max( $seats_field['min'], (int) ( $seats_original ? $seats_original : $seats_field['min'] ) );
			if ( $seats_field['max'] > 0 ) {
				$seats_value = min( $seats_field['max'], $seats_value );
			}
		}

		$default_title        = $switch_data ? __( 'Change Subscription', 'newspack-plugin' ) : __( 'Complete your transaction', 'newspack-plugin' );
		$default_button_label = $switch_data ? __( 'Change Subscription', 'newspack-plugin' ) : __( 'Purchase', 'newspack-plugin' );

		$title        = $title ?? $default_title;
		$button_label = $button_label ?? $default_button_label;

		// If the user has an active subscription and this is not a switch, render
		// the existing subscription info.
		if ( $user_subscription && empty( $switch_data ) ) {
			$is_limited = function_exists( 'wcs_is_product_limited_for_user' ) ? wcs_is_product_limited_for_user( $current_product->get_id(), get_current_user_id() ) : false;

			/**
			 * Woo Subscriptions Gifting doesn't work well with the subscription limiter functionality.
			 * For now, we're not doing a workaround to allow gifting of a limited subscription product.
			 *
			 * phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar, Squiz.PHP.CommentedOutCode.Found
			 *
			 * For future reference: $is_giftable = class_exists( 'WCSG_Product' ) && method_exists( 'WCSG_Product', 'is_giftable' ) ? \WCSG_Product::is_giftable( $current_product->get_id() ) : false;
			 */
			$render_form = ! $is_limited; // || $is_giftable;
			// phpcs:enable

			self::render_existing_subscription_info( $current_product, $user_subscription, ! $render_form );
			if ( ! $render_form ) {
				return;
			}
		}

		// Every plan's bucket holds the same products, so a card's product_id cannot
		// tell one plan from another: with more than one plan on offer, the frequency
		// control is what posts the choice, even when each bucket holds a single tier.
		$should_render_tabs = ! $is_single_tier || $is_nyp || ( $plan_fields && count( $frequencies ) > 1 );
		?>
		<form class="newspack__subscription-tiers__form <?php echo esc_attr( $is_nyp ? 'nyp' : '' ); ?>" target="newspack_modal_checkout_iframe" data-title="<?php echo esc_attr( $title ); ?>" data-product-id="<?php echo esc_attr( $product ? $product->get_id() : '' ); ?>">
			<?php if ( $should_render_tabs ) : ?>
				<div class="newspack-ui__segmented-control">
					<?php
					if ( count( $frequencies ) > 1 ) {
						self::render_frequency_control( $frequencies, $current_frequency, $is_nyp, $plan_fields, self::get_plan_bucket_labels( $tiers, $plan_fields ) );
					}
					?>
					<div class="newspack-ui__segmented-control__content">
						<?php foreach ( $tiers as $frequency => $products ) : ?>
							<div class="newspack-ui__segmented-control__panel">
								<?php
								if ( $is_nyp ) {
									self::render_nyp_product_card( $products[0], $products[0] === $current_product, $switch_data );
								} else {
									foreach ( $products as $product ) {
										self::render_product_card( $product, false, $switch_data && $product === $current_product, $product === $selected_product, $seats_ceiling );
									}
								}
								?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			<?php
			if ( ! $should_render_tabs ) {
				foreach ( $tiers as $products ) {
					foreach ( $products as $product ) {
						self::render_product_card( $product, true, $switch_data && $product === $current_product, $product === $selected_product, $seats_ceiling );
					}
				}
			}
			?>
			<input type="hidden" name="newspack_checkout" value="1">
			<input type="hidden" name="modal_checkout" value="1">
			<?php
			// A single bucket renders no frequency control to post its plan.
			if ( 1 === count( $frequencies ) && ! empty( $plan_fields[ $current_frequency ] ) ) :
				foreach ( $plan_fields[ $current_frequency ] as $field_name => $field_value ) :
					?>
				<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $field_value ); ?>">
					<?php
				endforeach;
			endif;
			?>
			<?php if ( ! empty( $switch_data ) ) : ?>
				<input type="hidden" name="switch-subscription" value="<?php echo esc_attr( $switch_data['subscription']->get_id() ); ?>">
				<input type="hidden" name="item" value="<?php echo absint( $switch_data['item_id'] ); ?>">
			<?php endif; ?>
			<?php if ( $seats_field ) : ?>
				<p class="newspack__subscription-tiers__seats" data-seats-floor="<?php echo esc_attr( $seats_floor ); ?>"<?php echo $selected_seats ? '' : ' hidden'; ?>>
					<label for="<?php echo esc_attr( $seats_input_id ); ?>"><?php echo esc_html( $seats_field['label'] ); ?></label>
					<input type="number" name="quantity" id="<?php echo esc_attr( $seats_input_id ); ?>" step="1" min="<?php echo esc_attr( $seats_field['min'] ); ?>"<?php echo $seats_field['max'] > 0 ? ' max="' . esc_attr( $seats_field['max'] ) . '"' : ''; ?> value="<?php echo esc_attr( $seats_value ); ?>" data-original-value="<?php echo esc_attr( $seats_original ); ?>"<?php echo $selected_seats ? '' : ' disabled'; ?>>
					<span class="newspack-ui__helper-text"><?php echo esc_html( $seats_field['help'] ); ?></span>
				</p>
				<?php
			elseif ( $line_quantity ) :
				// No tier here sells seats, so there is no seats field to submit the
				// count -- but the line item being switched may still hold more than
				// one, and the modal checkout buys one unless vouch_switch_quantity()
				// vouches for this exact number.
				?>
				<input type="hidden" name="quantity" value="<?php echo esc_attr( $line_quantity ); ?>">
			<?php endif; ?>

			<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide"><?php echo esc_html( $button_label ); ?></button>
			<?php if ( ! is_user_logged_in() ) : ?>
				<button type="button" class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide signin-link">
					<?php _e( 'Sign in to an existing account', 'newspack-plugin' ); ?>
				</button>
			<?php endif; ?>
			<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__cancel"><?php _e( 'Cancel', 'newspack-plugin' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render subscription tiers modal given a grouped or variable
	 * subscription product.
	 *
	 * If no grouped or variable subscription product is provided,
	 * all non-donation subscription products are rendered.
	 *
	 * @param \WC_Product|null $product       Optional product.
	 * @param string|null      $title         Optional title.
	 * @param string|null      $button_label  Optional button label.
	 * @param array|null       $switch_data   Switch subscription data or null.
	 * @param string           $initial_state Optional initial state.
	 */
	public static function render_modal( $product = null, $title = null, $button_label = null, $switch_data = null, $initial_state = 'closed' ) {
		$default_title = $switch_data ? __( 'Change Subscription', 'newspack-plugin' ) : __( 'Complete your transaction', 'newspack-plugin' );
		?>
		<div class="newspack-ui newspack-ui__modal-container newspack__subscription-tiers" data-state="<?php echo esc_attr( $initial_state ); ?>" data-product-id="<?php echo esc_attr( $product ? $product->get_id() : '' ); ?>" data-subscription-id="<?php echo esc_attr( $switch_data ? $switch_data['subscription']->get_id() : '' ); ?>">
			<div class="newspack-ui__modal-container__overlay"></div>
			<div class="newspack-ui__modal newspack-ui__modal--small">
				<header class="newspack-ui__modal__header">
					<h2><?php echo esc_html( $title ?? $default_title ); ?></h2>
					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php \Newspack\Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</header>
				<div class="newspack-ui__modal__content">
					<?php self::render_form( $product, $title, $button_label, $switch_data ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Order button text.
	 *
	 * @param string $text The text of the order button.
	 *
	 * @return string The text of the order button.
	 */
	public static function order_button_text( $text ) {
		if ( method_exists( 'WC_Subscriptions_Switcher', 'cart_contains_switches' ) && \WC_Subscriptions_Switcher::cart_contains_switches( 'any' ) ) {
			if ( Donations::is_donation_cart() ) {
				return __( 'Confirm donation', 'newspack-plugin' );
			}
			return __( 'Change subscription', 'newspack-plugin' );
		}
		return $text;
	}

	/**
	 * Whether the modal should be printed.
	 */
	protected static function should_print_modal() {
		// Upgrade subscription link.
		$upgrade_query_param = self::get_upgrade_subscription_query_param();
		if ( ! empty( $_GET[ $upgrade_query_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// Tiers modal link.
		$tiers_query_param = self::get_tiers_modal_query_param();
		if ( ! empty( $_GET[ $tiers_query_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		return false;
	}

	/**
	 * Should attempt to switch the subscription.
	 *
	 * @return bool Whether to attempt to switch the subscription.
	 */
	protected static function should_attempt_to_switch_subscription() {
		$upgrade_query_param = self::get_upgrade_subscription_query_param();
		if ( ! empty( $_GET[ $upgrade_query_param ] ) || ! empty( $_GET['switch'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return false;
	}

	/**
	 * Get the product from the query param.
	 *
	 * @return \WC_Product|null Product or null if no product is found.
	 */
	protected static function get_product_from_query_param() {
		$upgrade_query_param = self::get_upgrade_subscription_query_param();
		if ( ! empty( $_GET[ $upgrade_query_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return self::get_primary_subscription_tier_product();
		}

		$tiers_query_param = self::get_tiers_modal_query_param();
		if ( ! empty( $_GET[ $tiers_query_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return wc_get_product( absint( $_GET[ $tiers_query_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return null;
	}

	/**
	 * Get the switch data from the given product for the current user.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return array|null Switch data or null if no switch data is found.
	 */
	public static function get_product_switch_data( $product ) {
		$switch_data = null;
		if ( ! is_user_logged_in() ) {
			return $switch_data;
		}

		$user_subscription = WooCommerce_Subscriptions::get_user_subscription( $product );
		if ( $user_subscription ) {
			$product_id = $product->get_id();
			$item       = null;
			foreach ( $user_subscription->get_items() as $line_item ) {
				if (
					$line_item['product_id'] === $product_id
					|| $line_item['variation_id'] === $product_id
					|| ( method_exists( $product, 'get_children' ) && in_array( $line_item['product_id'], $product->get_children(), true ) ) // In case it's a grouped product.
				) {
					$item = $line_item;
					break;
				}
			}
			if ( $item ) {
				$switch_data = [
					'item_id'      => $item->get_id(),
					'item'         => $item,
					'subscription' => $user_subscription,
				];
			}
		}
		return $switch_data;
	}

	/**
	 * Prevent a reader from "switching" to the subscription they already own.
	 *
	 * The tiers/upgrade modal submits a WooCommerce Subscriptions switch
	 * (`switch-subscription` + `item`) straight into the modal checkout, which
	 * adds the product to the cart directly and so bypasses WCS's own
	 * "you can't switch to the same subscription" validation. The front-end
	 * guard (a disabled submit button on the current tier) is the primary
	 * protection; this is the server-side backstop for crafted requests or
	 * disabled JavaScript (NPPM-2952).
	 *
	 * A no-op for anything that isn't a switch onto a product the reader's own
	 * subscription already holds — at the same per-period amount for name-your-price.
	 *
	 * @param bool $passed       Whether add-to-cart validation has passed so far.
	 * @param int  $product_id   The product being added to the cart.
	 * @param int  $quantity     The quantity (unused; a deliberate quantity change is read from the request).
	 * @param int  $variation_id The variation being added, if any.
	 *
	 * @return bool Whether the product may be added to the cart.
	 */
	public static function prevent_switch_to_same_subscription( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
		if ( true !== $passed ) {
			return $passed;
		}
		$error = self::get_same_subscription_switch_error( $product_id, $variation_id );
		if ( null !== $error ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $error, 'error' );
			}
			return false;
		}
		return $passed;
	}

	/**
	 * The same guard, for cart additions that never run the validation filter.
	 *
	 * `woocommerce_add_to_cart_validation` is applied by WooCommerce's request
	 * handlers, not by `WC_Cart::add_to_cart()` itself, so direct calls — notably
	 * `Modal_Checkout::process_checkout_request()`, the flow the tiers modal
	 * submits to — bypass it. This filter runs inside `add_to_cart()` on every
	 * path. Throwing is WooCommerce's documented way for a plugin to abort the
	 * add: the cart catches the exception, queues its message as an error notice
	 * and returns false to the caller.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     The product being added to the cart.
	 * @param int   $variation_id   The variation being added, if any.
	 *
	 * @throws \Exception When the request is a switch onto the subscription the reader already owns.
	 *
	 * @return array Cart item data, unchanged.
	 */
	public static function prevent_switch_to_same_subscription_cart_item_data( $cart_item_data, $product_id, $variation_id = 0 ) {
		// The Store API applies this filter outside `WC_Cart::add_to_cart()`'s
		// try/catch (StoreApi CartController::filter_request_data()), where a throw
		// surfaces as a generic 500 instead of a clean cart error — and the same
		// applies to any non-WC_Cart caller. Let the validation-filter registration
		// handle REST requests: on the Store API path it runs right after this one.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $cart_item_data;
		}
		$error = self::get_same_subscription_switch_error( $product_id, $variation_id );
		if ( null !== $error ) {
			throw new \Exception( esc_html( $error ) );
		}
		return $cart_item_data;
	}

	/**
	 * Get the blocking error when an add-to-cart is a switch onto the very
	 * subscription the current reader already owns.
	 *
	 * Null means the request is not such a no-op: not a switch at all, a switch
	 * on someone else's subscription (left for WCS to authorize), a different
	 * product, variation or quantity, or a name-your-price amount change.
	 *
	 * @param int $product_id   The product being added to the cart.
	 * @param int $variation_id The variation being added, if any.
	 *
	 * @return string|null Error message when the switch must be blocked, null otherwise.
	 */
	private static function get_same_subscription_switch_error( $product_id, $variation_id = 0 ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}

		// The tiers modal submits the switch as query params, but read from
		// $_REQUEST so the backstop also covers a crafted POST request. The
		// logged-in test runs before the subscription is loaded so anonymous
		// requests never trigger a subscription post load.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_REQUEST['switch-subscription'] ) || ! is_user_logged_in() ) {
			return null;
		}
		$subscription = wcs_get_subscription( absint( wp_unslash( $_REQUEST['switch-subscription'] ) ) );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			return null;
		}
		$target_id = $variation_id ? (int) $variation_id : (int) $product_id;

		// Identify the specific line item being switched. Prefer the `item` the modal
		// (and WCS's own switch flow) submits, resolved against the subscription's own
		// items — order item IDs are globally unique, so the default (unscoped)
		// `get_item()` lookup would resolve a crafted `item` from an unrelated order
		// and let a same-tier switch through. Fall back to scanning for the target
		// product so a crafted request that omits `item` is still covered.
		$line_item = null;
		if ( ! empty( $_REQUEST['item'] ) ) {
			$line_item = $subscription->get_item( absint( wp_unslash( $_REQUEST['item'] ) ), false );
		}
		if ( ! $line_item ) {
			foreach ( $subscription->get_items() as $item ) {
				$item_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				if ( (int) $item_product_id === $target_id ) {
					$line_item = $item;
					break;
				}
			}
		}

		// No such line item, or the located item holds a different product than the one
		// being switched to: it's a real switch, not a no-op.
		if ( ! $line_item ) {
			return null;
		}
		$current_id = $line_item->get_variation_id() ? (int) $line_item->get_variation_id() : (int) $line_item->get_product_id();
		if ( $current_id !== $target_id ) {
			return null;
		}

		// A plan-based product keeps its ID across plans, so the same product on another
		// plan is a real switch. The plan comes from the request, where WooCommerce reads it.
		$target_product = wc_get_product( $target_id );
		$posted_plan    = $target_product instanceof \WC_Product ? Subscription_Products::get_posted_plan_option( $target_product ) : null;
		if ( $posted_plan && ! self::line_item_is_on_plan( $subscription, $line_item, $posted_plan ) ) {
			return null;
		}

		// WCS treats a switch as identical only when product, variation *and* quantity
		// all match, so a deliberate quantity change on the same plan is a legitimate
		// switch. Only an explicitly submitted quantity counts as deliberate: the tiers
		// modal always submits one when the target tier can carry seats, so an absent
		// quantity is not a seat change, and reading it as one would skip the product
		// and amount checks below for the crafted-request and no-JavaScript cases this
		// backstop exists for.
		$line_quantity = max( 1, (int) $line_item->get_quantity() );
		if ( isset( $_REQUEST['quantity'] ) && absint( wp_unslash( $_REQUEST['quantity'] ) ) !== $line_quantity ) {
			return null;
		}

		// Only name-your-price tiers have an amount to compare, and it must be read on
		// the same basis the modal submits: the NYP <input> carries a per-billing-period
		// amount, so the line total is divided by the interval and (for parity with the
		// per-unit price the submitted value becomes) by the quantity. A fixed-price
		// tier keeps a null amount, so re-selecting it is always a no-op — appending a
		// spurious `price` to a fixed tier can't slip a same-tier switch past this
		// check. The value is a plain period-decimal string from an
		// <input type="number">, so (float) is correct; wc_format_decimal() would
		// misread it on comma-decimal stores.
		$target_amount  = null;
		$current_amount = null;
		$target_is_nyp  = $target_product && (
			class_exists( '\WC_Name_Your_Price_Helpers' )
				// The helper resolves the variation/parent lookup; a bare meta read on a
				// variation would miss `_nyp` stored on the parent and misclassify the
				// tier as fixed-price, blocking a genuine amount change.
				? \WC_Name_Your_Price_Helpers::is_nyp( $target_id )
				: 'yes' === $target_product->get_meta( '_nyp' )
		);
		if ( $target_is_nyp && isset( $_REQUEST['price'] ) ) {
			$price_param = sanitize_text_field( wp_unslash( $_REQUEST['price'] ) );
			if ( '' !== $price_param ) {
				// WCS's canonical interval accessor, like the `_nyp` helper above:
				// it normalizes empty meta to 1 and applies WCS's product filters,
				// so the per-period basis tracks whatever WCS itself would use.
				$interval       = max(
					1,
					(int) ( class_exists( '\WC_Subscriptions_Product' )
						? \WC_Subscriptions_Product::get_interval( $target_product )
						: $target_product->get_meta( '_subscription_period_interval' ) )
				);
				$target_amount  = (float) $price_param;
				// get_subtotal() is the pre-discount line amount. A coupon on the
				// existing subscription discounts get_total(), which would make an
				// unchanged name-your-price re-submission compare unequal and skip
				// the guard.
				$current_amount = (float) $line_item->get_subtotal() / $interval / $line_quantity;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::is_same_subscription_switch( $current_id, $current_amount, $target_id, $target_amount ) ) {
			return __( 'You’re already subscribed to this option. Choose a different one to change your subscription.', 'newspack-plugin' );
		}

		return null;
	}

	/**
	 * Whether a switch would land on the same subscription the reader already has.
	 *
	 * Mirrors the front-end guard: the current tier can't be re-selected, and a
	 * name-your-price tier can only be "switched" to when the amount changes.
	 *
	 * @param int        $current_product_id Canonical product ID of the current subscription item.
	 * @param float|null $current_amount     Current recurring amount, or null if unknown.
	 * @param int        $target_product_id  Canonical product ID being switched to.
	 * @param float|null $target_amount      Target amount for name-your-price, or null for a fixed-price tier.
	 *
	 * @return bool True when the switch is a no-op (same product and, for NYP, an unchanged amount).
	 */
	public static function is_same_subscription_switch( $current_product_id, $current_amount, $target_product_id, $target_amount ) {
		if ( (int) $current_product_id !== (int) $target_product_id ) {
			return false;
		}
		// Same product. A fixed-price tier has no amount to change, so it is a no-op.
		if ( null === $target_amount ) {
			return true;
		}
		// Name-your-price: without a known current amount, don't risk blocking a real change.
		if ( null === $current_amount ) {
			return false;
		}
		// Compare in minor units so binary float noise can't misclassify a smallest-step
		// change (abs( 10.01 - 10.00 ) is 0.00999… in PHP, which an epsilon of 0.01
		// classifies as unchanged), sized by the store's price decimals so zero- and
		// three-decimal currencies keep a correct smallest step.
		$factor = pow( 10, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
		return (int) round( (float) $target_amount * $factor ) === (int) round( (float) $current_amount * $factor );
	}

	/**
	 * Render link-triggered modal.
	 */
	public static function print_modal() {
		if ( ! self::should_print_modal() ) {
			return;
		}

		$product = self::get_product_from_query_param();
		if ( ! $product ) {
			return;
		}

		// If coming from a subscription switch link, the reader must be logged in.
		// The authentication flow will be handled in the frontend.
		if ( self::should_attempt_to_switch_subscription() && ! is_user_logged_in() ) {
			return;
		}

		if ( ! class_exists( '\Newspack_Blocks\Modal_Checkout' ) ) {
			return;
		}
		\Newspack_Blocks\Modal_Checkout::enqueue_modal();

		$switch_data = null;
		if ( self::should_attempt_to_switch_subscription() ) {
			$switch_data = self::get_product_switch_data( $product );
		}

		self::render_modal( $product, null, null, $switch_data, 'open' );
	}

	/**
	 * Disable popups when opening the primary product modal.
	 *
	 * @param bool $disabled Whether popups have been disabled.
	 *
	 * @return bool Whether popups have been disabled.
	 */
	public static function disable_popups( $disabled ) {
		$query_param = self::get_upgrade_subscription_query_param();
		$tiers_query_param = self::get_tiers_modal_query_param();
		if ( ! empty( $_GET[ $query_param ] ) || ! empty( $_GET[ $tiers_query_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return $disabled;
	}

	/**
	 * Get the multiplier to convert from one subscription frequency to another.
	 *
	 * @param string $from_frequency The base frequency.
	 * @param string $to_frequency   The target frequency.
	 *
	 * @return float The multiplier to convert from base to target frequency.
	 */
	private static function get_frequency_conversion_multiplier( $from_frequency, $to_frequency ) {
		if ( $from_frequency === $to_frequency ) {
			return 1;
		}
		$conversions = [
			'day'   => [
				'week'  => 7,
				'month' => 30,
				'year'  => 365,
			],
			'week'  => [
				'day'   => 1 / 7,
				'month' => 52 / 12, // ~4.33 weeks per month.
				'year'  => 52,
			],
			'month' => [
				'day'  => 1 / 30,
				'week' => 12 / 52, // ~0.23 months per week.
				'year' => 12,
			],
			'year'  => [
				'day'   => 1 / 365,
				'week'  => 1 / 52,
				'month' => 1 / 12,
			],
		];
		if ( isset( $conversions[ $from_frequency ][ $to_frequency ] ) ) {
			return $conversions[ $from_frequency ][ $to_frequency ];
		}
		return 1;
	}
}
Subscriptions_Tiers::init_hooks();
