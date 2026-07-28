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

	/**
	 * Build a /name request carrying a subscription_id and a name (NPPD-1813).
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $name            Group name to set.
	 * @return WP_REST_Request
	 */
	private function rename_request_for( int $subscription_id, string $name ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/name' );
		$request->set_param( 'subscription_id', $subscription_id );
		$request->set_param( 'name', $name );
		return $request;
	}

	/**
	 * Renaming persists a custom group name, and the response echoes the resolved name.
	 */
	public function test_update_name_persists_custom_name() {
		$subscription = $this->create_group_subscription( 'active' );
		$request      = $this->rename_request_for( $subscription->get_id(), 'Marketing Team' );

		$result = Group_Subscription_API::api_update_name( $request );

		$this->assertNotWPError( $result, 'Renaming an active group should succeed.' );
		$this->assertSame( 'Marketing Team', $result->get_data()['name'], 'The response should echo the saved name.' );
		$this->assertSame(
			'Marketing Team',
			Group_Subscription_Settings::get_subscription_settings( $subscription )['name'],
			'The custom name should be persisted to the subscription settings.'
		);
	}

	/**
	 * Surrounding whitespace is trimmed before the name is stored.
	 */
	public function test_update_name_trims_whitespace() {
		$subscription = $this->create_group_subscription( 'active' );
		$request      = $this->rename_request_for( $subscription->get_id(), '  Spaced Team  ' );

		$result = Group_Subscription_API::api_update_name( $request );

		$this->assertSame( 'Spaced Team', $result->get_data()['name'], 'Leading/trailing whitespace should be trimmed from the saved name.' );
	}

	/**
	 * Clearing the name drops the override. With no product on the test subscription, the
	 * resolved name falls through the product-name step to the default singular group label
	 * (in production a real subscription has a product, so it would land on the product name first).
	 */
	public function test_update_name_empty_resets_to_label_when_no_product() {
		$subscription = $this->create_group_subscription( 'active' );
		// Give it a custom name first, then clear it.
		Group_Subscription_API::api_update_name( $this->rename_request_for( $subscription->get_id(), 'Temporary Name' ) );

		$result = Group_Subscription_API::api_update_name( $this->rename_request_for( $subscription->get_id(), '   ' ) );

		$this->assertSame(
			\Newspack\Group_Subscription::get_label( 'singular' ),
			$result->get_data()['name'],
			'Clearing the name with no product should fall back to the default singular group label.'
		);
	}

	/**
	 * Renaming to a name that happens to equal the currently-inherited name must still pin
	 * the override, so a later change to the inherited source can't silently rename the
	 * reader's group underneath them (NPPD-1813).
	 *
	 * Reproduced here via the label fallback (the test subscriptions carry no product); in
	 * production the same drift happens via the product-name step of the fallback chain.
	 */
	public function test_update_name_pins_override_matching_the_inherited_name() {
		$subscription   = $this->create_group_subscription( 'active' );
		$inherited_name = \Newspack\Group_Subscription::get_label( 'singular' );

		// The reader types the name they currently see, which equals the inherited fallback.
		Group_Subscription_API::api_update_name( $this->rename_request_for( $subscription->get_id(), $inherited_name ) );

		// The publisher then renames the underlying source the fallback resolves to.
		update_option( 'newspack_group_subscription_label_singular', 'Team' );

		$this->assertSame(
			$inherited_name,
			Group_Subscription_Settings::get_subscription_settings( $subscription )['name'],
			'A name the reader explicitly saved must stay pinned when the inherited source changes.'
		);
	}

	/**
	 * An over-long name is capped to GROUP_NAME_MAX_LENGTH so it can't break the header/picker layout.
	 */
	public function test_update_name_caps_length() {
		$subscription = $this->create_group_subscription( 'active' );
		$request      = $this->rename_request_for( $subscription->get_id(), str_repeat( 'a', Group_Subscription_Settings::GROUP_NAME_MAX_LENGTH + 50 ) );

		$result = Group_Subscription_API::api_update_name( $request );

		$this->assertSame(
			Group_Subscription_Settings::GROUP_NAME_MAX_LENGTH,
			mb_strlen( $result->get_data()['name'] ),
			'The saved name should be capped to GROUP_NAME_MAX_LENGTH.'
		);
	}

	/**
	 * Renaming is metadata-only and is NOT state-gated: it stays allowed on a
	 * cancelled subscription so an owner can still tell their groups apart in the picker.
	 */
	public function test_update_name_allowed_on_cancelled_subscription() {
		$subscription = $this->create_group_subscription( 'cancelled' );
		$request      = $this->rename_request_for( $subscription->get_id(), 'Archived Team' );

		$result = Group_Subscription_API::api_update_name( $request );

		$this->assertNotWPError( $result, 'Renaming should be allowed regardless of subscription status.' );
		$this->assertSame( 'Archived Team', $result->get_data()['name'], 'The name should persist even on a cancelled subscription.' );
	}

	/**
	 * A missing/invalid subscription returns a 404 WP_Error, the contract the JS relies on.
	 */
	public function test_update_name_returns_404_for_invalid_subscription() {
		$result = Group_Subscription_API::api_update_name( $this->rename_request_for( 999999, 'Nobody\'s Group' ) );

		$this->assertWPError( $result, 'An unresolvable subscription should yield a WP_Error.' );
		$this->assertSame( 404, $result->get_error_data()['status'], 'The error should carry HTTP 404.' );
	}

	/**
	 * Dispatch through the registered route as a fresh REST server, exercising the
	 * permission_callback and arg sanitization the direct-call tests bypass.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $name            Group name to set.
	 * @return WP_REST_Response
	 */
	private function dispatch_rename( int $subscription_id, string $name ): WP_REST_Response {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/name' );
		$request->set_param( 'subscription_id', $subscription_id );
		$request->set_param( 'name', $name );
		return $wp_rest_server->dispatch( $request );
	}

	/**
	 * The /name route's permission_callback rejects a reader who isn't the group's manager.
	 */
	public function test_rest_route_denies_non_manager() {
		$subscription = $this->create_group_subscription( 'active' );
		$non_manager  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $non_manager );

		$response = $this->dispatch_rename( $subscription->get_id(), 'Hijacked' );

		$this->assertSame( 403, $response->get_status(), 'A non-manager reader must not be able to rename the group.' );
		$this->assertSame(
			'',
			$subscription->get_meta( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', true ),
			'A rejected request must not change the stored name.'
		);
	}

	/**
	 * The route's sanitize_callback (sanitize_text_field) strips markup before the name is stored.
	 */
	public function test_rest_route_strips_markup_from_name() {
		$subscription = $this->create_group_subscription( 'active' );
		wp_set_current_user( $this->owner_id );

		$response = $this->dispatch_rename( $subscription->get_id(), '<b>Marketing Team</b>' );

		$this->assertSame( 200, $response->get_status(), 'The group manager should be allowed to rename.' );
		$this->assertSame( 'Marketing Team', $response->get_data()['name'], 'Markup should be stripped from the saved name.' );
	}
}
