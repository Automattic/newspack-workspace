<?php
/**
 * Newspack Network reader access collector.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Gate;

use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Reports what a reader holds on this site, for another network site deciding
 * whether to let them in: the subscriptions they own, their seats on other
 * people's group subscriptions, and their paid one-time orders.
 *
 * This site's own data is the source of truth, which is why it is read here rather
 * than from the hub's copies: those carry no order products or dates, and no group
 * seats at all.
 */
class Reader_Access_Collector {

	/**
	 * Orders are read in pages of this many.
	 */
	const ORDER_PAGE_SIZE = 50;

	/**
	 * The order walk stops after this many pages, so a reader with a very long
	 * order history can't make every sync walk all of it.
	 */
	const MAX_ORDER_PAGES = 20;

	/**
	 * Everything this site holds for a reader.
	 *
	 * @param string $email Reader email.
	 * @return array {
	 *     @type array[] $subscriptions Subscription ID => record, in the shape subscription events record.
	 *     @type array[] $groups        Group seats, each with id, status and network_ids.
	 *     @type array[] $orders        Paid one-time orders, each with id, date_created and network_ids.
	 * }
	 */
	public static function collect( $email ) {
		$data = [
			'subscriptions' => [],
			'groups'        => [],
			'orders'        => [],
		];

		$email = sanitize_email( $email );
		if ( ! $email ) {
			return $data;
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$data['subscriptions'] = self::get_subscriptions( $user->ID );
			$data['groups']        = self::get_group_seats( $user->ID );
		}
		$data['orders'] = self::get_orders( $user ? $user->ID : 0, $email );

		return $data;
	}

	/**
	 * The subscriptions the user owns on this site, in any status.
	 *
	 * @param int $user_id User ID.
	 * @return array[] Subscription ID => record.
	 */
	private static function get_subscriptions( $user_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return [];
		}
		$records = [];
		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			$records[ $subscription->get_id() ] = self::format_subscription( $subscription );
		}
		return $records;
	}

	/**
	 * The user's seats on group subscriptions owned by someone else on this site.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	private static function get_group_seats( $user_id ) {
		if ( ! class_exists( '\Newspack\Group_Subscription' ) || ! method_exists( '\Newspack\Group_Subscription', 'get_group_subscriptions_for_user' ) ) {
			return [];
		}
		$seats = [];
		foreach ( \Newspack\Group_Subscription::get_group_subscriptions_for_user( $user_id ) as $subscription ) {
			if ( ! is_object( $subscription ) ) {
				continue;
			}
			$seat = self::format_group_seat( $subscription );
			if ( ! empty( $seat['network_ids'] ) ) {
				$seats[] = $seat;
			}
		}
		return $seats;
	}

	/**
	 * The customer's paid one-time orders that can grant access elsewhere: the newest
	 * order for each Network ID.
	 *
	 * The customer matches on user ID or billing email, so guest orders count, as they
	 * do in the local one-time purchase rule.
	 *
	 * @param int    $user_id User ID, or 0 when the reader has no account here.
	 * @param string $email   Reader email.
	 * @return array[] Newest first.
	 */
	private static function get_orders( $user_id, $email ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}
		$customer = array_values( array_filter( [ (int) $user_id, $email ] ) );
		if ( empty( $customer ) ) {
			// An empty customer constraint is dropped by WooCommerce, which would list every customer's orders.
			return [];
		}
		$query  = [
			'customer' => $customer,
			'status'   => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : [ 'processing', 'completed' ],
			'orderby'  => 'date ID',
			'order'    => 'DESC',
			'limit'    => self::ORDER_PAGE_SIZE,
			'type'     => 'shop_order',
		];
		$orders = [];
		for ( $page = 1; $page <= self::MAX_ORDER_PAGES; $page++ ) {
			$query['page'] = $page;
			$batch         = wc_get_orders( $query );
			foreach ( $batch as $order ) {
				$orders[] = self::format_order( $order );
			}
			if ( count( $batch ) < self::ORDER_PAGE_SIZE ) {
				break;
			}
		}
		return self::keep_newest_order_per_network_id( $orders );
	}

	/**
	 * A subscription, in the shape Incoming_Events\Subscription_Changed records it,
	 * with each product's Network ID added.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @return array
	 */
	public static function format_subscription( $subscription ) {
		$products = [];
		foreach ( self::get_products( $subscription ) as $product ) {
			$products[ $product->get_id() ] = [
				'id'         => $product->get_id(),
				'name'       => $product->get_name(),
				'slug'       => $product->get_slug(),
				'network_id' => (string) Product_Admin::get_network_id( $product->get_id() ),
			];
		}
		return [
			'id'       => $subscription->get_id(),
			'status'   => $subscription->get_status(),
			'products' => $products,
		];
	}

	/**
	 * A seat on a group subscription.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @return array
	 */
	public static function format_group_seat( $subscription ) {
		$network_ids = [];
		foreach ( self::get_products( $subscription ) as $product ) {
			$network_ids[] = (string) Product_Admin::get_network_id( $product->get_id() );
		}
		return [
			'id'          => $subscription->get_id(),
			'status'      => $subscription->get_status(),
			'network_ids' => array_values( array_unique( array_filter( $network_ids ) ) ),
		];
	}

	/**
	 * A paid order, with the Network IDs of its one-time products.
	 *
	 * Subscription products are left out even when they share a Network ID with a
	 * one-time product: their orders are renewals and sign-ups, which the subscription
	 * check already covers, and the local one-time rule never counts them either.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function format_order( $order ) {
		$network_ids = [];
		foreach ( self::get_products( $order ) as $product ) {
			if ( self::is_subscription_product( $product ) ) {
				continue;
			}
			$network_ids[] = (string) Product_Admin::get_network_id( $product->get_id() );
		}
		$date_created = $order->get_date_created();
		return [
			'id'           => $order->get_id(),
			'date_created' => $date_created ? $date_created->getTimestamp() : 0,
			'network_ids'  => array_values( array_unique( array_filter( $network_ids ) ) ),
		];
	}

	/**
	 * Keep only orders that are the newest for at least one of their Network IDs,
	 * and only those Network IDs. An older order for the same Network ID can never
	 * grant access the newer one doesn't.
	 *
	 * @param array[] $orders Formatted orders, newest first.
	 * @return array[]
	 */
	public static function keep_newest_order_per_network_id( $orders ) {
		$seen = [];
		$kept = [];
		foreach ( $orders as $order ) {
			$new_network_ids = array_values( array_diff( $order['network_ids'], $seen ) );
			if ( empty( $new_network_ids ) ) {
				continue;
			}
			$seen                 = array_merge( $seen, $new_network_ids );
			$order['network_ids'] = $new_network_ids;
			$kept[]               = $order;
		}
		return $kept;
	}

	/**
	 * The products on an order's or subscription's line items that still exist.
	 *
	 * @param \WC_Order $order Order or subscription.
	 * @return \WC_Product[]
	 */
	private static function get_products( $order ) {
		$products = [];
		foreach ( $order->get_items() as $item ) {
			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : false;
			if ( $product ) {
				$products[] = $product;
			}
		}
		return $products;
	}

	/**
	 * Whether a product is a subscription product, including a variation of one.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private static function is_subscription_product( $product ) {
		if ( class_exists( '\WC_Subscriptions_Product' ) ) {
			return \WC_Subscriptions_Product::is_subscription( $product );
		}
		return $product->is_type( [ 'subscription', 'variable-subscription', 'subscription_variation' ] );
	}
}
