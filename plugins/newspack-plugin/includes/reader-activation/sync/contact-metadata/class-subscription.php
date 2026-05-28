<?php
/**
 * Subscription contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Donations;
use Newspack\Subscriptions_Meta;
use Newspack\WooCommerce_Connection;
use Newspack\Reader_Activation\Sync\Contact_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription metadata class.
 */
class Subscription extends Contact_Metadata {
	/**
	 * Cache for user subscriptions.
	 *
	 * @var \WC_Subscription[]
	 */
	private $user_subscriptions_cache;

	/**
	 * Cache for the current subscription.
	 *
	 * @var \WC_Subscription|null
	 */
	private $current_subscription_cache;

	/**
	 * Whether the current subscription has been resolved.
	 *
	 * @var bool
	 */
	private $current_subscription_resolved = false;

	/**
	 * Whether or not the metadata fields of this class are available to be synced.
	 *
	 * @return boolean
	 */
	public static function is_available() {
		return function_exists( 'wcs_get_users_subscriptions' );
	}

	/**
	 * The name of the metadata class, used as a section name for the fields handled by this class when syncing and in the UI for selecting which fields to sync.
	 *
	 * @return string
	 */
	public static function get_section_name() {
		return __( 'Subscription', 'newspack' );
	}

	/**
	 * The fields handled by this metadata class.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'Subscriber_Status'                      => 'Subscriber Status',
			'Active_Subscription_Count'              => 'Active Subscription Count',
			'Current_Subscription_Start_Date'        => 'Current Subscription Start Date',
			'Current_Subscription_End_Date'          => 'Current Subscription End Date',
			'Subscription_Cancellation_Reason'       => 'Subscription Cancellation Reason',
			'Current_Subscription_Billing_Cycle'     => 'Current Subscription Billing Cycle',
			'Current_Subscription_Recurring_Payment' => 'Current Subscription Recurring Payment',
			'Current_Subscription_Next_Payment_Date' => 'Current Subscription Next Payment Date',
			'Current_Subscription_Product_Name'      => 'Current Subscription Product Name',
			'Previous_Subscription_Product'          => 'Previous Subscription Product',
			'Current_Subscription_Coupon_Code'       => 'Current Subscription Coupon Code',
			'Last_Payment_Amount'                    => 'Last Payment Amount',
			'Last_Payment_Date'                      => 'Last Payment Date',
		];
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->user || ! self::is_available() ) {
			return [];
		}

		return [
			'Subscriber_Status'                      => $this->get_subscriber_status(),
			'Active_Subscription_Count'              => $this->get_active_subscription_count(),
			'Current_Subscription_Start_Date'        => $this->get_current_subscription_start_date(),
			'Current_Subscription_End_Date'          => $this->get_current_subscription_end_date(),
			'Subscription_Cancellation_Reason'       => $this->get_subscription_cancellation_reason(),
			'Current_Subscription_Billing_Cycle'     => $this->get_current_subscription_billing_cycle(),
			'Current_Subscription_Recurring_Payment' => $this->get_current_subscription_recurring_payment(),
			'Current_Subscription_Next_Payment_Date' => $this->get_current_subscription_next_payment_date(),
			'Current_Subscription_Product_Name'      => $this->get_current_subscription_product_name(),
			'Previous_Subscription_Product'          => $this->get_previous_subscription_product(),
			'Current_Subscription_Coupon_Code'       => $this->get_current_subscription_coupon_code(),
			'Last_Payment_Amount'                    => $this->get_last_payment_amount(),
			'Last_Payment_Date'                      => $this->get_last_payment_date(),
		];
	}

	/**
	 * Whether the given subscription is relevant to this metadata class.
	 *
	 * For Subscription, only non-donation subscriptions are relevant.
	 * Override in Donation class to invert the filter.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 *
	 * @return bool
	 */
	protected function is_relevant_subscription( $subscription ) {
		return ! Donations::is_donation_order( $subscription );
	}

	/**
	 * Get all relevant subscriptions for the current user.
	 *
	 * @return \WC_Subscription[]
	 */
	protected function get_user_subscriptions() {
		if ( isset( $this->user_subscriptions_cache ) ) {
			return $this->user_subscriptions_cache;
		}

		$this->user_subscriptions_cache = [];

		if ( ! $this->user || ! self::is_available() ) {
			return $this->user_subscriptions_cache;
		}

		$all_subscriptions = \wcs_get_users_subscriptions( $this->user->ID );
		foreach ( $all_subscriptions as $subscription ) {
			if ( $this->is_relevant_subscription( $subscription ) ) {
				$this->user_subscriptions_cache[] = $subscription;
			}
		}

		return $this->user_subscriptions_cache;
	}

	/**
	 * Get active relevant subscriptions for the current user.
	 *
	 * @return \WC_Subscription[]
	 */
	protected function get_active_subscriptions() {
		return array_filter(
			$this->get_user_subscriptions(),
			function ( $subscription ) {
				return $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES );
			}
		);
	}

	/**
	 * Get the current subscription for metadata purposes.
	 *
	 * Priority: most recent active subscription, then most recent cancelled/expired/on-hold.
	 *
	 * @return \WC_Subscription|null
	 */
	protected function get_current_subscription() {
		if ( $this->current_subscription_resolved ) {
			return $this->current_subscription_cache;
		}

		$this->current_subscription_resolved = true;

		$active = $this->get_active_subscriptions();
		if ( ! empty( $active ) ) {
			$this->current_subscription_cache = $this->prefer_non_gift( $active );
			return $this->current_subscription_cache;
		}

		$former = array_filter(
			$this->get_user_subscriptions(),
			function ( $subscription ) {
				return $subscription->has_status( WooCommerce_Connection::FORMER_SUBSCRIBER_STATUSES );
			}
		);
		if ( ! empty( $former ) ) {
			$this->current_subscription_cache = $this->prefer_non_gift( $former );
			return $this->current_subscription_cache;
		}

		return null;
	}

	/**
	 * From a list of subscriptions, prefer non-gift subscriptions over gifts.
	 *
	 * Falls back to the first subscription if all are gifts or the gifting plugin is not active.
	 *
	 * @param \WC_Subscription[] $subscriptions Subscriptions to choose from.
	 * @return \WC_Subscription
	 */
	private function prefer_non_gift( $subscriptions ) {
		if ( class_exists( 'WCS_Gifting' ) ) {
			foreach ( $subscriptions as $subscription ) {
				if ( ! \WCS_Gifting::is_gifted_subscription( $subscription ) ) {
					return $subscription;
				}
			}
		}
		return reset( $subscriptions );
	}

	/**
	 * Get the last successful order for a subscription.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @return \WC_Order|null
	 */
	protected function get_last_successful_order( $subscription ) {
		$last_order = $subscription->get_last_order(
			'all',
			[
				'parent',
				'renewal',
			],
			[
				'pending',
				'failed',
				'on-hold',
				'cancelled',
				'trash',
				'draft',
				'auto-draft',
				'new',
			]
		);

		return $last_order ? $last_order : null;
	}

	/**
	 * Get the subscriber status.
	 *
	 * @return string
	 */
	protected function get_subscriber_status() {
		$subscription = $this->get_current_subscription();
		return $subscription ? $subscription->get_status() : '';
	}

	/**
	 * Get the number of active subscriptions.
	 *
	 * @return int
	 */
	protected function get_active_subscription_count() {
		return count( $this->get_active_subscriptions() );
	}

	/**
	 * Get the start date of the current subscription.
	 *
	 * @return string
	 */
	protected function get_current_subscription_start_date() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}
		return $this->format_date( $subscription->get_date( 'start', 'site' ) );
	}

	/**
	 * Get the end date of the current subscription.
	 *
	 * @return string
	 */
	protected function get_current_subscription_end_date() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}
		return $this->format_date( $subscription->get_date( 'end', 'site' ) );
	}

	/**
	 * Get the cancellation reason for the current subscription.
	 *
	 * @return string
	 */
	protected function get_subscription_cancellation_reason() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}

		$reason = $subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY );
		if ( empty( $reason ) ) {
			return '';
		}

		// Exclude pending-cancel reasons — these are intermediate states, not final.
		$pending_reasons = [
			Subscriptions_Meta::CANCELLATION_REASON_USER_PENDING_CANCEL,
			Subscriptions_Meta::CANCELLATION_REASON_ADMIN_PENDING_CANCEL,
		];
		if ( in_array( $reason, $pending_reasons, true ) ) {
			return '';
		}

		return $reason;
	}

	/**
	 * Get the billing cycle of the current subscription.
	 *
	 * @return string
	 */
	protected function get_current_subscription_billing_cycle() {
		$subscription = $this->get_current_subscription();
		return $subscription ? $subscription->get_billing_period() : '';
	}

	/**
	 * Get the recurring payment amount of the current subscription.
	 *
	 * @return string
	 */
	protected function get_current_subscription_recurring_payment() {
		$subscription = $this->get_current_subscription();
		return $subscription ? $subscription->get_total() : '';
	}

	/**
	 * Get the next payment date of the current subscription.
	 *
	 * @return string
	 */
	protected function get_current_subscription_next_payment_date() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}
		$next_payment = $subscription->get_date( 'next_payment' );
		// When a subscription is terminated, next_payment is set to 0.
		if ( ! $next_payment || '0' === $next_payment ) {
			return '';
		}
		return $this->format_date( $next_payment );
	}

	/**
	 * Get the product name of the current subscription.
	 *
	 * @return string
	 */
	protected function get_current_subscription_product_name() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}
		$items = $subscription->get_items();
		if ( empty( $items ) ) {
			return '';
		}
		return reset( $items )->get_name();
	}

	/**
	 * Get the previous subscription product name before a plan switch.
	 *
	 * @return string
	 */
	protected function get_previous_subscription_product() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}

		$switch_orders = $subscription->get_related_orders( 'all', 'switch' );
		if ( empty( $switch_orders ) ) {
			return '';
		}

		// Get the most recent switch order.
		$switch_order = reset( $switch_orders );
		if ( is_numeric( $switch_order ) ) {
			$switch_order = \wc_get_order( $switch_order );
		}
		if ( ! $switch_order ) {
			return '';
		}

		$switch_data = $switch_order->get_meta( '_subscription_switch_data' );
		if ( ! empty( $switch_data ) && is_array( $switch_data ) ) {
			// Switch data is keyed by subscription ID.
			$sub_switch_data = isset( $switch_data[ $subscription->get_id() ] ) ? $switch_data[ $subscription->get_id() ] : reset( $switch_data );
			if ( ! empty( $sub_switch_data['old_product_id'] ) ) {
				$old_product = \wc_get_product( $sub_switch_data['old_product_id'] );
				if ( $old_product ) {
					return $old_product->get_name();
				}
			}
		}

		return '';
	}

	/**
	 * Get the coupon code applied to the current subscription.
	 *
	 * @return string
	 */
	protected function get_current_subscription_coupon_code() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}

		$coupons = $subscription->get_coupon_codes();
		if ( ! empty( $coupons ) ) {
			return reset( $coupons );
		}

		$parent_order = $subscription->get_parent();
		if ( $parent_order ) {
			$parent_coupons = $parent_order->get_coupon_codes();
			if ( ! empty( $parent_coupons ) ) {
				return reset( $parent_coupons );
			}
		}

		return '';
	}

	/**
	 * Get the last payment amount.
	 *
	 * @return string
	 */
	protected function get_last_payment_amount() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}

		$last_order = $this->get_last_successful_order( $subscription );
		return $last_order ? $last_order->get_total() : '';
	}

	/**
	 * Get the last payment date.
	 *
	 * @return string
	 */
	protected function get_last_payment_date() {
		$subscription = $this->get_current_subscription();
		if ( ! $subscription ) {
			return '';
		}

		$last_order = $this->get_last_successful_order( $subscription );
		if ( ! $last_order ) {
			return '';
		}

		$date_paid = $last_order->get_date_paid();
		if ( empty( $date_paid ) ) {
			return '';
		}

		return $date_paid->date( self::DATE_FORMAT );
	}
}
