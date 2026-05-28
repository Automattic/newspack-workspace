<?php
/**
 * Reader Activation Sync WooCommerce.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync;

use Newspack\Donations;
use Newspack\WooCommerce_Connection;
use Newspack\WooCommerce_Order_UTM;
use Newspack\Subscriptions_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Class.
 */
class WooCommerce {

	/**
	 * Should a WooCommerce order be synchronized?
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function should_sync_order( $order ) {
		// $order is not a valid WC_Order object, so don't try to sync.
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}
		// If the order lacks a customer.
		if ( ! $order->get_customer_id() ) {
			return [];
		}
		if ( $order->get_meta( '_subscription_switch' ) ) {
			// This is a "switch" order, which is just recording a subscription update. It has value of 0 and
			// should not be synced anywhere.
			return false;
		}
		return true;
	}

	/**
	 * Get the order containing what we consider to be the "Current Product" for a given user.
	 *
	 * All the payment fields that are synced relate to this product.
	 *
	 * The criteria for the "Current Product" are:
	 * 1. The most recent subscription (either regular subscriptions or recurring donations).
	 * 2. If no active subscriptions, the most recently cancelled or expired subscription.
	 * 3. If no subscriptions at all, the most recent one-time donation.
	 *
	 * @param \WC_Customer $customer Customer object.
	 *
	 * @return \WC_Order|false Order object or false.
	 */
	private static function get_current_product_order_for_sync( $customer ) {
		if ( ! class_exists( 'WC_Customer' ) || ! is_a( $customer, 'WC_Customer' ) ) {
			return false;
		}

		$user_id = $customer->get_id();

		// 1. The most recent subscription (either regular subscriptions or recurring donations).
		$active_subscriptions = WooCommerce_Connection::get_active_subscriptions_for_user( $user_id );
		if ( ! empty( $active_subscriptions ) ) {
			return \wcs_get_subscription( reset( $active_subscriptions ) );
		}

		// 2. If no active subscriptions, the most recently cancelled or expired subscription.
		$most_recent_cancelled_or_expired_subscription = self::get_most_recent_cancelled_or_expired_subscription( $user_id );
		if ( $most_recent_cancelled_or_expired_subscription ) {
			return \wcs_get_subscription( $most_recent_cancelled_or_expired_subscription );
		}

		// 3. If no subscriptions at all, the most recent one-time donation.
		$one_time_donation_order = self::get_one_time_donation_order_for_user( $user_id );
		if ( $one_time_donation_order ) {
			return $one_time_donation_order;
		}

		/**
		 * Filter the order containing what we consider to be the "Current Product" for a given user when nothing is found.
		 *
		 * This is used for tests to mock the return value.
		 *
		 * @param false $current_product_order The returned value.
		 * @return int $user_id The user ID.
		 */
		return apply_filters( 'newspack_reader_activation_get_current_product_order_for_sync', false, $user_id );
	}

	/**
	 * Get the most recent cancelled or expired subscription for a user.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return ?WCS_Subscription A Subscription object or null.
	 */
	private static function get_most_recent_cancelled_or_expired_subscription( $user_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return;
		}
		$subscriptions = array_reduce(
			array_keys( \wcs_get_users_subscriptions( $user_id ) ),
			function( $acc, $subscription_id ) {
				$subscription = \wcs_get_subscription( $subscription_id );
				if ( $subscription->has_status( WooCommerce_Connection::FORMER_SUBSCRIBER_STATUSES ) ) {

					// Only donation subscriptions that have at least one completed order are considered.
					$is_donation = Donations::is_donation_order( $subscription );
					$is_valid    = $is_donation ? false : true;
					if ( $is_donation ) {
						$related_orders = $subscription->get_related_orders();
						foreach ( $related_orders as $order_id ) {
							$order = \wc_get_order( $order_id );
							if ( $order->has_status( 'completed' ) ) {
								$is_valid = true;
								break;
							}
						}
					}

					/**
					 * Filter to determine if a subscription with inactive status can be considered a contact's current product.
					 * Allows for customizing the sync behavior to include or exclude certain types of subscriptions.
					 *
					 * @param bool            $is_valid If true, this subscription can be the contact's current product.
					 * @param WC_Subscription $subscription The subscription object.
					 */
					$is_valid = \apply_filters( 'newspack_reader_activation_inactive_subscription_is_valid', $is_valid, $subscription );
					if ( ! empty( $is_valid ) ) {
						$acc[] = $subscription_id;
					}
				}
				return $acc;
			},
			[]
		);

		if ( ! empty( $subscriptions ) ) {
			return reset( $subscriptions );
		}
	}

	/**
	 * Get the most recent one-time donation order for a user.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return ?WC_Order An Order object or null.
	 */
	private static function get_one_time_donation_order_for_user( $user_id ) {
		$donation_product = Donations::get_donation_product( 'once' );
		if ( ! $donation_product ) {
			return;
		}
		$user_has_donated = \wc_customer_bought_product( null, $user_id, $donation_product );
		if ( ! $user_has_donated ) {
			return;
		}

		// If user has donated, we'll loop through their orders to find the most recent donation.
		// If this method was called, that's because they don't have any active subscriptions, so there shouldn't be too many.
		$args = [
			'customer_id' => $user_id,
			'status'      => [ 'wc-completed' ],
			'limit'       => -1,
			'order'       => 'DESC',
			'orderby'     => 'date',
			'return'      => 'objects',
		];

		// Return the most recent completed order.
		$orders = \wc_get_orders( $args );
		foreach ( $orders as $order ) {
			if ( Donations::is_donation_order( $order ) ) {
				return $order;
			}
		}
	}

	/**
	 * Get the successful order associated with the given subscription.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 *
	 * @return \WC_Order? The order.
	 */
	private static function get_last_successful_order( $subscription ) {
		$last_order = $subscription->get_last_order(
			// The whole WC_Order object, not just the ID.
			'all',
			// Only parent and renewal orders.
			[
				'parent',
				'renewal',
			],
			// Only completed or processing orders, so exclude all other statuses.
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

		if ( $last_order ) {
			return $last_order;
		}
	}

	/**
	 * Get data about a customer's order to sync to the connected ESP.
	 *
	 * Note that all dates are in the site's timezone.
	 *
	 * @param \WC_Order|int $order WooCommerce order or order ID.
	 * @param bool|string   $payment_page_url Payment page URL. If not provided, checkout URL will be used.
	 *
	 * @return array Contact order metadata.
	 */
	private static function get_order_metadata( $order, $payment_page_url = false ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = \wc_get_order( $order );
		}

		if ( ! self::should_sync_order( $order ) ) {
			return [];
		}

		$metadata = [];

		if ( empty( $payment_page_url ) ) {
			$referer_from_order = $order->get_meta( '_newspack_referer' );
			if ( empty( $referer_from_order ) ) {
				$payment_page_url = \wc_get_checkout_url();
			} else {
				$payment_page_url = $referer_from_order;
			}
		}
		$metadata['payment_page'] = $payment_page_url;

		$utm = $order->get_meta( 'utm' );
		if ( empty( $utm ) ) {
			$utm = [];
			// Try the explicit `utm_<name>` meta.
			foreach ( WooCommerce_Order_UTM::$params as $param ) {
				$param_name = 'utm_' . $param;
				$utm_value = $order->get_meta( $param_name );
				if ( ! empty( $utm_value ) ) {
					$utm[ $param ] = $utm_value;
				}
			}
		}
		if ( ! empty( $utm ) ) {
			foreach ( $utm as $key => $value ) {
				$metadata[ 'payment_page_utm_' . $key ] = $value;
			}
		}

		$order_subscriptions = [];
		if ( function_exists( 'wcs_is_subscription' ) ) {
			$order_subscriptions = \wcs_is_subscription( $order ) ? [ $order ] : \wcs_get_subscriptions_for_order( $order->get_id(), [ 'order_type' => 'any' ] );
		}
		$is_donation_order = Donations::is_donation_order( $order );

		// One-time transaction.
		if ( empty( $order_subscriptions ) ) {

			/**
			 * For donation-type products, use donation membership status as defined by BlueLena.
			 * For non-donation-type products, we just need to know that the reader is a customer.
			 */
			if ( $is_donation_order ) {
				$metadata['membership_status'] = 'Donor';
			} else {
				$metadata['membership_status'] = 'customer';
			}

			$metadata['product_name'] = '';
			$order_items = $order->get_items();
			if ( $order_items ) {
				$metadata['product_name'] = reset( $order_items )->get_name();
			}
			$order_date_paid = $order->get_date_paid();
			if ( ! empty( $order_date_paid ) ) {
				$metadata['last_payment_amount'] = $order->get_total();
				$metadata['last_payment_date']   = $order_date_paid->date( Metadata::DATE_FORMAT );
			}

			// Subscription transaction.
		} else {
			$current_subscription = reset( $order_subscriptions );

			/**
			 * For donation-type products, use donation membership status as defined by BlueLena.
			 * For non-donation-type products, use the subscription's current status.
			 */
			if ( $is_donation_order ) {
				$donor_status = 'Donor';
				if ( 'month' === $current_subscription->get_billing_period() ) {
					$donor_status = 'Monthly ' . $donor_status;
				}
				if ( 'year' === $current_subscription->get_billing_period() ) {
					$donor_status = 'Yearly ' . $donor_status;
				}

				// If the subscription has moved to a cancelled or expired status.
				if ( $current_subscription->has_status( [ 'cancelled', 'expired', 'on-hold' ] ) ) {
					$donor_status = 'Ex-' . $donor_status;
				}
				$metadata['membership_status'] = $donor_status;
			} else {
				$metadata['membership_status'] = $current_subscription->get_status();
			}

			$sub_start_date    = $current_subscription->get_date( 'start', 'site' );
			$sub_end_date      = $current_subscription->get_date( 'end', 'site' );

			$metadata['last_payment_amount'] = 0;
			$metadata['last_payment_date']   = '';
			$last_successful_order = self::get_last_successful_order( $current_subscription );
			if ( $last_successful_order ) {
				$last_order_date_paid = $last_successful_order->get_date_paid();
				if ( ! empty( $last_order_date_paid ) ) {
					$metadata['last_payment_amount'] = $last_successful_order->get_total();
					$metadata['last_payment_date']   = $last_order_date_paid->date( Metadata::DATE_FORMAT );
				}
			}

			$metadata['sub_start_date']      = empty( $sub_start_date ) ? '' : $sub_start_date;
			$metadata['sub_end_date']        = empty( $sub_end_date ) ? '' : $sub_end_date;
			$metadata['billing_cycle']       = $current_subscription->get_billing_period();
			$metadata['recurring_payment']   = $current_subscription->get_total();

			// When a WC Subscription is terminated, the next payment date is set to 0. We don't want to sync that – the next payment date should remain as it was
			// in the event of cancellation.
			$next_payment_date = $current_subscription->get_date( 'next_payment', 'site' );
			if ( $next_payment_date ) {
				$metadata['next_payment_date'] = $next_payment_date;
			}

			$metadata['product_name'] = '';
			if ( $current_subscription ) {
				$subscription_order_items = $current_subscription->get_items();
				if ( $subscription_order_items ) {
					$metadata['product_name'] = reset( $subscription_order_items )->get_name();
				}
			}

			// Record the cancellation reason if meta exists and is not a pending cancellation.
			$cancellation_reason = $current_subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY );
			if ( ! empty( $cancellation_reason ) && ! in_array( $cancellation_reason, [ Subscriptions_Meta::CANCELLATION_REASON_USER_PENDING_CANCEL, Subscriptions_Meta::CANCELLATION_REASON_ADMIN_PENDING_CANCEL ], true ) ) {
				$metadata['cancellation_reason'] = $cancellation_reason;
			}
		}

		// Clear out any payment-related fields that don't relate to the current order.
		$payment_fields = array_keys( Metadata::get_payment_fields() );
		foreach ( WooCommerce_Order_UTM::$params as $param ) {
			if ( ! isset( $metadata[ 'payment_page_utm_' . $param ] ) ) {
				$metadata[ 'payment_page_utm_' . $param ] = '';
			}
		}
		foreach ( $payment_fields as $meta_key ) {
			if ( ! isset( $metadata[ $meta_key ] ) && 'payment_page_utm' !== $meta_key ) {
				$metadata[ $meta_key ] = '';
			}
		}
		return $metadata;
	}

	/**
	 * Get the contact data from a WooCommerce customer.
	 *
	 * @param \WC_Customer $customer Customer object.
	 * @param bool|string  $payment_page_url Payment page URL. If not provided, checkout URL will be used.
	 *
	 * @return array|false Contact data or false.
	 */
	public static function get_contact_from_customer( $customer, $payment_page_url = false ) {
		if ( ! class_exists( 'WC_Customer' ) || ! is_a( $customer, 'WC_Customer' ) ) {
			$customer = new \WC_Customer( $customer );
		}

		$metadata = [];

		$customer_id                   = $customer->get_id();
		$created_date                  = $customer->get_date_created();
		$metadata['account']           = $customer_id;
		$metadata['registration_date'] = $created_date ? get_date_from_gmt( $created_date->date( Metadata::DATE_FORMAT ) ) : '';
		$metadata['total_paid']        = $customer->get_total_spent();

		$order = self::get_current_product_order_for_sync( $customer );

		// Get the order metadata.
		$order_metadata = [];
		if ( $order ) {
			$order_metadata = self::get_order_metadata( $order, $payment_page_url );
		} else {
			// If the customer has no successful orders, clear out subscription-related fields.
			$payment_fields = array_keys( Metadata::get_payment_fields() );
			foreach ( $payment_fields as $meta_key ) {
				$metadata[ $meta_key ] = '';
			}
		}

		$metadata = array_merge( $order_metadata, $metadata );

		$first_name = $customer->get_billing_first_name();
		$last_name  = $customer->get_billing_last_name();
		$full_name  = trim( "$first_name $last_name" );

		// Correct for empty First and Last Name fields.
		if ( ! empty( trim( $first_name ) ) && empty( \get_user_meta( $customer_id, 'first_name', true ) ) ) {
			\update_user_meta( $customer_id, 'first_name', $first_name );
		}
		if ( ! empty( trim( $last_name ) ) && empty( \get_user_meta( $customer_id, 'last_name', true ) ) ) {
			\update_user_meta( $customer_id, 'last_name', $last_name );
		}

		$contact = [
			'email'    => $customer->get_email(),
			'metadata' => $metadata,
		];
		if ( ! empty( $full_name ) ) {
			$contact['name'] = $full_name;
		}
		return $contact;
	}

	/**
	 * Get the contact data from a WooCommerce order.
	 *
	 * @param \WC_Order|int $order WooCommerce order or order ID.
	 * @param bool|string   $payment_page_url Payment page URL. If not provided, checkout URL will be used.
	 *
	 * @return array|false Contact data or false.
	 */
	public static function get_contact_from_order( $order, $payment_page_url = false ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = \wc_get_order( $order );
		}

		if ( ! self::should_sync_order( $order ) ) {
			return;
		}

		return self::get_contact_from_customer( $order->get_customer_id(), $payment_page_url );
	}
}
