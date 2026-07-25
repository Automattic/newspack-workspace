<?php
/**
 * Tests for the Subscribers wizard single-group read endpoint (NPPD-1753).
 *
 * GET /wizard/newspack-subscribers/groups/<id> backs the in-wizard group detail
 * screen. It returns the same shape the collection endpoint returns for a group,
 * plus the detail-only members / invitations / invite-link / billing payloads,
 * with related objects embedded rather than left as ids for the client to resolve.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Settings;

/**
 * GET /wizard/newspack-subscribers/groups/<id>.
 */
class Test_Subscribers_Wizard_Group_Endpoint extends WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-subscribers/groups/';

	/**
	 * Track created user IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Include the WC mocks before the class boots.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';
		// The wizard rides the Access Control feature flag; enable it so its routes register.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Reset the mock databases and register REST routes.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		$this->user_ids         = [];
		Group_Subscription::reset_cache();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: reset databases and delete users.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		Group_Subscription::reset_cache();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		parent::tear_down();
	}

	/**
	 * Create a reader user and track it for cleanup.
	 *
	 * @param string $role Optional role. Defaults to subscriber.
	 *
	 * @return int The new user ID.
	 */
	private function create_reader_user( string $role = 'subscriber' ): int {
		$suffix  = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			[
				'user_login'   => 'reader-' . $suffix,
				'user_pass'    => wp_generate_password(),
				'user_email'   => 'reader-' . $suffix . '@test.com',
				'display_name' => 'Reader ' . $suffix,
				'role'         => $role,
			]
		);
		update_user_meta( $user_id, '_newspack_reader', true );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Create a group subscription owned by $owner_id.
	 *
	 * @param int    $owner_id The owner user ID.
	 * @param int    $limit    Owner-inclusive seat limit.
	 * @param string $status   Subscription status.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, int $limit, string $status = 'active' ): WC_Subscription {
		// The WC mock reads these straight out of its data array with no defaults, so
		// a fixture that omits them errors as soon as subscription_billing() touches
		// it. Stage a complete, realistic subscription rather than patching the
		// shared mock, which several branches are working against concurrently.
		$sub = wcs_create_subscription(
			[
				'customer_id'      => $owner_id,
				'status'           => $status,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => '120.00',
				'currency'         => 'USD',
				'dates'            => [
					'start'                => '2026-01-15 00:00:00',
					'next_payment'         => '2026-08-15 00:00:00',
					'last_order_date_paid' => '2026-07-15 00:00:00',
					'cancelled'            => 0,
				],
			]
		);
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', (string) $limit );
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', 'Acme Team' );
		Group_Subscription::reset_cache();
		return $sub;
	}

	/**
	 * Add $member_id as a plain member of $subscription.
	 *
	 * @param int             $member_id    The member user ID.
	 * @param WC_Subscription $subscription The group subscription.
	 */
	private function add_member( int $member_id, WC_Subscription $subscription ): void {
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Group_Subscription::reset_cache();
	}

	/**
	 * Dispatch the single-group endpoint as the current user.
	 *
	 * @param int $subscription_id The group subscription ID.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch( int $subscription_id ): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE . $subscription_id ) );
	}

	/**
	 * The detail payload carries the collection group shape plus the members list,
	 * with the owner and each member embedded as full objects (not ids the client
	 * has to resolve).
	 */
	public function test_returns_group_detail_with_embedded_members() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$group     = $this->create_group_subscription( $owner_id, 5 );
		$this->add_member( $member_id, $group );

		$response = $this->dispatch( $group->get_id() );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		// The collection shape is preserved so both screens read one contract.
		$this->assertSame( $group->get_id(), $data['id'] );
		$this->assertSame( $owner_id, $data['ownerId'] );
		$this->assertSame( $owner_id, $data['owner']['id'] );
		$this->assertSame( 'Acme Team', $data['plan'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( 5, $data['seatLimit'] );
		$this->assertSame( 2, $data['members'], 'The owner-inclusive member count is preserved from the collection shape.' );

		// Detail-only: the people, embedded.
		$this->assertCount( 2, $data['memberList'] );
		$member_ids = array_column( $data['memberList'], 'id' );
		$this->assertContains( $owner_id, $member_ids );
		$this->assertContains( $member_id, $member_ids );
		foreach ( $data['memberList'] as $person ) {
			$this->assertArrayHasKey( 'name', $person, 'Each member is embedded with their display name.' );
			$this->assertArrayHasKey( 'email', $person );
			$this->assertArrayHasKey( 'role', $person );
			$this->assertArrayHasKey( 'editUrl', $person );
		}
	}

	/**
	 * Members are returned owner first, then managers, then plain members — the order
	 * the detail table renders in, resolved server-side so the client does not have to
	 * re-derive the role ranking.
	 */
	public function test_member_list_is_sorted_owner_then_managers_then_members() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id   = $this->create_reader_user();
		$member_id  = $this->create_reader_user();
		$manager_id = $this->create_reader_user();
		$group      = $this->create_group_subscription( $owner_id, 5 );
		// Add the plain member first, so a stable sort would leave it ahead of the manager.
		$this->add_member( $member_id, $group );
		$this->add_member( $manager_id, $group );
		Group_Subscription::add_manager( $group, $manager_id );
		Group_Subscription::reset_cache();

		$data = $this->dispatch( $group->get_id() )->get_data();

		$this->assertSame(
			[ 'owner', 'manager', 'member' ],
			array_column( $data['memberList'], 'role' ),
			'The member list must be ordered owner → managers → members.'
		);
		$this->assertSame(
			[ $owner_id, $manager_id, $member_id ],
			array_column( $data['memberList'], 'id' ),
			'The ordering must place each person by role, not by when they joined.'
		);
	}

	/**
	 * Pending email invitations are surfaced, with expired ones flagged rather than
	 * dropped — an admin needs to see a lapsed invite in order to resend or cancel it.
	 */
	public function test_returns_invitations_with_expiry_state() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id = $this->create_reader_user();
		$group    = $this->create_group_subscription( $owner_id, 5 );
		Group_Subscription_Invite::generate_invite( $group, 'invitee@test.com' );

		$data = $this->dispatch( $group->get_id() )->get_data();

		$this->assertCount( 1, $data['invites'] );
		$invite = $data['invites'][0];
		$this->assertSame( 'invitee@test.com', $invite['email'] );
		$this->assertSame( 'pending', $invite['status'], 'A fresh invite is pending, not expired.' );
		$this->assertArrayHasKey( 'sentAt', $invite );
		// The row is identified by email, and the acceptance key is NOT emitted: the
		// key + email pair is a working accept URL, so leaking it here would let anyone
		// who captured the admin response consume the invitation without inbox access.
		$this->assertSame( 'invitee@test.com', $invite['id'], 'The row is identified by email, not by the acceptance key.' );
		$stored_keys = array_keys( Group_Subscription_Invite::get_invites( $group ) );
		$this->assertNotContains( $invite['id'], $stored_keys, 'The raw acceptance key must never appear in the response.' );
		foreach ( $invite as $value ) {
			$this->assertNotContains( (string) $value, $stored_keys, 'No field in the invite row may carry the acceptance key.' );
		}
	}

	/**
	 * The shareable invite link reports whether one is currently active, so the
	 * screen can offer Regenerate / Disable only when there is a link to act on.
	 */
	public function test_reports_invite_link_state() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id = $this->create_reader_user();
		$group    = $this->create_group_subscription( $owner_id, 5 );

		$before = $this->dispatch( $group->get_id() )->get_data();
		$this->assertFalse( $before['inviteLink']['active'], 'No link exists until one is minted.' );

		Group_Subscription_Invite::generate_link_invite( $group, $owner_id );
		Group_Subscription::reset_cache();

		$after = $this->dispatch( $group->get_id() )->get_data();
		$this->assertTrue( $after['inviteLink']['active'], 'A minted link is reported as active.' );
		$this->assertNotEmpty( $after['inviteLink']['url'], 'The active link carries the shareable URL.' );
	}

	/**
	 * `seatsReserved` is the floor the seat limit cannot be set below: everyone
	 * holding a seat plus every outstanding invite.
	 */
	public function test_reports_reserved_seats_including_pending_invites() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id = $this->create_reader_user();
		$group    = $this->create_group_subscription( $owner_id, 6 );
		$this->add_member( $this->create_reader_user(), $group );
		Group_Subscription_Invite::generate_invite( $group, 'invitee@test.com' );

		$data = $this->dispatch( $group->get_id() )->get_data();

		// Owner + 1 member + 1 pending invite.
		$this->assertSame( 3, $data['seatsReserved'], 'Outstanding invites hold a seat and count towards the floor.' );
	}

	/**
	 * A seat limit of 0 is the unlimited sentinel and must survive the round-trip
	 * as 0, not be normalised into a number of seats.
	 */
	public function test_unlimited_seat_limit_is_reported_as_zero() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id = $this->create_reader_user();
		$group    = $this->create_group_subscription( $owner_id, 0 );

		$data = $this->dispatch( $group->get_id() )->get_data();

		$this->assertSame( 0, $data['seatLimit'], '0 means unlimited and must round-trip unchanged.' );
	}

	/**
	 * The billing shape the "View subscription" drawer renders comes from the same
	 * helper the person profile's card uses, with dates as bare calendar days so the
	 * client can anchor them at UTC and show a stable day in any timezone.
	 */
	public function test_returns_billing_shape_for_the_drawer() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id = $this->create_reader_user();
		$group    = $this->create_group_subscription( $owner_id, 5 );

		$billing = $this->dispatch( $group->get_id() )->get_data()['billing'];

		$this->assertSame( 120.0, $billing['amount'] );
		$this->assertSame( 'USD', $billing['currency'] );
		$this->assertSame( 'month', $billing['billingPeriod'] );
		$this->assertSame( 1, $billing['billingInterval'] );
		$this->assertSame( '2026-08-15', $billing['nextBillingDate'], 'Dates travel as bare Y-m-d calendar days.' );
		$this->assertSame( '2026-07-15', $billing['lastPayment'] );
		$this->assertNull( $billing['endDate'], 'A live subscription has neither a cancellation nor an end date.' );
	}

	/**
	 * A subscription that is not group-enabled is not a group, so the detail screen
	 * gets a 404 rather than a half-populated group.
	 */
	public function test_404_for_non_group_subscription() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$plain = wcs_create_subscription(
			[
				'customer_id'    => $this->create_reader_user(),
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);

		$this->assertSame( 404, $this->dispatch( $plain->get_id() )->get_status() );
	}

	/**
	 * An id that resolves to nothing is a 404.
	 */
	public function test_404_for_unknown_id() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$this->assertSame( 404, $this->dispatch( 999999 )->get_status() );
	}

	/**
	 * NEGATIVE — a non-admin is refused, matching the collection endpoint. The detail
	 * payload exposes every member's email address, so this gate is load-bearing.
	 */
	public function test_forbidden_for_non_admin() {
		$owner_id = $this->create_reader_user();
		$group    = $this->create_group_subscription( $owner_id, 5 );
		wp_set_current_user( $this->create_reader_user( 'subscriber' ) );

		$this->assertSame( 403, $this->dispatch( $group->get_id() )->get_status() );
	}

	/**
	 * NEGATIVE — the group's own owner is not an admin, so they cannot read the
	 * admin detail payload either. Their view of the group is My Account.
	 */
	public function test_forbidden_for_the_group_owner() {
		$owner_id = $this->create_reader_user();
		$group    = $this->create_group_subscription( $owner_id, 5 );
		wp_set_current_user( $owner_id );

		$this->assertSame( 403, $this->dispatch( $group->get_id() )->get_status() );
	}
}
