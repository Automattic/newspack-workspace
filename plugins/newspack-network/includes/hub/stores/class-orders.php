<?php
/**
 * Newspack Hub Woocommerce Orders Store
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Debugger;
use Newspack_Network\Incoming_Events\Order_Changed;
use Newspack_Network\Hub\Database\Orders as Orders_DB;

/**
 * Class to handle Woocommerce Orders Store
 */
class Orders extends Woo_Store {

	/**
	 * Gets the post type slug
	 *
	 * @return string
	 */
	protected static function get_post_type_slug() {
		return Orders_DB::POST_TYPE_SLUG;
	}

	/**
	 * Gets the api endpoint prefix
	 *
	 * @return string
	 */
	protected static function get_api_endpoint_prefix() {
		return 'orders';
	}

	/**
	 * Gets the name of the items class
	 *
	 * @return string
	 */
	protected static function get_item_class() {
		return 'Order_Item';
	}

	/**
	 * Gets the post status prefix
	 *
	 * @return string
	 */
	protected static function get_post_status_prefix() {
		return Orders_DB::POST_STATUS_PREFIX;
	}

	/**
	 * Persists a Order_Changed event by creating or updating a Subscription post.
	 *
	 * @param Order_Changed $order The Order_Changed event.
	 * @return int The local post ID.
	 */
	public static function persist( Order_Changed $order ) {
		$order_id = $order->get_id();

		if ( ! $order_id ) {
			return;
		}

		Debugger::log( 'Persisting order ' . $order_id );

		$local_id = self::get_local_id( $order );

		Debugger::log( 'Local ID: ' . $local_id );

		// Data from the event.
		update_post_meta( $local_id, 'payment_count', $order->get_payment_count() );
		update_post_meta( $local_id, 'formatted_total', $order->get_formatted_total() );
		update_post_meta( $local_id, 'subscription_relationship', $order->get_subscription_relationship() );
		update_post_meta( $local_id, 'currency', $order->get_currency() );
		update_post_meta( $local_id, 'total', $order->get_total() );
		update_post_meta( $local_id, 'payment_method_title', $order->get_payment_method_title() );

		Debugger::log( 'Updating post status to ' . $order->get_status_after() );
		$update_array = [
			'ID'          => $local_id,
			'post_status' => self::get_post_status_for_db( $order->get_status_after() ),
		];
		$update       = wp_update_post( $update_array );
		Debugger::log( 'Updated post status: ' . $update );

		return $local_id;
	}
}
