<?php
/**
 * Tests for computing and storing the `active_subscriptions` reader data item.
 *
 * @package Newspack\Tests
 */

use Newspack\Active_Subscriptions_Reader_Data;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Reader_Activation;
use Newspack\Reader_Data;
use Newspack\WooCommerce_Products;

/**
 * The item lists the products a reader holds a subscription for: the ones they
 * own, a gift they received, and a group they belong to.
 *
 * @group reader-data-active-subscriptions
 */
class Newspack_Test_Reader_Data_Active_Subscriptions extends WP_UnitTestCase {

	/**
	 * Load the mocks the subscription fixtures lean on.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__ ) . '/mocks/wcs-gifting-mock.php';
	}

	/**
	 * Reset the mock subscription store and the gifting stub's recipient map.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database   = [];
		\WCS_Gifting::$recipients = [];
		Group_Subscription::reset_cache();
	}

	/**
	 * The gifting stub's recipient map is static and shared across test classes.
	 */
	public function tear_down() {
		\WCS_Gifting::$recipients = [];
		Group_Subscription::reset_cache();
		parent::tear_down();
	}

	/**
	 * Create a verified reader.
	 *
	 * @return int User ID.
	 */
	private function create_reader(): int {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		Reader_Activation::set_reader_verified( $user_id );
		return $user_id;
	}

	/**
	 * Create a subscription with one line item, because the item's product IDs
	 * are read from the subscription's line items.
	 *
	 * @param int    $customer_id Owner.
	 * @param int    $product_id  Product on the subscription.
	 * @param string $status      Subscription status.
	 * @param array  $extra       Further mock subscription data.
	 *
	 * @return \WC_Subscription
	 */
	private function create_subscription( int $customer_id, int $product_id = 101, string $status = 'active', array $extra = [] ) {
		return \wcs_create_subscription(
			array_merge(
				[
					'customer_id' => $customer_id,
					'status'      => $status,
					'products'    => [ $product_id ],
					'items'       => [ new \WC_Order_Item_Product( [ 'product_id' => $product_id ] ) ],
				],
				$extra
			)
		);
	}

	/**
	 * Create a gifted subscription, listed under its recipient the way WooCommerce
	 * Subscriptions Gifting lists it.
	 *
	 * @param int $purchaser_id Buyer.
	 * @param int $recipient_id Recipient.
	 * @param int $product_id   Product on the subscription.
	 *
	 * @return \WC_Subscription
	 */
	private function create_gifted_subscription( int $purchaser_id, int $recipient_id, int $product_id = 101 ) {
		$subscription = $this->create_subscription( $purchaser_id, $product_id );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;
		add_filter(
			'wcs_get_users_subscriptions',
			function ( $subscriptions, $user_id ) use ( $subscription, $recipient_id ) {
				if ( (int) $user_id === $recipient_id ) {
					$subscriptions[ $subscription->get_id() ] = $subscription;
				}
				return $subscriptions;
			},
			10,
			2
		);
		return $subscription;
	}

	/**
	 * Create a group subscription and seat a member in it.
	 *
	 * @param int $owner_id  Owner.
	 * @param int $member_id Member.
	 *
	 * @return \WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, int $member_id ) {
		$subscription = $this->create_subscription( $owner_id );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Group_Subscription::reset_cache();
		return $subscription;
	}

	/**
	 * Build a payload shaped like the `product_subscription_changed` event, with the
	 * keys the listeners in includes/data-events/listeners.php give it. A mock
	 * subscription fires no hooks on `update_status()`, so a test hands this to the
	 * handler itself.
	 *
	 * @param \WC_Subscription $subscription  Subscription the event is about.
	 * @param int[]            $product_ids   Non-donation products on it, as the listener reports them.
	 * @param string           $status_before Status before the change.
	 * @param string           $status_after  Status after the change. A plan switch reports its current status for both.
	 *
	 * @return array
	 */
	private function product_subscription_changed( $subscription, array $product_ids, string $status_before, string $status_after ): array {
		return [
			'user_id'         => $subscription->get_customer_id(),
			'email'           => $subscription->get_billing_email(),
			'subscription_id' => $subscription->get_id(),
			'product_ids'     => $product_ids,
			'amount'          => 10.0,
			'currency'        => 'USD',
			'recurrence'      => 'month',
			'status_before'   => $status_before,
			'status_after'    => $status_after,
		];
	}

	/**
	 * The stored item, decoded.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return int[]|false Product IDs, or false when the item was never written.
	 */
	private function stored_products( int $user_id ) {
		$value = Reader_Data::get_data( $user_id, Active_Subscriptions_Reader_Data::STORE_KEY );
		return false === $value ? false : json_decode( $value, true );
	}

	/**
	 * Count the `newspack_reader_data_updated` events for the item while a callback
	 * runs.
	 *
	 * @param callable $callback Callback to run.
	 *
	 * @return int Number of events fired for the `active_subscriptions` key.
	 */
	private function count_item_updates( callable $callback ): int {
		$count    = 0;
		$listener = function ( $user_id, $key ) use ( &$count ) {
			if ( Active_Subscriptions_Reader_Data::STORE_KEY === $key ) {
				$count++;
			}
		};
		add_action( 'newspack_reader_data_updated', $listener, 10, 2 );
		$callback();
		remove_action( 'newspack_reader_data_updated', $listener, 10 );
		return $count;
	}

	/**
	 * An owner holds the product on their own subscription.
	 */
	public function test_owner_holds_their_product() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, 101 );

		$this->assertSame( [ 101 ], Active_Subscriptions_Reader_Data::get_product_ids( $owner_id ) );
	}

	/**
	 * A gift belongs to the person it was given to. Its buyer pays for it but
	 * cannot read it, which is how content gates treat the gift too.
	 */
	public function test_gift_recipient_holds_the_product_and_the_buyer_does_not() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$this->create_gifted_subscription( $buyer_id, $recipient_id, 101 );

		$this->assertSame( [ 101 ], Active_Subscriptions_Reader_Data::get_product_ids( $recipient_id ) );
		$this->assertSame( [], Active_Subscriptions_Reader_Data::get_product_ids( $buyer_id ) );
	}

	/**
	 * A member of an enabled group holds the group's product through it.
	 */
	public function test_group_member_holds_the_group_product() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$this->create_group_subscription( $owner_id, $member_id );

		$this->assertSame( [ 101 ], Active_Subscriptions_Reader_Data::get_product_ids( $member_id ) );
		$this->assertSame( [ 101 ], Active_Subscriptions_Reader_Data::get_product_ids( $owner_id ) );
	}

	/**
	 * A subscription on hold while its payment is retried does not count. The
	 * item keeps its old meaning, active or pending cancel, and only an event
	 * changes it. Retries running out removes the retry date with no status
	 * change, so counting this window would leave the reader listed with no event
	 * to take them off.
	 */
	public function test_subscription_in_payment_recovery_is_excluded() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, 101, 'on-hold', [ 'times' => [ 'payment_retry' => time() + HOUR_IN_SECONDS ] ] );

		$this->assertSame( [], Active_Subscriptions_Reader_Data::get_product_ids( $owner_id ) );
	}

	/**
	 * A recurring donation is a subscription too, but it is no product a segment
	 * should treat as a subscriber's.
	 */
	public function test_donation_product_is_excluded() {
		$owner_id    = $this->create_reader();
		$donation_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $donation_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		$this->create_subscription( $owner_id, $donation_id );
		$this->create_subscription( $owner_id, 101 );

		$this->assertSame( [ 101 ], Active_Subscriptions_Reader_Data::get_product_ids( $owner_id ) );
	}

	/**
	 * A subscription set to cancel at the end of the paid term still grants access
	 * until then.
	 */
	public function test_pending_cancel_subscription_is_included() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, 101, 'pending-cancel' );

		$this->assertSame( [ 101 ], Active_Subscriptions_Reader_Data::get_product_ids( $owner_id ) );
	}

	/**
	 * Products come back as sorted, unique integers, so an unchanged set of
	 * products always encodes to the same stored value.
	 */
	public function test_product_ids_are_unique_sorted_integers() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, 303 );
		$this->create_subscription( $owner_id, 101 );
		$this->create_subscription( $owner_id, 303 );

		$this->assertSame( [ 101, 303 ], Active_Subscriptions_Reader_Data::get_product_ids( $owner_id ) );
	}

	/**
	 * A reader who no longer holds anything has the item emptied rather than left
	 * listing a product they lost.
	 */
	public function test_refresh_empties_the_item_of_a_former_subscriber() {
		$owner_id = $this->create_reader();
		Reader_Data::update_item( $owner_id, Active_Subscriptions_Reader_Data::STORE_KEY, [ 123 ] );

		Active_Subscriptions_Reader_Data::refresh( $owner_id );

		$this->assertSame( [], $this->stored_products( $owner_id ) );
	}

	/**
	 * A reader who never subscribed gets no item. A missing item and an empty one
	 * match the same segments, so writing one would only add a row per reader.
	 */
	public function test_refresh_creates_no_item_for_a_never_subscriber() {
		$reader_id = $this->create_reader();

		$updates = $this->count_item_updates(
			function () use ( $reader_id ) {
				Active_Subscriptions_Reader_Data::refresh( $reader_id );
			}
		);

		$this->assertFalse( $this->stored_products( $reader_id ) );
		$this->assertSame( 0, $updates );
		$this->assertFalse( Active_Subscriptions_Reader_Data::is_stale( $reader_id ), 'Having nothing to store still counts as computed.' );
	}

	/**
	 * Recomputing an unchanged list writes nothing, so listeners of
	 * `newspack_reader_data_updated` (such as contact sync) stay quiet.
	 */
	public function test_refresh_of_an_unchanged_list_fires_no_update() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, 101 );

		$first_updates = $this->count_item_updates(
			function () use ( $owner_id ) {
				Active_Subscriptions_Reader_Data::refresh( $owner_id );
			}
		);
		$this->assertSame( 1, $first_updates );
		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );

		$second_updates = $this->count_item_updates(
			function () use ( $owner_id ) {
				Active_Subscriptions_Reader_Data::refresh( $owner_id );
			}
		);
		$this->assertSame( 0, $second_updates );
		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );
	}

	/**
	 * A list that shrinks is written, and one already empty is not written again.
	 */
	public function test_refresh_writes_a_changed_list_once() {
		$owner_id     = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, 101 );
		Active_Subscriptions_Reader_Data::refresh( $owner_id );

		$subscription->update_status( 'cancelled' );
		$cancel_updates = $this->count_item_updates(
			function () use ( $owner_id ) {
				Active_Subscriptions_Reader_Data::refresh( $owner_id );
			}
		);
		$this->assertSame( 1, $cancel_updates );
		$this->assertSame( [], $this->stored_products( $owner_id ) );

		$repeat_updates = $this->count_item_updates(
			function () use ( $owner_id ) {
				Active_Subscriptions_Reader_Data::refresh( $owner_id );
			}
		);
		$this->assertSame( 0, $repeat_updates );
	}

	/**
	 * A refused write leaves the reader unstamped, so the next page view tries
	 * again. Stamped with no item, a subscriber would read as a non-subscriber
	 * until the stamp aged out.
	 *
	 * The key cap refuses a write only for a key the reader does not have yet,
	 * so the reader here holds a product and has no stored item.
	 */
	public function test_refused_write_leaves_the_stamp_unset() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, 101 );
		add_filter( 'newspack_reader_data_max_items', '__return_zero', 11 );

		Active_Subscriptions_Reader_Data::refresh( $owner_id );

		$this->assertFalse( $this->stored_products( $owner_id ), 'The cap refused the write.' );
		$this->assertTrue( Active_Subscriptions_Reader_Data::is_stale( $owner_id ) );
		$this->assertEmpty( get_user_meta( $owner_id, Active_Subscriptions_Reader_Data::STAMP_META_KEY, true ) );

		remove_filter( 'newspack_reader_data_max_items', '__return_zero', 11 );
		Active_Subscriptions_Reader_Data::refresh( $owner_id );

		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );
		$this->assertFalse( Active_Subscriptions_Reader_Data::is_stale( $owner_id ) );
	}

	/**
	 * The stamp is the time of the computation, and a list older than a day is
	 * stale. That age limit is what catches a payment retry window closing, which
	 * fires no event.
	 */
	public function test_stamp_is_a_timestamp_that_goes_stale_after_a_day() {
		$owner_id = $this->create_reader();
		$this->assertTrue( Active_Subscriptions_Reader_Data::is_stale( $owner_id ), 'No stamp is stale.' );

		$before = time();
		Active_Subscriptions_Reader_Data::refresh( $owner_id );
		$stamp = get_user_meta( $owner_id, Active_Subscriptions_Reader_Data::STAMP_META_KEY, true );

		$this->assertTrue( is_numeric( $stamp ) );
		$this->assertGreaterThanOrEqual( $before, (int) $stamp );
		$this->assertLessThanOrEqual( time(), (int) $stamp );
		$this->assertFalse( Active_Subscriptions_Reader_Data::is_stale( $owner_id ) );

		update_user_meta( $owner_id, Active_Subscriptions_Reader_Data::STAMP_META_KEY, time() - DAY_IN_SECONDS - 1 );
		$this->assertTrue( Active_Subscriptions_Reader_Data::is_stale( $owner_id ) );
	}

	/**
	 * Marking a reader stale drops the stamp, so their next page view recomputes.
	 */
	public function test_mark_stale_deletes_the_stamp() {
		$owner_id = $this->create_reader();
		Active_Subscriptions_Reader_Data::refresh( $owner_id );
		$this->assertFalse( Active_Subscriptions_Reader_Data::is_stale( $owner_id ) );

		Active_Subscriptions_Reader_Data::mark_stale( $owner_id );

		$this->assertTrue( Active_Subscriptions_Reader_Data::is_stale( $owner_id ) );
		$this->assertEmpty( get_user_meta( $owner_id, Active_Subscriptions_Reader_Data::STAMP_META_KEY, true ) );
	}

	/**
	 * No user, no work: a zero or negative ID writes nothing, even though a guest
	 * purchase is a subscription whose customer is user 0.
	 */
	public function test_refresh_and_mark_stale_ignore_invalid_user_ids() {
		$this->create_subscription( 0, 101 );

		$updates = $this->count_item_updates(
			function () {
				Active_Subscriptions_Reader_Data::refresh( 0 );
				Active_Subscriptions_Reader_Data::refresh( -5 );
				Active_Subscriptions_Reader_Data::mark_stale( 0 );
			}
		);

		$this->assertSame( 0, $updates );
		$this->assertEmpty( get_user_meta( 0, Active_Subscriptions_Reader_Data::STAMP_META_KEY, true ) );
	}

	/**
	 * The item is stored as the JSON list of product IDs that the consumers decode:
	 * Campaigns criteria and the GA4 `is_subscriber` flag both read it through
	 * `Reader_Data`. The other tests decode the value themselves, so this one pins
	 * the stored string and the reader the consumers use.
	 */
	public function test_stored_item_is_the_json_list_consumers_read() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, 303 );
		$this->create_subscription( $owner_id, 101 );

		Active_Subscriptions_Reader_Data::refresh( $owner_id );

		$this->assertSame( '[101,303]', Reader_Data::get_data( $owner_id, 'active_subscriptions' ) );
		$this->assertSame( [ 101, 303 ], Reader_Data::get_active_subscriptions( $owner_id ) );
	}

	/**
	 * A gift goes on its recipient's list, not its buyer's. The subscription's
	 * customer is the buyer, who cannot read what they bought, so an event that
	 * listed the product under its customer would call the buyer a subscriber and
	 * miss the recipient.
	 */
	public function test_event_for_a_gift_fills_the_recipient_and_empties_the_buyer() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$gift         = $this->create_gifted_subscription( $buyer_id, $recipient_id, 101 );
		// The buyer is listed already, the way an earlier event left them.
		Reader_Data::update_item( $buyer_id, Active_Subscriptions_Reader_Data::STORE_KEY, [ 101 ] );

		Reader_Data::update_active_subscriptions( time(), $this->product_subscription_changed( $gift, [ 101 ], 'pending', 'active' ) );

		$this->assertSame( [ 101 ], $this->stored_products( $recipient_id ) );
		$this->assertSame( [], $this->stored_products( $buyer_id ) );
	}

	/**
	 * A group can have hundreds of members, so the event only marks them stale
	 * rather than recompute each one in the request that changed the subscription.
	 * One recompute on their next page view gives them the group's products.
	 */
	public function test_event_marks_group_members_stale_and_one_refresh_gives_them_the_product() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		// The member's list was computed while they belonged to no group.
		Active_Subscriptions_Reader_Data::refresh( $member_id );
		$this->assertFalse( Active_Subscriptions_Reader_Data::is_stale( $member_id ) );
		$group = $this->create_group_subscription( $owner_id, $member_id );

		Reader_Data::update_active_subscriptions( time(), $this->product_subscription_changed( $group, [ 101 ], 'pending', 'active' ) );

		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );
		$this->assertTrue( Active_Subscriptions_Reader_Data::is_stale( $member_id ) );
		$this->assertFalse( $this->stored_products( $member_id ), 'The event does not recompute members.' );

		Active_Subscriptions_Reader_Data::refresh( $member_id );

		$this->assertSame( [ 101 ], $this->stored_products( $member_id ) );
		$this->assertFalse( Active_Subscriptions_Reader_Data::is_stale( $member_id ) );
	}

	/**
	 * A group member loses the product when the group's subscription ends, once
	 * the recompute the event queued for them runs.
	 */
	public function test_cancelled_group_empties_a_member_after_a_refresh() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$group     = $this->create_group_subscription( $owner_id, $member_id );
		Active_Subscriptions_Reader_Data::refresh( $owner_id );
		Active_Subscriptions_Reader_Data::refresh( $member_id );
		$this->assertSame( [ 101 ], $this->stored_products( $member_id ) );

		$group->update_status( 'cancelled' );
		Reader_Data::update_active_subscriptions( time(), $this->product_subscription_changed( $group, [ 101 ], 'active', 'cancelled' ) );

		$this->assertSame( [], $this->stored_products( $owner_id ) );
		$this->assertTrue( Active_Subscriptions_Reader_Data::is_stale( $member_id ) );

		Active_Subscriptions_Reader_Data::refresh( $member_id );

		$this->assertSame( [], $this->stored_products( $member_id ) );
	}

	/**
	 * Two subscriptions to one product still hold it after one of them ends.
	 * Subtracting the cancelled subscription's products from the list would take
	 * the product off a reader whose other subscription is still active.
	 */
	public function test_cancelling_one_of_two_subscriptions_to_a_product_keeps_the_product() {
		$owner_id = $this->create_reader();
		$first    = $this->create_subscription( $owner_id, 101 );
		$second   = $this->create_subscription( $owner_id, 101 );
		Active_Subscriptions_Reader_Data::refresh( $owner_id );
		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );

		$first->update_status( 'cancelled' );
		Reader_Data::update_active_subscriptions( time(), $this->product_subscription_changed( $first, [ 101 ], 'active', 'cancelled' ) );
		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );

		$second->update_status( 'cancelled' );
		Reader_Data::update_active_subscriptions( time(), $this->product_subscription_changed( $second, [ 101 ], 'active', 'cancelled' ) );
		$this->assertSame( [], $this->stored_products( $owner_id ) );
	}

	/**
	 * A plan switch replaces the product on the same subscription and reports the
	 * subscription's status as both its before and after. An event that only added
	 * the new product would leave the old one on the list.
	 */
	public function test_plan_switch_drops_the_old_product() {
		$owner_id     = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, 101 );
		Active_Subscriptions_Reader_Data::refresh( $owner_id );
		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );

		$subscription->remove_item( array_key_first( $subscription->get_items() ) );
		$subscription->add_item( new \WC_Order_Item_Product( [ 'product_id' => 202 ] ) );
		$subscription->products = [ 202 ];
		Reader_Data::update_active_subscriptions( time(), $this->product_subscription_changed( $subscription, [ 202 ], 'active', 'active' ) );

		$this->assertSame( [ 202 ], $this->stored_products( $owner_id ) );
	}

	/**
	 * The handler works from the user and the subscription alone. The event's
	 * product IDs and statuses describe one change, and the handler recomputes
	 * from what the reader holds now.
	 */
	public function test_handler_needs_only_a_user_and_a_subscription() {
		$owner_id     = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, 101 );
		Reader_Data::update_item( $owner_id, Active_Subscriptions_Reader_Data::STORE_KEY, [ 123 ] );

		Active_Subscriptions_Reader_Data::handle_product_subscription_changed(
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
			]
		);

		$this->assertSame( [ 101 ], $this->stored_products( $owner_id ) );
	}

	/**
	 * Without a user or a subscription there is nobody to recompute.
	 */
	public function test_handler_ignores_an_event_missing_its_user_or_subscription() {
		$owner_id     = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, 101 );
		Reader_Data::update_item( $owner_id, Active_Subscriptions_Reader_Data::STORE_KEY, [ 123 ] );

		$updates = $this->count_item_updates(
			function () use ( $owner_id, $subscription ) {
				Active_Subscriptions_Reader_Data::handle_product_subscription_changed( [ 'user_id' => $owner_id ] );
				Active_Subscriptions_Reader_Data::handle_product_subscription_changed( [ 'subscription_id' => $subscription->get_id() ] );
				Active_Subscriptions_Reader_Data::handle_product_subscription_changed( [] );
			}
		);

		$this->assertSame( 0, $updates );
		$this->assertSame( [ 123 ], $this->stored_products( $owner_id ) );
	}

	/**
	 * A subscription deleted before a queued event runs has no readers to look up,
	 * but the customer is still recomputed, which takes the deleted subscription's
	 * product off them.
	 */
	public function test_handler_still_refreshes_the_user_when_the_subscription_no_longer_loads() {
		$owner_id = $this->create_reader();
		Reader_Data::update_item( $owner_id, Active_Subscriptions_Reader_Data::STORE_KEY, [ 123 ] );

		Reader_Data::update_active_subscriptions(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => 9999,
				'product_ids'     => [ 123 ],
				'status_after'    => 'cancelled',
			]
		);

		$this->assertSame( [], $this->stored_products( $owner_id ) );
	}

	/**
	 * Logging in recomputes the list, so a reader whose last subscription ended
	 * without an event is taken off it. A login handler that gave up when the
	 * reader held nothing would leave the stale list in place.
	 */
	public function test_login_empties_a_stored_list_when_nothing_is_held() {
		$user_id = $this->create_reader();
		Reader_Data::update_item( $user_id, Active_Subscriptions_Reader_Data::STORE_KEY, [ 123 ] );

		Reader_Data::check_product_subscriptions(
			time(),
			[
				'user_id' => $user_id,
				'email'   => 'reader@example.com',
			]
		);

		$this->assertSame( [], $this->stored_products( $user_id ) );
	}

	/**
	 * Logging in lists what the reader holds, including a gift they received.
	 */
	public function test_login_lists_the_products_a_reader_holds() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$this->create_gifted_subscription( $buyer_id, $recipient_id, 101 );
		$this->create_subscription( $recipient_id, 303 );

		Reader_Data::check_product_subscriptions(
			time(),
			[
				'user_id' => $recipient_id,
				'email'   => 'recipient@example.com',
			]
		);

		$this->assertSame( [ 101, 303 ], $this->stored_products( $recipient_id ) );
	}

	/**
	 * The login handler still needs a user and an email before it does anything.
	 */
	public function test_login_without_a_user_or_email_changes_nothing() {
		$user_id = $this->create_reader();
		Reader_Data::update_item( $user_id, Active_Subscriptions_Reader_Data::STORE_KEY, [ 123 ] );

		Reader_Data::check_product_subscriptions( time(), [ 'user_id' => $user_id ] );
		Reader_Data::check_product_subscriptions( time(), [ 'email' => 'reader@example.com' ] );

		$this->assertSame( [ 123 ], $this->stored_products( $user_id ) );
	}
}
