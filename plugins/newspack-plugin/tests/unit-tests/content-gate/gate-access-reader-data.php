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

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 2 ) . '/mocks/newspack-segments-model-mock.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wcsg-recipient-addresses-mock.php';

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
		\WCS_Gifting::$recipients = [];
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
	 * Give a subscription to a recipient, or hand it to a different one.
	 *
	 * WooCommerce Subscriptions Gifting lists a gift under its recipient, so the
	 * lookup filter reads the recipient map each time it runs. Handing the gift on,
	 * or clearing its recipient, is then one write to the map.
	 *
	 * @param \WC_Subscription $subscription Subscription to gift.
	 * @param int              $recipient_id Recipient.
	 */
	private function gift_to( $subscription, $recipient_id ) {
		$is_gifted = isset( \WCS_Gifting::$recipients[ $subscription->get_id() ] );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;
		if ( $is_gifted ) {
			return;
		}
		add_filter(
			'wcs_get_users_subscriptions',
			function ( $subscriptions, $user_id ) use ( $subscription ) {
				if ( (int) $user_id === ( \WCS_Gifting::$recipients[ $subscription->get_id() ] ?? 0 ) ) {
					$subscriptions[ $subscription->get_id() ] = $subscription;
				}
				return $subscriptions;
			},
			10,
			2
		);
	}

	/**
	 * A gift's status change names its buyer. The recipient, who holds the gift,
	 * is marked stale and loses the gate on their next page view.
	 */
	public function test_gift_status_change_marks_the_recipient_stale() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$gift         = $this->create_subscription( $buyer_id );
		$this->gift_to( $gift, $recipient_id );
		$this->view_page_as( $recipient_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $recipient_id ) );

		$gift->update_status( 'cancelled' );
		Gate_Access_Reader_Data::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $buyer_id,
				'subscription_id' => $gift->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'cancelled',
			]
		);

		$this->assertEmpty( get_user_meta( $recipient_id, Gate_Access_Reader_Data::STAMP_META_KEY, true ), 'The recipient is marked stale.' );
		$this->view_page_as( $recipient_id );
		$this->assertSame( [], $this->stored_gates( $recipient_id ) );
	}

	/**
	 * With WooCommerce Memberships active, Group_Subscription::is_group_subscription()
	 * answers "no" on a My Account page. A status change made there still marks the
	 * group's members stale.
	 *
	 * Runs in its own process: the Memberships stand-in can't be undeclared, and
	 * would make Memberships::is_active() true for every later test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_group_status_change_on_my_account_marks_members_stale() {
		require dirname( __DIR__, 2 ) . '/mocks/wc-memberships-active-mock.php';
		if ( ! function_exists( 'is_account_page' ) ) {
			/**
			 * Stub for WooCommerce is_account_page().
			 *
			 * @return bool
			 */
			function is_account_page() {
				return $GLOBALS['newspack_test_is_account_page'] ?? false;
			}
		}
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, true );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		update_user_meta(
			$member_id,
			Gate_Access_Reader_Data::STAMP_META_KEY,
			[
				'version'  => (string) get_option( Gate_Access_Reader_Data::GATES_VERSION_OPTION, '' ),
				'computed' => time(),
			]
		);
		$GLOBALS['newspack_test_is_account_page'] = true;
		$this->assertFalse( Group_Subscription::is_group_subscription( $subscription ), 'The My Account caveat applies.' );

		$subscription->update_status( 'cancelled' );
		Gate_Access_Reader_Data::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'cancelled',
			]
		);
		unset( $GLOBALS['newspack_test_is_account_page'] );

		$this->assertEmpty( get_user_meta( $member_id, Gate_Access_Reader_Data::STAMP_META_KEY, true ), 'The member is marked stale.' );
	}

	/**
	 * Handing a gift to someone else fires no subscription event. The new
	 * recipient gains the gate and the old one loses it, on their next page view.
	 */
	public function test_reassigning_a_gift_moves_the_gate_to_the_new_recipient() {
		$buyer_id = $this->create_reader();
		$old_id   = $this->create_reader();
		$new_id   = $this->create_reader();
		$gift     = $this->create_subscription( $buyer_id );
		$this->gift_to( $gift, $old_id );
		$this->view_page_as( $old_id );
		$this->view_page_as( $new_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $old_id ) );
		$this->assertSame( [], $this->stored_gates( $new_id ) );

		$this->gift_to( $gift, $new_id );
		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $gift, $new_id, $old_id );

		$this->view_page_as( $old_id );
		$this->view_page_as( $new_id );
		$this->assertSame( [], $this->stored_gates( $old_id ), 'The old recipient loses the gate.' );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $new_id ), 'The new recipient gains it.' );
	}

	/**
	 * A subscription that becomes a gift was the buyer's own until then. The
	 * recipient-changed hook reports no earlier recipient, so the buyer is marked,
	 * and loses the gate on their next page view.
	 */
	public function test_gifting_a_subscription_the_buyer_held_takes_the_gate_from_the_buyer() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$subscription = $this->create_subscription( $buyer_id );
		$this->view_page_as( $buyer_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $buyer_id ) );

		$this->gift_to( $subscription, $recipient_id );
		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient_id, 0 );

		$this->view_page_as( $buyer_id );
		$this->view_page_as( $recipient_id );
		$this->assertSame( [], $this->stored_gates( $buyer_id ) );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $recipient_id ) );
	}

	/**
	 * Clearing a gift's recipient (in the admin, or by deleting an account) fires
	 * Newspack's own announcement instead of the WCS hook. The previous recipient
	 * loses the gate, and the buyer, who holds a subscription that is no longer a
	 * gift, gains it.
	 */
	public function test_clearing_a_gift_recipient_takes_the_gate_from_them() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$gift         = $this->create_subscription( $buyer_id );
		$this->gift_to( $gift, $recipient_id );
		$this->view_page_as( $recipient_id );
		$this->view_page_as( $buyer_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $recipient_id ) );
		$this->assertSame( [], $this->stored_gates( $buyer_id ) );

		unset( \WCS_Gifting::$recipients[ $gift->get_id() ] );
		do_action( 'newspack_subscription_gift_recipient_cleared', $gift, $recipient_id );

		$this->view_page_as( $recipient_id );
		$this->view_page_as( $buyer_id );
		$this->assertSame( [], $this->stored_gates( $recipient_id ), 'The previous recipient loses the gate.' );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $buyer_id ), 'The buyer holds what is no longer a gift.' );
	}

	/**
	 * Turning a group off fires no subscription event. Its members hold the
	 * product only while it is on, so each loses the gate on their next page view.
	 * Turning it back on gives it back.
	 */
	public function test_turning_a_group_off_takes_the_gate_from_its_members() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, true );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Group_Subscription::reset_cache();
		$this->view_page_as( $member_id );
		$this->view_page_as( $owner_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $member_id ) );

		Group_Subscription_Settings::update_subscription_settings( $subscription, [ 'enabled' => false ] );
		Group_Subscription::reset_cache();

		$this->assertNotEmpty( get_user_meta( $owner_id, Gate_Access_Reader_Data::STAMP_META_KEY, true ), 'The owner holds their subscription either way.' );
		$this->view_page_as( $member_id );
		$this->assertSame( [], $this->stored_gates( $member_id ), 'A member of a group that is off loses the gate.' );

		Group_Subscription_Settings::update_subscription_settings( $subscription, [ 'enabled' => true ] );
		Group_Subscription::reset_cache();

		$this->view_page_as( $member_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $member_id ) );
	}

	/**
	 * A settings save that leaves the group's on/off state alone does not touch
	 * its members.
	 */
	public function test_changing_another_group_setting_leaves_members_alone() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id, true );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		$this->view_page_as( $member_id );

		Group_Subscription_Settings::update_subscription_settings( $subscription, [ 'limit' => 5 ] );

		$this->assertNotEmpty( get_user_meta( $member_id, Gate_Access_Reader_Data::STAMP_META_KEY, true ) );
	}

	/**
	 * A group that doesn't set its own on/off state follows its product's. Turning
	 * group subscriptions off for the product names no group or member, so every
	 * list computed before the change goes stale, and the member loses the gate on
	 * their next page view.
	 */
	public function test_turning_a_products_groups_off_takes_the_gate_from_inheriting_members() {
		$enabled_key = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled';
		$product_id  = $this->factory->post->create( [ 'post_type' => 'product' ] );
		add_post_meta( $product_id, $enabled_key, 'yes' );
		$product = wc_create_mock_product(
			[
				'id'   => $product_id,
				'type' => 'subscription',
				'meta' => [ $enabled_key => 'yes' ],
			]
		);
		$gate_id      = $this->create_gate( 'Group plan', [ [ self::subscription_rule( $product_id ) ] ] );
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = \wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'items'          => [ new \WC_Order_Item_Product( [ 'product_id' => $product_id ] ) ],
			]
		);
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		// Lists computed from here on postdate the product's last change.
		update_option( 'newspack_group_subscription_products_changed_at', time() - MINUTE_IN_SECONDS );
		Group_Subscription::reset_cache();
		$this->view_page_as( $member_id );
		$this->assertSame( [ $gate_id ], $this->stored_gates( $member_id ), 'The group inherits the product setting.' );

		// What WooCommerce_Products::save_custom_product_options() writes when the box is unchecked.
		$product->update_meta_data( $enabled_key, 'no' );
		update_post_meta( $product_id, $enabled_key, 'no' );
		Group_Subscription::reset_cache();

		$this->view_page_as( $member_id );
		$this->assertSame( [], $this->stored_gates( $member_id ), 'The member loses the gate with the product setting.' );
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
	 * An order that starts or stops counting as paid can change access (a
	 * one-time purchase grants it only while paid), so the buyer is recomputed
	 * then, and not on a move between two paid statuses: the event fires once per
	 * line item.
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
	 * A refused write leaves the reader unstamped, so the next page view tries
	 * again. Stamped with no item, they would match every "cannot access" segment
	 * until the list aged out.
	 */
	public function test_refused_write_is_retried_on_next_page_view() {
		$owner_id = $this->create_reader();
		$this->create_subscription( $owner_id );
		add_filter( 'newspack_reader_data_max_items', '__return_zero', 11 );

		$this->view_page_as( $owner_id );
		$this->assertFalse( $this->stored_gates( $owner_id ) );

		remove_filter( 'newspack_reader_data_max_items', '__return_zero', 11 );
		$this->view_page_as( $owner_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $owner_id ) );
	}

	/**
	 * The segment editor renders a list criterion with no options as a free-text
	 * field that can never match. With no paid gate to pick, the criteria stay
	 * out of the editor, unless a saved segment uses one: the publisher has to be
	 * able to see and remove that condition. The front end keeps them either way.
	 */
	public function test_criteria_stay_out_of_the_editor_without_paid_gates_unless_used() {
		wp_delete_post( $this->gate_id, true );
		Newspack_Segments_Model::$segments = [];

		$front_end_criteria = Gate_Access_Reader_Data::register_criteria( [] );
		set_current_screen( 'dashboard' );
		$editor_criteria_unused = Gate_Access_Reader_Data::register_criteria( [] );
		Newspack_Segments_Model::$segments[1] = [
			'id'       => 1,
			'criteria' => [
				[
					'criteria_id' => 'cannot_access_gates',
					'value'       => [ (string) $this->gate_id ],
				],
			],
		];
		$editor_criteria_used = Gate_Access_Reader_Data::register_criteria( [] );
		set_current_screen( 'front' );
		Newspack_Segments_Model::$segments = [];

		$this->assertArrayHasKey( 'cannot_access_gates', $front_end_criteria );
		$this->assertSame( [], $editor_criteria_unused );
		$this->assertArrayHasKey( 'cannot_access_gates', $editor_criteria_used );
	}

	/**
	 * The item is server-owned: a reader must not be able to write their way into
	 * or out of a gate-access segment.
	 */
	public function test_item_is_read_only() {
		$this->assertContains( Gate_Access_Reader_Data::STORE_KEY, Reader_Data::get_read_only_keys() );
	}

	/**
	 * Gifting hides a recipient's gifts while the billing address form is on
	 * screen. A list refreshed on that page keeps the gift's gate, instead of
	 * dropping it until the list is next a day old.
	 */
	public function test_billing_address_page_keeps_a_gifts_gate() {
		$purchaser_id = $this->create_reader();
		$recipient_id = $this->create_reader();
		$gift         = $this->create_subscription( $purchaser_id );
		\WCS_Gifting::$recipients[ $gift->get_id() ] = $recipient_id;
		add_filter(
			'wcs_get_users_subscriptions',
			fn( $subscriptions, $user_id ) => (int) $user_id === $recipient_id ? $subscriptions + [ $gift->get_id() => $gift ] : $subscriptions,
			10,
			2
		);
		add_filter( 'wcs_get_users_subscriptions', 'WCSG_Recipient_Addresses::get_users_subscriptions', 100, 2 );
		set_query_var( 'edit-address', 'billing' );

		$this->view_page_as( $recipient_id );

		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $recipient_id ) );
	}

	/**
	 * A change of owner moves access from one reader to another with no
	 * subscription event and, for an outsider taking over from a previous owner
	 * who can't take a member's seat, no membership write. Group_Subscription's
	 * announcement marks both, and their next page views put the gate right.
	 */
	public function test_owner_change_marks_both_owners_stale() {
		$previous_owner_id = $this->create_reader();
		$new_owner_id      = $this->create_reader();
		$subscription      = $this->create_subscription( $previous_owner_id, true );
		add_user_meta( $this->create_reader(), Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Group_Subscription::reset_cache();
		$ineligible = function ( $eligible, $user_id ) use ( $previous_owner_id ) {
			return $user_id === $previous_owner_id ? false : $eligible;
		};
		add_filter( 'newspack_group_subscription_member_eligible', $ineligible, 10, 2 );
		$this->view_page_as( $previous_owner_id );
		$this->view_page_as( $new_owner_id );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $previous_owner_id ) );
		$this->assertSame( [], $this->stored_gates( $new_owner_id ) );

		$changed = Group_Subscription::change_owner( $subscription, $new_owner_id );
		remove_filter( 'newspack_group_subscription_member_eligible', $ineligible, 10 );

		$this->assertTrue( $changed );
		$this->assertEmpty( get_user_meta( $previous_owner_id, Gate_Access_Reader_Data::STAMP_META_KEY, true ), 'The previous owner is marked stale.' );
		$this->assertEmpty( get_user_meta( $new_owner_id, Gate_Access_Reader_Data::STAMP_META_KEY, true ), 'The new owner is marked stale.' );

		Group_Subscription::reset_cache();
		$this->view_page_as( $previous_owner_id );
		$this->view_page_as( $new_owner_id );
		$this->assertSame( [], $this->stored_gates( $previous_owner_id ) );
		$this->assertSame( [ $this->gate_id ], $this->stored_gates( $new_owner_id ) );
	}
}
