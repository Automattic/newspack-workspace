<?php
/**
 * Tests for the Group Subscription classes.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Settings;

/**
 * Tests for the Group Subscription and Group Subscription Invite classes.
 */
class Test_Group_Subscriptions extends \WP_UnitTestCase {

	/**
	 * User IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $user_ids = [];

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];

		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];

		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a WC_Subscription for a given customer and register it in the
	 * global subscriptions database.
	 *
	 * @param int   $customer_id Customer/owner user ID.
	 * @param array $settings    Optional group-subscription settings to apply.
	 *                           Supported keys: 'enabled' (bool), 'limit' (int), 'name' (string).
	 * @return \WC_Subscription
	 */
	private function create_group_subscription( $customer_id, $settings = [] ) {
		$settings  = array_merge(
			[
				'enabled' => true,
				'limit'   => 0,
				'name'    => '',
			],
			$settings
		);
		$sub       = wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		// Enable group subscription directly via meta (mirrors how the settings
		// class stores and retrieves the data).
		if ( $settings['enabled'] ) {
			$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		}
		if ( $settings['limit'] > 0 ) {
			$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', $settings['limit'] );
		}
		if ( ! empty( $settings['name'] ) ) {
			$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', $settings['name'] );
		}
		return $sub;
	}

	/**
	 * Create a plain (non-group) subscription.
	 *
	 * @param int $customer_id Customer/owner user ID.
	 * @return \WC_Subscription
	 */
	private function create_regular_subscription( $customer_id ) {
		return wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
	}

	/**
	 * Create a reader user directly without going through Reader_Activation::register_reader()
	 * (which would authenticate and block subsequent registrations in the same test).
	 *
	 * @param string $email Optional reader email.
	 * @return int User ID.
	 */
	private function create_reader_user( $email = null ) {
		if ( ! $email ) {
			$email = 'reader-' . wp_generate_password( 6, false ) . '@group-test.com';
		}
		$user_id = wp_insert_user(
			[
				'user_login' => 'reader-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $email,
				'role'       => 'subscriber',
			]
		);
		if ( ! is_wp_error( $user_id ) ) {
			// Mark as a reader so Reader_Activation::is_user_reader() returns true.
			update_user_meta( $user_id, '_newspack_reader', true );
			$this->user_ids[] = $user_id;
		}
		return $user_id;
	}

	/**
	 * Create a WordPress administrator user and grant the manage_woocommerce cap.
	 *
	 * @return int User ID.
	 */
	private function create_admin_user() {
		$user_id = wp_insert_user(
			[
				'user_login' => 'test-admin-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'admin-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'administrator',
			]
		);
		// Grant the WooCommerce capability manually since WC is mocked.
		$user = new \WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );

		$this->user_ids[] = $user_id;
		return $user_id;
	}

	// -------------------------------------------------------------------------
	// Group_Subscription tests
	// -------------------------------------------------------------------------

	/**
	 * Test is_group_subscription() returns true for enabled group subscriptions
	 * and false for regular subscriptions.
	 */
	public function test_is_group_subscription() {
		$reader_id   = $this->create_reader_user();
		$group_sub   = $this->create_group_subscription( $reader_id );
		$regular_sub = $this->create_regular_subscription( $reader_id );

		$this->assertTrue(
			Group_Subscription::is_group_subscription( $group_sub ),
			'Enabled group subscription should return true'
		);
		$this->assertFalse(
			Group_Subscription::is_group_subscription( $regular_sub ),
			'Regular subscription without group meta should return false'
		);
	}

	/**
	 * Test is_group_subscription() also accepts a subscription ID.
	 */
	public function test_is_group_subscription_by_id() {
		$reader_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $reader_id );

		$this->assertTrue(
			Group_Subscription::is_group_subscription( $group_sub->get_id() ),
			'Should resolve subscription by ID'
		);
		$this->assertFalse(
			Group_Subscription::is_group_subscription( 99999 ),
			'Non-existent ID should return false'
		);
	}

	/**
	 * Test get_managers() returns the subscription owner.
	 */
	public function test_get_managers() {
		$reader_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $reader_id );

		$managers = Group_Subscription::get_managers( $group_sub );

		$this->assertCount( 1, $managers, 'Should have exactly one manager' );
		$this->assertContains( $reader_id, $managers, 'Owner should be listed as manager' );
	}

	/**
	 * Test get_managers() with a non-existent subscription returns an empty-ish result.
	 */
	public function test_get_managers_invalid_subscription() {
		$managers = Group_Subscription::get_managers( 99999 );
		$this->assertContains( 0, $managers, 'Invalid subscription should return [0]' );
	}

	/**
	 * Test get_members() returns users who have the group subscription meta.
	 */
	public function test_get_members() {
		$owner_id   = $this->create_reader_user();
		$member1_id = $this->create_reader_user();
		$member2_id = $this->create_reader_user();
		$group_sub  = $this->create_group_subscription( $owner_id );

		// Manually add members via the user meta that get_members() queries.
		add_user_meta( $member1_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group_sub->get_id() );
		add_user_meta( $member2_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group_sub->get_id() );

		$members = Group_Subscription::get_members( $group_sub );

		// get_members() may return IDs as strings (raw from DB), so compare after casting.
		$members_int = array_map( 'intval', $members );
		$this->assertCount( 2, $members_int );
		$this->assertContains( (int) $member1_id, $members_int );
		$this->assertContains( (int) $member2_id, $members_int );
	}

	/**
	 * Test get_members() returns empty array for a subscription with no members.
	 */
	public function test_get_members_empty() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$members = Group_Subscription::get_members( $group_sub );

		$this->assertIsArray( $members );
		$this->assertEmpty( $members );
	}

	/**
	 * Test update_members() adds reader users to the group subscription.
	 */
	public function test_update_members_add() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription::update_members( $group_sub, [ $member_id ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'members_added', $result );
		$this->assertArrayHasKey( $member_id, $result['members_added'], 'Member should appear in added list' );

		$members = Group_Subscription::get_members( $group_sub );
		// get_members() may return IDs as strings (raw from DB), so compare after casting.
		$this->assertContains( (int) $member_id, array_map( 'intval', $members ), 'Member should appear in get_members()' );
	}

	/**
	 * Test update_members() skips non-reader users.
	 */
	public function test_update_members_skips_non_readers() {
		$owner_id    = $this->create_reader_user();
		$non_reader  = wp_insert_user(
			[
				'user_login' => 'editor-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'editor-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'editor',
			]
		);
		$this->user_ids[] = $non_reader;
		$group_sub = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription::update_members( $group_sub, [ $non_reader ] );

		$this->assertEmpty( $result['members_added'], 'Non-readers should not be added' );
	}

	/**
	 * Test update_members() does not add a member who is already a member.
	 */
	public function test_update_members_no_duplicates() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		Group_Subscription::update_members( $group_sub, [ $member_id ] );
		$result = Group_Subscription::update_members( $group_sub, [ $member_id ] );

		$this->assertEmpty( $result['members_added'], 'Duplicate member should not be added a second time' );
		$this->assertCount( 1, Group_Subscription::get_members( $group_sub ) );
	}

	/**
	 * Test update_members() returns WP_Error when member limit is reached.
	 */
	public function test_update_members_limit_enforcement() {
		$owner_id  = $this->create_reader_user();
		$member1   = $this->create_reader_user();
		$member2   = $this->create_reader_user();
		// Limit is 1.
		$group_sub = $this->create_group_subscription( $owner_id, [ 'limit' => 1 ] );

		Group_Subscription::update_members( $group_sub, [ $member1 ] );
		$result = Group_Subscription::update_members( $group_sub, [ $member2 ] );

		$this->assertWPError( $result, 'Adding beyond the limit should return WP_Error' );
	}

	/**
	 * Test update_members() removes members correctly.
	 */
	public function test_update_members_remove() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		Group_Subscription::update_members( $group_sub, [ $member_id ] );
		$result = Group_Subscription::update_members( $group_sub, [], [ $member_id ] );

		$this->assertArrayHasKey( $member_id, $result['members_removed'], 'Member should appear in removed list' );
		$this->assertEmpty( Group_Subscription::get_members( $group_sub ), 'Member list should be empty after removal' );
	}

	/**
	 * Test user_is_member() returns true for explicit members and false for the manager.
	 */
	public function test_user_is_member() {
		$owner_id   = $this->create_reader_user();
		$member_id  = $this->create_reader_user();
		$other_id   = $this->create_reader_user();
		$group_sub  = $this->create_group_subscription( $owner_id );

		Group_Subscription::update_members( $group_sub, [ $member_id ] );

		$this->assertFalse(
			Group_Subscription::user_is_member( $owner_id, $group_sub ),
			'Owner/manager should not be considered a member'
		);
		$this->assertTrue(
			Group_Subscription::user_is_member( $member_id, $group_sub ),
			'Added member should be considered a member'
		);
		$this->assertFalse(
			Group_Subscription::user_is_member( $other_id, $group_sub ),
			'Unrelated user should not be considered a member'
		);
	}

	/**
	 * Test user_is_member() returns null when the subscription is not a group subscription.
	 */
	public function test_user_is_member_non_group_subscription() {
		$reader_id   = $this->create_reader_user();
		$regular_sub = $this->create_regular_subscription( $reader_id );

		$result = Group_Subscription::user_is_member( $reader_id, $regular_sub );
		$this->assertNull( $result, 'Should return null for non-group subscriptions' );
	}

	/**
	 * Test user_is_manager() returns true only for the subscription owner.
	 */
	public function test_user_is_manager() {
		$owner_id  = $this->create_reader_user();
		$other_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$this->assertTrue(
			Group_Subscription::user_is_manager( $owner_id, $group_sub ),
			'Owner should be manager'
		);
		$this->assertFalse(
			Group_Subscription::user_is_manager( $other_id, $group_sub ),
			'Non-owner should not be manager'
		);
	}

	/**
	 * Test user_is_manager() returns null for a non-group subscription.
	 */
	public function test_user_is_manager_non_group_subscription() {
		$reader_id   = $this->create_reader_user();
		$regular_sub = $this->create_regular_subscription( $reader_id );

		$result = Group_Subscription::user_is_manager( $reader_id, $regular_sub );
		$this->assertNull( $result, 'Should return null for non-group subscriptions' );
	}

	/**
	 * Test get_group_subscriptions_for_user() returns IDs when $ids_only is true.
	 */
	public function test_get_group_subscriptions_for_user_ids_only() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		Group_Subscription::update_members( $group_sub, [ $member_id ] );

		$ids = Group_Subscription::get_group_subscriptions_for_user( $member_id, true );
		$this->assertContains( $group_sub->get_id(), $ids, 'Should include the group subscription ID' );
	}

	/**
	 * Test get_group_subscriptions_for_user() returns empty array for non-readers.
	 */
	public function test_get_group_subscriptions_for_non_reader() {
		$admin_id = $this->create_admin_user();

		$result = Group_Subscription::get_group_subscriptions_for_user( $admin_id, true );
		$this->assertEmpty( $result, 'Non-reader users should not have group subscriptions' );
	}

	/**
	 * Test get_group_subscriptions_for_user() returns empty when user has no memberships.
	 */
	public function test_get_group_subscriptions_for_user_with_no_memberships() {
		$reader_id = $this->create_reader_user();

		$result = Group_Subscription::get_group_subscriptions_for_user( $reader_id, true );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_group_subscriptions_for_user() filters out user meta entries that
	 * point to subscriptions which no longer exist (e.g. were deleted), so the
	 * returned array never contains false/null items.
	 */
	public function test_get_group_subscriptions_for_user_filters_missing_subscriptions() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		// Legitimate membership.
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group_sub->get_id() );
		// Stale meta pointing to a subscription ID that does not exist in the database.
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, 99999 );

		$subscriptions = Group_Subscription::get_group_subscriptions_for_user( $member_id );

		$this->assertCount( 1, $subscriptions, 'Stale subscription IDs should be filtered out' );
		foreach ( $subscriptions as $subscription ) {
			$this->assertNotEmpty( $subscription, 'Returned items must not be false/null' );
			$this->assertInstanceOf( \WC_Subscription::class, $subscription );
		}

		// IDs-only mode should also exclude the missing subscription.
		$ids = Group_Subscription::get_group_subscriptions_for_user( $member_id, true );
		$this->assertEquals( [ $group_sub->get_id() ], array_values( $ids ) );
		$this->assertNotContains( 99999, $ids, 'Stale subscription IDs should not appear in IDs-only mode' );
	}

	/**
	 * Test get_group_subscriptions_for_user() returns only group subscriptions,
	 * filtering out any meta entries that point to non-group subscriptions.
	 */
	public function test_get_group_subscriptions_for_user_filters_non_group_subscriptions() {
		$owner_id    = $this->create_reader_user();
		$member_id   = $this->create_reader_user();
		$group_sub   = $this->create_group_subscription( $owner_id );
		$regular_sub = $this->create_regular_subscription( $owner_id );

		// Membership in a real group subscription.
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group_sub->get_id() );
		// Meta pointing to a regular (non-group) subscription that should be filtered out.
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $regular_sub->get_id() );

		$subscriptions = Group_Subscription::get_group_subscriptions_for_user( $member_id );

		$this->assertCount( 1, $subscriptions, 'Non-group subscriptions should be filtered out' );
		foreach ( $subscriptions as $subscription ) {
			$this->assertTrue(
				Group_Subscription::is_group_subscription( $subscription ),
				'Every returned subscription must be a group subscription'
			);
		}

		// IDs-only mode should also exclude the regular subscription.
		$ids = Group_Subscription::get_group_subscriptions_for_user( $member_id, true );
		$this->assertEquals( [ $group_sub->get_id() ], array_values( $ids ) );
		$this->assertNotContains( $regular_sub->get_id(), $ids, 'Regular subscription IDs should not appear in IDs-only mode' );
	}

	// -------------------------------------------------------------------------
	// Group_Subscription_Settings name tests
	// -------------------------------------------------------------------------

	/**
	 * Test get_subscription_settings() falls back to the publisher singular group label
	 * when no name meta and no product item are set.
	 */
	public function test_group_name_defaults_to_singular_label() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$settings = Group_Subscription_Settings::get_subscription_settings( $group_sub );

		$this->assertSame( Group_Subscription::get_label( 'singular' ), $settings['name'], 'Default name should fall back to the publisher singular group label when neither a name meta nor a product name is available.' );
	}

	/**
	 * Test get_subscription_settings() returns a custom name when one is stored
	 * in subscription meta.
	 */
	public function test_group_name_custom_override() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id, [ 'name' => 'Acme Newsroom' ] );

		$settings = Group_Subscription_Settings::get_subscription_settings( $group_sub );

		$this->assertEquals( 'Acme Newsroom', $settings['name'] );
	}

	/**
	 * Test update_subscription_settings() persists a name change that is
	 * reflected in subsequent get_subscription_settings() calls.
	 */
	public function test_group_name_update_and_read_back() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		Group_Subscription_Settings::update_subscription_settings(
			$group_sub,
			[ 'name' => 'Daily Planet' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $group_sub );

		$this->assertEquals( 'Daily Planet', $settings['name'] );
	}

	/**
	 * Test that an empty name in update_subscription_settings() stores an
	 * empty string, which causes get_subscription_settings() to fall back
	 * to the publisher singular group label when no product name is available.
	 */
	public function test_group_name_empty_falls_back_to_default() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id, [ 'name' => 'Temp Name' ] );

		Group_Subscription_Settings::update_subscription_settings(
			$group_sub,
			[ 'name' => '' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $group_sub );

		$this->assertSame( Group_Subscription::get_label( 'singular' ), $settings['name'], 'Clearing the name should revert to the publisher singular group label when no product name is available.' );
	}

	// -------------------------------------------------------------------------
	// Group_Subscription_Invite tests
	// -------------------------------------------------------------------------

	/**
	 * Test get_expiration_time() returns the default (30 days).
	 */
	public function test_get_expiration_time_default() {
		$time = Group_Subscription_Invite::get_expiration_time();
		$this->assertEquals( 30 * DAY_IN_SECONDS, $time );
	}

	/**
	 * Test get_expiration_time() respects the filter.
	 */
	public function test_get_expiration_time_filter() {
		$callback = function() {
			return 7 * DAY_IN_SECONDS;
		};

		add_filter(
			'newspack_group_subscription_invite_expiration_time',
			$callback
		);

		$time = Group_Subscription_Invite::get_expiration_time();
		$this->assertEquals( 7 * DAY_IN_SECONDS, $time );

		remove_filter( 'newspack_group_subscription_invite_expiration_time', $callback );
	}

	/**
	 * Test is_invite_expired() returns false for a fresh invite.
	 */
	public function test_is_invite_expired_fresh() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );

		$email  = 'fresh@example.com';
		$invite = Group_Subscription_Invite::generate_invite( $group_sub, $email );

		$this->assertIsArray( $invite );
		$this->assertFalse( Group_Subscription_Invite::is_invite_expired( $invite ) );
	}

	/**
	 * Test generate_invite() returns WP_Error for a non-existent subscription.
	 */
	public function test_generate_invite_invalid_subscription() {
		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$result = Group_Subscription_Invite::generate_invite( 99999, 'test@example.com' );

		$this->assertWPError( $result );
		$this->assertEquals(
			'newspack_group_subscription_invite_invalid_subscription',
			$result->get_error_code()
		);
	}

	/**
	 * Test generate_invite() returns WP_Error when the subscription is not a group subscription.
	 */
	public function test_generate_invite_not_group_subscription() {
		$admin_id    = $this->create_admin_user();
		$reader_id   = $this->create_reader_user();
		$regular_sub = $this->create_regular_subscription( $reader_id );
		wp_set_current_user( $admin_id );

		$result = Group_Subscription_Invite::generate_invite( $regular_sub->get_id(), 'test@example.com' );

		$this->assertWPError( $result );
		$this->assertEquals(
			'newspack_group_subscription_invite_invalid_subscription',
			$result->get_error_code()
		);
	}

	/**
	 * Test generate_invite() returns WP_Error when the email is empty.
	 */
	public function test_generate_invite_empty_email() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), '' );

		$this->assertWPError( $result );
		$this->assertEquals(
			'newspack_group_subscription_invite_invalid_email',
			$result->get_error_code()
		);
	}

	/**
	 * Test generate_invite() returns WP_Error when the email belongs to an existing member.
	 */
	public function test_generate_invite_existing_member() {
		$admin_id     = $this->create_admin_user();
		$owner_id     = $this->create_reader_user();
		$member_email = 'member@example.com';
		$member_id    = $this->create_reader_user( $member_email );
		$group_sub    = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		// Add the user as an existing member of the subscription.
		update_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group_sub->get_id() );

		$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $member_email );

		$this->assertWPError( $result );
		$this->assertEquals(
			'newspack_group_subscription_invite_existing_user',
			$result->get_error_code()
		);
	}

	/**
	 * Test generate_invite() returns WP_Error when the email belongs to a WP user
	 * who is not a Reader Activation reader (e.g. an editor).
	 */
	public function test_generate_invite_non_reader_wp_user() {
		$admin_id     = $this->create_admin_user();
		$owner_id     = $this->create_reader_user();
		$group_sub    = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		// Create a WP user with editor role — not a reader (no _newspack_reader meta,
		// and editor is in the restricted roles list).
		$editor_email = 'editor@example.com';
		$editor_id    = wp_insert_user(
			[
				'user_login' => 'test-editor-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $editor_email,
				'role'       => 'editor',
			]
		);
		$this->user_ids[] = $editor_id;

		$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $editor_email );

		$this->assertWPError( $result );
		$this->assertEquals(
			'newspack_group_subscription_invite_non_reader',
			$result->get_error_code()
		);
	}

	/**
	 * Test generate_invite() succeeds when the current user has manage_woocommerce.
	 */
	public function test_generate_invite_as_woocommerce_admin() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'invite@example.com' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'invite@example.com', $result['email'] );
	}

	/**
	 * Test generate_invite() succeeds when the current user is the subscription manager.
	 */
	public function test_generate_invite_as_subscription_manager() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		// Log in as the subscription owner (the manager).
		wp_set_current_user( $owner_id );

		$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'invite@example.com' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'invite@example.com', $result['email'] );
	}

	/**
	 * Test generate_invite() returns WP_Error when the member limit is reached.
	 */
	public function test_generate_invite_limit_reached() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		// Limit of 1 – one fresh invite will consume the full allowance.
		$group_sub = $this->create_group_subscription( $owner_id, [ 'limit' => 1 ] );
		wp_set_current_user( $admin_id );

		// First invite fills the slot.
		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'first@example.com' );

		// Second invite for a different email should hit the limit.
		$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'second@example.com' );

		$this->assertWPError( $result );
		$this->assertEquals(
			'newspack_group_subscription_invite_limit_reached',
			$result->get_error_code()
		);
	}

	/**
	 * Test generate_invite() succeeds regardless of invite count when limit is 0 (unlimited).
	 */
	public function test_generate_invite_unlimited() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		// limit = 0 means unlimited.
		$group_sub = $this->create_group_subscription( $owner_id, [ 'limit' => 0 ] );
		wp_set_current_user( $admin_id );

		for ( $i = 1; $i <= 5; $i++ ) {
			$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), "user{$i}@example.com" );
			$this->assertIsArray( $result, "Invite #{$i} should succeed when limit is 0" );
		}
	}

	/**
	 * Test generate_invite() replaces an existing invite for the same email
	 * so duplicate invites are not accumulated.
	 */
	public function test_generate_invite_replaces_existing_email_invite() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		$first   = Group_Subscription_Invite::generate_invite( $group_sub, 'repeat@example.com' );
		$second = Group_Subscription_Invite::generate_invite( $group_sub, 'repeat@example.com' );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );

		// There should be exactly one invite for this email in get_invites().
		$invites = Group_Subscription_Invite::get_invites( $group_sub );
		$this->assertCount( 1, $invites, 'Only one invite should exist for the same email' );
		$this->assertEquals( 'repeat@example.com', reset( $invites )['email'] );
	}

	/**
	 * Test generate_invite() stores the invite data on the subscription.
	 */
	public function test_generate_invite_stores_data() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		$result  = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'stored@example.com' );
		$invites = Group_Subscription_Invite::get_invites( $group_sub );

		$this->assertIsArray( $invites );
		$this->assertEquals( 'stored@example.com', reset( $invites )['email'] );
		$this->assertEquals( 'stored@example.com', $result['email'] );
	}

	/**
	 * Test get_invites() returns all invites for a subscription.
	 */
	public function test_get_invites() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'one@example.com' );
		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'two@example.com' );

		$invites = Group_Subscription_Invite::get_invites( $group_sub );

		$this->assertIsArray( $invites );
		$this->assertCount( 2, $invites );
		$this->assertEquals( 'one@example.com', reset( $invites )['email'] );
		$this->assertEquals( 'two@example.com', next( $invites )['email'] );
	}

	/**
	 * Test get_invites() filters out expired invites when $show_expired is false.
	 */
	public function test_get_invites_filters_expired() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$email     = 'expired@example.com';
		wp_set_current_user( $owner_id );

		// Manually store an already-expired timestamp.
		add_filter(
			'newspack_group_subscription_invite_expiration_time',
			function() {
				return -31 * DAY_IN_SECONDS;
			}
		);
		$invite = Group_Subscription_Invite::generate_invite( $group_sub, $email );

		$group_sub->save();

		// With show_expired = false, expired invites should be filtered out.
		$invites = Group_Subscription_Invite::get_invites( $group_sub, false );
		$this->assertEmpty( $invites );

		// With show_expired = true (default), they should appear.
		$invites_all = Group_Subscription_Invite::get_invites( $group_sub, true );
		$this->assertCount( 1, $invites_all );
	}

	/**
	 * Test generate_invite() stores the invite with a valid key.
	 */
	public function test_generate_invite_stores_key() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		$result = Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'key-test@example.com' );

		$this->assertIsArray( $result );
		$invites = Group_Subscription_Invite::get_invites( $group_sub );
		$key     = array_key_first( $invites );
		$this->assertEquals( 32, strlen( $key ), 'Key should be 32 characters' );
	}

	/**
	 * Test get_invite_url() generates a valid URL with expected query params.
	 */
	public function test_get_invite_url() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'url+test@example.com' );
		$invites    = Group_Subscription_Invite::get_invites( $group_sub );
		$invite_key = array_key_first( $invites );
		$url        = Group_Subscription_Invite::get_invite_url( $group_sub->get_id(), $invite_key, 'url+test@example.com' );

		$parsed = wp_parse_url( $url );
		parse_str( $parsed['query'], $query );

		$this->assertEquals( 'group_invite', $query['action'] );
		$this->assertEquals( $invite_key, $query['key'] );
		$this->assertEquals( 'url+test@example.com', $query['email'] );
		$this->assertEquals( (string) $group_sub->get_id(), $query['subscription'] );
	}

	/**
	 * Test get_invite_by_key() returns the invite data for a valid key.
	 */
	public function test_get_invite_by_key() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'key-lookup@example.com' );
		$invites    = Group_Subscription_Invite::get_invites( $group_sub );
		$invite_key = array_key_first( $invites );
		$found      = Group_Subscription_Invite::get_invite_by_key( $group_sub->get_id(), $invite_key );

		$this->assertIsArray( $found );
		$this->assertEquals( 'key-lookup@example.com', $found['email'] );
	}

	/**
	 * Test get_invite_by_key() returns null for an invalid key.
	 */
	public function test_get_invite_by_key_invalid() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$found = Group_Subscription_Invite::get_invite_by_key( $group_sub->get_id(), 'nonexistentkey' );

		$this->assertNull( $found );
	}

	/**
	 * Test cancel_invite() removes a pending invite.
	 */
	public function test_cancel_invite() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'cancel@example.com' );

		$before = Group_Subscription_Invite::get_invites( $group_sub );
		$this->assertEquals( 'cancel@example.com', reset( $before )['email'] );

		$cancelled = Group_Subscription_Invite::cancel_invite( $group_sub->get_id(), 'cancel@example.com' );
		$this->assertTrue( $cancelled );

		$after = Group_Subscription_Invite::get_invites( $group_sub );
		$this->assertEmpty( $after );
	}

	// -------------------------------------------------------------------------
	// Group_Subscription_Invite::accept_invite() tests
	// -------------------------------------------------------------------------

	/**
	 * Test accept_invite() adds the user to the group and deletes the invite.
	 */
	public function test_accept_invite() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$email     = 'accept@example.com';
		$member_id = $this->create_reader_user( $email );
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $email );
		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub ) );
		$result     = Group_Subscription_Invite::accept_invite( $group_sub->get_id(), $invite_key, $email );

		$this->assertTrue( $result );
		$this->assertTrue( Group_Subscription::user_is_member( $member_id, $group_sub ) );
		$this->assertEmpty( Group_Subscription_Invite::get_invites( $group_sub ) );
	}

	/**
	 * Test accept_invite() returns WP_Error for expired invite.
	 */
	public function test_accept_invite_expired() {
		$owner_id  = $this->create_reader_user();
		$email     = 'expired-accept@example.com';
		$this->create_reader_user( $email );
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );

		// Generate an expired invite.
		add_filter(
			'newspack_group_subscription_invite_expiration_time',
			function() {
				return -1;
			}
		);
		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $email );
		remove_all_filters( 'newspack_group_subscription_invite_expiration_time' );

		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub->get_id(), true ) );
		$result     = Group_Subscription_Invite::accept_invite( $group_sub->get_id(), $invite_key, $email );

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_invite_expired', $result->get_error_code() );
	}

	/**
	 * Test accept_invite() returns WP_Error for invalid key.
	 */
	public function test_accept_invite_invalid_key() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription_Invite::accept_invite( $group_sub->get_id(), 'badkey', 'nobody@example.com' );

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_invite_not_found', $result->get_error_code() );
	}

	/**
	 * Test accept_invite() returns WP_Error when email doesn't match the invite.
	 */
	public function test_accept_invite_email_mismatch() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), 'correct@example.com' );
		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub ) );
		$result     = Group_Subscription_Invite::accept_invite( $group_sub->get_id(), $invite_key, 'wrong@example.com' );

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_invite_not_found', $result->get_error_code() );
	}

	/**
	 * Test accept_invite() with a newly created reader user.
	 */
	public function test_accept_invite_new_user() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$email     = 'new-reader@example.com';
		$group_sub = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $admin_id );

		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $email );
		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub ) );

		// Simulate creating the user (as process_invite_request would).
		$new_user_id = $this->create_reader_user( $email );

		$result = Group_Subscription_Invite::accept_invite( $group_sub->get_id(), $invite_key, $email );

		$this->assertTrue( $result );
		$this->assertTrue( Group_Subscription::user_is_member( $new_user_id, $group_sub ) );
	}

	/**
	 * Test accept_invite() respects member limit.
	 */
	public function test_accept_invite_respects_limit() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$member1   = $this->create_reader_user( 'member1@example.com' );
		$email2    = 'member2@example.com';
		$this->create_reader_user( $email2 );
		$group_sub = $this->create_group_subscription( $owner_id, [ 'limit' => 1 ] );
		wp_set_current_user( $admin_id );

		// Fill the single slot.
		Group_Subscription::update_members( $group_sub, [ $member1 ] );

		// Manually store an invite to bypass the limit check in generate_invite.
		$key     = wp_generate_password( 32, false );
		$invites = [
			$key => [
				'added_by'   => $admin_id,
				'email'      => $email2,
				'expiration' => time() + DAY_IN_SECONDS,
			],
		];
		$group_sub->update_meta_data( Group_Subscription_Invite::META, $invites );
		$group_sub->save();

		$result = Group_Subscription_Invite::accept_invite( $group_sub->get_id(), $key, $email2 );

		$this->assertWPError( $result, 'Should fail when member limit is reached' );
	}

	// -------------------------------------------------------------------------
	// search_group_name_where() tests
	// -------------------------------------------------------------------------

	/**
	 * Build a stub WP_Query object representing a subscription search.
	 *
	 * @param string $term Search term.
	 * @return \WP_Query
	 */
	private function build_subscription_search_query( $term ) {
		$query = new \WP_Query();
		$query->set( 's', $term );
		$query->set( 'post_type', 'shop_subscription' );
		$query->is_search = true;
		return $query;
	}

	/**
	 * Test that search_group_name_where() returns the search clause unchanged
	 * when the query is not a subscription search.
	 */
	public function test_search_group_name_where_skips_non_subscription_query() {
		$query = new \WP_Query();
		$query->set( 's', 'foo' );
		$query->set( 'post_type', 'post' );
		$query->is_search = true;

		$original = " AND ( ( wp_posts.post_title LIKE '%foo%' ) ) ";
		$result   = Group_Subscription_Settings::search_group_name_where( $original, $query );

		$this->assertEquals( $original, $result, 'Non-subscription queries should be untouched' );
	}

	/**
	 * Test that search_group_name_where() returns the search clause unchanged
	 * when the search clause is empty (no search performed).
	 */
	public function test_search_group_name_where_skips_empty_search() {
		$query  = $this->build_subscription_search_query( 'foo' );
		$result = Group_Subscription_Settings::search_group_name_where( '', $query );

		$this->assertEquals( '', $result, 'Empty search clause should be returned as-is' );
	}

	/**
	 * Test that search_group_name_where() wraps a simple search clause with
	 * an OR for the group name meta.
	 */
	public function test_search_group_name_where_wraps_simple_clause() {
		$query    = $this->build_subscription_search_query( 'acme' );
		$original = " AND ( ( wp_posts.post_title LIKE '%acme%' ) ) ";
		$result   = Group_Subscription_Settings::search_group_name_where( $original, $query );

		// The original search clause should still be present.
		$this->assertStringContainsString( "wp_posts.post_title LIKE '%acme%'", $result );
		// Our group name meta should be added with an OR.
		$this->assertStringContainsString( 'np_group_name.meta_value LIKE', $result );
		$this->assertStringContainsString( ' OR ', $result );
		// The clause should still start with " AND ".
		$this->assertStringStartsWith( ' AND ', $result );
	}

	/**
	 * Test that search_group_name_where() handles a multi-term search clause
	 * (where WP produces a more complex inner shape).
	 */
	public function test_search_group_name_where_handles_multi_term() {
		$query    = $this->build_subscription_search_query( 'foo bar' );
		$original = " AND ( ( ( wp_posts.post_title LIKE '%foo%' ) ) AND ( ( wp_posts.post_title LIKE '%bar%' ) ) ) ";
		$result   = Group_Subscription_Settings::search_group_name_where( $original, $query );

		// All original sub-clauses should still appear.
		$this->assertStringContainsString( "LIKE '%foo%'", $result );
		$this->assertStringContainsString( "LIKE '%bar%'", $result );
		// Our OR clause should be present.
		$this->assertStringContainsString( 'np_group_name.meta_value LIKE', $result );
		$this->assertStringContainsString( ' OR ', $result );
	}

	/**
	 * Test that search_group_name_where() escapes special characters in the
	 * search term so they don't break out of the SQL string.
	 */
	public function test_search_group_name_where_escapes_special_chars() {
		$query    = $this->build_subscription_search_query( "foo'bar" );
		$original = " AND ( ( wp_posts.post_title LIKE '%foo%' ) ) ";
		$result   = Group_Subscription_Settings::search_group_name_where( $original, $query );

		// The single quote should be escaped (doubled or backslash-escaped) — not appear raw.
		$this->assertStringNotContainsString( "foo'bar'", $result, 'Single quote in term must be escaped' );
		// Our OR clause should still be added.
		$this->assertStringContainsString( 'np_group_name.meta_value LIKE', $result );
	}

	// -------------------------------------------------------------------------
	// get_group_subscription_ids() cache tests
	// -------------------------------------------------------------------------

	/**
	 * Test that get_group_subscription_ids() reads from the transient cache
	 * when present, bypassing the underlying queries.
	 */
	public function test_get_group_subscription_ids_uses_transient_cache() {
		// Pre-seed the transient with a known set of IDs.
		$cached_ids = [ 101, 202, 303 ];
		set_transient(
			Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT,
			$cached_ids,
			5 * MINUTE_IN_SECONDS
		);

		$result = Group_Subscription_Settings::get_group_subscription_ids();

		$this->assertEquals( $cached_ids, $result, 'Should return the cached IDs verbatim' );

		// Cleanup.
		delete_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT );
	}

	/**
	 * Test that clear_group_subscription_ids_cache() deletes the transient.
	 */
	public function test_clear_group_subscription_ids_cache_deletes_transient() {
		set_transient(
			Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT,
			[ 1, 2, 3 ],
			5 * MINUTE_IN_SECONDS
		);

		$this->assertNotFalse(
			get_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT ),
			'Transient should exist before clearing'
		);

		Group_Subscription_Settings::clear_group_subscription_ids_cache();

		$this->assertFalse(
			get_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT ),
			'Transient should be deleted after clearing'
		);
	}

	/**
	 * Test get_link_invite() returns null when none exists.
	 */
	public function test_get_link_invite_returns_null_when_missing() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$this->assertNull( Group_Subscription_Invite::get_link_invite( $group_sub, $owner_id ) );
	}

	/**
	 * Test get_link_invite() returns the stored entry for a user.
	 */
	public function test_get_link_invite_returns_stored_entry() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$entry = [
			'key'        => 'abc123',
			'created_at' => time(),
		];
		$group_sub->update_meta_data( Group_Subscription_Invite::LINK_META, [ $owner_id => $entry ] );
		$group_sub->save();

		$result = Group_Subscription_Invite::get_link_invite( $group_sub, $owner_id );
		$this->assertEquals( $entry, $result );
	}

	/**
	 * Test get_link_invite() returns null for a different user.
	 */
	public function test_get_link_invite_returns_null_for_other_user() {
		$owner_id  = $this->create_reader_user();
		$other_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$group_sub->update_meta_data(
			Group_Subscription_Invite::LINK_META,
			[
				$owner_id => [
					'key'        => 'key',
					'created_at' => time(),
				],
			]
		);
		$group_sub->save();

		$this->assertNull( Group_Subscription_Invite::get_link_invite( $group_sub, $other_id ) );
	}

	/**
	 * Test get_link_invite_url() builds the expected URL.
	 */
	public function test_get_link_invite_url_format() {
		$url = Group_Subscription_Invite::get_link_invite_url( 42, 7, 'thekey' );

		$this->assertStringContainsString( 'action=' . Group_Subscription_Invite::LINK_QUERY_ARG, $url );
		$this->assertStringContainsString( 'subscription=42', $url );
		$this->assertStringContainsString( 'manager=7', $url );
		$this->assertStringContainsString( 'key=thekey', $url );
	}

	/**
	 * Test generate_link_invite() succeeds for a valid manager.
	 */
	public function test_generate_link_invite_success() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );

		$result = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'key', $result );

		// Verify it's persisted.
		$stored = Group_Subscription_Invite::get_link_invite( $group_sub, $owner_id );
		$this->assertEquals( $result['key'], $stored['key'] );
	}

	/**
	 * Test generate_link_invite() rejects a non-group subscription.
	 */
	public function test_generate_link_invite_rejects_non_group_subscription() {
		$owner_id   = $this->create_reader_user();
		$regular    = $this->create_regular_subscription( $owner_id );

		$result = Group_Subscription_Invite::generate_link_invite( $regular, $owner_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_link_invite_invalid_subscription', $result->get_error_code() );
	}

	/**
	 * Test generate_link_invite() rejects a user who is not a manager.
	 */
	public function test_generate_link_invite_rejects_non_manager() {
		$owner_id     = $this->create_reader_user();
		$non_manager  = $this->create_reader_user();
		$group_sub    = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription_Invite::generate_link_invite( $group_sub, $non_manager );
		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_link_invite_not_manager', $result->get_error_code() );
	}

	/**
	 * Test generate_link_invite() replaces an existing entry for the same user.
	 */
	public function test_generate_link_invite_replaces_existing() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );

		$first  = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );
		$second = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		$this->assertNotEquals( $first['key'], $second['key'] );

		$stored = Group_Subscription_Invite::get_link_invite( $group_sub, $owner_id );
		$this->assertEquals( $second['key'], $stored['key'] );
	}

	/**
	 * Test delete_link_invite() removes an existing entry and returns true.
	 */
	public function test_delete_link_invite_success() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );
		Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		$result = Group_Subscription_Invite::delete_link_invite( $group_sub, $owner_id );
		$this->assertTrue( $result );

		$stored = Group_Subscription_Invite::get_link_invite( $group_sub, $owner_id );
		$this->assertNull( $stored );
	}

	/**
	 * Test delete_link_invite() rejects a non-group subscription.
	 */
	public function test_delete_link_invite_rejects_non_group_subscription() {
		$owner_id = $this->create_reader_user();
		$regular  = $this->create_regular_subscription( $owner_id );

		$result = Group_Subscription_Invite::delete_link_invite( $regular, $owner_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_link_invite_invalid_subscription', $result->get_error_code() );
	}

	/**
	 * Test delete_link_invite() rejects a user who is not a manager.
	 */
	public function test_delete_link_invite_rejects_non_manager() {
		$owner_id    = $this->create_reader_user();
		$non_manager = $this->create_reader_user();
		$group_sub   = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription_Invite::delete_link_invite( $group_sub, $non_manager );
		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_link_invite_not_manager', $result->get_error_code() );
	}

	/**
	 * Test delete_link_invite() short-circuits to true without writing meta when
	 * no entry exists for the user.
	 */
	public function test_delete_link_invite_no_op_for_missing_entry() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription_Invite::delete_link_invite( $group_sub, $owner_id );
		$this->assertTrue( $result );

		// Meta should not have been written by the no-op path.
		$meta = $group_sub->get_meta( Group_Subscription_Invite::LINK_META, true );
		$this->assertTrue( '' === $meta || ( is_array( $meta ) && empty( $meta ) ) );
	}

	/**
	 * Test validate_link_invite() returns true for a valid link.
	 */
	public function test_validate_link_invite_valid() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );
		$invite = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		$result = Group_Subscription_Invite::validate_link_invite( $group_sub, $owner_id, $invite['key'] );
		$this->assertTrue( $result );
	}

	/**
	 * Test validate_link_invite() rejects an unknown subscription.
	 */
	public function test_validate_link_invite_unknown_subscription() {
		$result = Group_Subscription_Invite::validate_link_invite( 99999, 1, 'key' );
		$this->assertWPError( $result );
	}

	/**
	 * Test validate_link_invite() rejects a non-group subscription.
	 */
	public function test_validate_link_invite_non_group() {
		$owner_id = $this->create_reader_user();
		$regular  = $this->create_regular_subscription( $owner_id );

		$result = Group_Subscription_Invite::validate_link_invite( $regular, $owner_id, 'key' );
		$this->assertWPError( $result );
	}

	/**
	 * Test validate_link_invite() rejects when no entry exists for the user.
	 */
	public function test_validate_link_invite_no_entry() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription_Invite::validate_link_invite( $group_sub, $owner_id, 'key' );
		$this->assertWPError( $result );
	}

	/**
	 * Test validate_link_invite() rejects a key mismatch.
	 */
	public function test_validate_link_invite_key_mismatch() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );
		Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		$result = Group_Subscription_Invite::validate_link_invite( $group_sub, $owner_id, 'wrong-key' );
		$this->assertWPError( $result );
	}

	/**
	 * Test validate_link_invite() rejects when the manager is no longer a manager.
	 */
	public function test_validate_link_invite_manager_no_longer_manager() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );
		$invite = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		// Use a filter to simulate the user no longer being a manager.
		$callback = function ( $is_manager, $user_id ) use ( $owner_id ) {
			if ( (int) $user_id === (int) $owner_id ) {
				return false;
			}
			return $is_manager;
		};
		add_filter( 'newspack_group_subscription_user_is_manager', $callback, 10, 2 );

		$result = Group_Subscription_Invite::validate_link_invite( $group_sub, $owner_id, $invite['key'] );

		remove_filter( 'newspack_group_subscription_user_is_manager', $callback, 10 );

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_group_subscription_link_invite_not_manager', $result->get_error_code() );
	}

	/**
	 * Smoke test: render_invite_notice() handles a missing result query arg without errors.
	 */
	public function test_render_invite_notice_no_result_does_nothing() {
		// Just verify the function is callable without exploding when nothing is set.
		// If wc_add_notice is not defined in this env, the early return will skip.
		$this->assertNull( Group_Subscription_Invite::render_invite_notice() );
	}

	/**
	 * Test the REST /invite-link endpoint succeeds for a manager.
	 */
	public function test_rest_invite_link_success() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $group_sub->get_id() );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'key', $data );
	}

	/**
	 * Test the REST /invite-link endpoint denies non-managers.
	 */
	public function test_rest_invite_link_permission_denied() {
		$owner_id     = $this->create_reader_user();
		$non_manager  = $this->create_reader_user();
		$group_sub    = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $non_manager );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $group_sub->get_id() );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test the REST /invite-link endpoint returns 404 when the subscription
	 * exists but is not a group subscription. The caller is a WooCommerce
	 * admin so the permission callback passes; the WP_Error from
	 * generate_link_invite() must surface as a 404 status.
	 */
	public function test_rest_invite_link_invalid_subscription_returns_404() {
		$admin_id   = $this->create_admin_user();
		$reader_id  = $this->create_reader_user();
		$regular    = $this->create_regular_subscription( $reader_id );

		wp_set_current_user( $admin_id );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $regular->get_id() );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals(
			'newspack_group_subscription_link_invite_invalid_subscription',
			is_array( $data ) ? ( $data['code'] ?? null ) : null
		);
	}

	/**
	 * Test the REST /invite-link endpoint returns 403 when the caller passes
	 * the permission callback (manage_woocommerce admin) but is not the
	 * manager of the target subscription. The WP_Error from generate_link_invite()
	 * must surface as a 403 status.
	 */
	public function test_rest_invite_link_not_manager_returns_403() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $admin_id );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $group_sub->get_id() );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals(
			'newspack_group_subscription_link_invite_not_manager',
			is_array( $data ) ? ( $data['code'] ?? null ) : null
		);
	}

	/**
	 * Test the REST DELETE /invite-link endpoint succeeds for a manager.
	 */
	public function test_rest_invite_link_delete_success() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );
		do_action( 'rest_api_init' );

		// First generate a link to delete.
		Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		$request = new \WP_REST_Request( 'DELETE', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $group_sub->get_id() );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test the REST DELETE /invite-link endpoint denies non-managers.
	 */
	public function test_rest_invite_link_delete_returns_403_for_non_manager() {
		$owner_id    = $this->create_reader_user();
		$non_manager = $this->create_reader_user();
		$group_sub   = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $non_manager );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'DELETE', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $group_sub->get_id() );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test the REST DELETE /invite-link endpoint returns 404 when the subscription
	 * exists but is not a group subscription. The caller is a WooCommerce admin so
	 * the permission callback passes; the WP_Error from delete_link_invite() must
	 * surface as a 404 status.
	 */
	public function test_rest_invite_link_delete_returns_404_for_invalid_subscription() {
		$admin_id  = $this->create_admin_user();
		$reader_id = $this->create_reader_user();
		$regular   = $this->create_regular_subscription( $reader_id );

		wp_set_current_user( $admin_id );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'DELETE', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $regular->get_id() );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals(
			'newspack_group_subscription_link_invite_invalid_subscription',
			is_array( $data ) ? ( $data['code'] ?? null ) : null
		);
	}

	/**
	 * Test validate_link_invite() rejects when the subscription is no longer active.
	 */
	public function test_validate_link_invite_rejects_inactive_subscription() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $owner_id );
		$invite = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );

		// Cancel the subscription after the invite was generated.
		$group_sub->data['status'] = 'cancelled';

		$result = Group_Subscription_Invite::validate_link_invite( $group_sub, $owner_id, $invite['key'] );
		$this->assertWPError( $result );
		$this->assertEquals(
			'newspack_group_subscription_link_invite_invalid_subscription',
			$result->get_error_code()
		);
	}

	// -------------------------------------------------------------------------
	// process_link_invite_request() tests
	// -------------------------------------------------------------------------

	/**
	 * Test process_link_invite_request() happy path: a logged-in non-member
	 * with a valid link is added to the group and redirected to the
	 * view-subscription URL with success.
	 */
	public function test_process_link_invite_request_happy_path() {
		$owner_id      = $this->create_reader_user();
		$non_member_id = $this->create_reader_user();
		$group_sub     = $this->create_group_subscription( $owner_id );

		// Generate the link as the manager.
		wp_set_current_user( $owner_id );
		$invite = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );
		$this->assertIsArray( $invite );

		// Switch to the visitor clicking the link.
		wp_set_current_user( $non_member_id );

		$_GET = [
			'action'       => Group_Subscription_Invite::LINK_QUERY_ARG,
			'subscription' => $group_sub->get_id(),
			'manager'      => $owner_id,
			'key'          => $invite['key'],
		];

		// Hook wp_redirect to capture the URL and abort before exit.
		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		// Allow the test wc_get_*_url() host through wp_safe_redirect()'s validation.
		// The WC stubs return https://example.com/... which differs from the WP test
		// suite's example.org host, so wp_safe_redirect() would otherwise fall back.
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_link_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString( 'view-subscription', $captured_url, 'Success redirect should target view-subscription' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=success',
				$captured_url,
				'Success redirect should carry the success result'
			);
			$this->assertTrue(
				Group_Subscription::user_is_member( $non_member_id, $group_sub ),
				'Visitor should be added as a member'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_link_invite_request() error path: an invalid key for a
	 * logged-in visitor redirects with link_invalid and does NOT add them.
	 */
	public function test_process_link_invite_request_invalid_key() {
		$owner_id      = $this->create_reader_user();
		$non_member_id = $this->create_reader_user();
		$group_sub     = $this->create_group_subscription( $owner_id );

		// Visitor is logged in (so we hit link_invalid, not login_needed).
		wp_set_current_user( $non_member_id );

		$_GET = [
			'action'       => Group_Subscription_Invite::LINK_QUERY_ARG,
			'subscription' => $group_sub->get_id(),
			'manager'      => $owner_id,
			'key'          => 'bogus-key',
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		// Allow the test wc_get_*_url() host through wp_safe_redirect()'s validation.
		// The WC stubs return https://example.com/... which differs from the WP test
		// suite's example.org host, so wp_safe_redirect() would otherwise fall back.
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_link_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=link_invalid',
				$captured_url,
				'Invalid link should redirect with link_invalid result'
			);
			$this->assertFalse(
				Group_Subscription::user_is_member( $non_member_id, $group_sub ),
				'Visitor must NOT be added when the link is invalid'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_link_invite_request() logged-out branch: a logged-out
	 * visitor with a valid link is bounced to My Account with login_needed and
	 * a redirect= query arg containing the rawurlencoded link URL.
	 */
	public function test_process_link_invite_request_logged_out_bounce() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		// Generate the link as the manager.
		wp_set_current_user( $owner_id );
		$invite = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );
		$this->assertIsArray( $invite );

		// Visitor is logged out.
		wp_set_current_user( 0 );

		$_GET = [
			'action'       => Group_Subscription_Invite::LINK_QUERY_ARG,
			'subscription' => $group_sub->get_id(),
			'manager'      => $owner_id,
			'key'          => $invite['key'],
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		// Allow the test wc_get_*_url() host through wp_safe_redirect()'s validation.
		// The WC stubs return https://example.com/... which differs from the WP test
		// suite's example.org host, so wp_safe_redirect() would otherwise fall back.
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_link_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=login_needed',
				$captured_url,
				'Logged-out branch should redirect with login_needed result'
			);
			$this->assertStringContainsString(
				'redirect=',
				$captured_url,
				'Logged-out redirect should carry a redirect= query arg'
			);
			// The inner link URL's `&subscription=` must appear rawurlencoded as `%26subscription%3D` so the
			// link URL is preserved as a single value rather than leaking into outer args.
			$this->assertStringContainsString(
				'%26subscription%3D',
				$captured_url,
				'Logged-out redirect should rawurlencode the inner link URL'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_link_invite_request() already-member branch: a visitor who
	 * is already a member of the group is sent to the subscription view URL
	 * with success, and is NOT removed from the group.
	 */
	public function test_process_link_invite_request_already_member() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		// Generate the link as the manager.
		wp_set_current_user( $owner_id );
		$invite = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );
		$this->assertIsArray( $invite );

		// Add the visitor as an existing member before they click the link.
		$add_result = Group_Subscription::update_members( $group_sub, [ $member_id ] );
		$this->assertNotInstanceOf( \WP_Error::class, $add_result, 'Pre-test setup should succeed in adding the member' );
		$this->assertTrue(
			Group_Subscription::user_is_member( $member_id, $group_sub ),
			'Pre-test setup: visitor should already be a member'
		);

		// Visitor (already a member) clicks the link.
		wp_set_current_user( $member_id );

		$_GET = [
			'action'       => Group_Subscription_Invite::LINK_QUERY_ARG,
			'subscription' => $group_sub->get_id(),
			'manager'      => $owner_id,
			'key'          => $invite['key'],
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		// Allow the test wc_get_*_url() host through wp_safe_redirect()'s validation.
		// The WC stubs return https://example.com/... which differs from the WP test
		// suite's example.org host, so wp_safe_redirect() would otherwise fall back.
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_link_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=success',
				$captured_url,
				'Already-member branch should redirect with success result'
			);
			$this->assertStringContainsString(
				'view-subscription',
				$captured_url,
				'Already-member redirect should target view-subscription'
			);
			$this->assertTrue(
				Group_Subscription::user_is_member( $member_id, $group_sub ),
				'Already-existing member must remain a member after clicking the link'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_link_invite_request() at-limit branch: when the group has
	 * reached its member limit, a non-member visitor clicking the link is
	 * redirected with link_full and is NOT added to the group.
	 */
	public function test_process_link_invite_request_at_member_limit() {
		$owner_id   = $this->create_reader_user();
		$existing   = $this->create_reader_user();
		$visitor_id = $this->create_reader_user();
		// Limit is 1 — adding $existing fills the group.
		$group_sub = $this->create_group_subscription( $owner_id, [ 'limit' => 1 ] );

		// Generate the link as the manager.
		wp_set_current_user( $owner_id );
		$invite = Group_Subscription_Invite::generate_link_invite( $group_sub, $owner_id );
		$this->assertIsArray( $invite );

		// Fill the group to its limit.
		$add_result = Group_Subscription::update_members( $group_sub, [ $existing ] );
		$this->assertNotInstanceOf( \WP_Error::class, $add_result, 'Pre-test setup should succeed in adding the limit-filling member' );
		$this->assertTrue(
			Group_Subscription::user_is_member( $existing, $group_sub ),
			'Pre-test setup: limit-filling member should be a member'
		);

		// A fresh visitor (not yet a member) clicks the link.
		wp_set_current_user( $visitor_id );

		$_GET = [
			'action'       => Group_Subscription_Invite::LINK_QUERY_ARG,
			'subscription' => $group_sub->get_id(),
			'manager'      => $owner_id,
			'key'          => $invite['key'],
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		// Allow the test wc_get_*_url() host through wp_safe_redirect()'s validation.
		// The WC stubs return https://example.com/... which differs from the WP test
		// suite's example.org host, so wp_safe_redirect() would otherwise fall back.
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_link_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=link_full',
				$captured_url,
				'At-limit branch should redirect with link_full result'
			);
			$this->assertFalse(
				Group_Subscription::user_is_member( $visitor_id, $group_sub ),
				'Visitor must NOT be added when the group is at its member limit'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_link_invite_request() rejects an unknown subscription ID with
	 * link_invalid. (Same validation as test_validate_link_invite_unknown_subscription,
	 * but verified through the full request flow.)
	 */
	public function test_process_link_invite_request_invalid_subscription_id() {
		$visitor_id = $this->create_reader_user();
		wp_set_current_user( $visitor_id );

		$_GET = [
			'action'       => Group_Subscription_Invite::LINK_QUERY_ARG,
			'subscription' => 999999, // Non-existent.
			'manager'      => $visitor_id,
			'key'          => 'whatever',
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_link_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=link_invalid',
				$captured_url,
				'Unknown subscription should redirect with link_invalid result'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	// -------------------------------------------------------------------------
	// process_invite_request() tests (email-invite path)
	// -------------------------------------------------------------------------

	/**
	 * Test process_invite_request() with a missing key/email/subscription triggers
	 * error_invalid_link.
	 */
	public function test_process_invite_request_invalid_link() {
		// Missing key, email, and subscription should trigger the invalid-link branch.
		$_GET = [
			'action' => Group_Subscription_Invite::QUERY_ARG,
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=error_invalid_link',
				$captured_url,
				'Missing query params should redirect with error_invalid_link result'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_invite_request() Case 1 happy path: a logged-in user whose
	 * email matches the invite is added to the group and redirected to the
	 * view-subscription URL with success.
	 */
	public function test_process_invite_request_case1_happy_path() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$email     = 'case1-happy@example.com';
		$member_id = $this->create_reader_user( $email );
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $admin_id );
		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $email );
		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub ) );

		// Switch to the invitee clicking the link.
		wp_set_current_user( $member_id );

		$_GET = [
			'action'       => Group_Subscription_Invite::QUERY_ARG,
			'key'          => $invite_key,
			'email'        => $email,
			'subscription' => $group_sub->get_id(),
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=success',
				$captured_url,
				'Case 1 happy path should redirect with success result'
			);
			$this->assertStringContainsString(
				'view-subscription',
				$captured_url,
				'Case 1 happy path should target view-subscription URL'
			);
			$this->assertTrue(
				Group_Subscription::user_is_member( $member_id, $group_sub ),
				'Invitee should be added as a member'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_invite_request() Case 1 email mismatch: a logged-in user
	 * whose email does NOT match the invite is redirected with
	 * error_email_mismatch and is NOT added to the group.
	 */
	public function test_process_invite_request_case1_email_mismatch() {
		$admin_id      = $this->create_admin_user();
		$owner_id      = $this->create_reader_user();
		$invitee_email = 'correct-email@example.com';
		$logged_in_id  = $this->create_reader_user( 'different-email@example.com' );
		$group_sub     = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $admin_id );
		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $invitee_email );
		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub ) );

		// A different user (wrong email) is logged in.
		wp_set_current_user( $logged_in_id );

		$_GET = [
			'action'       => Group_Subscription_Invite::QUERY_ARG,
			'key'          => $invite_key,
			'email'        => $invitee_email,
			'subscription' => $group_sub->get_id(),
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=error_email_mismatch',
				$captured_url,
				'Email mismatch should redirect with error_email_mismatch result'
			);
			$this->assertFalse(
				Group_Subscription::user_is_member( $logged_in_id, $group_sub ),
				'Mismatched-email visitor must NOT be added'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_invite_request() Case 2: a logged-out visitor whose email
	 * matches an existing user is bounced to My Account with login_needed and
	 * a redirect= query arg containing the rawurlencoded invite URL.
	 */
	public function test_process_invite_request_case2_existing_user_bounce() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$email     = 'case2-existing@example.com';
		$this->create_reader_user( $email ); // Existing user.
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $admin_id );
		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $email );
		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub ) );

		// Visitor is logged out.
		wp_set_current_user( 0 );

		$_GET = [
			'action'       => Group_Subscription_Invite::QUERY_ARG,
			'key'          => $invite_key,
			'email'        => $email,
			'subscription' => $group_sub->get_id(),
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=login_needed',
				$captured_url,
				'Case 2 should redirect with login_needed result'
			);
			$this->assertStringContainsString(
				'redirect=',
				$captured_url,
				'Case 2 redirect should carry a redirect= query arg'
			);
			// The inner invite URL's `&key=` must appear rawurlencoded as `%26key%3D`.
			$this->assertStringContainsString(
				'%26key%3D',
				$captured_url,
				'Case 2 should rawurlencode the inner invite URL'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test process_invite_request() Case 3: a logged-out visitor with no
	 * existing account triggers auto-registration and is added to the group on
	 * success.
	 *
	 * Note: this test exercises Reader_Activation::register_reader(), which
	 * authenticates the new user via cookies. In the test environment those
	 * cookie-setting side effects are harmless but produce a "headers already
	 * sent" warning on some PHP versions when wp_safe_redirect() runs — hence
	 * the redirect filter is hooked at priority 1 to intercept before those
	 * warnings can short-circuit the test.
	 */
	public function test_process_invite_request_case3_new_user_registration() {
		$admin_id  = $this->create_admin_user();
		$owner_id  = $this->create_reader_user();
		$email     = 'case3-new-' . wp_generate_password( 6, false ) . '@example.com';
		$group_sub = $this->create_group_subscription( $owner_id );

		wp_set_current_user( $admin_id );
		Group_Subscription_Invite::generate_invite( $group_sub->get_id(), $email );
		$invite_key = array_key_first( Group_Subscription_Invite::get_invites( $group_sub ) );

		// Visitor is logged out and has no existing account.
		wp_set_current_user( 0 );
		$this->assertFalse( get_user_by( 'email', $email ), 'Pre-test: target email should have no existing account' );

		$_GET = [
			'action'       => Group_Subscription_Invite::QUERY_ARG,
			'key'          => $invite_key,
			'email'        => $email,
			'subscription' => $group_sub->get_id(),
		];

		$captured_url = null;
		$capture      = function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );
		$allow_host = function ( $hosts ) {
			$hosts[] = 'example.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				Group_Subscription_Invite::process_invite_request();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			$this->assertNotNull( $captured_url, 'A redirect URL should have been captured' );
			$this->assertStringContainsString(
				Group_Subscription_Invite::RESULT_QUERY_ARG . '=success',
				$captured_url,
				'Case 3 happy path should redirect with success result'
			);
			$new_user = get_user_by( 'email', $email );
			$this->assertInstanceOf( \WP_User::class, $new_user, 'A new reader user should have been created' );
			$this->user_ids[] = $new_user->ID;
			$this->assertTrue(
				Group_Subscription::user_is_member( $new_user->ID, $group_sub ),
				'Newly registered user should be added as a member'
			);
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_GET = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Test accept_invite() short-circuits to true when the visitor is already a
	 * member of the group, even if the supplied key is invalid. (Prevents
	 * "invalid invitation" errors when an already-joined user re-clicks an old
	 * invite URL.)
	 */
	public function test_accept_invite_returns_true_for_existing_member_even_with_bogus_key() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		// Add the visitor as a member up-front.
		$add_result = Group_Subscription::update_members( $group_sub, [ $member_id ] );
		$this->assertNotInstanceOf( \WP_Error::class, $add_result, 'Pre-test: existing member should be added' );
		$this->assertTrue(
			Group_Subscription::user_is_member( $member_id, $group_sub ),
			'Pre-test: visitor should already be a member'
		);

		// Re-click the (now invalid) invite URL as that member.
		wp_set_current_user( $member_id );
		$result = Group_Subscription_Invite::accept_invite(
			$group_sub->get_id(),
			'bogus-or-stale-key',
			'whatever@example.com'
		);

		$this->assertTrue( $result, 'accept_invite() should short-circuit to true for an already-member visitor' );
		$this->assertTrue(
			Group_Subscription::user_is_member( $member_id, $group_sub ),
			'Existing member must remain a member after re-clicking an invalid invite'
		);
	}

	// -------------------------------------------------------------------------
	// get_expiration_label()
	// -------------------------------------------------------------------------

	/**
	 * Promote to the largest unit that divides exactly; never round.
	 */
	public function test_get_expiration_label_uses_largest_exact_unit() {
		$cases = [
			'default (30 days)' => [ 30 * DAY_IN_SECONDS, '30 days' ],
			'1 week'            => [ WEEK_IN_SECONDS, '1 week' ],
			'2 weeks (14 days)' => [ 14 * DAY_IN_SECONDS, '2 weeks' ],
			'10 days'           => [ 10 * DAY_IN_SECONDS, '10 days' ],
			'1 day'             => [ DAY_IN_SECONDS, '1 day' ],
			'1 hour'            => [ HOUR_IN_SECONDS, '1 hour' ],
			'2 hours'           => [ 2 * HOUR_IN_SECONDS, '2 hours' ],
			'90 minutes'        => [ 90 * MINUTE_IN_SECONDS, '90 minutes' ],
			'15 minutes'        => [ 15 * MINUTE_IN_SECONDS, '15 minutes' ],
			'61 seconds'        => [ 61, '1 minute' ],
			'90 seconds'        => [ 90, '1 minute' ],
		];

		foreach ( $cases as $label => $case ) {
			[ $seconds, $expected ] = $case;
			$callback               = function () use ( $seconds ) {
				return $seconds;
			};
			add_filter( 'newspack_group_subscription_invite_expiration_time', $callback );

			try {
				$this->assertSame(
					$expected,
					Group_Subscription_Invite::get_expiration_label(),
					"Expected '{$expected}' for case: {$label}"
				);
			} finally {
				remove_filter( 'newspack_group_subscription_invite_expiration_time', $callback );
			}
		}
	}

	/**
	 * Sub-minute values are floored at "1 minute".
	 */
	public function test_get_expiration_label_floor_is_one_minute() {
		$callback = function () {
			return 0;
		};
		add_filter( 'newspack_group_subscription_invite_expiration_time', $callback );

		try {
			$this->assertSame( '1 minute', Group_Subscription_Invite::get_expiration_label() );
		} finally {
			remove_filter( 'newspack_group_subscription_invite_expiration_time', $callback );
		}
	}

	/**
	 * Negative expiration times are coerced to the "1 minute" floor.
	 */
	public function test_get_expiration_label_floors_negative_values() {
		$callback = function () {
			return -100;
		};
		add_filter( 'newspack_group_subscription_invite_expiration_time', $callback );

		try {
			$this->assertSame( '1 minute', Group_Subscription_Invite::get_expiration_label() );
		} finally {
			remove_filter( 'newspack_group_subscription_invite_expiration_time', $callback );
		}
	}

	/**
	 * Large counts are formatted via number_format_i18n() (thousands separator under the active locale).
	 */
	public function test_get_expiration_label_uses_number_format_i18n() {
		$callback = function () {
			return 1000 * DAY_IN_SECONDS;
		};
		add_filter( 'newspack_group_subscription_invite_expiration_time', $callback );

		try {
			$this->assertSame( '1,000 days', Group_Subscription_Invite::get_expiration_label() );
		} finally {
			remove_filter( 'newspack_group_subscription_invite_expiration_time', $callback );
		}
	}
}
