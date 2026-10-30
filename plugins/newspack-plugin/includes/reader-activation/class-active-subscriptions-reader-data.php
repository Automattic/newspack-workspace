<?php
/**
 * The `active_subscriptions` reader data item.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a list of the products each reader holds a subscription for, which
 * Campaigns criteria and the GA4 `is_subscriber` flag read.
 *
 * Holding a product means what it means to a content gate: a subscription the
 * reader owns, a gift they received (its buyer does not hold it), or a seat in
 * an enabled group subscription. The list uses the same subscription lookup as
 * the gates, with payment-recovery grace off and Network sibling-site access
 * left out.
 *
 * Those two differences keep the item meaning what it always has. A subscription
 * on hold while its payment is retried does not count: only an active or
 * pending-cancel one does. And a subscription held on a sibling site of a
 * Newspack Network does not count, because the list is built from this site's
 * subscriptions alone.
 *
 * Each reader's list carries a stamp: when it was computed. A page view
 * recomputes a list that is missing or more than a day old, which is what
 * catches a change that fires no event.
 */
final class Active_Subscriptions_Reader_Data {
	const STORE_KEY = 'active_subscriptions';

	/**
	 * User meta holding the time the reader's stored list was computed.
	 */
	const STAMP_META_KEY = 'newspack_active_subscriptions_stamp';

	/**
	 * Initialize hooks.
	 */
	public static function init() {}

	/**
	 * The IDs of the non-donation products a reader holds a subscription for.
	 *
	 * Payment-recovery grace is off, on purpose. Retries running out deletes the
	 * retry date without a status change (see
	 * WooCommerce_Connection::is_subscription_in_payment_recovery()), so counting
	 * the window would leave a reader listed with no event to take them off the
	 * list. Without grace, a change of status is the only thing that moves a
	 * subscription in or out.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return int[]|null Unique product IDs in ascending order, or null when
	 *                    WooCommerce Subscriptions is not active. Null means
	 *                    "do not write", which is not the same as "holds nothing".
	 */
	public static function get_product_ids( int $user_id ): ?array {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}
		$subscription_ids = Access_Rules::with_evaluation_context(
			[ 'payment_recovery_grace' => false ],
			fn() => Access_Rules::get_active_subscription_ids( $user_id, [] )
		);
		$product_ids      = [];
		foreach ( $subscription_ids as $subscription_id ) {
			// get_products_for_order() calls get_items() on whatever wc_get_order()
			// returns, so an ID that no longer loads would be a fatal error.
			if ( ! \wcs_get_subscription( $subscription_id ) ) {
				continue;
			}
			$product_ids = array_merge( $product_ids, WooCommerce_Connection::get_products_for_order( $subscription_id ) );
		}
		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		sort( $product_ids );
		return $product_ids;
	}

	/**
	 * Recompute and store the reader's item.
	 *
	 * A reader who holds nothing gets an empty list only if they already have an
	 * item, which clears a former subscriber. One who never had an item stays
	 * without one: a missing item and an empty one match the same segments and
	 * report the same to GA4, so writing it would only add a row per reader. An
	 * unchanged list is not rewritten, which keeps `newspack_reader_data_updated`
	 * listeners quiet.
	 *
	 * The stamp is written once the item is stored, or found already right, so a
	 * refused write is retried on the next page view instead of waiting out the
	 * age limit.
	 *
	 * @param int $user_id User ID.
	 */
	public static function refresh( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$product_ids = self::get_product_ids( $user_id );
		if ( null === $product_ids ) {
			return;
		}
		$value      = wp_json_encode( $product_ids );
		$stored     = Reader_Data::get_data( $user_id, self::STORE_KEY );
		$is_current = false === $stored ? empty( $product_ids ) : $stored === $value;
		if ( ! $is_current && \is_wp_error( Reader_Data::update_item( $user_id, self::STORE_KEY, $value ) ) ) {
			return;
		}
		update_user_meta( $user_id, self::STAMP_META_KEY, time() );
	}

	/**
	 * Data event handler for `product_subscription_changed`: bring up to date the
	 * list of every reader the changed subscription can affect.
	 *
	 * Each list is recomputed from the subscriptions as they stand, never patched
	 * with the event's products and status. A patch would take a product off a
	 * reader while a second subscription still holds it, miss that a plan switch
	 * swapped the product out, and list a gift under its buyer, who is the
	 * subscription's customer but not the reader holding it.
	 *
	 * The customer and a gift's recipient are recomputed here. Group members are
	 * only marked stale: a group can have hundreds, and each recomputes on their
	 * next page view instead of inside the request that changed the subscription.
	 *
	 * Only `user_id` and `subscription_id` are read; the rest of the event
	 * describes the one change and is ignored.
	 *
	 * @param array $data Event data.
	 */
	public static function handle_product_subscription_changed( array $data ): void {
		if ( empty( $data['user_id'] ) || empty( $data['subscription_id'] ) ) {
			return;
		}
		$user_id = (int) $data['user_id'];
		self::refresh( $user_id );

		$readers = WooCommerce_Subscriptions::get_subscription_readers( $data['subscription_id'] );
		if ( $readers['recipient'] !== $user_id ) {
			self::refresh( $readers['recipient'] );
		}
		foreach ( $readers['members'] as $member_id ) {
			self::mark_stale( $member_id );
		}
	}

	/**
	 * Mark one reader's list stale. It is recomputed on their next page view.
	 *
	 * @param int $user_id User ID.
	 */
	public static function mark_stale( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::STAMP_META_KEY );
		}
	}

	/**
	 * Whether a reader's stored list needs recomputing: it was never computed, or
	 * was computed more than a day ago.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	public static function is_stale( int $user_id ): bool {
		$computed = (int) get_user_meta( $user_id, self::STAMP_META_KEY, true );
		return $computed < time() - DAY_IN_SECONDS;
	}
}
Active_Subscriptions_Reader_Data::init();
