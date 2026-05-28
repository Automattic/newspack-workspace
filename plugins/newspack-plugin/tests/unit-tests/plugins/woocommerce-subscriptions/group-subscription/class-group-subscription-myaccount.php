<?php
/**
 * Tests for Group_Subscription_MyAccount My Account integration.
 *
 * @package Newspack\Tests
 * @group group-subscription-myaccount
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_MyAccount;
use Newspack\Group_Subscription_Settings;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Test file intentionally mixes stub functions with test class.

// Stub is_account_page() so we can control it in tests.
// The real function is provided by WooCommerce and absent in the test environment.
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

// Stub wc_get_endpoint_url() used by get_manage_members_url().
if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
	/**
	 * Stub for WooCommerce wc_get_endpoint_url().
	 *
	 * @param string $endpoint  The endpoint slug.
	 * @param string $value     Optional endpoint value.
	 * @param string $permalink Optional base permalink.
	 * @return string
	 */
	function wc_get_endpoint_url( $endpoint, $value = '', $permalink = '' ) {
		return $permalink . $endpoint . '/' . $value;
	}
}

// Stub wc_get_page_permalink() used by get_manage_members_url().
if ( ! function_exists( 'wc_get_page_permalink' ) ) {
	/**
	 * Stub for WooCommerce wc_get_page_permalink().
	 *
	 * @param string $page The page slug.
	 * @return string
	 */
	function wc_get_page_permalink( $page ) {
		return 'https://example.com/my-account/';
	}
}

/**
 * Test Group_Subscription_MyAccount My Account integration.
 */
class Test_Group_Subscription_MyAccount extends WP_UnitTestCase {

	/**
	 * User IDs tracked for teardown.
	 *
	 * @var int[]
	 */
	protected $user_ids = [];

	/**
	 * Set up: simulate being on the account page.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['newspack_test_is_account_page'] = true;
	}

	/**
	 * Tear down: reset globals and subscriptions DB.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];

		unset( $GLOBALS['newspack_test_is_account_page'] );

		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];

		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ---- Helpers ----

	/**
	 * Create a reader user.
	 *
	 * @param string $email Optional email address.
	 * @return int User ID.
	 */
	private function create_reader_user( string $email = '' ): int {
		if ( ! $email ) {
			$email = 'reader-' . wp_generate_password( 6, false ) . '@test.com';
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
			update_user_meta( $user_id, '_newspack_reader', true );
			$this->user_ids[] = $user_id;
		}
		return $user_id;
	}

	/**
	 * Create a group subscription owned by $customer_id.
	 *
	 * @param int $customer_id The customer/owner user ID.
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $customer_id ): WC_Subscription {
		$sub = wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		return $sub;
	}

	/**
	 * Create a regular (non-group) subscription owned by $customer_id.
	 *
	 * @param int $customer_id The customer/owner user ID.
	 * @return WC_Subscription
	 */
	private function create_regular_subscription( int $customer_id ): WC_Subscription {
		return wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
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

	// ---- inject_member_group_subscriptions tests ----

	/**
	 * Group subscriptions the user is a member of are injected into the list.
	 */
	public function test_inject_member_group_subscriptions_adds_group_sub() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $group_sub );

		// Start with an empty list (member has no owned subscriptions).
		$result = Group_Subscription_MyAccount::inject_member_group_subscriptions( [], $member_id );

		$this->assertArrayHasKey(
			$group_sub->get_id(),
			$result,
			'Group subscription should be injected for the member'
		);
	}

	/**
	 * A subscription already in the list is not duplicated when the member is injected.
	 */
	public function test_inject_does_not_duplicate_existing_subscription() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $group_sub );

		// Pre-populate $existing with the group sub — simulates the sub already being present.
		$existing = [ $group_sub->get_id() => $group_sub ];
		$result   = Group_Subscription_MyAccount::inject_member_group_subscriptions( $existing, $member_id );

		$this->assertCount( 1, $result, 'Should not duplicate subscription already in list.' );
	}

	/**
	 * Injection is skipped when not on an account page.
	 */
	public function test_inject_skipped_when_not_on_account_page() {
		$GLOBALS['newspack_test_is_account_page'] = false;

		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $group_sub );

		$result = Group_Subscription_MyAccount::inject_member_group_subscriptions( [], $member_id );

		$this->assertEmpty( $result, 'Should not inject when not on account page' );
	}

	/**
	 * Trashed group subscriptions are excluded.
	 */
	public function test_inject_excludes_trashed_subscriptions() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();

		// Create a trashed group subscription.
		$trashed_sub = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'trash',
				'billing_period' => 'month',
			]
		);
		$trashed_sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$this->add_member( $member_id, $trashed_sub );

		$result = Group_Subscription_MyAccount::inject_member_group_subscriptions( [], $member_id );

		$this->assertArrayNotHasKey(
			$trashed_sub->get_id(),
			$result,
			'Trashed subscription should not be injected'
		);
	}

	// ---- grant_group_member_view_order_cap tests ----

	/**
	 * Group members receive the read cap when view_order is checked on a group subscription.
	 */
	public function test_grant_view_order_cap_for_group_member() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $group_sub );

		$result = Group_Subscription_MyAccount::grant_group_member_view_order_cap(
			[ 'manage_woocommerce' ],
			'view_order',
			$member_id,
			[ $group_sub->get_id() ]
		);

		$this->assertEquals( [ 'read' ], $result, 'Group member should receive read cap for view_order' );
	}

	/**
	 * Non-members do not receive an elevated cap for view_order.
	 */
	public function test_does_not_grant_view_order_cap_for_non_member() {
		$owner_id  = $this->create_reader_user();
		$stranger  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$original_caps = [ 'manage_woocommerce' ];
		$result        = Group_Subscription_MyAccount::grant_group_member_view_order_cap(
			$original_caps,
			'view_order',
			$stranger,
			[ $group_sub->get_id() ]
		);

		$this->assertEquals( $original_caps, $result, 'Non-member should not receive elevated caps' );
	}

	/**
	 * Cap is not granted when the request is outside of the My Account page.
	 */
	public function test_grant_view_order_cap_skipped_off_account_page() {
		$GLOBALS['newspack_test_is_account_page'] = false;

		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $group_sub );

		$original_caps = [ 'manage_woocommerce' ];
		$result        = Group_Subscription_MyAccount::grant_group_member_view_order_cap(
			$original_caps,
			'view_order',
			$member_id,
			[ $group_sub->get_id() ]
		);

		$this->assertEquals( $original_caps, $result, 'Should not grant cap outside account page' );
	}

	/**
	 * Caps other than view_order are passed through unchanged.
	 */
	public function test_grant_view_order_cap_ignores_other_caps() {
		$member_id     = $this->create_reader_user();
		$original_caps = [ 'manage_woocommerce' ];

		$result = Group_Subscription_MyAccount::grant_group_member_view_order_cap(
			$original_caps,
			'edit_posts',
			$member_id,
			[ 999 ]
		);

		$this->assertEquals( $original_caps, $result, 'Non-view_order caps should be passed through unchanged' );
	}

	// ---- view_subscription_actions tests ----

	/**
	 * Non-manager group members should receive an empty actions array.
	 */
	public function test_view_subscription_actions_empty_for_non_manager_member() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $group_sub );

		$result = Group_Subscription_MyAccount::view_subscription_actions(
			[
				'cancel' => [
					'url'  => '#',
					'name' => 'Cancel',
				],
			],
			$group_sub,
			$member_id
		);

		$this->assertEmpty( $result, 'Non-manager group members should see no actions' );
	}

	/**
	 * Managers (subscription owners) receive a "Manage members" action.
	 */
	public function test_view_subscription_actions_adds_manage_members_for_manager() {
		$owner_id  = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );

		$result = Group_Subscription_MyAccount::view_subscription_actions(
			[],
			$group_sub,
			$owner_id
		);

		$this->assertArrayHasKey( 'manage_members', $result, 'Manager should see Manage members action' );
		$this->assertStringContainsString( 'manage-members', $result['manage_members']['url'] );
	}

	/**
	 * Regular (non-group) subscriptions pass through unchanged.
	 */
	public function test_view_subscription_actions_unchanged_for_regular_subscription() {
		$owner_id    = $this->create_reader_user();
		$regular_sub = $this->create_regular_subscription( $owner_id );
		$actions     = [
			'cancel' => [
				'url'  => '#',
				'name' => 'Cancel',
			],
		];

		$result = Group_Subscription_MyAccount::view_subscription_actions(
			$actions,
			$regular_sub,
			$owner_id
		);

		$this->assertEquals( $actions, $result, 'Regular subscription actions should be returned unchanged' );
	}

	/**
	 * Actions pass through unchanged when not on the account page.
	 */
	public function test_view_subscription_actions_unchanged_off_account_page() {
		$GLOBALS['newspack_test_is_account_page'] = false;

		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group_sub = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $group_sub );
		$actions = [
			'cancel' => [
				'url'  => '#',
				'name' => 'Cancel',
			],
		];

		$result = Group_Subscription_MyAccount::view_subscription_actions(
			$actions,
			$group_sub,
			$member_id
		);

		$this->assertEquals( $actions, $result, 'Actions should be unchanged when not on account page' );
	}
}
