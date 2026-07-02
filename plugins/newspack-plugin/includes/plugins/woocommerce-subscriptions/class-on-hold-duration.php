<?php
/**
 * WooCommerce Subscriptions On-Hold Duration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class On_Hold_Duration {
	/**
	 * The hook name for the manual subscription expiration scheduled action.
	 */
	const AS_HOOK = 'newspack_expire_manual_subscription';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		if ( ! WooCommerce_Subscriptions::is_enabled() ) {
			return;
		}

		add_filter( 'woocommerce_subscription_settings', [ __CLASS__, 'add_on_hold_duration_setting' ], 11, 1 );
		add_filter( 'wcs_default_retry_rules', [ __CLASS__, 'maybe_apply_on_hold_duration_rule' ], 99, 1 );
		add_action( 'woocommerce_subscription_status_on-hold', [ __CLASS__, 'maybe_schedule_expiration' ], 10, 1 );
		add_action( 'woocommerce_subscription_status_active', [ __CLASS__, 'maybe_unschedule_expiration' ], 10, 1 );
		add_action( 'woocommerce_subscriptions_after_apply_retry_rule', [ __CLASS__, 'maybe_unschedule_expiration_on_retry' ], 10, 3 );
		add_action( 'woocommerce_subscriptions_retry_status_updated', [ __CLASS__, 'maybe_reschedule_expiration_on_retry_end' ], 10, 2 );
		add_action( 'woocommerce_subscription_payment_failed', [ __CLASS__, 'trash_subscription_on_failed_payment' ], 10, 2 );
		add_action( self::AS_HOOK, [ __CLASS__, 'handle_scheduled_action' ] );
	}

	/**
	 * Add on-hold duration setting.
	 *
	 * @param array $settings Subscription settings.
	 *
	 * @return array
	 */
	public static function add_on_hold_duration_setting( $settings ) {
		if ( WooCommerce_Subscriptions::is_active() ) {
			return array_merge(
				$settings,
				[
					[
						'name' => __( 'Newspack Subscriptions Settings', 'newspack-plugin' ),
						'type' => 'title',
						'desc' => __( 'Subscriptions settings added by Newspack.', 'newspack-plugin' ),
						'id'   => 'newspack_subscriptions_options',
					],
					[
						'name'              => __( 'On-hold Duration', 'newspack-plugin' ),
						'id'                => 'newspack_subscriptions_on_hold_duration',
						'css'               => 'max-width:80px;',
						'value'             => self::get_on_hold_duration(),
						'type'              => 'number',
						'custom_attributes' => array(
							'min'  => 0,
							'step' => 1,
						),
						'desc'              => sprintf(
							// Translators: %s is a line break.
							__(
								'Set the number of days a subscription remains in the On-Hold status after all automatic payment retries have failed.%sDuring this period, subscribers can update their payment details to restore the subscription at their current price. Once this time expires, the subscription will automatically transition to expired.',
								'newspack-plugin'
							),
							'<br>'
						),
					],
					[
						'type' => 'sectionend',
						'id'   => 'newspack_subscriptions_options',
					],
				]
			);
		}
		return $settings;
	}

	/**
	 * Get on-hold duration. Defaults to 30.
	 *
	 * @return int
	 */
	public static function get_on_hold_duration() {
		return absint( get_option( 'newspack_subscriptions_on_hold_duration', 30 ) );
	}

	/**
	 * Conditionally adds on-hold duration rule to retry rules.
	 *
	 * @param array $retry_rules Subscriptions retry rules.
	 */
	public static function maybe_apply_on_hold_duration_rule( $retry_rules ) {
		if ( WooCommerce_Subscriptions::is_active() && count( $retry_rules ) > 0 ) {
			$on_hold_duration = self::get_on_hold_duration();
			if ( 0 < $on_hold_duration ) {
				$retry_rules[] = [
					'retry_after_interval'            => $on_hold_duration * DAY_IN_SECONDS,
					'status_to_apply_to_order'        => 'pending',
					'status_to_apply_to_subscription' => 'on-hold',
				];
			}
			$retry_rules[] = [
				'retry_after_interval'            => 0,
				'status_to_apply_to_order'        => 'failed',
				'status_to_apply_to_subscription' => 'expired',
			];
		}
		return $retry_rules;
	}

	/**
	 * Conditionally schedule expiration action if no retries are scheduled.
	 *
	 * @param object $subscription Subscription object.
	 */
	public static function maybe_schedule_expiration( $subscription ) {
		if ( $subscription->get_date( 'payment_retry' ) === 0 || ! \WCS_Retry_Manager::is_retry_enabled() ) {
			$default_grace_period = $subscription->is_manual() ? 7 * DAY_IN_SECONDS : 0; // Default grace period is 7 days if the subscription is manual otherwise 0.
			$on_hold_duration     = self::get_on_hold_duration() * DAY_IN_SECONDS;
			$timestamp            = $subscription->get_time( 'next_payment' ) + $default_grace_period + $on_hold_duration;
			self::schedule_expiration( $subscription->get_id(), $timestamp );
		}
	}

	/**
	 * Schedule expiration action.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $timestamp       Timestamp.
	 */
	public static function schedule_expiration( $subscription_id, $timestamp ) {
		if ( ! as_has_scheduled_action( self::AS_HOOK, [ $subscription_id ], 'newspack' ) ) {
			as_schedule_single_action( $timestamp, self::AS_HOOK, [ $subscription_id ], 'newspack' );
		}
	}

	/**
	 * Unschedule expiration if scheduled.
	 *
	 * @param \WC_Subscription $subscription The Subscription.
	 */
	public static function maybe_unschedule_expiration( $subscription ) {
		if ( false !== as_has_scheduled_action( self::AS_HOOK, [ $subscription->get_id() ], 'newspack' ) ) {
			as_unschedule_action( self::AS_HOOK, [ $subscription->get_id() ], 'newspack' );
		}
	}

	/**
	 * Unschedule expiration if payment retry is scheduled.
	 *
	 * @param array            $retry_rule   Retry rule.
	 * @param \WC_Order        $last_order   The last order.
	 * @param \WC_Subscription $subscription The Subscription.
	 */
	public static function maybe_unschedule_expiration_on_retry( $retry_rule, $last_order, $subscription ) {
		if ( $subscription->get_date( 'payment_retry' ) > 0 ) {
			self::maybe_unschedule_expiration( $subscription );
		}
	}

	/**
	 * Re-arm the on-hold expiration when a payment retry ends without a
	 * replacement retry being scheduled.
	 *
	 * The primary scheduler ({@see maybe_schedule_expiration}) only runs on the
	 * transition into the on-hold status. When a pending retry is cancelled —
	 * e.g. by the Stripe gateway after Stripe Radar blocks a renewal payment —
	 * the subscription is already on-hold, so that hook never fires again. Both
	 * expiration paths (the retry-rule progression and the scheduled action) are
	 * then lost, leaving the subscription on-hold indefinitely.
	 *
	 * This listens for a retry reaching a terminal status and, when the related
	 * subscription is left on-hold with no pending retry, schedules the
	 * expiration so the On-Hold Duration is still honored.
	 *
	 * @param \WCS_Retry $retry      The retry whose status changed.
	 * @param string     $new_status The new retry status.
	 */
	public static function maybe_reschedule_expiration_on_retry_end( $retry, $new_status ) {
		// Pending/processing retries still drive their own progression; only act once the retry has stopped.
		if ( in_array( $new_status, [ 'pending', 'processing' ], true ) ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_renewal_order( $retry->get_order_id() ) as $subscription ) {
			// A subscription is only stranded when it is left on-hold with no further retry queued.
			if ( 'on-hold' === $subscription->get_status() && 0 === $subscription->get_date( 'payment_retry' ) ) {
				self::maybe_schedule_expiration( $subscription );
			}
		}
	}

	/**
	 * Handle expiration scheduled action.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function handle_scheduled_action( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );
		if ( $subscription && 'on-hold' === $subscription->get_status() ) {
			$subscription->update_status( 'expired' );
		}
	}

	/**
	 * Trash subscription on failed payment.
	 *
	 * @param \WC_Subscription $subscription The Subscription.
	 * @param string           $status       The status.
	 */
	public static function trash_subscription_on_failed_payment( $subscription, $status ) {
		$last_order = $subscription->get_last_order( 'all' );
		if ( ! $last_order || $last_order->get_id() === $subscription->get_parent_id() ) {
			$subscription->update_status( 'trash', __( 'Subscription status updated by Newspack.', 'newspack-plugin' ) );
			$subscription->save();
		}
	}
}
