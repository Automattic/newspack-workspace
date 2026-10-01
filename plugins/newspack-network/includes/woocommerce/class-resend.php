<?php
/**
 * Newspack Network: re-send a reader's grants.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce;

use Newspack_Network\Woocommerce_Subscriptions\Group_Seats;

/**
 * Re-sends what this site holds for a reader once a sibling site has an account
 * for them.
 *
 * A site drops a subscription, seat, or purchase event when it has no account for
 * the email, and nothing retries. When the account appears, the sibling sends
 * `reader_registered`; this site answers by sending its own events again.
 */
class Resend {

	/**
	 * Paid orders are read in pages of this many.
	 */
	const ORDERS_PAGE_SIZE = 50;

	/**
	 * The order walk stops after this many pages, as the local one-time rule's does.
	 */
	const ORDERS_MAX_PAGES = 50;

	/**
	 * Re-send everything this site holds for an email.
	 *
	 * @param string $email Reader email.
	 * @return void
	 */
	public static function for_email( $email ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return;
		}
		/**
		 * Filters the events re-sent for a reader, as [ action, data ] pairs.
		 *
		 * @param array  $pairs   Pairs.
		 * @param int    $user_id User ID.
		 * @param string $email   Email.
		 */
		$pairs = apply_filters( 'newspack_network_resend_pairs', self::collect( $user->ID, $email ), $user->ID, $email );
		foreach ( $pairs as list( $action, $data ) ) {
			/**
			 * Fires for each re-sent event. Data Events dispatches it when loaded.
			 *
			 * @param string $action Action name.
			 * @param array  $data   Event data.
			 */
			do_action( 'newspack_network_resend', $action, $data );
			if ( class_exists( 'Newspack\Data_Events' ) ) {
				\Newspack\Data_Events::dispatch( $action, $data, false );
			}
		}
	}

	/**
	 * What this site holds for a reader: owned subscriptions, seats, and tagged one-time orders.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Email.
	 * @return array [ action, data ] pairs.
	 */
	public static function collect( $user_id, $email ) {
		$pairs = [];
		if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
			foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
				$status  = $subscription->get_status();
				$pairs[] = [ 'newspack_node_subscription_changed', Events::subscription_changed( $subscription->get_id(), $status, $status, $subscription ) ];
			}
		}
		foreach ( array_map( 'absint', get_user_meta( $user_id, Group_Seats::MEMBER_META_KEY, false ) ) as $subscription_id ) {
			$data = Group_Seats::get_event_data( $user_id, $subscription_id );
			if ( $data ) {
				$pairs[] = [ Group_Seats::ACTION, $data ];
			}
		}
		if ( function_exists( 'wc_get_orders' ) ) {
			$query = [
				'customer' => [ $user_id, $email ],
				'status'   => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : [ 'processing', 'completed' ],
				'type'     => 'shop_order',
				'orderby'  => 'date ID',
				'order'    => 'DESC',
				'limit'    => self::ORDERS_PAGE_SIZE,
			];
			// Paged, so a long order history (renewals count) can't push an old pass past a cap.
			for ( $page = 1; $page <= self::ORDERS_MAX_PAGES; $page++ ) {
				$query['page'] = $page;
				$orders        = wc_get_orders( $query );
				foreach ( $orders as $order ) {
					$data = Events::one_time_purchase_changed( $order->get_id(), '', $order->get_status(), $order );
					if ( $data ) {
						$pairs[] = [ 'newspack_node_one_time_purchase_changed', $data ];
					}
				}
				if ( count( $orders ) < self::ORDERS_PAGE_SIZE ) {
					break;
				}
			}
		}
		return $pairs;
	}
}
