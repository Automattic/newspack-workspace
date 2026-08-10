<?php
/**
 * Tests for the Subscribers wizard groups read endpoint.
 *
 * @package Newspack\Tests
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * GET /wizard/newspack-subscribers/groups.
 *
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */
class Test_Subscribers_Wizard_Groups_Endpoint extends WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-subscribers/groups';

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
	 * Create a group subscription owned by $owner_id, enabled with a seat limit.
	 *
	 * @param int    $owner_id The owner user ID.
	 * @param int    $limit    Seat limit (owner-inclusive).
	 * @param string $status   Subscription status.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, int $limit, string $status = 'active' ): WC_Subscription {
		$sub = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => $status,
				'billing_period' => 'month',
			]
		);
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', (string) $limit );
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', 'Acme Team' );
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
	}

	/**
	 * Dispatch the groups endpoint as the current user.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch(): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );
	}

	/**
	 * An admin gets the group list in the { items, total, pages } envelope, with each
	 * group hydrated with owner, plan, status, seat limit, owner-inclusive member count
	 * and created date.
	 */
	public function test_returns_hydrated_groups_for_admin() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$sub       = $this->create_group_subscription( $owner_id, 5 );
		$this->add_member( $member_id, $sub );

		$response = $this->dispatch();
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'pages', $data );
		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['items'] );

		$group = $data['items'][0];
		$this->assertSame( $sub->get_id(), $group['id'] );
		$this->assertSame( $owner_id, $group['ownerId'] );
		$this->assertSame( $owner_id, $group['owner']['id'] );
		$this->assertSame( 'Acme Team', $group['plan'] );
		$this->assertSame( 'active', $group['status'] );
		$this->assertSame( 5, $group['seatLimit'] );
		// Owner + one member.
		$this->assertSame( 2, $group['members'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $group['createdAt'] );
		// The interim click-through target is always present (empty when the
		// subscription object can't resolve an edit URL, e.g. under the mock).
		$this->assertArrayHasKey( 'editUrl', $group );
		$this->assertIsString( $group['editUrl'] );
		// The owner carries their own target: a person's name links to the person,
		// the plan name links to the subscription above.
		$this->assertStringContainsString( 'user_id=' . $owner_id, $group['owner']['editUrl'] );
		$this->assertNull( $group['seatRequest'] );
	}

	/**
	 * A subscription that is not group-enabled is excluded, even though the test mock's
	 * get_group_subscription_ids() over-returns (it ignores the meta_query).
	 */
	public function test_excludes_non_group_subscriptions() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$owner_id = $this->create_reader_user();
		$this->create_group_subscription( $owner_id, 3 );

		// A plain, non-group subscription.
		wcs_create_subscription(
			[
				'customer_id'    => $this->create_reader_user(),
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);

		$data = $this->dispatch()->get_data();
		$this->assertSame( 1, $data['total'], 'Only the group-enabled subscription should be listed.' );
	}

	/**
	 * WCS statuses collapse onto the four values the list has labels and badges for
	 * (active / pending / on-hold / cancelled).
	 *
	 * A group awaiting its first payment is a real, reachable state, and it is the
	 * one that bites: it maps to `pending`, so both the status badge and the list's
	 * default status filter have to know about it, or such a group renders with an
	 * empty status — or is hidden outright.
	 */
	public function test_maps_wcs_status_to_prototype_vocabulary() {
		wp_set_current_user( $this->create_reader_user( 'administrator' ) );

		$prototype_status_by_wcs_status = [
			'active'         => 'active',
			// Cancelled at period end, but still entitled until then.
			'pending-cancel' => 'active',
			'pending'        => 'pending',
			'on-hold'        => 'on-hold',
			'cancelled'      => 'cancelled',
			'expired'        => 'cancelled',
			// An unrecognised WCS slug lands in the "needs attention" bucket rather
			// than reaching the UI as a status it cannot label.
			'switched'       => 'on-hold',
		];

		$wcs_status_by_group_id = [];
		foreach ( array_keys( $prototype_status_by_wcs_status ) as $wcs_status ) {
			$group = $this->create_group_subscription( $this->create_reader_user(), 3, $wcs_status );

			$wcs_status_by_group_id[ $group->get_id() ] = $wcs_status;
		}

		$data = $this->dispatch()->get_data();
		$this->assertCount( count( $prototype_status_by_wcs_status ), $data['items'] );
		foreach ( $data['items'] as $group ) {
			$wcs_status = $wcs_status_by_group_id[ $group['id'] ];
			$this->assertSame(
				$prototype_status_by_wcs_status[ $wcs_status ],
				$group['status'],
				sprintf( 'WCS status "%s" should surface as "%s".', $wcs_status, $prototype_status_by_wcs_status[ $wcs_status ] )
			);
		}
	}

	/**
	 * A non-admin reader is refused.
	 */
	public function test_forbidden_for_non_admin() {
		wp_set_current_user( $this->create_reader_user( 'subscriber' ) );
		$this->create_group_subscription( $this->create_reader_user(), 3 );

		$response = $this->dispatch();
		$this->assertSame( 403, $response->get_status() );
	}
}
