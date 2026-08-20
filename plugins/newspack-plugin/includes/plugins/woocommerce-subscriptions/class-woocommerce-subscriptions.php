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
	 * Order meta holding the IDs of pending-cancel subscriptions that were
	 * reactivated for a switch processed on that order.
	 */
	const REACTIVATED_FOR_SWITCH_META = '_newspack_switch_reactivated_subscriptions';

	/**
	 * Option recording which WooCommerce Subscriptions product-type settings
	 * Newspack turned on, as a list of the option names written.
	 *
	 * Write provenance, not a gate: once the WooCommerce row exists,
	 * `maybe_enable_legacy_product_types()` never reconsiders it anyway. This
	 * exists so the write is answerable afterwards — which sites Newspack
	 * touched, and which of the two rows it created — so that a corrective
	 * release could reverse only those and leave a publisher's own choice alone.
	 */
	const PRODUCT_TYPES_ENABLED_OPTION = 'newspack_subscriptions_enabled_product_types';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'woocommerce_subscriptions_integration_init' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_enable_legacy_product_types' ] );
		add_filter( 'woocommerce_subscriptions_product_limited_for_user', [ __CLASS__, 'maybe_limit_subscription_product_for_user' ], 10, 3 );
		add_filter( 'woocommerce_subscriptions_product_trial_length', [ __CLASS__, 'limit_free_trials_to_one_per_user' ], 10, 2 );
		add_filter( 'wcs_get_users_subscriptions', [ __CLASS__, 'filter_subscriptions_for_account_page' ], 10, 1 );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', [ __CLASS__, 'allow_migrated_subscription_switch' ], 10, 3 );
		add_filter( 'wcs_switch_total_paid_for_current_period', [ __CLASS__, 'recover_total_paid_for_switch' ], 10, 3 );
		add_filter( 'wcs_switch_proration_days_in_old_cycle', [ __CLASS__, 'bound_switch_proration_days_in_old_cycle' ], 10, 2 );
		add_filter( 'wcs_switch_sign_up_fee', [ __CLASS__, 'apply_stepped_pricing_switch_charge' ], 10, 2 );
		add_filter( 'wcs_can_user_resubscribe_to_subscription', [ __CLASS__, 'allow_migrated_subscription_to_resubscribe' ], 10, 3 );

		// Pending-cancel switches: eligibility plus the reactivate-then-switch
		// pair. The three callbacks work together — see
		// allow_pending_cancel_subscription_switch() for the whole story.
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', [ __CLASS__, 'allow_pending_cancel_subscription_switch' ], 10, 3 );
		// Priority 40: WC_Subscriptions_Switcher::process_checkout() runs at 50
		// on both hooks, and it must see an already-active subscription.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'maybe_reactivate_pending_cancel_switch' ], 40 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'maybe_reactivate_pending_cancel_switch' ], 40 );
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'maybe_revert_reactivation_on_failed_switch' ] );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'maybe_revert_reactivation_on_failed_switch' ] );

		// Adding a card to a subscription that has no next payment date: eligibility
		// plus the follow-through that resumes billing. See
		// allow_add_payment_method_without_next_payment() for the whole story.
		// Priority 20: WCS registers its own answer at 10 and we only override a no.
		add_filter( 'woocommerce_can_subscription_be_updated_to_new-payment-method', [ __CLASS__, 'allow_add_payment_method_without_next_payment' ], 20, 2 );
		// Not `woocommerce_subscription_payment_method_updated`: that fires before the
		// card is validated or charged, so a declined card would still leave a
		// schedule behind. This filter runs after process_payment() and carries its
		// result, so the schedule can follow the payment instead of preceding it.
		add_filter( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode', [ __CLASS__, 'schedule_next_payment_after_payment_method_added' ], 10, 2 );
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
	 * Allow a pending-cancel subscription to be switched.
	 *
	 * WCS's own eligibility test (WC_Subscriptions_Switcher::is_action_allowed())
	 * requires `active` status, which shuts the tiers/upgrade modal for exactly
	 * the win-back readers it targets (NPPM-2952): a reader who turned off
	 * auto-renew is `pending-cancel` for the rest of their paid term.
	 *
	 * Granting eligibility alone is not enough, though: WCS's checkout
	 * processing computes a `next_payment` date update for the switched
	 * subscription, and its date validation rejects any `next_payment` on a
	 * subscription carrying a `cancelled` date — the switch would hard-fail at
	 * checkout. The completing half is therefore
	 * {@see maybe_reactivate_pending_cancel_switch()}, which reactivates the
	 * subscription immediately before WCS processes the switch order, restoring
	 * exactly the state WCS's active-switch flow is built for (cancelled date
	 * cleared, next payment back on the prepaid-term end). A switch initiated
	 * from a pending-cancel subscription thus means "resume auto-renewal on the
	 * new tier". If the switch order fails,
	 * {@see maybe_revert_reactivation_on_failed_switch()} puts the subscription
	 * back to pending-cancel.
	 *
	 * Eligibility is gated on `can_be_updated_to( 'active' )` so the offer is
	 * only made when that reactivation can actually happen (end date still in
	 * the future; payment method supports reactivation and date changes, or
	 * manual renewals). The remaining checks mirror the non-status half of
	 * WCS's own is_action_allowed().
	 *
	 * @param bool                   $can_switch   Whether the item can be switched.
	 * @param \WC_Order_Item_Product $item         An order item on the subscription to switch.
	 * @param \WC_Subscription       $subscription An instance of WC_Subscription.
	 *
	 * @return bool Whether the item can be switched.
	 */
	public static function allow_pending_cancel_subscription_switch( $can_switch, $item, $subscription ) {
		if ( $can_switch ) {
			return $can_switch;
		}

		if ( ! ( $subscription instanceof \WC_Subscription ) || ! $subscription->has_status( 'pending-cancel' ) ) {
			return $can_switch;
		}

		if ( ! $subscription->can_be_updated_to( 'active' ) ) {
			return $can_switch;
		}

		if ( ! function_exists( 'wcs_get_canonical_product_id' ) || ! function_exists( 'wcs_is_product_switchable_type' ) ) {
			return $can_switch;
		}

		// The non-status checks from WC_Subscriptions_Switcher::is_action_allowed().
		// The type read must be bare, like WCS's own and allow_migrated_subscription_switch()'s:
		// WC_Order_Item::offsetGet() resolves getter-backed keys such as `type`, but
		// offsetExists() does not report them, so an isset() here is false on a real
		// order item and would deny every switch.
		$product_id            = wcs_get_canonical_product_id( $item );
		$is_product_switchable = 'line_item' === $item['type'] && wcs_is_product_switchable_type( $product_id );
		$has_last_order        = 0 !== $subscription->get_date( 'last_order_date_created' );
		$can_be_updated        = $subscription->payment_method_supports( 'subscription_amount_changes' ) && $subscription->payment_method_supports( 'subscription_date_changes' );

		if ( $is_product_switchable && $has_last_order && $can_be_updated ) {
			return true;
		}

		return $can_switch;
	}

	/**
	 * Reactivate the current reader's pending-cancel subscriptions that are
	 * being switched by the order just processed at checkout.
	 *
	 * Runs right before WCS's own switch processing (priority 50 on the same
	 * hooks) so the date updates it computes are validated against an active
	 * subscription — see {@see allow_pending_cancel_subscription_switch()} for
	 * why a pending-cancel subscription cannot go through it directly.
	 * Reactivation restores the next payment date to the prepaid-term end, so
	 * the switch proration math is unchanged from what the reader was shown.
	 *
	 * Only subscriptions the current reader owns are touched: a switch on
	 * someone else's subscription is left for WCS to reject.
	 *
	 * @param int|\WC_Order $order The order processed at checkout (ID on the classic hook, object on the Store API hook).
	 */
	public static function maybe_reactivate_pending_cancel_switch( $order ) {
		if (
			! class_exists( 'WC_Subscriptions_Switcher' )
			|| ! function_exists( 'wcs_get_subscription' )
			|| ! is_callable( [ 'WC_Subscriptions_Switcher', 'cart_contains_switches' ] )
		) {
			return;
		}

		$switches = \WC_Subscriptions_Switcher::cart_contains_switches( 'switch' );
		if ( empty( $switches ) || ! is_array( $switches ) ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$reactivated = [];
		foreach ( $switches as $switch_details ) {
			if ( empty( $switch_details['subscription_id'] ) ) {
				continue;
			}
			$subscription = wcs_get_subscription( $switch_details['subscription_id'] );
			if (
				! $subscription
				|| ! $subscription->has_status( 'pending-cancel' )
				|| (int) $subscription->get_user_id() !== $user_id
				|| ! $subscription->can_be_updated_to( 'active' )
			) {
				continue;
			}
			$subscription->update_status(
				'active',
				sprintf(
					/* translators: %s: the order number of the switch order. */
					__( 'Subscription reactivated for the tier switch in order %s.', 'newspack-plugin' ),
					$order->get_id()
				)
			);
			$reactivated[] = $subscription->get_id();
		}

		if ( empty( $reactivated ) ) {
			return;
		}

		// Stamp the order so the reactivation can be reverted if this switch
		// order never completes.
		$order->update_meta_data( self::REACTIVATED_FOR_SWITCH_META, $reactivated );
		$order->save();
	}

	/**
	 * Revert a reactivation performed by
	 * {@see maybe_reactivate_pending_cancel_switch()} when its switch order
	 * fails or is cancelled without payment.
	 *
	 * The reader turned off auto-renewal before attempting the switch; a
	 * declined or abandoned switch payment must not leave that choice silently
	 * undone. Re-cancelling recomputes the prepaid-term end from the restored
	 * next payment date, so the subscription returns to the state it was in
	 * before the switch attempt. If the order is later paid anyway, WCS
	 * completes the item switch on the pending-cancel subscription (its
	 * completion-time date updates carry no next payment), leaving the reader
	 * on the new tier for the rest of the prepaid term.
	 *
	 * @param int $order_id The failed or cancelled order.
	 */
	public static function maybe_revert_reactivation_on_failed_switch( $order_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// A switch order that was paid at some point completed its switch — a
		// later cancellation (e.g. an admin refund) must not undo the
		// subscription state the reader paid for.
		if ( $order->get_date_paid() ) {
			return;
		}

		$subscription_ids = $order->get_meta( self::REACTIVATED_FOR_SWITCH_META );
		if ( empty( $subscription_ids ) || ! is_array( $subscription_ids ) ) {
			return;
		}

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if (
				! $subscription
				// Only revert the state this integration created: a subscription
				// that has moved on (renewed, cancelled, completed another switch)
				// is left alone.
				|| ! $subscription->has_status( 'active' )
				|| ! $subscription->can_be_updated_to( 'pending-cancel' )
			) {
				continue;
			}
			$subscription->update_status(
				'pending-cancel',
				sprintf(
					/* translators: %s: the order number of the failed switch order. */
					__( 'Reactivation reverted: the switch order %s was not completed.', 'newspack-plugin' ),
					$order->get_id()
				)
			);
		}

		// One revert per stamp: a later transition of the same order (e.g.
		// failed, then cancelled) must not re-cancel a subscription the reader
		// reactivated on purpose in the meantime.
		$order->delete_meta_data( self::REACTIVATED_FOR_SWITCH_META );
		$order->save();
	}

	/**
	 * Let a reader add a card to a subscription that has no next payment date.
	 *
	 * WCS withholds the "change payment method" action from any subscription
	 * whose next payment date is unset, in
	 * {@see \WC_Subscriptions_Change_Payment_Gateway::can_subscription_be_updated_to_new_payment_method()}.
	 * That rule assumes a next payment date always exists once a subscription is
	 * running, which holds for subscriptions bought at checkout but not for ones
	 * a publisher creates by hand in wp-admin: those start with no payment method
	 * and nothing scheduled, so the reader is offered no way to put a card on
	 * file and the subscription can never be paid (NPPD-2170).
	 *
	 * The rule is enforced in exactly one place, and every part of the flow reads
	 * it — the action button, the form, and the request validator all call
	 * `can_be_updated_to( 'new-payment-method' )`. The POST handler applies no
	 * status or date test of its own, so granting eligibility is sufficient to
	 * open the flow end to end. WCS then labels the action "Add payment" rather
	 * than "Change payment" whenever the subscription has no gateway yet, which
	 * is the wording this case wants.
	 *
	 * Deliberately narrow. WCS refuses the action for several distinct reasons;
	 * this reproduces the ones current WCS applies in
	 * `can_subscription_be_updated_to_new_payment_method()` — automatic payments
	 * switched off store-wide, no recurring charge, no gateway that can take a
	 * customer card, a gateway that cannot cancel — and overrides only the
	 * missing-next-payment / not-active refusal it targets. If a future WCS adds a
	 * refusal, mirror it below. Overriding just the one refusal, rather than
	 * returning a blanket `true`, is what keeps a publisher's "no automatic
	 * renewals" choice intact.
	 *
	 * Paired with {@see schedule_next_payment_after_payment_method_added()},
	 * which schedules the payment the missing date would otherwise leave unset.
	 *
	 * @param bool             $can_be_updated Whether WCS allows the change.
	 * @param \WC_Subscription $subscription   The subscription being checked.
	 *
	 * @return bool Whether the payment method can be changed.
	 */
	public static function allow_add_payment_method_without_next_payment( $can_be_updated, $subscription ) {
		if ( $can_be_updated ) {
			return $can_be_updated;
		}

		if ( ! ( $subscription instanceof \WC_Subscription ) ) {
			return $can_be_updated;
		}

		// Reproduce every WCS refusal except the missing-next-payment / not-active
		// one this filter exists to override. See
		// WC_Subscriptions_Change_Payment_Gateway::can_subscription_be_updated_to_new_payment_method().

		// The store has turned automatic payments off entirely — a publisher
		// configuration choice, not a per-subscription state. static:: so a test
		// subclass can override the WCS-dependent read.
		if ( static::store_requires_manual_renewal() ) {
			return $can_be_updated;
		}

		// Nothing recurring to charge, so nothing to add a card for. WCS reads the
		// filtered ('view') total here; 'edit' is deliberate, so a site filtering
		// order totals cannot open the flow on a subscription with nothing to bill.
		if ( (float) $subscription->get_total( 'edit' ) <= 0 ) {
			return $can_be_updated;
		}

		// No gateway can take a card from the reader, so the flow would dead-end.
		if ( ! self::one_gateway_supports_customer_payment_method_change() ) {
			return $can_be_updated;
		}

		// The subscription's own gateway cannot cancel, which WCS requires before
		// letting a reader swap payment method. A card-less subscription reads as
		// manual here, so this passes for the hand-made case it targets.
		if ( ! $subscription->payment_method_supports( 'subscription_cancellation' ) ) {
			return $can_be_updated;
		}

		// The refusal we override, limited to statuses where putting a card on file
		// leads somewhere: awaiting a first payment, suspended, or running.
		// pending-cancel is excluded — its next payment is already cleared by
		// design, so it would always match, and the action would do nothing for a
		// subscription winding down (reactivating restores WCS's own flow).
		if ( ! $subscription->has_status( [ 'pending', 'on-hold', 'active' ] ) ) {
			return $can_be_updated;
		}

		if ( $subscription->get_time( 'next_payment' ) > 0 ) {
			return $can_be_updated;
		}

		return true;
	}

	/**
	 * Whether the store has switched automatic payments off — WCS's manual
	 * renewals required with the reader-facing auto-renew toggle disabled. Under
	 * that configuration WCS deliberately hides the payment-method change action,
	 * and the eligibility filter must not re-open it.
	 *
	 * `protected` rather than `private` and called through `static::` so a test
	 * can override this WCS-dependent read with a subclass, without the eligibility
	 * filter having to load WooCommerce Subscriptions or define its globals. A
	 * missing toggle class leaves the manual-renewal setting to decide, matching
	 * WCS's own guard.
	 *
	 * @return bool
	 */
	protected static function store_requires_manual_renewal() {
		$required       = function_exists( 'wcs_is_manual_renewal_required' ) && \wcs_is_manual_renewal_required();
		$toggle_enabled = class_exists( 'WCS_My_Account_Auto_Renew_Toggle' ) && \WCS_My_Account_Auto_Renew_Toggle::is_enabled();

		return $required && ! $toggle_enabled;
	}

	/**
	 * Whether any gateway lets a reader change their own payment method.
	 *
	 * Routed through the WCS handler class rather than
	 * `WC_Subscriptions_Payment_Gateways` directly, because WooPayments
	 * substitutes its own handler and WCS resolves the capability through
	 * whichever is active.
	 *
	 * @return bool
	 */
	private static function one_gateway_supports_customer_payment_method_change() {
		if ( ! class_exists( 'WC_Subscriptions_Core_Plugin' ) ) {
			return false;
		}

		$handler = \WC_Subscriptions_Core_Plugin::instance()->get_gateways_handler_class();
		if ( ! is_callable( [ $handler, 'one_gateway_supports' ] ) ) {
			return false;
		}

		return (bool) $handler::one_gateway_supports( 'subscription_payment_method_change_customer' );
	}

	/**
	 * Schedule the next payment once a card is attached to a subscription that
	 * had none.
	 *
	 * The other half of {@see allow_add_payment_method_without_next_payment()},
	 * and deliberately narrower than it: eligibility opens the form for three
	 * statuses, but only `active` gets a date written here.
	 *
	 * An `active` subscription is, by WCS's own model, currently inside a paid
	 * period — the reader has access. For the subscriptions this targets, a human
	 * granted that period on purpose (support setting a stuck subscription active
	 * so the reader keeps access). Scheduling the first *future* charge one
	 * billing period out is consistent with both facts, and it is what unlocks
	 * WCS's early renewal — `wcs_can_user_renew_early()` refuses on
	 * `subscription_no_next_payment`, so a reader who wants to pay sooner has no
	 * self-serve route until a date exists. With a date set, "Renew now" appears
	 * and takes payment immediately, resetting the cycle from that payment.
	 *
	 * `pending` and `on-hold` are left to WooCommerce: they carry an unpaid order,
	 * and paying it is what activates the subscription and sets its date. Writing
	 * a date for them would also withdraw the "Add payment method" action (this
	 * pair steps back once a date exists, while WCS still refuses a non-active
	 * subscription), stranding the reader with a card on file and no way back.
	 *
	 * `calculate_date( 'next_payment' )` derives the date from the last payment or
	 * the start date plus one billing period, keeps it far enough out to survive a
	 * daylight-saving shift, and returns `0` when the next period would fall past
	 * the subscription's end date; a `0` is respected. `can_date_be_updated()`
	 * additionally gates on the gateway supporting date changes, so a gateway that
	 * owns the billing agreement and refuses them (PayPal Standard) never gets a
	 * Woo-side schedule it would not honour.
	 *
	 * Runs on `woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode`,
	 * which WCS applies after `process_payment()` and before it bails on a
	 * non-success result. Scheduling from the earlier
	 * `woocommerce_subscription_payment_method_updated` action would write a date
	 * for a card that was later declined, leaving an automatic renewal queued
	 * against a card the gateway never accepted.
	 *
	 * @param array            $result       The payment result, with a `result` key.
	 * @param \WC_Subscription $subscription The subscription whose method changed.
	 *
	 * @return array The unmodified payment result.
	 */
	public static function schedule_next_payment_after_payment_method_added( $result, $subscription ) {
		if ( ! ( $subscription instanceof \WC_Subscription ) ) {
			return $result;
		}

		// Only once the card has actually been accepted. WCS returns here after
		// process_payment() and bails on anything but success, so a declined card
		// must leave no schedule behind.
		if ( ! is_array( $result ) || ! isset( $result['result'] ) || 'success' !== $result['result'] ) {
			return $result;
		}

		// Ask the subscription whether a chargeable gateway is attached rather than
		// trusting a method string: this runs for admin and bulk updates too, and
		// has_payment_gateway() is the same predicate the My Account label uses.
		if ( ! $subscription->has_payment_gateway() || $subscription->is_manual() ) {
			return $result;
		}

		// Only the gap this pair exists to close. An already-scheduled payment is
		// the reader's or WCS's, and is never rewritten.
		if ( $subscription->get_time( 'next_payment' ) > 0 ) {
			return $result;
		}

		// Active only. See the note above on why pending / on-hold are left to
		// WooCommerce's own payment-completes-then-schedules flow.
		if ( ! $subscription->has_status( 'active' ) ) {
			return $result;
		}

		// Respect the same gate WCS applies to every date write: the gateway must
		// support date changes, or its schedule and Woo's would silently diverge.
		if ( ! $subscription->can_date_be_updated( 'next_payment' ) ) {
			return $result;
		}

		$next_payment = $subscription->calculate_date( 'next_payment' );
		if ( empty( $next_payment ) ) {
			return $result;
		}

		// update_dates() validates the new date against the subscription's other
		// dates and throws when they disagree. A subscription we could not
		// schedule is left as it was: the reader still has their card on file,
		// and the publisher can set the date by hand.
		try {
			$subscription->update_dates( [ 'next_payment' => $next_payment ] );
			$subscription->save();
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: the newly scheduled next payment date, localised. */
					__( 'Next payment scheduled for %s after the subscriber added a payment method.', 'newspack-plugin' ),
					$subscription->get_date_to_display( 'next_payment' )
				)
			);
		} catch ( \Exception $e ) {
			// An order note rather than Logger::error(): Logger::log() returns early
			// unless NEWSPACK_LOG_LEVEL is defined and non-zero, which it is not on a
			// stock site, so the one failure this method plans for would otherwise
			// leave no trace anywhere the publisher looks.
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: the reason the date could not be set. */
					__( 'A payment method was added, but the next payment date could not be scheduled: %s. The next payment date may need setting by hand.', 'newspack-plugin' ),
					$e->getMessage()
				)
			);
		}

		return $result;
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
		include_once __DIR__ . '/class-card-expiry-warning.php';

		On_Hold_Duration::init();
		Renewal::init();
		Subscriptions_Meta::init();
		Subscriptions_Confirmation::init();
		Card_Expiry_Warning::init();
	}

	/**
	 * Enable the `subscription` and `variable-subscription` product types, once.
	 *
	 * WooCommerce Subscriptions 9.0 added a "Subscription product creation" section
	 * whose two checkboxes default to off, which removes those product types from
	 * the product-type dropdown. Newspack still creates them — the Audience wizard
	 * writes them directly — so on an unconfigured site a publisher cannot create
	 * or switch to a product type our own wizard produces.
	 *
	 * Runs on `admin_init`, matching how the plugin writes other third-party
	 * options (`Parsely::migrate_meta_type()`, `WooCommerce_Email_Style_Sync`,
	 * `Emails_Section`), which keeps it off ordinary front-end pageloads, REST
	 * requests and cron. It is not an authentication guarantee: `admin-ajax.php`
	 * fires `admin_init` too, so a `wp_ajax_nopriv_*` action reaches this
	 * unauthenticated. That is harmless here — the write is one-shot and the same
	 * either way — but the hook is a narrower surface, not a logged-in one.
	 *
	 * Nothing is lost by waiting — a Subscriptions upgrade happens through an
	 * admin action, so `admin_init` fires in the same session, and the
	 * product-type dropdown this unblocks is itself only reachable in wp-admin.
	 * It also makes the opt-out filter usable from a theme's `functions.php`,
	 * which a `plugins_loaded` call would not.
	 *
	 * @return void
	 */
	public static function maybe_enable_legacy_product_types() {
		if ( ! self::is_active() ) {
			return;
		}
		self::enable_legacy_product_types();
	}

	/**
	 * Write the product-type settings, for options that have never been written.
	 *
	 * Only an option whose row is absent is touched. Anything that has saved the
	 * setting leaves a `'no'`, including WooCommerce's own settings screen when the
	 * box is unticked, so an absent row is the only signal that the publisher has no
	 * preference. That makes this self-limiting: after the first write the row
	 * exists, so the check never passes again and an unticked box stays unticked.
	 *
	 * Known limitation, accepted deliberately: `WC_Admin_Settings::save_fields()`
	 * writes every field in a section on any save of that tab, not only the fields
	 * the publisher touched. A site that reached 9.0 and then saved WooCommerce →
	 * Settings → Subscriptions for an unrelated reason therefore has `'no'` rows
	 * nobody chose, and this permanently no-ops for it. Widening the test to treat
	 * `'no'` as fair game would forfeit the one thing that makes writing another
	 * plugin's setting safe — so those sites are left to the settings screen.
	 *
	 * Deliberately not gated on the Subscriptions version. Writing the option before
	 * a site reaches 9.0 is the point — the new default then never applies.
	 *
	 * @return void
	 */
	public static function enable_legacy_product_types() {
		/**
		 * Filters whether Newspack enables the legacy subscription product types.
		 *
		 * Returning false leaves the WooCommerce settings untouched. Because the
		 * write runs on `admin_init` and happens at most once per option, the
		 * filter must be added before then — a plugin, mu-plugin or a theme's
		 * `functions.php` all work; it cannot be used to undo a write already made.
		 *
		 * @param bool $enable Whether to enable the legacy subscription product types.
		 */
		if ( ! apply_filters( 'newspack_subscriptions_enable_legacy_product_types', true ) ) {
			return;
		}

		$options = [
			'woocommerce_subscriptions_enable_simple_subscription',
			'woocommerce_subscriptions_enable_variable_subscription',
		];

		// A sentinel default rather than a `false ===` test: `get_option()` returns
		// the default only when the row is absent, so this stays correct even for
		// an option stored as `false` or an empty string.
		$absent  = new \stdClass();
		$written = [];
		foreach ( $options as $option ) {
			if ( $absent === get_option( $option, $absent ) ) {
				$written[] = $option;
			}
		}

		if ( empty( $written ) ) {
			return;
		}

		foreach ( $written as $option ) {
			update_option( $option, 'yes' );
		}

		update_option(
			self::PRODUCT_TYPES_ENABLED_OPTION,
			array_values( array_unique( array_merge( (array) get_option( self::PRODUCT_TYPES_ENABLED_OPTION, [] ), $written ) ) )
		);

		Logger::newspack_log(
			'newspack_subscriptions_product_types_enabled',
			'Enabled WooCommerce Subscriptions product types that had never been configured.',
			[ 'options' => $written ],
			'info'
		);
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

		// Standard and modal checkout refreshes can both carry guest emails in serialized post_data.
		if (
			! $user_id &&
			method_exists( 'Newspack_Blocks\Modal_Checkout', 'get_user_id_from_email' )
		) {
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
