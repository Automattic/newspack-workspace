<?php
/**
 * Tests for Group_Subscription manager storage (DSGNEWS-184 / NPPD-1815).
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Test the manager role data layer: promote/demote, reverse lookups, and the
 * membership-removal cleanup.
 */
class Test_Group_Subscription_Managers extends WP_UnitTestCase {

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Include WC mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset state between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		Group_Subscription::reset_cache();
	}

	/**
	 * Reset state between tests.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		parent::tear_down();
	}

	/**
	 * Create a reader user (member fixture).
	 *
	 * @return int User ID.
	 */
	private function create_reader(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'user-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'user-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * Create an active, group-enabled subscription owned by $owner_id.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		return $subscription;
	}

	/**
	 * Promoting an existing member via add_manager(): get_managers() returns
	 * the owner plus the manager, and user_is_manager() flips to true.
	 */
	public function test_add_manager_promotes_a_member() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );

		$this->assertFalse( Group_Subscription::user_is_manager( $member_id, $subscription ), 'A plain member is not a manager.' );

		$result = Group_Subscription::add_manager( $subscription, $member_id );

		$this->assertTrue( $result, 'Promoting a member should succeed.' );
		$this->assertEqualsCanonicalizing( [ $owner_id, $member_id ], Group_Subscription::get_managers( $subscription ), 'Managers are the owner plus the promoted member.' );
		$this->assertTrue( Group_Subscription::user_is_manager( $member_id, $subscription ), 'The promoted member is a manager.' );
	}

	/**
	 * The owner (implicit manager) and non-members are rejected by add_manager().
	 */
	public function test_add_manager_rejects_owner_and_non_members() {
		$owner_id     = $this->create_reader();
		$outsider_id  = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );

		$this->assertWPError( Group_Subscription::add_manager( $subscription, $owner_id ), 'The owner cannot be stored as a manager.' );
		$this->assertWPError( Group_Subscription::add_manager( $subscription, $outsider_id ), 'A non-member cannot be made a manager.' );
		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ), 'Managers remain owner-only.' );
	}

	/**
	 * Demoting via remove_manager() returns the manager to a plain member; the
	 * owner is protected.
	 */
	public function test_remove_manager_demotes() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		Group_Subscription::add_manager( $subscription, $member_id );

		$this->assertTrue( Group_Subscription::remove_manager( $subscription, $member_id ), 'Demoting a manager should succeed.' );
		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ), 'Managers are back to owner-only.' );
		$this->assertTrue( Group_Subscription::user_is_member( $member_id, $subscription ), 'The demoted manager remains a member.' );
		$this->assertWPError( Group_Subscription::remove_manager( $subscription, $owner_id ), 'The owner cannot be demoted.' );
	}

	/**
	 * Removing a member from the group clears their manager meta too.
	 */
	public function test_removing_a_member_clears_their_manager_role() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		Group_Subscription::add_manager( $subscription, $member_id );

		Group_Subscription::update_members( $subscription, [], [ $member_id ] );

		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ), 'Leaving the group ends the manager role.' );
		$this->assertEmpty(
			get_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY, false ),
			'No orphaned manager meta remains.'
		);
	}

	/**
	 * A promoted manager's managed-subscriptions lookup includes the group they
	 * manage without owning — this is what lights up their My Account access.
	 */
	public function test_managed_subscriptions_include_manager_of_groups() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );

		Group_Subscription::reset_cache();
		$this->assertNotContains( $subscription->get_id(), Group_Subscription::get_managed_subscriptions_for_user( $member_id, true ), 'A plain member manages nothing.' );

		Group_Subscription::add_manager( $subscription, $member_id );

		$this->assertContains( $subscription->get_id(), Group_Subscription::get_managed_subscriptions_for_user( $member_id, true ), 'A promoted manager manages the group.' );

		Group_Subscription::remove_manager( $subscription, $member_id );

		$this->assertNotContains( $subscription->get_id(), Group_Subscription::get_managed_subscriptions_for_user( $member_id, true ), 'A demoted manager loses the group.' );
	}
}
