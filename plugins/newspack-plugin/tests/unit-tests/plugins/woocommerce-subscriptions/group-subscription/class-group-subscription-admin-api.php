<?php
/**
 * Tests for the admin-facing group-subscription write paths (NPPD-1753).
 *
 * The Subscribers wizard's group detail screen writes through the same
 * `newspack-group-subscription/v1` API the reader-facing My Account UX uses, so
 * these tests pin two things at once:
 *
 * 1. The role model the admin surface must not widen — owner-only may promote or
 *    demote a manager; a manager may touch plain members only; nobody but a store
 *    admin may move the seat limit.
 * 2. That the owner/manager paths My Account already ships behave exactly as they
 *    did before the admin surface existed. A regression here breaks real readers,
 *    so those assertions come first in each group.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_API;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Settings;

/**
 * Permission boundaries and behaviour of the group-subscription write endpoints.
 */
class Test_Group_Subscription_Admin_API extends WP_UnitTestCase {

	/**
	 * Include the WC mocks before the class boots.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the mock subscription store between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		Group_Subscription::reset_cache();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
	}

	/**
	 * Reset state between tests.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		wp_set_current_user( 0 );
		Group_Subscription::reset_cache();
		parent::tear_down();
	}

	/**
	 * Create a reader user.
	 *
	 * @return int User ID.
	 */
	private function create_reader(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * Create a reader who also holds `manage_woocommerce` — a store admin, which is
	 * what the Subscribers wizard's admin acts as against this API.
	 *
	 * @return int User ID.
	 */
	private function create_store_admin(): int {
		$user_id = $this->create_reader();
		get_user_by( 'id', $user_id )->add_cap( 'manage_woocommerce' );
		return $user_id;
	}

	/**
	 * Create a group subscription with a seat limit, owned by $owner_id.
	 *
	 * @param int    $owner_id The owner user ID.
	 * @param int    $limit    Owner-inclusive seat limit (0 = unlimited).
	 * @param string $status   Subscription status.
	 *
	 * @return WC_Subscription
	 */
	private function create_group( int $owner_id, int $limit = 5, string $status = 'active' ): WC_Subscription {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => $status,
				'billing_period' => 'month',
				// A real price, so the "seats are not a money action" test has a
				// billing figure it can watch for movement.
				'total'          => '120.00',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', (string) $limit );
		Group_Subscription::reset_cache();
		return $subscription;
	}

	/**
	 * Make $user_id a plain member of $subscription.
	 *
	 * @param int             $user_id      The member user ID.
	 * @param WC_Subscription $subscription The group subscription.
	 */
	private function add_member( int $user_id, WC_Subscription $subscription ): void {
		add_user_meta( $user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Group_Subscription::reset_cache();
	}

	/**
	 * Make $user_id a member and then a manager of $subscription.
	 *
	 * @param int             $user_id      The user ID.
	 * @param WC_Subscription $subscription The group subscription.
	 */
	private function add_manager( int $user_id, WC_Subscription $subscription ): void {
		$this->add_member( $user_id, $subscription );
		Group_Subscription::add_manager( $subscription, $user_id );
		Group_Subscription::reset_cache();
	}

	/**
	 * Build a role-change request.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $user_id         Target member.
	 * @param string $role            'manager' or 'member'.
	 *
	 * @return WP_REST_Request
	 */
	private function role_request( int $subscription_id, int $user_id, string $role ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/manager' );
		$request->set_param( 'subscription_id', $subscription_id );
		$request->set_param( 'user_id', $user_id );
		$request->set_param( 'role', $role );
		return $request;
	}

	/**
	 * Build a seat-limit request.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $limit           New owner-inclusive seat limit.
	 *
	 * @return WP_REST_Request
	 */
	private function seat_limit_request( int $subscription_id, int $limit ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/seat-limit' );
		$request->set_param( 'subscription_id', $subscription_id );
		$request->set_param( 'limit', $limit );
		return $request;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Role changes: owner-only, and the admin acting on the owner's behalf.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The owner may promote one of their members. This is the shipped My Account
	 * behaviour and must not shift.
	 */
	public function test_owner_may_change_roles() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$group     = $this->create_group( $owner_id );
		$this->add_member( $member_id, $group );

		wp_set_current_user( $owner_id );

		$this->assertTrue(
			Group_Subscription_API::role_permission_callback( $this->role_request( $group->get_id(), $member_id, 'manager' ) ),
			'The owner must be allowed to change roles in their own group.'
		);
	}

	/**
	 * NEGATIVE — a manager must not be able to promote a plain member. Promotion is
	 * the owner's call alone; a manager who could promote could manufacture peers
	 * and route around the peer-manager guard on removal.
	 */
	public function test_manager_may_not_promote_a_member() {
		$owner_id   = $this->create_reader();
		$manager_id = $this->create_reader();
		$member_id  = $this->create_reader();
		$group      = $this->create_group( $owner_id );
		$this->add_manager( $manager_id, $group );
		$this->add_member( $member_id, $group );

		wp_set_current_user( $manager_id );
		$request = $this->role_request( $group->get_id(), $member_id, 'manager' );

		// The general group-management callback DOES admit this manager — that is
		// what lets them add and remove plain members. Asserting it here proves the
		// role guard below is doing real work rather than passing vacuously: if
		// /manager were ever wired to the general callback, this test fails.
		$this->assertTrue(
			Group_Subscription_API::permission_callback( $request ),
			'A manager is admitted by the general group-management callback.'
		);
		$this->assertFalse(
			Group_Subscription_API::role_permission_callback( $request ),
			'A manager must not be able to promote a member to manager.'
		);
	}

	/**
	 * NEGATIVE — a manager must not be able to demote a peer manager either.
	 */
	public function test_manager_may_not_demote_a_peer_manager() {
		$owner_id        = $this->create_reader();
		$manager_id      = $this->create_reader();
		$peer_manager_id = $this->create_reader();
		$group           = $this->create_group( $owner_id );
		$this->add_manager( $manager_id, $group );
		$this->add_manager( $peer_manager_id, $group );

		wp_set_current_user( $manager_id );

		$this->assertFalse(
			Group_Subscription_API::role_permission_callback( $this->role_request( $group->get_id(), $peer_manager_id, 'member' ) ),
			'A manager must not be able to demote a peer manager.'
		);
	}

	/**
	 * NEGATIVE — a plain member reaches nothing.
	 */
	public function test_plain_member_may_not_change_roles() {
		$owner_id      = $this->create_reader();
		$member_id     = $this->create_reader();
		$other_member  = $this->create_reader();
		$group         = $this->create_group( $owner_id );
		$this->add_member( $member_id, $group );
		$this->add_member( $other_member, $group );

		wp_set_current_user( $member_id );

		$this->assertFalse(
			Group_Subscription_API::role_permission_callback( $this->role_request( $group->get_id(), $other_member, 'manager' ) ),
			'A plain member must not be able to change roles.'
		);
	}

	/**
	 * NEGATIVE — a reader with no relationship to the group reaches nothing.
	 */
	public function test_outsider_may_not_change_roles() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$group     = $this->create_group( $owner_id );
		$this->add_member( $member_id, $group );

		wp_set_current_user( $this->create_reader() );

		$this->assertFalse(
			Group_Subscription_API::role_permission_callback( $this->role_request( $group->get_id(), $member_id, 'manager' ) ),
			'A reader outside the group must not be able to change roles.'
		);
	}

	/**
	 * NEGATIVE — a logged-out request must not read as the owner of an ownerless
	 * group. Mirrors the uid-0 guard in can_actor_remove_member().
	 */
	public function test_logged_out_actor_may_not_change_roles_on_ownerless_group() {
		$member_id = $this->create_reader();
		$group     = $this->create_group( 0 );
		$this->add_member( $member_id, $group );

		wp_set_current_user( 0 );

		$this->assertFalse(
			Group_Subscription_API::role_permission_callback( $this->role_request( $group->get_id(), $member_id, 'manager' ) ),
			'A logged-out actor (uid 0) must not match an ownerless group (owner 0).'
		);
	}

	/**
	 * A store admin — the capability the Subscribers wizard's admin holds — may
	 * change roles on behalf of the owner. This is the admin path the wizard needs.
	 */
	public function test_store_admin_may_change_roles() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$group     = $this->create_group( $owner_id );
		$this->add_member( $member_id, $group );

		wp_set_current_user( $this->create_store_admin() );

		$this->assertTrue(
			Group_Subscription_API::role_permission_callback( $this->role_request( $group->get_id(), $member_id, 'manager' ) ),
			'A store admin must be able to change roles on behalf of the owner.'
		);
	}

	/**
	 * Promotion persists: the member joins the manager list and the owner stays on it.
	 */
	public function test_promote_persists_manager() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$group     = $this->create_group( $owner_id );
		$this->add_member( $member_id, $group );
		wp_set_current_user( $owner_id );

		$response = Group_Subscription_API::api_set_manager_role( $this->role_request( $group->get_id(), $member_id, 'manager' ) );

		$this->assertNotWPError( $response, 'Promoting a member should succeed.' );
		Group_Subscription::reset_cache();
		$managers = array_map( 'intval', Group_Subscription::get_managers( $group ) );
		$this->assertContains( $member_id, $managers, 'The promoted member should now be a manager.' );
		$this->assertContains( $owner_id, $managers, 'The owner remains a manager: ownership implies management.' );
	}

	/**
	 * Demotion persists: the manager drops back to a plain member and keeps their seat.
	 */
	public function test_demote_persists_and_keeps_membership() {
		$owner_id   = $this->create_reader();
		$manager_id = $this->create_reader();
		$group      = $this->create_group( $owner_id );
		$this->add_manager( $manager_id, $group );
		wp_set_current_user( $owner_id );

		$response = Group_Subscription_API::api_set_manager_role( $this->role_request( $group->get_id(), $manager_id, 'member' ) );

		$this->assertNotWPError( $response, 'Demoting a manager should succeed.' );
		Group_Subscription::reset_cache();
		$this->assertNotContains(
			$manager_id,
			array_map( 'intval', Group_Subscription::get_managers( $group ) ),
			'The demoted manager should no longer be a manager.'
		);
		$this->assertContains(
			$manager_id,
			array_map( 'intval', Group_Subscription::get_members( $group ) ),
			'Demotion must not remove the person from the group.'
		);
	}

	/**
	 * The owner can never be demoted, whoever asks — ownership implies management.
	 */
	public function test_owner_cannot_be_demoted_by_admin() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id );
		wp_set_current_user( $this->create_store_admin() );

		$response = Group_Subscription_API::api_set_manager_role( $this->role_request( $group->get_id(), $owner_id, 'member' ) );

		$this->assertWPError( $response, 'Demoting the owner must be refused even for a store admin.' );
	}

	/**
	 * A role change on a terminal-state group is refused with 409, matching the
	 * state gate the member/invite endpoints already apply.
	 */
	public function test_role_change_rejected_on_cancelled_group() {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$group     = $this->create_group( $owner_id, 5, 'cancelled' );
		$this->add_member( $member_id, $group );
		wp_set_current_user( $owner_id );

		$response = Group_Subscription_API::api_set_manager_role( $this->role_request( $group->get_id(), $member_id, 'manager' ) );

		$this->assertWPError( $response, 'A role change on a cancelled group should be refused.' );
		$this->assertSame( 409, $response->get_error_data()['status'], 'The rejection should carry HTTP 409.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Peer-manager removal guard — regression on shipped My Account behaviour.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * REGRESSION — a manager may still remove a plain member, and still may not
	 * remove a peer manager. The admin surface must not have moved either.
	 */
	public function test_manager_removal_rights_are_unchanged() {
		$owner_id        = $this->create_reader();
		$manager_id      = $this->create_reader();
		$peer_manager_id = $this->create_reader();
		$member_id       = $this->create_reader();
		$group           = $this->create_group( $owner_id );
		$this->add_manager( $manager_id, $group );
		$this->add_manager( $peer_manager_id, $group );
		$this->add_member( $member_id, $group );

		$this->assertTrue(
			Group_Subscription::can_actor_remove_member( $manager_id, $member_id, $group ),
			'A manager may still remove a plain member.'
		);
		$this->assertFalse(
			Group_Subscription::can_actor_remove_member( $manager_id, $peer_manager_id, $group ),
			'A manager still may not remove a peer manager.'
		);
		$this->assertFalse(
			Group_Subscription::can_actor_remove_member( $manager_id, $owner_id, $group ),
			'Nobody may remove the owner.'
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Seat limit — admin-only, and never a money action.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * NEGATIVE — the owner may not move their own seat limit. Capacity is sold to
	 * them; changing it is a publisher decision, so it has no My Account equivalent
	 * and the owner must not reach it through the shared API.
	 */
	public function test_owner_may_not_adjust_seat_limit() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id );
		wp_set_current_user( $owner_id );

		$this->assertFalse(
			Group_Subscription_API::admin_permission_callback( $this->seat_limit_request( $group->get_id(), 10 ) ),
			'The group owner must not be able to change their own seat limit.'
		);
	}

	/**
	 * NEGATIVE — a manager certainly may not.
	 */
	public function test_manager_may_not_adjust_seat_limit() {
		$owner_id   = $this->create_reader();
		$manager_id = $this->create_reader();
		$group      = $this->create_group( $owner_id );
		$this->add_manager( $manager_id, $group );
		wp_set_current_user( $manager_id );
		$request = $this->seat_limit_request( $group->get_id(), 10 );

		// Same proof as the role guard: the general callback admits this manager, so
		// the admin-only guard below is what is actually keeping them out.
		$this->assertTrue(
			Group_Subscription_API::permission_callback( $request ),
			'A manager is admitted by the general group-management callback.'
		);
		$this->assertFalse(
			Group_Subscription_API::admin_permission_callback( $request ),
			'A manager must not be able to change the seat limit.'
		);
	}

	/**
	 * A subscription that is not group-enabled has no seat limit to move, so the
	 * route 404s rather than silently writing limit meta onto a non-group. Mirrors
	 * the read endpoint and the /manager route.
	 */
	public function test_seat_limit_404_for_non_group_subscription() {
		$plain = wcs_create_subscription(
			[
				'customer_id'    => $this->create_reader(),
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		wp_set_current_user( $this->create_store_admin() );

		$response = Group_Subscription_API::api_update_seat_limit( $this->seat_limit_request( $plain->get_id(), 10 ) );

		$this->assertWPError( $response, 'A seat-limit write on a non-group subscription must be refused.' );
		$this->assertSame( 404, $response->get_error_data()['status'], 'The rejection should carry HTTP 404.' );
		$this->assertSame(
			'',
			(string) $plain->get_meta( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit' ),
			'No limit meta may be written onto a non-group subscription.'
		);
	}

	/**
	 * A store admin may raise the limit, and the change persists.
	 */
	public function test_store_admin_may_raise_seat_limit() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id, 5 );
		wp_set_current_user( $this->create_store_admin() );

		$this->assertTrue(
			Group_Subscription_API::admin_permission_callback( $this->seat_limit_request( $group->get_id(), 10 ) ),
			'A store admin must be able to change the seat limit.'
		);
		$response = Group_Subscription_API::api_update_seat_limit( $this->seat_limit_request( $group->get_id(), 10 ) );

		$this->assertNotWPError( $response, 'Raising the seat limit should succeed.' );
		$this->assertSame( 10, $response->get_data()['seatLimit'], 'The response should echo the stored limit.' );
		$this->assertSame(
			10,
			Group_Subscription_Settings::get_subscription_settings( $group )['limit'],
			'The new limit should be persisted to the subscription settings.'
		);
	}

	/**
	 * The floor is what the group has already committed — members plus outstanding
	 * invites — so a reduction can never strand an obligation the group has made.
	 */
	public function test_seat_limit_cannot_drop_below_committed_seats() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id, 5 );
		$this->add_member( $this->create_reader(), $group );
		$this->add_member( $this->create_reader(), $group );
		wp_set_current_user( $this->create_store_admin() );

		// Owner + 2 members = 3 seats committed; 2 is below the floor.
		$response = Group_Subscription_API::api_update_seat_limit( $this->seat_limit_request( $group->get_id(), 2 ) );

		$this->assertWPError( $response, 'A limit below the committed seat count must be refused.' );
		$this->assertSame( 400, $response->get_error_data()['status'], 'The rejection should carry HTTP 400.' );
		$this->assertSame(
			5,
			Group_Subscription_Settings::get_subscription_settings( $group )['limit'],
			'A refused change must leave the stored limit untouched.'
		);
	}

	/**
	 * Zero is not "no seats" — it is the unlimited sentinel, so it is always
	 * accepted regardless of how many seats are committed.
	 */
	public function test_seat_limit_zero_means_unlimited() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id, 5 );
		$this->add_member( $this->create_reader(), $group );
		$this->add_member( $this->create_reader(), $group );
		wp_set_current_user( $this->create_store_admin() );

		$response = Group_Subscription_API::api_update_seat_limit( $this->seat_limit_request( $group->get_id(), 0 ) );

		$this->assertNotWPError( $response, 'Setting the limit to 0 (unlimited) must be accepted, not read as "no seats".' );
		$this->assertSame( 0, $response->get_data()['seatLimit'], 'Unlimited is stored as 0.' );
	}

	/**
	 * Admin acting for an owner is a maintenance capability, not a billing one:
	 * moving the seat limit must not touch the subscription's money fields.
	 */
	public function test_seat_limit_change_does_not_touch_billing() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id, 5 );
		wp_set_current_user( $this->create_store_admin() );

		$total_before  = $group->get_total();
		$status_before = $group->get_status();

		Group_Subscription_API::api_update_seat_limit( $this->seat_limit_request( $group->get_id(), 25 ) );

		$after = wcs_get_subscription( $group->get_id() );
		$this->assertSame( $total_before, $after->get_total(), 'Adjusting seats must not change what the group is billed.' );
		$this->assertSame( $status_before, $after->get_status(), 'Adjusting seats must not change the subscription status.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Invite link — which manager the link is minted for.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * REGRESSION — for the owner, the link is still minted under the owner's own id.
	 * This is the shipped My Account behaviour; if the admin-path resolver ever
	 * shifted it, every owner's outstanding link would change hands.
	 */
	public function test_invite_link_manager_is_self_for_owner() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id );
		wp_set_current_user( $owner_id );

		$this->assertSame(
			$owner_id,
			Group_Subscription_API::resolve_link_manager_id( $group ),
			'The owner must still mint their own link.'
		);
	}

	/**
	 * REGRESSION — for a manager, the link is still minted under the manager's own id.
	 */
	public function test_invite_link_manager_is_self_for_manager() {
		$owner_id   = $this->create_reader();
		$manager_id = $this->create_reader();
		$group      = $this->create_group( $owner_id );
		$this->add_manager( $manager_id, $group );
		wp_set_current_user( $manager_id );

		$this->assertSame(
			$manager_id,
			Group_Subscription_API::resolve_link_manager_id( $group ),
			'A manager must still mint their own link.'
		);
	}

	/**
	 * An admin is never a manager of the group, so minting under their own id would
	 * produce a link that validate_link_invite() rejects at click time. The admin
	 * acts on the owner's link instead — the same link the owner sees in My Account.
	 */
	public function test_invite_link_manager_is_owner_for_admin() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id );
		wp_set_current_user( $this->create_store_admin() );

		$this->assertSame(
			$owner_id,
			Group_Subscription_API::resolve_link_manager_id( $group ),
			'An admin must operate on the owner\'s invite link, not mint a dead one under their own id.'
		);
	}

	/**
	 * The link an admin mints is usable: it validates at click time, which a link
	 * keyed to the admin's own (non-manager) id would not.
	 */
	public function test_admin_minted_invite_link_validates_at_click_time() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id );
		wp_set_current_user( $this->create_store_admin() );

		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/invite-link' );
		$request->set_param( 'subscription_id', $group->get_id() );
		$response = Group_Subscription_API::api_generate_invite_link( $request );

		$this->assertNotWPError( $response, 'An admin should be able to mint an invite link.' );
		$link = $response->get_data();
		$this->assertTrue(
			Group_Subscription_Invite::validate_link_invite( $group, $owner_id, $link['key'] ),
			'The admin-minted link must validate against the owner, so an invitee clicking it gets in.'
		);
	}

	/**
	 * NEGATIVE — a reader who is neither a manager nor an admin resolves to nothing,
	 * so no link can be minted on their behalf.
	 */
	public function test_invite_link_manager_is_zero_for_outsider() {
		$owner_id = $this->create_reader();
		$group    = $this->create_group( $owner_id );
		wp_set_current_user( $this->create_reader() );

		$this->assertSame(
			0,
			Group_Subscription_API::resolve_link_manager_id( $group ),
			'An outsider must resolve to no manager at all.'
		);
	}
}
