<?php
/**
 * Tests for Group_Subscription member-count helpers.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Test Group_Subscription member counting (managers/owner are counted as members).
 */
class Test_Group_Subscription extends WP_UnitTestCase {

	/**
	 * Track created user IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Include WC mocks.
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up: reset subscriptions and products databases.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		$this->user_ids         = [];
	}

	/**
	 * Tear down: reset subscriptions and products databases and delete users.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		parent::tear_down();
	}

	/**
	 * Create a reader user and track it for cleanup.
	 *
	 * @return int The new user ID.
	 */
	private function create_reader_user(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'reader-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'reader-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		if ( ! is_wp_error( $user_id ) ) {
			update_user_meta( $user_id, '_newspack_reader', true );
			$this->user_ids[] = $user_id;
		}
		return $user_id;
	}

	/**
	 * Create an enabled group subscription owned by $customer_id, optionally with a member limit.
	 *
	 * @param int      $customer_id The owner user ID.
	 * @param int|null $limit       Optional member limit to set on the subscription.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $customer_id, $limit = null ): WC_Subscription {
		$sub = wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		if ( null !== $limit ) {
			$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', (string) $limit );
		}
		return $sub;
	}

	/**
	 * Add $member_id as a member of $subscription.
	 *
	 * @param int             $member_id    The user ID to add as a member.
	 * @param WC_Subscription $subscription The group subscription.
	 */
	private function add_member( int $member_id, WC_Subscription $subscription ): void {
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
	}

	/**
	 * The owner counts as a member even when there are no other members.
	 */
	public function test_member_count_includes_owner_when_only_member() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id );

		$this->assertSame(
			1,
			Group_Subscription::get_member_count( $sub ),
			'A group whose only member is the owner should report a count of 1, not 0.'
		);
	}

	/**
	 * The owner is counted alongside added members.
	 */
	public function test_member_count_includes_owner_and_members() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id );
		$this->add_member( $this->create_reader_user(), $sub );
		$this->add_member( $this->create_reader_user(), $sub );

		$this->assertSame(
			3,
			Group_Subscription::get_member_count( $sub ),
			'Two added members plus the owner should report a count of 3.'
		);
	}

	/**
	 * The get_all_members() helper returns owner + members, de-duplicated, without empty IDs.
	 */
	public function test_get_all_members_returns_owner_and_members() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$sub       = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $sub );

		$all = Group_Subscription::get_all_members( $sub );
		sort( $all );
		$expected = [ $owner_id, $member_id ];
		sort( $expected );

		$this->assertSame( $expected, $all, 'get_all_members should return the owner and member IDs.' );
	}

	/**
	 * A user who is both the owner and carries member meta is only counted once.
	 */
	public function test_member_count_dedupes_owner_with_member_meta() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id );
		// Owner also carries member meta (edge case).
		$this->add_member( $owner_id, $sub );
		$this->add_member( $this->create_reader_user(), $sub );

		$this->assertSame(
			2,
			Group_Subscription::get_member_count( $sub ),
			'The owner should be counted once even if they also carry member meta.'
		);
	}

	/**
	 * Capacity is the configured limit plus the owner (limit is "in addition to owner").
	 */
	public function test_member_capacity_is_limit_plus_owner() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 10 );

		$this->assertSame(
			11,
			Group_Subscription::get_member_capacity( $sub ),
			'A limit of 10 should yield a total capacity of 11 (10 members in addition to the owner).'
		);
	}

	/**
	 * Capacity is null when there is no limit (unlimited).
	 */
	public function test_member_capacity_null_when_unlimited() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 0 );

		$this->assertNull(
			Group_Subscription::get_member_capacity( $sub ),
			'A limit of 0 (unlimited) should yield a null capacity.'
		);
	}

	/**
	 * For an ownerless subscription the capacity (denominator) and count (numerator)
	 * agree about the owner: neither counts a phantom owner, so it reads "0 of limit",
	 * not "0 of limit + 1".
	 */
	public function test_member_capacity_excludes_phantom_owner_when_ownerless() {
		// customer_id 0 -> get_managers() returns [0], an empty/phantom owner.
		$sub = $this->create_group_subscription( 0, 10 );

		$this->assertSame(
			0,
			Group_Subscription::get_member_count( $sub ),
			'An ownerless group with no members should report a count of 0.'
		);
		$this->assertSame(
			10,
			Group_Subscription::get_member_capacity( $sub ),
			'An ownerless group should not add a phantom owner to capacity (10, not 11), keeping numerator and denominator consistent.'
		);
	}
}
