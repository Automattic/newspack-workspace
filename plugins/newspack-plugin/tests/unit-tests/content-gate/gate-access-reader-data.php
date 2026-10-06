<?php
/**
 * Tests the `accessible_gates` reader data item and its segment criteria.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gate;
use Newspack\Gate_Access_Reader_Data;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Reader_Activation;
use Newspack\Reader_Data;

/**
 * The item lists the paid gates a reader can pass, however they hold the product.
 *
 * @group Gate_Access_Reader_Data
 */
class Newspack_Test_Gate_Access_Reader_Data extends WP_UnitTestCase {

	/**
	 * Gate requiring an active subscription to product 101.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * Set up a paid gate and reset the mock subscription store.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		global $subscriptions_database;
		$subscriptions_database = [];

		$this->gate_id = $this->create_gate( 'All Access', [ [ self::subscription_rule( 101 ) ] ] );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A subscription rule for one product.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array
	 */
	private static function subscription_rule( $product_id ) {
		return [
			'slug'  => 'subscription',
			'value' => [ $product_id ],
		];
	}

	/**
	 * Create a published gate with custom access on.
	 *
	 * @param string $title        Gate title.
	 * @param array  $access_rules Access rule groups.
	 *
	 * @return int Gate ID.
	 */
	private function create_gate( $title, $access_rules ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => $access_rules,
			]
		);
		return $gate_id;
	}

	/**
	 * Create a verified reader.
	 *
	 * @param string $email Optional email.
	 *
	 * @return int User ID.
	 */
	private function create_reader( $email = null ) {
		$user_id = $this->factory->user->create(
			array_filter(
				[
					'role'       => 'subscriber',
					'user_email' => $email,
				]
			)
		);
		Reader_Activation::set_reader_verified( $user_id );
		return $user_id;
	}

	/**
	 * Create a subscription.
	 *
	 * @param int  $customer_id Owner.
	 * @param bool $is_group    Whether the subscription is a group subscription.
	 * @param int  $product_id  Product on the subscription.
	 *
	 * @return \WC_Subscription
	 */
	private function create_subscription( $customer_id, $is_group = false, $product_id = 101 ) {
		$subscription = \wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ $product_id ],
			]
		);
		if ( $is_group ) {
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
			$subscription->save();
		}
		return $subscription;
	}

	/**
	 * The stored item, decoded.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return int[]|false Gate IDs, or false when the item was never written.
	 */
	private function stored_gates( $user_id ) {
		$value = Reader_Data::get_data( $user_id, Gate_Access_Reader_Data::STORE_KEY );
		return false === $value ? false : json_decode( $value, true );
	}

	/**
	 * A page view by the reader, which recomputes a missing or stale item.
	 *
	 * @param int $user_id User ID.
	 */
	private function view_page_as( $user_id ) {
		wp_set_current_user( $user_id );
		Gate_Access_Reader_Data::maybe_backfill_current_reader();
	}

	/**
	 * The point of the item: a subscriber who owns the product and a seat-holder in
	 * someone else's group both read as able to access the gate, while
	 * `active_subscriptions` only ever sees the owner.
	 */
	public function test_owner_and_group_member_both_list_the_gate() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$outsider_id  = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, true );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );

		foreach ( [ $owner_id, $member_id, $outsider_id ] as $user_id ) {
			Gate_Access_Reader_Data::refresh( $user_id );
		}

		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $owner_id ) );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $member_id ), 'A group member holds the product through the group.' );
		$this->assertSame( [], $this->stored_gates( $outsider_id ), 'A reader with no access gets an empty list, not a missing item.' );
	}

	/**
	 * A group subscription's status change names only its owner. The owner is
	 * recomputed at once; the members are marked stale and recomputed on their
	 * next page view, so a large group costs one meta delete per member.
	 */
	public function test_group_subscription_status_change_marks_members_stale() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, true );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		$this->view_page_as( $member_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $member_id ) );

		$subscription->update_status( 'cancelled' );
		Group_Subscription::reset_cache();
		Gate_Access_Reader_Data::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'cancelled',
			]
		);
		$this->assertSame( [], $this->stored_gates( $owner_id ) );

		$this->view_page_as( $member_id );
		$this->assertSame( [], $this->stored_gates( $member_id ), 'The member loses access with the group.' );
	}

	/**
	 * Joining or leaving a group fires no data event naming the reader, so the
	 * membership write marks the reader's item stale.
	 */
	public function test_joining_and_leaving_a_group_marks_the_member_stale() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, true );
		$this->view_page_as( $member_id );
		$this->assertSame( [], $this->stored_gates( $member_id ) );

		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		$this->view_page_as( $member_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $member_id ) );

		delete_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		$this->view_page_as( $member_id );
		$this->assertSame( [], $this->stored_gates( $member_id ) );
	}

	/**
	 * Readers who never trigger an event after the deploy (a remember-me session
	 * that outlives it) would otherwise read as having no access and match every
	 * "cannot access" segment. Their first page view computes the item.
	 */
	public function test_missing_item_is_computed_on_page_view() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id );
		$this->assertFalse( $this->stored_gates( $owner_id ) );

		$this->view_page_as( $owner_id );

		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $owner_id ) );
	}

	/**
	 * A gate re-ruled after a reader's list was written changes no reader event.
	 * The list is recomputed on the reader's next page view rather than kept until
	 * their next login. Gate settings are saved without a save_post, so this goes
	 * through the meta write alone.
	 */
	public function test_gate_change_recomputes_lists_on_next_page_view() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id, false, 202 );
		$this->view_page_as( $owner_id );
		$this->assertSame( [], $this->stored_gates( $owner_id ) );

		Content_Gate::update_custom_access_settings( $this->gate_id, [ 'access_rules' => [ [ self::subscription_rule( 202 ) ] ] ] );
		$this->view_page_as( $owner_id );

		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $owner_id ) );
	}

	/**
	 * Access can also end with no event at all: a one-time purchase's duration
	 * runs out, or a payment-recovery window closes. A list older than a day is
	 * recomputed on the next page view.
	 */
	public function test_list_older_than_a_day_is_recomputed() {
		$owner_id = $this->create_reader();
		$this->view_page_as( $owner_id );
		$this->create_subscription( $owner_id );

		$this->view_page_as( $owner_id );
		$this->assertSame( [], $this->stored_gates( $owner_id ), 'A fresh list is not recomputed on every view.' );

		$stamp             = get_user_meta( $owner_id, Gate_Access_Reader_Data::STAMP_META_KEY, true );
		$stamp['computed'] = time() - DAY_IN_SECONDS - 1;
		update_user_meta( $owner_id, Gate_Access_Reader_Data::STAMP_META_KEY, $stamp );
		$this->view_page_as( $owner_id );

		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $owner_id ) );
	}

	/**
	 * A one-time purchase grants access while its order counts as paid. The buyer
	 * is recomputed when an order starts or stops counting, not on every status
	 * move, since the event fires once per line item.
	 */
	public function test_order_paid_status_crossing_refreshes_the_buyer() {
		$buyer_id = $this->create_reader();
		$this->view_page_as( $buyer_id );
		$this->create_subscription( $buyer_id );

		Gate_Access_Reader_Data::handle_woo_order_updated(
			time(),
			[
				'user_id'     => $buyer_id,
				'status_from' => 'processing',
				'status'      => 'completed',
			]
		);
		$this->assertSame( [], $this->stored_gates( $buyer_id ), 'Paid to paid changes nothing.' );

		Gate_Access_Reader_Data::handle_woo_order_updated(
			time(),
			[
				'user_id'     => $buyer_id,
				'status_from' => 'pending',
				'status'      => 'processing',
			]
		);
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $buyer_id ) );
	}

	/**
	 * Only rule groups made of product rules count. A group that grants access by
	 * email domain, IP or reader data would make the stored answer depend on the
	 * request that computed it, and is not a purchase.
	 */
	public function test_only_product_rule_groups_grant_listed_access() {
		$domain_gate_id = $this->create_gate(
			'Subscribers or staff',
			[
				[ self::subscription_rule( 303 ) ],
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.test',
					],
				],
			]
		);
		$this->create_gate(
			'Staff only',
			[
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.test',
					],
				],
			]
		);
		$staff_id = $this->create_reader( 'staff@example.test' );

		Gate_Access_Reader_Data::refresh( $staff_id );
		set_current_screen( 'dashboard' );
		$options = Gate_Access_Reader_Data::register_criteria( [] )['cannot_access_gates']['options'];
		set_current_screen( 'front' );

		$this->assertSame( [], $this->stored_gates( $staff_id ), 'Email-domain access is not product access.' );
		$this->assertSame(
			[ (string) $this->gate_id, (string) $domain_gate_id ],
			array_column( $options, 'value' ),
			'A gate with no product rule is not offered.'
		);
	}

	/**
	 * A reader who already holds the maximum number of reader data keys still gets
	 * the item. Without it they would match every "cannot access" segment, paying
	 * or not.
	 */
	public function test_item_is_stored_for_a_reader_at_the_key_cap() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id );
		for ( $i = count( Reader_Data::get_data( $owner_id ) ); $i < Reader_Data::MAX_ITEMS; $i++ ) {
			Reader_Data::update_item( $owner_id, "filler_$i", '1' );
		}

		$this->view_page_as( $owner_id );

		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $owner_id ) );
	}

	/**
	 * The item is server-owned: a reader must not be able to write their way into
	 * or out of a gate-access segment.
	 */
	public function test_item_is_read_only() {
		$this->assertContains( Gate_Access_Reader_Data::STORE_KEY, Reader_Data::get_read_only_keys() );
	}
}
