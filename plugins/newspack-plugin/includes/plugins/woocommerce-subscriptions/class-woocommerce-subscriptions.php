<?php
/**
 * WooCommerce Subscriptions Integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class WooCommerce_Subscriptions {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'woocommerce_subscriptions_integration_init' ] );
		add_filter( 'woocommerce_subscriptions_product_limited_for_user', [ __CLASS__, 'maybe_limit_subscription_product_for_user' ], 10, 3 );
		add_filter( 'woocommerce_subscriptions_product_trial_length', [ __CLASS__, 'limit_free_trials_to_one_per_user' ], 10, 2 );
		add_filter( 'wcs_get_users_subscriptions', [ __CLASS__, 'filter_subscriptions_for_account_page' ], 10, 1 );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', [ __CLASS__, 'allow_migrated_subscription_switch' ], 10, 3 );
		add_filter( 'wcs_can_user_resubscribe_to_subscription', [ __CLASS__, 'allow_migrated_subscription_to_resubscribe' ], 10, 3 );
	}

	/**
	 * Filter to allow migrated subscription without a last order date to switch.
	 *
	 * This filter will also populate the subscription's last order date meta with
	 * the scheduled start date.
	 *
	 * @param bool                   $can_switch   Whether the item can be switched.
	 * @param \WC_Order_Item_Product $item         An order item on the subscription to switch, or cart item to add.
	 * @param \WC_Subscription       $subscription An instance of WC_Subscription.
	 *
	 * @return bool Whether the item can be switched.
	 */
	public static function allow_migrated_subscription_switch( $can_switch, $item, $subscription ) {
		if ( ! $can_switch ) {
			$no_last_order_date = 0 === $subscription->get_date( 'last_order_date_created' );

			// Bail if the subscription has a last order date, as we're only handling
			// subscriptions without it.
			if ( ! $no_last_order_date ) {
				return $can_switch;
			}

			// Detect whether it's a migrated subscription.
			$migrated_meta = [ '_piano_subscription_id', '_stripe_subscription_id' ];
			$migrated = false;
			foreach ( $migrated_meta as $meta ) {
				if ( $subscription->get_meta( $meta ) ) {
					$migrated = true;
					break;
				}
			}
			if ( ! $migrated ) {
				return $can_switch;
			}

			// Set the last order date meta to the scheduled start date.
			$subscription->set_last_order_date_created( $subscription->get_date( 'start' ) );
			$subscription->save();

			$product_id = wcs_get_canonical_product_id( $item );
			// Run other standard checks to see if the item can be switched.
			// @see WC_Subscriptions_Switcher::can_user_perform_action().
			$is_product_switchable = 'line_item' === $item['type'] && wcs_is_product_switchable_type( $product_id );
			$is_active             = $subscription->has_status( 'active' );
			$can_be_updated        = $subscription->payment_method_supports( 'subscription_amount_changes' ) && $subscription->payment_method_supports( 'subscription_date_changes' );

			if ( $is_product_switchable && $is_active && $can_be_updated ) {
				return true;
			}
		}

		return $can_switch;
	}

	/**
	 * Initialize WooCommerce Subscriptions Integration.
	 */
	public static function woocommerce_subscriptions_integration_init() {
		include_once __DIR__ . '/class-on-hold-duration.php';
		include_once __DIR__ . '/class-renewal.php';
		include_once __DIR__ . '/class-subscriptions-meta.php';
		include_once __DIR__ . '/class-subscriptions-confirmation.php';
		include_once __DIR__ . '/class-subscriptions-tiers.php';

		On_Hold_Duration::init();
		Renewal::init();
		Subscriptions_Meta::init();
		Subscriptions_Confirmation::init();
	}


	/**
	 * Check if WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'WC' ) && class_exists( 'WC_Subscriptions' );
	}

	/**
	 * Check if WooCommerce Subscriptions Integration is enabled.
	 *
	 * True if:
	 * - WooCommerce Subscriptions is active and,
	 * - Reader Activation is enabled and,
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$is_enabled = self::is_active() && Reader_Activation::is_enabled();
		/**
		 * Filters whether subscriptions expiration is enabled.
		 *
		 * @param bool $is_enabled
		 */
		return apply_filters( 'newspack_subscriptions_expiration_enabled', $is_enabled );
	}

	/**
	 * Sanitize and validate a subscription ID or object as a WC_Subscription object.
	 *
	 * @param int|WC_Subscription $subscription The subscription ID or object.
	 *
	 * @return WC_Subscription|false The subscription object, or false if the subscription is not valid.
	 */
	public static function sanitize_subscription( $subscription ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}
		if ( ! is_a( $subscription, 'WC_Subscription' ) ) {
			$subscription = \wcs_get_subscription( $subscription );
		}
		if ( ! $subscription ) {
			return false;
		}
		return $subscription;
	}

	/**
	 * Get the user's active subscription for a product (simple, grouped, or variable).
	 *
	 * @param \WC_Product $product Product.
	 * @param int|null    $user_id User ID. Defaults to the current user.
	 *
	 * @return \WC_Subscription|null Subscription or null if the user does not have a subscription.
	 */
	public static function get_user_subscription( $product, $user_id = null ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$children           = $product->get_children();
		$user_subscriptions = wcs_get_users_subscriptions( $user_id );

		// Simple products have no children; check their status directly.
		$product_ids = ! empty( $children ) ? $children : [ $product->get_id() ];

		foreach ( $product_ids as $product_id ) {
			$product_to_check = wc_get_product( $product_id );
			if ( ! $product_to_check ) {
				continue;
			}
			foreach ( $user_subscriptions as $subscription ) {
				if ( $subscription->has_product( $product_to_check->get_id() ) && $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
					return $subscription;
				}
			}
		}

		return null;
	}

	/**
	 * Get the label for a frequency.
	 *
	 * @param string   $frequency Frequency.
	 * @param int|null $interval  Optional interval. If not provided, the interval
	 *                            can be extracted from the frequency string.
	 *                            E.g. 'month_2' -> 2.
	 *
	 * @return string
	 */
	public static function get_frequency_label( $frequency, $interval = null ) {
		$parts    = explode( '_', $frequency );
		$period   = $parts[0] ?? '';
		$interval = $interval ?? ( isset( $parts[1] ) ? (int) $parts[1] : 1 );
		$interval = $interval > 0 ? $interval : 1;

		$single_labels = [
			'day'   => __( 'Daily', 'newspack-plugin' ),
			'week'  => __( 'Weekly', 'newspack-plugin' ),
			'month' => __( 'Monthly', 'newspack-plugin' ),
			'year'  => __( 'Yearly', 'newspack-plugin' ),
		];

		// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
		$multiple_templates = [
			'day'   => __( '%s Days', 'newspack-plugin' ),
			'week'  => __( '%s Weeks', 'newspack-plugin' ),
			'month' => __( '%s Months', 'newspack-plugin' ),
			'year'  => __( '%s Years', 'newspack-plugin' ),
		];
		// phpcs:enable

		if ( 1 === $interval ) {
			$label = $single_labels[ $period ] ?? ucfirst( $period );
		} elseif ( isset( $multiple_templates[ $period ] ) ) {
				$label = sprintf(
					$multiple_templates[ $period ],
					number_format_i18n( $interval )
				);
		} else {
			$label = sprintf(
				// translators: 1: Subscription interval. 2: Subscription period.
				__( '%1$s %2$ss', 'newspack-plugin' ),
				number_format_i18n( $interval ),
				ucfirst( $period )
			);
		}

		/**
		 * Filters the frequency label.
		 *
		 * @param string $label     Frequency label.
		 * @param string $frequency Frequency.
		 */
		return apply_filters( 'newspack_subscriptions_frequency_label', $label, $frequency );
	}

	/**
	 * Maybe limit the subscription product for user. If the product is limited to one active
	 * subscription per user, treat on-hold, pending, and pending-cancel statuses as active.
	 *
	 * @param bool           $is_limited_for_user Whether the subscription product is limited for user.
	 * @param int|WC_Product $product A WC_Product object or the ID of a product.
	 * @param int            $user_id The user ID.
	 */
	public static function maybe_limit_subscription_product_for_user( $is_limited_for_user, $product, $user_id ) {
		$product_limitation = \wcs_get_product_limitation( $product );
		if ( ! $is_limited_for_user && 'active' === $product_limitation ) {
			$is_limited_for_user = \wcs_user_has_subscription( $user_id, $product->get_id(), [ 'active', 'on-hold', 'pending', 'pending-cancel' ] );
		}

		// Use custom error messaging if available.
		if ( $is_limited_for_user && method_exists( 'Newspack_Blocks\Modal_Checkout', 'get_subscription_limited_message' ) && method_exists( 'Newspack_Blocks\Modal_Checkout', 'get_subscription_limited_message_any' ) ) {
			$callback = 'active' === $product_limitation ? 'get_subscription_limited_message' : 'get_subscription_limited_message_any';
			add_filter( 'woocommerce_cart_item_removed_message', [ 'Newspack_Blocks\Modal_Checkout', $callback ] );
		}
		return $is_limited_for_user;
	}

	/**
	 * Limit free trial purchases to one per user. If the user already has a subscription of any status,
	 * return 0 to force no free trial period during checkout for this product.
	 *
	 * @param int        $trial_length The trial length.
	 * @param WC_Product $product The product.
	 * @return int The trial length.
	 */
	public static function limit_free_trials_to_one_per_user( $trial_length, $product ) {
		/**
		 * Bail if this is a subscription switch.
		 *
		 * Subscription switches mock a free trial on the cart item to make sure
		 * the switch total doesn't include any recurring amount.
		 *
		 * With this method, if the product being switched to is a subscription
		 * already owned, it'll charge the full subscription amount + proration.
		 *
		 * @see https://github.com/woocommerce/woocommerce-subscriptions/blob/8a3cd300786218d76eb51d28b8d2d9ff5eee3ef6/includes/switching/class-wc-subscriptions-switcher.php#L134
		 */
		if ( class_exists( 'WC_Subscriptions_Switcher' ) && \WC_Subscriptions_Switcher::cart_contains_switches() ) {
			return $trial_length;
		}

		$user_id = get_current_user_id();

		// If not logged in, try to get the user ID from the billing email.
		if ( ! $user_id && method_exists( 'Newspack_Blocks\Modal_Checkout', 'get_user_id_from_email' ) ) {
			$user_id = \Newspack_Blocks\Modal_Checkout::get_user_id_from_email();
		}
		if ( $trial_length && $user_id && $product && $product->is_type( [ 'subscription', 'subscription_variation', 'variable-subscription' ] ) ) {
			$user_subscriptions = array_values( \wcs_get_users_subscriptions( $user_id ) );
			foreach ( $user_subscriptions as $subscription ) {
				if ( $subscription->has_product( $product->get_id() ) && 'trash' !== $subscription->get_status() ) {
					return 0;
				}
			}
		}

		return $trial_length;
	}

	/**
	 * Remove 'trash' subscriptions from the subscriptions list on the My Account page.
	 *
	 * @param array $subscriptions The subscriptions.
	 * @return array The filtered subscriptions.
	 */
	public static function filter_subscriptions_for_account_page( $subscriptions ) {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$subscriptions = array_filter(
				$subscriptions,
				function( $subscription ) {
					return ! $subscription->has_status( 'trash' );
				}
			);
		}
		return $subscriptions;
	}

	/**
	 * Get the product ID for a subscription.
	 *
	 * @param WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int|false The product ID, or false if no product is found.
	 */
	public static function get_subscription_product_id( $subscription ) {
		if ( ! is_a( $subscription, 'WC_Subscription' ) ) {
			$subscription = \wcs_get_subscription( $subscription );
		}
		if ( ! $subscription ) {
			return false;
		}
		$product_id = false;
		foreach ( $subscription->get_items() as $item ) {
			$product_id = \wcs_get_canonical_product_id( $item );
			if ( $product_id ) {
				break;
			}
		}
		return $product_id;
	}

	/**
	 * Allow migrated subscription to resubscribe.
	 *
	 * The original function only allows resubscriptions if there are payments
	 * associated with the subscription to avoid circumventing the sign-up fees.
	 * This filter allows resubscriptions for migrated subscriptions, which don't
	 * have any payments associated with them.
	 *
	 * @param bool             $can_resubscribe Whether the user can resubscribe to the subscription.
	 * @param \WC_Subscription $subscription    The subscription.
	 * @param int              $user_id         The user ID.
	 *
	 * @return bool Whether the user can resubscribe to the subscription.
	 */
	public static function allow_migrated_subscription_to_resubscribe( $can_resubscribe, $subscription, $user_id ) {
		if ( $can_resubscribe ) {
			return $can_resubscribe;
		}

		/**
		 * Replicate the original checks.
		 */
		if (
			empty( $subscription ) ||
			! user_can( $user_id, 'subscribe_again', $subscription->get_id() ) || // phpcs:ignore WordPress.WP.Capabilities.Unknown
			! $subscription->has_status( [ 'pending-cancel', 'cancelled', 'expired', 'trash' ] ) ||
			$subscription->get_total() <= 0 ||
			$subscription->contains_unavailable_product()
		) {
			return false;
		}

		$migrated_meta = [ '_piano_subscription_id', '_stripe_subscription_id' ];
		foreach ( $migrated_meta as $meta ) {
			if ( $subscription->get_meta( $meta ) ) {
				$can_resubscribe = true;
				break;
			}
		}
		return $can_resubscribe;
	}
}
WooCommerce_Subscriptions::init();
