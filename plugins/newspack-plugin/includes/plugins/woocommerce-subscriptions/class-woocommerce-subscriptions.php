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
		add_filter( 'wcs_switch_total_paid_for_current_period', [ __CLASS__, 'recover_total_paid_for_switch' ], 10, 3 );
		add_filter( 'wcs_switch_proration_days_in_old_cycle', [ __CLASS__, 'bound_switch_proration_days_in_old_cycle' ], 10, 2 );
		add_filter( 'wcs_switch_sign_up_fee', [ __CLASS__, 'apply_stepped_pricing_switch_charge' ], 10, 2 );
		add_filter( 'wcs_can_user_resubscribe_to_subscription', [ __CLASS__, 'allow_migrated_subscription_to_resubscribe' ], 10, 3 );
	}

	/**
	 * Detect a migrated subscription by the meta a migration writes.
	 *
	 * @param \WC_Subscription $subscription The subscription to check.
	 *
	 * @return bool True if the subscription carries Piano or Stripe migration meta.
	 */
	private static function is_migrated_subscription( $subscription ) {
		if ( ! ( $subscription instanceof \WC_Subscription ) ) {
			return false;
		}
		foreach ( [ '_piano_subscription_id', '_stripe_subscription_id' ] as $meta_key ) {
			if ( $subscription->get_meta( $meta_key ) ) {
				return true;
			}
		}
		return false;
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

			if ( ! self::is_migrated_subscription( $subscription ) ) {
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
	 * Recover the switch proration baseline when WooCommerce Subscriptions
	 * cannot determine the amount paid for the current billing period.
	 *
	 * WCS sums the matching line item across the subscription's related
	 * orders. That sum is `0` in two cases this method handles:
	 *
	 * - Migrated subscriptions (Piano, Stripe) have no Woo order history, so
	 *   there is nothing to sum. The recurring line-item total is used as the
	 *   baseline (one billing period's recurring charge), which is
	 *   dimensionally what WCS divides by the old billing cycle length.
	 *
	 * - Paid-trial subscriptions (a one-time sign-up fee plus a free trial)
	 *   have an order, but WCS excludes the sign-up fee from the amount paid,
	 *   so a switch during the trial sees `0`. When the publisher opts in via
	 *   the NEWSPACK_WC_SUBS_SWITCH_INCLUDE_SIGNUP_FEE constant, the amount
	 *   paid including the sign-up fee is used instead.
	 *
	 * A `0` baseline makes WCS treat the old subscription as `$0/day`,
	 * misclassify downgrades as upgrades, and charge the full prorated price
	 * of the new plan as a sign-up fee. Every other zero-paid case (100%-
	 * discount purchases, comps) is left to WCS's default behavior on purpose.
	 *
	 * @param float                  $total_paid    The amount WCS computed for the current period.
	 * @param \WC_Subscription       $subscription  The subscription being switched.
	 * @param \WC_Order_Item_Product $existing_item The subscription line item being switched.
	 *
	 * @return float The corrected amount paid for the current period.
	 */
	public static function recover_total_paid_for_switch( $total_paid, $subscription, $existing_item ) {
		// Only intervene when WCS could not determine a positive amount paid.
		if ( (float) $total_paid > 0 ) {
			return $total_paid;
		}

		if ( ! ( $existing_item instanceof \WC_Order_Item_Product ) ) {
			return $total_paid;
		}

		// Branch 1: subscriptions migrated into WooCommerce from another
		// platform (Piano, Stripe) have no Woo order history, so WCS cannot
		// see what was paid. Fall back to the recurring line-item total. The
		// companion wcs_switch_proration_days_in_old_cycle filter bounds the
		// denominator so the per-day baseline matches a single billing cycle.
		if ( self::is_migrated_subscription( $subscription ) ) {
			// A migrated subscription still within its free trial has paid
			// nothing and has no accrued credit -- recovering a baseline here
			// would let an unpaid trial be switched into manufactured credit.
			if ( $subscription->get_time( 'trial_end' ) > time() ) {
				return $total_paid;
			}

			return max( (float) $existing_item->get_total(), (float) $total_paid );
		}

		// Branch 2: publishers that sell stepped pricing as a one-time sign-up
		// fee plus a free trial. WCS excludes the sign-up fee from the amount
		// paid, so a switch during the trial sees $0. When the publisher has
		// opted in, count the sign-up fee the reader actually paid. A free
		// trial with no sign-up fee, or a comp, yields nothing and no-ops.
		//
		// Unlike apply_stepped_pricing_switch_charge(), this branch is NOT
		// additionally gated on an active trial or a paid sign-up fee, and
		// that looser gate is intentional: this is the general baseline that
		// feeds every downstream WCS switch calculation, not just the
		// active-trial override. The bound is the recovered value itself --
		// get_total_paid_including_signup_fee() returns what WCS's own
		// accounting says the reader actually paid (including sign-up fees),
		// so it can never fabricate credit beyond a real payment. A comp or
		// 100%-discount returns ~0 and the max() leaves total_paid untouched;
		// an out-of-trial period the reader paid for already returns a
		// positive total_paid above and never reaches here.
		if ( self::should_count_signup_fee_on_switch( $subscription, $existing_item ) ) {
			return max( self::get_total_paid_including_signup_fee( $subscription, $existing_item ), (float) $total_paid );
		}

		return $total_paid;
	}

	/**
	 * Bound the switch proration denominator to one billing cycle for migrated
	 * subscriptions.
	 *
	 * WCS computes days_in_old_cycle as
	 * (next_payment_timestamp - last_order_paid_time) / DAY_IN_SECONDS. For a
	 * migrated subscription with no Woo order history, last_order_paid_time
	 * falls back to the subscription's start timestamp -- the original
	 * platform sign-up date, which can be months or years in the past. The
	 * resulting denominator spans many billing cycles, which would make
	 * old_price_per_day artificially low and still misclassify a downgrade as
	 * an upgrade even after recover_total_paid_for_switch supplies one cycle's
	 * worth of recurring total.
	 *
	 * Clamping to one billing cycle here keeps both sides of WCS's per-day
	 * price calculation in agreement for migrated subscriptions. Non-migrated
	 * subscriptions are left to WCS's default behavior.
	 *
	 * @param int              $days_in_old_cycle The number of days WCS computed for the old cycle.
	 * @param \WC_Subscription $subscription      The subscription being switched.
	 *
	 * @return int The (possibly bounded) number of days in the old cycle.
	 */
	public static function bound_switch_proration_days_in_old_cycle( $days_in_old_cycle, $subscription ) {
		if ( ! self::is_migrated_subscription( $subscription ) ) {
			return $days_in_old_cycle;
		}

		if ( ! function_exists( 'wcs_get_days_in_cycle' ) ) {
			return $days_in_old_cycle;
		}

		$cycle_days = (int) wcs_get_days_in_cycle( $subscription->get_billing_period(), $subscription->get_billing_interval() );
		if ( $cycle_days <= 0 ) {
			return $days_in_old_cycle;
		}

		return min( (int) $days_in_old_cycle, $cycle_days );
	}

	/**
	 * Apply the stepped-pricing switch charge: full new recurring price for
	 * the first cycle, minus the unconsumed portion of what the reader paid
	 * for the old plan, exposed to WCS as the apportioned sign-up fee.
	 *
	 * For publishers using a sign-up fee + free trial as a first-period
	 * discount (Newspack's stepped-pricing pattern), switching ends the
	 * discount on both sides: the old plan's discount stops accruing value,
	 * and the new plan is charged at its regular recurring price (not its
	 * own first-period discount). The unconsumed portion of what the reader
	 * paid for the old plan is credited toward the new plan's first cycle.
	 *
	 * We hook wcs_switch_sign_up_fee (not extra_to_pay) because WCS
	 * classifies a matching-trials switch as a downgrade -- it forces
	 * new_price_per_day to 0, then sees old_pp > new_pp and routes through
	 * extend_prepaid_term, which never calls calculate_upgrade_cost or
	 * applies wcs_switch_proration_extra_to_pay. wcs_switch_sign_up_fee, by
	 * contrast, fires in apportion_sign_up_fees before the switch-type
	 * branching, so it is the only WCS hook reachable for both
	 * upgrade-as-downgrade and ordinary downgrade paths.
	 *
	 * The exception is a switch into a one-payment (length-1) subscription:
	 * WCS routes that through set_upgrade_cost() regardless of switch type
	 * and stacks the apportioned sign-up fee on top of the gap payment, so we
	 * bail in that case (below) and leave the pricing to WCS.
	 *
	 * Setting the sign-up fee to (new_recurring - unconsumed_credit) makes
	 * the cart show the right one-time charge, while WCS continues to set
	 * up the new plan's recurring schedule from the inherited trial_end.
	 *
	 * Only fires when the publisher has opted in, the subscription is in an
	 * active trial, and the existing line item actually carries a paid
	 * sign-up fee (the stepped-pricing signature). Real free trials, comps,
	 * and out-of-trial switches all pass through unchanged.
	 *
	 * @param float                 $value       The sign-up fee WCS computed (delta when apportion=yes, 0 when apportion=no).
	 * @param \WCS_Switch_Cart_Item $switch_item The WCS switch context.
	 *
	 * @return float The sign-up fee to charge for the switch.
	 */
	public static function apply_stepped_pricing_switch_charge( $value, $switch_item ) {
		if ( ! is_object( $switch_item ) ) {
			return $value;
		}

		$subscription  = $switch_item->subscription ?? null;
		$existing_item = $switch_item->existing_item ?? null;
		$new_product   = $switch_item->product ?? null;

		if ( ! ( $subscription instanceof \WC_Subscription ) ) {
			return $value;
		}

		// Only intervene during an active trial -- the only time the
		// stepped-pricing pattern produces the wrong charge.
		if ( $subscription->get_time( 'trial_end' ) <= time() ) {
			return $value;
		}

		if ( ! self::should_count_signup_fee_on_switch( $subscription, $existing_item ) ) {
			return $value;
		}

		if ( ! ( $existing_item instanceof \WC_Order_Item_Product ) || ! is_object( $new_product ) ) {
			return $value;
		}

		// The stepped-pricing signature: the old line item actually carries
		// a paid sign-up fee. A real free trial (sign-up fee = 0) is left
		// alone so we never invent a charge for a reader who paid nothing.
		// Mirror WCS's tax-mode selection (WCS_Switch_Totals_Calculator::
		// apportion_sign_up_fees) so the recovered baseline is dimensionally
		// consistent with new_recurring on a tax-inclusive store.
		$tax_mode         = ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) ? 'inclusive_of_tax' : 'exclusive_of_tax';
		$paid_sign_up_fee = (float) $subscription->get_items_sign_up_fee( $existing_item, $tax_mode );
		if ( $paid_sign_up_fee <= 0 ) {
			return $value;
		}

		// When trial periods do not match between the old and new products
		// (for example switching from a paid-trial plan into a no-trial
		// plan), WCS does not force new_price_per_day to 0 and classifies
		// the switch as an upgrade, then computes extra_to_pay via
		// calculate_upgrade_cost(). Given the corrected total_paid baseline
		// from recover_total_paid_for_switch, that extra_to_pay equals the
		// prorated remaining-term price minus the prorated unconsumed
		// credit -- the right answer for switches that inherit the existing
		// next-payment date. Pass through here so WCS's default applies and
		// we do not double-charge by also overriding the sign-up fee.
		// Fail safe: when we cannot confirm the trial periods match, pass
		// through to WCS's default rather than applying the override on an
		// assumption (every other guard here fails to pass-through).
		if ( ! method_exists( $switch_item, 'trial_periods_match' ) || ! $switch_item->trial_periods_match() ) {
			return $value;
		}

		// A switch into a one-payment (length-1) subscription routes through
		// WCS's set_upgrade_cost() regardless of switch type
		// (WCS_Switch_Totals_Calculator::calculate_prorated_totals), which
		// sets the sign-up fee to existing_fee + extra_to_pay -- adding our
		// override on top would double-charge. Pass through and let WCS price
		// the gap payment.
		if ( method_exists( $switch_item, 'is_switch_to_one_payment_subscription' ) && $switch_item->is_switch_to_one_payment_subscription() ) {
			return $value;
		}

		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return $value;
		}

		$new_recurring = (float) \WC_Subscriptions_Product::get_price( $new_product );
		if ( $new_recurring <= 0 ) {
			return $value;
		}

		// Compute the unconsumed credit from the switch_item's own helpers
		// rather than recomputing here -- WCS exposes them publicly and they
		// stay in sync with whatever total_paid/cycle adjustments our other
		// filters apply.
		if (
			! method_exists( $switch_item, 'get_total_paid_for_current_period' )
			|| ! method_exists( $switch_item, 'get_days_in_old_cycle' )
			|| ! method_exists( $switch_item, 'get_days_until_next_payment' )
		) {
			return $value;
		}

		$total_paid        = (float) $switch_item->get_total_paid_for_current_period();
		$days_in_old_cycle = (int) $switch_item->get_days_in_old_cycle();
		$days_until_next   = (int) $switch_item->get_days_until_next_payment();

		if ( $days_in_old_cycle <= 0 ) {
			return $value;
		}

		// Clamp the unconsumed fraction to [0, 1]. days_until_next (WCS ceil)
		// can exceed days_in_old_cycle (WCS round) by ~1 day near a cycle
		// boundary, and for migrated subs only days_in_old_cycle flows through
		// our clamp filter -- either can push the ratio above 1.0 and credit
		// the reader more than they paid.
		$unconsumed_ratio  = min( 1.0, max( 0.0, $days_until_next / $days_in_old_cycle ) );
		$unconsumed_credit = $total_paid * $unconsumed_ratio;

		return max( $new_recurring - $unconsumed_credit, 0.0 );
	}

	/**
	 * Whether a paid one-time sign-up fee should count toward the switch
	 * proration baseline.
	 *
	 * Off by default. Publishers that sell stepped pricing as a sign-up fee
	 * plus a free trial opt in by defining the
	 * NEWSPACK_WC_SUBS_SWITCH_INCLUDE_SIGNUP_FEE constant in wp-config.php, or
	 * by returning true from the newspack_wc_subs_switch_include_signup_fee
	 * filter for finer-grained control (e.g. per-subscription or per-product).
	 *
	 * @param \WC_Subscription       $subscription  The subscription being switched.
	 * @param \WC_Order_Item_Product $existing_item The subscription line item being switched.
	 *
	 * @return bool
	 */
	private static function should_count_signup_fee_on_switch( $subscription, $existing_item ) {
		$enabled = defined( 'NEWSPACK_WC_SUBS_SWITCH_INCLUDE_SIGNUP_FEE' ) && NEWSPACK_WC_SUBS_SWITCH_INCLUDE_SIGNUP_FEE;

		/**
		 * Filters whether a paid one-time sign-up fee is counted toward the
		 * proration baseline when switching subscriptions.
		 *
		 * The subscription and line item are provided so callbacks can scope
		 * the decision per-subscription or per-product (e.g. enable for a
		 * specific product variation only).
		 *
		 * @param bool                   $enabled       Whether the sign-up fee is counted.
		 * @param \WC_Subscription       $subscription  The subscription being switched.
		 * @param \WC_Order_Item_Product $existing_item The subscription line item being switched.
		 */
		return (bool) apply_filters( 'newspack_wc_subs_switch_include_signup_fee', $enabled, $subscription, $existing_item );
	}

	/**
	 * Get the amount paid for the current billing period including the
	 * one-time sign-up fee.
	 *
	 * Reuses WooCommerce Subscriptions' own accounting -- which walks the
	 * subscription's related orders and handles trials, synced fees, switch
	 * chains, and tax -- but includes sign-up fees, where WCS's
	 * get_total_paid_for_current_period() excludes them.
	 *
	 * @param \WC_Subscription       $subscription  The subscription being switched.
	 * @param \WC_Order_Item_Product $existing_item The subscription line item being switched.
	 *
	 * @return float The amount paid including sign-up fees, or 0 if it cannot be determined.
	 */
	private static function get_total_paid_including_signup_fee( $subscription, $existing_item ) {
		if (
			! class_exists( 'WC_Subscriptions_Switcher' )
			|| ! method_exists( 'WC_Subscriptions_Switcher', 'calculate_total_paid_since_last_order' )
		) {
			return 0.0;
		}

		// Leaving the 4th argument ($orders_to_include) at its default of an
		// empty array means WCS scans all related orders. WCS's own caller in
		// WCS_Switch_Cart_Item::get_total_paid_for_current_period() narrows
		// this for switch chains via is_switch_after_fully_reduced_prepaid_term();
		// we accept the broader scan because this branch only fires for
		// publishers who opted in to counting the sign-up fee, where a long
		// switch chain at the same product price would still sum to the same
		// amount the reader paid.
		return (float) \WC_Subscriptions_Switcher::calculate_total_paid_since_last_order(
			$subscription,
			$existing_item,
			'include_sign_up_fees'
		);
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

		return self::is_migrated_subscription( $subscription );
	}
}
WooCommerce_Subscriptions::init();
