<?php
/**
 * Tests for Group_Subscription_API REST state gating.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription_API;
use Newspack\Group_Subscription_Settings;

/**
 * Test that the REST member/invite-link endpoints reject subscriptions in states
 * the admin-post UI also refuses (terminal states for member changes; non-active
 * for new invite links). See NPPD-1593 (S2, S3).
 */
class Test_Group_Subscription_API extends WP_UnitTestCase {

	/**
	 * Include WC mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Owner/manager user ID, set per test.
	 *
	 * @var int
	 */
	private $owner_id = 0;

	/**
	 * Reset state between tests and create an owner/manager user.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		$this->owner_id         = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Reset state between tests.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a group subscription in a given status, owned by the manager user.
	 *
	 * @param string $status Subscription status.
	 * @return WC_Subscription
	 */
	private function create_group_subscription( string $status = 'active' ): WC_Subscription {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $this->owner_id,
				'status'         => $status,
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		return $subscription;
	}

	/**
	 * Build a REST request carrying a subscription_id param.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return WP_REST_Request
	 */
	private function request_for( int $subscription_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/members' );
		$request->set_param( 'subscription_id', $subscription_id );
		return $request;
	}

	/**
	 * S2: member mutation must be rejected on a terminal-state (cancelled) subscription.
	 */
	public function test_update_members_rejected_on_cancelled_subscription() {
		$subscription = $this->create_group_subscription( 'cancelled' );
		$request      = $this->request_for( $subscription->get_id() );
		$request->set_param( 'members_to_add', [ 999 ] );

		$result = Group_Subscription_API::api_update_members( $request );

		$this->assertWPError( $result, 'Member mutation on a cancelled subscription should return a WP_Error.' );
		$this->assertSame( 409, $result->get_error_data()['status'], 'The rejection should carry HTTP 409.' );
	}

	/**
	 * S2: member mutation must be rejected on an expired subscription.
	 */
	public function test_update_members_rejected_on_expired_subscription() {
		$subscription = $this->create_group_subscription( 'expired' );
		$request      = $this->request_for( $subscription->get_id() );
		$request->set_param( 'members_to_add', [ 999 ] );

		$result = Group_Subscription_API::api_update_members( $request );

		$this->assertWPError( $result, 'Member mutation on an expired subscription should return a WP_Error.' );
	}

	/**
	 * S2: an active subscription passes the manageable gate (no 409).
	 */
	public function test_update_members_allowed_on_active_subscription() {
		$subscription = $this->create_group_subscription( 'active' );
		$request      = $this->request_for( $subscription->get_id() );
		$request->set_param( 'members_to_add', [] );
		$request->set_param( 'members_to_remove', [] );

		$result = Group_Subscription_API::api_update_members( $request );

		$this->assertNotWPError( $result, 'An active subscription should pass the manageable gate.' );
	}

	/**
	 * Email invitations (api_invite) must also be rejected on a non-active subscription,
	 * for parity with api_generate_invite_link.
	 */
	public function test_email_invite_rejected_on_cancelled_subscription() {
		$subscription = $this->create_group_subscription( 'cancelled' );
		wp_set_current_user( $this->owner_id );
		$request = $this->request_for( $subscription->get_id() );
		$request->set_param( 'email', 'invitee@test.com' );

		$result = Group_Subscription_API::api_invite( $request );

		$this->assertWPError( $result, 'An email invite on a cancelled subscription should return a WP_Error.' );
		$this->assertSame( 409, $result->get_error_data()['status'], 'The rejection should carry HTTP 409.' );
	}

	/**
	 * S3: generating an invite link must be rejected on a non-active (cancelled) subscription.
	 *
	 * The current user is the owner/manager, so the only thing that can reject the request is the
	 * active-state gate (not the manager permission check inside generate_link_invite()).
	 */
	public function test_generate_invite_link_rejected_on_cancelled_subscription() {
		$subscription = $this->create_group_subscription( 'cancelled' );
		wp_set_current_user( $this->owner_id );
		$request = $this->request_for( $subscription->get_id() );

		$result = Group_Subscription_API::api_generate_invite_link( $request );

		$this->assertWPError( $result, 'Generating an invite link on a cancelled subscription should return a WP_Error.' );
		$this->assertSame( 409, $result->get_error_data()['status'], 'The rejection should carry HTTP 409.' );
	}

	/**
	 * S3: an on-hold subscription is not active (only active/pending-cancel are), so issuing a
	 * new invite link must be rejected even for the manager.
	 */
	public function test_generate_invite_link_rejected_on_on_hold_subscription() {
		$subscription = $this->create_group_subscription( 'on-hold' );
		wp_set_current_user( $this->owner_id );
		$request = $this->request_for( $subscription->get_id() );

		$result = Group_Subscription_API::api_generate_invite_link( $request );

		$this->assertWPError( $result, 'Generating an invite link on an on-hold subscription should return a WP_Error.' );
	}

	/**
	 * S3: an active subscription passes the active gate and mints a link (manager is current user).
	 */
	public function test_generate_invite_link_allowed_on_active_subscription() {
		$subscription = $this->create_group_subscription( 'active' );
		wp_set_current_user( $this->owner_id );
		$request = $this->request_for( $subscription->get_id() );

		$result = Group_Subscription_API::api_generate_invite_link( $request );

		$this->assertNotWPError( $result, 'An active subscription should pass the active gate and mint an invite link.' );
		$data = $result->get_data();
		$this->assertIsArray( $data, 'A successful response should carry the minted invite link data.' );
		$this->assertArrayHasKey( 'key', $data, 'The minted invite link should include a key.' );
	}

	/**
	 * S3: a pending-cancel subscription is still active enough to issue invitations
	 * (ACTIVE_SUBSCRIPTION_STATUSES includes pending-cancel), so the gate must allow it.
	 */
	public function test_generate_invite_link_allowed_on_pending_cancel_subscription() {
		$subscription = $this->create_group_subscription( 'pending-cancel' );
		wp_set_current_user( $this->owner_id );
		$request = $this->request_for( $subscription->get_id() );

		$result = Group_Subscription_API::api_generate_invite_link( $request );

		$this->assertNotWPError( $result, 'A pending-cancel subscription should still pass the active gate.' );
	}
}
