<?php
/**
 * Donation contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Donations;
use Newspack\WooCommerce_Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Donation metadata class.
 *
 * Extends Subscription to reuse subscription data access helpers,
 * with the filter inverted to only consider donation subscriptions.
 */
class Donation extends Subscription {

	/**
	 * Cache for the one-time donation order.
	 *
	 * @var \WC_Order|null
	 */
	private $one_time_donation_order_cache;

	/**
	 * Whether the one-time donation order has been resolved.
	 *
	 * @var bool
	 */
	private $one_time_donation_order_resolved = false;

	/**
	 * The name of the metadata class, used as a section name for the fields handled by this class when syncing and in the UI for selecting which fields to sync.
	 *
	 * @return string
	 */
	public static function get_section_name() {
		return __( 'Donation', 'newspack' );
	}

	/**
	 * The fields handled by this metadata class.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'Donor_Status'                  => 'Donor Status',
			'Active_Donation_Count'         => 'Active Donation Count',
			'Current_Donation_Start_Date'   => 'Current Donation Start Date',
			'Current_Donation_End_Date'     => 'Current Donation End Date',
			'Current_Donation_Cycle'        => 'Current Donation Cycle',
			'Current_Recurring_Donation'    => 'Current Recurring Donation',
			'Next_Donation_Date'            => 'Next Donation Date',
			'Current_Donation_Product_Name' => 'Current Donation Product Name',
			'Previous_Donation_Product'     => 'Previous Donation Product',
			'Previous_Donation_Amount'      => 'Previous Donation Amount',
			'Last_Donation_Amount'          => 'Last Donation Amount',
			'Last_Donation_Date'            => 'Last Donation Date',
		];
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->user || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return [];
		}

		return [
			'Donor_Status'                  => $this->get_donor_status(),
			'Active_Donation_Count'         => $this->get_active_subscription_count(),
			'Current_Donation_Start_Date'   => $this->get_current_subscription_start_date(),
			'Current_Donation_End_Date'     => $this->get_current_subscription_end_date(),
			'Current_Donation_Cycle'        => $this->get_current_subscription_billing_cycle(),
			'Current_Recurring_Donation'    => $this->get_current_subscription_recurring_payment(),
			'Next_Donation_Date'            => $this->get_current_subscription_next_payment_date(),
			'Current_Donation_Product_Name' => $this->get_current_donation_product_name(),
			'Previous_Donation_Product'     => $this->get_previous_subscription_product(),
			'Previous_Donation_Amount'      => $this->get_previous_donation_amount(),
			'Last_Donation_Amount'          => $this->get_last_donation_amount(),
			'Last_Donation_Date'            => $this->get_last_donation_date(),
		];
	}

	/**
	 * Whether the given subscription is relevant to this metadata class.
	 *
	 * For Donation, only donation subscriptions are relevant.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @return bool
	 */
	protected function is_relevant_subscription( $subscription ) {
		return Donations::is_donation_order( $subscription );
	}

	/**
	 * Get the most recent one-time donation order for the current user.
	 *
	 * @return \WC_Order|null
	 */
	protected function get_one_time_donation_order() {
		if ( $this->one_time_donation_order_resolved ) {
			return $this->one_time_donation_order_cache;
		}

		$this->one_time_donation_order_resolved = true;

		if ( ! $this->user ) {
			return null;
		}

		$donation_product = Donations::get_donation_product( 'once' );
		if ( ! $donation_product ) {
			return null;
		}

		$user_has_donated = \wc_customer_bought_product( null, $this->user->ID, $donation_product );
		if ( ! $user_has_donated ) {
			return null;
		}

		$page = 1;
		do {
			$orders = \wc_get_orders(
				[
					'customer_id' => $this->user->ID,
					'status'      => [ 'wc-completed' ],
					'limit'       => 20,
					'order'       => 'DESC',
					'orderby'     => 'date',
					'return'      => 'objects',
					'page'        => $page++,
				]
			);

			foreach ( $orders as $order ) {
				if ( Donations::is_donation_order( $order ) ) {
					$this->one_time_donation_order_cache = $order;
					return $this->one_time_donation_order_cache;
				}
			}
		} while ( ! empty( $orders ) );

		return null;
	}

	/**
	 * Get the donor status label.
	 *
	 * Returns a summarized label: Monthly Donor, Yearly Donor, Ex-Monthly Donor, Ex-Yearly Donor, or Donor.
	 *
	 * @return string
	 */
	protected function get_donor_status() {
		$subscription = $this->get_current_subscription();

		if ( $subscription ) {
			$donor_status = 'Donor';
			$billing_period = $subscription->get_billing_period();

			if ( 'month' === $billing_period ) {
				$donor_status = 'Monthly ' . $donor_status;
			} elseif ( 'year' === $billing_period ) {
				$donor_status = 'Yearly ' . $donor_status;
			}

			if ( $subscription->has_status( WooCommerce_Connection::FORMER_SUBSCRIBER_STATUSES ) ) {
				$donor_status = 'Ex-' . $donor_status;
			}

			return $donor_status;
		}

		// Fallback: check for one-time donation.
		$one_time_order = $this->get_one_time_donation_order();
		if ( $one_time_order ) {
			return 'Donor';
		}

		return '';
	}

	/**
	 * Get the donation product name, with fallback to one-time donation order.
	 *
	 * @return string
	 */
	protected function get_current_donation_product_name() {
		$name = $this->get_current_subscription_product_name();
		if ( ! empty( $name ) ) {
			return $name;
		}

		$one_time_order = $this->get_one_time_donation_order();
		if ( $one_time_order ) {
			$items = $one_time_order->get_items();
			if ( ! empty( $items ) ) {
				return reset( $items )->get_name();
			}
		}

		return '';
	}

	/**
	 * Get the previous donation amount before a plan switch.
	 *
	 * @return string
	 */
	protected function get_previous_donation_amount() {
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
			$sub_switch_data = isset( $switch_data[ $subscription->get_id() ] ) ? $switch_data[ $subscription->get_id() ] : reset( $switch_data );
			if ( ! empty( $sub_switch_data['old_subscription_id'] ) ) {
				$old_subscription = \wcs_get_subscription( $sub_switch_data['old_subscription_id'] );
				if ( $old_subscription ) {
					return $old_subscription->get_total();
				}
			}
		}

		return '';
	}

	/**
	 * Get the last donation amount, with fallback to one-time donation order.
	 *
	 * @return string
	 */
	protected function get_last_donation_amount() {
		$amount = $this->get_last_payment_amount();
		if ( ! empty( $amount ) ) {
			return $amount;
		}

		$one_time_order = $this->get_one_time_donation_order();
		if ( $one_time_order ) {
			return $one_time_order->get_total();
		}

		return '';
	}

	/**
	 * Get the last donation date, with fallback to one-time donation order.
	 *
	 * @return string
	 */
	protected function get_last_donation_date() {
		$date = $this->get_last_payment_date();
		if ( ! empty( $date ) ) {
			return $date;
		}

		$one_time_order = $this->get_one_time_donation_order();
		if ( $one_time_order ) {
			$date_paid = $one_time_order->get_date_paid();
			if ( ! empty( $date_paid ) ) {
				return $date_paid->date( self::DATE_FORMAT );
			}
		}

		return '';
	}
}
