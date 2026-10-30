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
 * Campaigns criteria, the GA4 `is_subscriber` flag and the GAM
 * `reader_status=subscriber` targeting in newspack-ads read.
 *
 * Holding a product means what it means to a content gate: a subscription the
 * reader owns, a gift they received (its buyer does not hold it), or a seat in
 * an enabled group subscription. The list uses the same subscription lookup as
 * the gates, with payment-recovery grace off and Network sibling-site access
 * left out.
 *
 * Grace is off so that only a change of status moves a subscription in or out
 * of the list: one on hold while its payment is retried does not count, and
 * only an active or pending-cancel one does. Network access is left out because
 * it comes from a filter result that carries no subscription ID, so the lookup
 * cannot list it.
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
	public static function init() {
		add_filter( 'newspack_reader_data_max_items', [ __CLASS__, 'exempt_from_item_cap' ], 10, 3 );

		// Joining or leaving a group fires no Data Event naming the member.
		add_action( 'added_user_meta', [ __CLASS__, 'handle_group_membership_change' ], 10, 3 );
		add_action( 'deleted_user_meta', [ __CLASS__, 'handle_group_membership_change' ], 10, 3 );

		// Nor does handing a gift to someone else, or turning a group on or off on
		// its subscription. Turning group subscriptions on or off for a whole
		// product fires no hook either, so members of groups that inherit the
		// product's setting are put right at their next login or within a day.
		add_action( 'woocommerce_subscriptions_gifting_recipient_changed', [ __CLASS__, 'handle_gift_recipient_changed' ], 10, 3 );
		add_action( 'newspack_group_subscription_settings_updated', [ __CLASS__, 'handle_group_settings_updated' ], 10, 2 );

		// Runs before Reader_Data hands the stored items to the browser (priority 10
		// on both paths).
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_backfill_current_reader' ], 9 );
		add_filter( 'newspack_session_hydration_response', [ __CLASS__, 'maybe_backfill_hydrated_reader' ], 9, 2 );
	}

	/**
	 * Keep the item storable for a reader at the reader data key cap. A missing
	 * item reads as "holds no subscription", so a refused write would show a
	 * subscriber the offers meant for non-subscribers. It would also leave the
	 * stamp unset, which has every page view recompute their list. The cap bounds
	 * keys the browser adds; this is one server-owned key.
	 *
	 * @param int    $max_items Maximum number of items.
	 * @param int    $user_id   User ID.
	 * @param string $key       Key being written.
	 *
	 * @return int
	 */
	public static function exempt_from_item_cap( $max_items, $user_id, $key ) {
		return self::STORE_KEY === $key ? PHP_INT_MAX : $max_items;
	}

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
			// returns, so an ID that no longer loads would be a fatal error. This is
			// defensive against a subscription deleted between the lookup and this call.
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
	 * Mark a reader stale when they join or leave a group subscription. Bulk adds
	 * in the admin fire this once per member, so it stays a meta delete.
	 *
	 * @param int|int[] $meta_ids Meta ID(s).
	 * @param int       $user_id  User whose meta changed.
	 * @param string    $meta_key Meta key.
	 */
	public static function handle_group_membership_change( $meta_ids, $user_id, $meta_key ): void {
		if ( Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY === $meta_key ) {
			self::mark_stale( (int) $user_id );
		}
	}

	/**
	 * Mark stale the readers whose list changes when a gift's recipient does: the
	 * new recipient gains the product, and whoever held it before loses it. That
	 * is the earlier recipient, or the buyer when the subscription had none.
	 *
	 * Clearing a recipient (`WCS_Gifting::delete_recipient_user()`) fires no hook.
	 * The recipient who lost the gift, and whoever holds it afterwards, are put
	 * right by the daily stale window instead. A gift bought at checkout keeps the
	 * recipient's email, so nobody holds it then.
	 *
	 * @param \WC_Subscription $subscription          The gifted subscription.
	 * @param int              $recipient_id          The new recipient.
	 * @param int              $previous_recipient_id The earlier recipient, 0 for none.
	 */
	public static function handle_gift_recipient_changed( $subscription, $recipient_id, $previous_recipient_id ): void {
		self::mark_stale( (int) $recipient_id );
		$previous_holder_id = (int) $previous_recipient_id;
		if ( $previous_holder_id <= 0 ) {
			$previous_holder_id = WooCommerce_Subscriptions::get_subscription_readers( $subscription )['purchaser'];
		}
		self::mark_stale( $previous_holder_id );
	}

	/**
	 * Mark a group's members stale when the group is turned on or off. Members
	 * hold its products only while it is on, and the change names none of them.
	 * The owner holds their own subscription either way.
	 *
	 * This covers a setting changed on the subscription. Turning group
	 * subscriptions on or off for a product fires no hook, so members of groups
	 * that inherit the product's setting are put right at their next login or
	 * within a day.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param string[]         $changed_keys The settings that changed.
	 */
	public static function handle_group_settings_updated( $subscription, $changed_keys ): void {
		if ( ! in_array( 'enabled', (array) $changed_keys, true ) ) {
			return;
		}
		// get_members() is filterable, so treat its result as untrusted input.
		foreach ( (array) Group_Subscription::get_members( $subscription ) as $member_id ) {
			$member_id = (int) $member_id;
			if ( $member_id > 0 ) {
				self::mark_stale( $member_id );
			}
		}
	}

	/**
	 * Recompute a missing or stale list.
	 *
	 * A switched session belongs to an admin browsing as the reader, which
	 * Reader_Data never lets write the reader's data.
	 *
	 * @param int $user_id User ID.
	 */
	private static function maybe_backfill( int $user_id ): void {
		if ( $user_id > 0 && ! Reader_Data::is_switched_session() && self::is_stale( $user_id ) ) {
			self::refresh( $user_id );
		}
	}

	/**
	 * Backfill the item for the logged-in reader on a page render.
	 */
	public static function maybe_backfill_current_reader(): void {
		self::maybe_backfill( get_current_user_id() );
	}

	/**
	 * Backfill the item before the session hydration response reads it, the path
	 * a reader on a cached page takes. The hydration endpoint carries no CSRF
	 * nonce; this takes nothing from the request and recomputes from server state
	 * alone, so a forged request can only make the item correct.
	 *
	 * @param array $data    Hydration response data.
	 * @param int   $user_id The authenticated user's ID.
	 *
	 * @return array Unchanged response data.
	 */
	public static function maybe_backfill_hydrated_reader( $data, $user_id ) {
		self::maybe_backfill( (int) $user_id );
		return $data;
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
