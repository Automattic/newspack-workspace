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
use Newspack_Network\Woocommerce_Subscriptions\Group_Members;

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
		update_post_meta( $local_id, 'user_email', $subscription->get_email() );
		self::update_reader_key( $local_id, $subscription->get_email() );
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
			add_post_meta( $local_id, 'products', (array) $product );
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

	/**
	 * Prefix of the meta key naming a member on a group subscription's copy, one key
	 * per email, so a reader's seats are found through an indexed lookup.
	 */
	const MEMBER_KEY_PREFIX = 'np_member_';

	/**
	 * The lookup meta key for a group member's email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function get_member_key( $email ) {
		return self::MEMBER_KEY_PREFIX . md5( strtolower( trim( (string) $email ) ) );
	}

	/**
	 * Set the member emails on the hub's copy of a group subscription.
	 *
	 * Only members who joined or left are written, so a large group that fills one
	 * seat at a time doesn't rewrite every row on each join.
	 *
	 * @param int      $local_id The hub's copy of the subscription.
	 * @param string[] $emails   Member emails; empty when the group is off.
	 * @return void
	 */
	public static function update_group_members( $local_id, $emails ) {
		$current = array_map( 'strtolower', (array) get_post_meta( $local_id, Group_Members::HUB_MEMBER_META_KEY, false ) );
		$emails  = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'sanitize_email', (array) $emails ) ) ) ) );
		foreach ( array_diff( $current, $emails ) as $email ) {
			delete_post_meta( $local_id, Group_Members::HUB_MEMBER_META_KEY, $email );
			delete_post_meta( $local_id, self::get_member_key( $email ) );
		}
		foreach ( array_diff( $emails, $current ) as $email ) {
			add_post_meta( $local_id, Group_Members::HUB_MEMBER_META_KEY, $email );
			add_post_meta( $local_id, self::get_member_key( $email ), 1 );
		}
	}
}
