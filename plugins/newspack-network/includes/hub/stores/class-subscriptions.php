<?php
/**
 * Newspack Hub Woocommerce Subscriptions Store
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Debugger;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;

/**
 * Class to handle Woocommerce Subscriptions Store
 */
class Subscriptions extends Woo_Store {

	/**
	 * Gets the post type slug
	 *
	 * @return string
	 */
	protected static function get_post_type_slug() {
		return Subscriptions_DB::POST_TYPE_SLUG;
	}

	/**
	 * Gets the api endpoint prefix
	 *
	 * @return string
	 */
	protected static function get_api_endpoint_prefix() {
		return 'subscriptions';
	}

	/**
	 * Gets the name of the items class
	 *
	 * @return string
	 */
	protected static function get_item_class() {
		return 'Subscription_Item';
	}

	/**
	 * Gets the post status prefix
	 *
	 * @return string
	 */
	protected static function get_post_status_prefix() {
		return Subscriptions_DB::POST_STATUS_PREFIX;
	}

	/**
	 * Persists a Subscription_Changed event by creating or updating a Subscription post.
	 *
	 * @param Subscription_Changed $subscription The Subscription_Changed event.
	 * @return int The local post ID.
	 */
	public static function persist( Subscription_Changed $subscription ) {
		$subscription_id = $subscription->get_id();

		if ( ! $subscription_id ) {
			return;
		}

		Debugger::log( 'Persisting subscription ' . $subscription_id );

		$local_id = self::get_local_id( $subscription );

		Debugger::log( 'Local ID: ' . $local_id );

		// Data from the event.
		update_post_meta( $local_id, 'payment_count', $subscription->get_payment_count() );
		update_post_meta( $local_id, 'formatted_total', $subscription->get_formatted_total() );
		update_post_meta( $local_id, 'currency', $subscription->get_currency() );
		update_post_meta( $local_id, 'total', $subscription->get_total() );
		update_post_meta( $local_id, 'payment_method_title', $subscription->get_payment_method_title() );
		update_post_meta( $local_id, 'start_date', $subscription->get_start_date() );
		update_post_meta( $local_id, 'trial_end_date', $subscription->get_trial_end_date() );
		update_post_meta( $local_id, 'next_payment_date', $subscription->get_next_payment_date() );
		update_post_meta( $local_id, 'last_payment_date', $subscription->get_last_payment_date() );
		update_post_meta( $local_id, 'end_date', $subscription->get_end_date() );

		delete_post_meta( $local_id, 'products' );
		foreach ( $subscription->get_products() as $product ) {
			add_post_meta( $local_id, 'products', $product );
		}

		Debugger::log( 'Updating post status to ' . $subscription->get_status_after() );
		$update_array = [
			'ID'          => $local_id,
			'post_status' => self::get_post_status_for_db( $subscription->get_status_after() ),
		];
		$update       = wp_update_post( $update_array );
		Debugger::log( 'Updated post status: ' . $update );

		return $local_id;
	}
}
