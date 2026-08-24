<?php
/**
 * Tests for the Subscribers wizard single-subscriber read endpoint.
 *
 * The person profile (L1) reads one reader in full: every subscription they
 * hold, individual and group alike, each with the billing detail its card
 * renders. These tests pin the parts that are not obvious from the code —
 * the permission boundary, the mixed-status case the profile exists to show,
 * and the decision that a group arrives as a whole object rather than an ID
 * the client would have to resolve.
 *
 * @package Newspack\Tests
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * GET /wizard/newspack-subscribers/subscribers/<id>.
 *
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */
class Test_Subscribers_Wizard_Subscriber_Detail_Endpoint extends WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-subscribers/subscribers/';

	/**
	 * Track created user IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * The site timezone options as they stood before a test changed them, or null
	 * when untouched. See set_site_timezone().
	 *
	 * @var array|null
	 */
	private $original_timezone = null;

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
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		$this->user_ids          = [];
		$this->original_timezone = null;
		Group_Subscription::reset_cache();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: reset databases and delete users.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		$this->restore_site_timezone();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		parent::tear_down();
	}

	/**
	 * Point the site at a timezone for one test.
	 *
	 * The previous values are remembered so tear_down() puts them back even when
	 * the test fails part-way. WP_UnitTestCase's per-test DB rollback would cover
	 * this in practice, but a test that quietly depends on the harness undoing its
	 * own global state stops being correct the moment it moves.
	 *
	 * @param string    $timezone_string A PHP timezone identifier, or '' to fall back to the offset.
	 * @param int|float $gmt_offset      The UTC offset, in hours.
	 */
	private function set_site_timezone( string $timezone_string, $gmt_offset ) {
		if ( null === $this->original_timezone ) {
			$this->original_timezone = [
				'timezone_string' => get_option( 'timezone_string' ),
				'gmt_offset'      => get_option( 'gmt_offset' ),
			];
		}
		update_option( 'timezone_string', $timezone_string );
		update_option( 'gmt_offset', $gmt_offset );
	}

	/**
	 * Put back whatever set_site_timezone() replaced, if anything.
	 */
	private function restore_site_timezone() {
		if ( null === $this->original_timezone ) {
			return;
		}
		update_option( 'timezone_string', $this->original_timezone['timezone_string'] );
		update_option( 'gmt_offset', $this->original_timezone['gmt_offset'] );
		$this->original_timezone = null;
	}

	/**
	 * Create a reader user and track it for cleanup.
	 *
	 * @param string $name Display name.
	 *
	 * @return int The new user ID.
	 */
	private function create_reader( string $name = 'Reader' ): int {
		$suffix  = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			[
				'user_login'   => 'reader-' . $suffix,
				'user_pass'    => wp_generate_password(),
				'user_email'   => 'reader-' . $suffix . '@test.com',
				'display_name' => $name,
				'role'         => 'subscriber',
			]
		);
		update_user_meta( $user_id, '_newspack_reader', true );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Create an admin and make it the current user.
	 *
	 * @return int The admin user ID.
	 */
	private function login_admin(): int {
		$admin_id         = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->user_ids[] = $admin_id;
		wp_set_current_user( $admin_id );
		return $admin_id;
	}

	/**
	 * Billing fixture data shared by the individual and group subscription
	 * factories, so both exercise the same billing hydration path.
	 *
	 * @param string $status Subscription status.
	 *
	 * @return array
	 */
	private function billing_fixture( string $status ): array {
		return [
			'status'           => $status,
			'total'            => 12.5,
			'currency'         => 'USD',
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'dates'            => [
				'start'                => '2024-02-01 09:00:00',
				'next_payment'         => '2026-08-01 09:00:00',
				'last_order_date_paid' => '2026-07-01 09:00:00',
				'cancelled'            => 'cancelled' === $status ? '2026-06-01 09:00:00' : '',
			],
		];
	}

	/**
	 * Create a plain (non-group) individual subscription owned by $owner_id.
	 *
	 * @param int    $owner_id The owner user ID.
	 * @param string $status   Subscription status.
	 *
	 * @return WC_Subscription
	 */
	private function create_individual_subscription( int $owner_id, string $status = 'active' ): WC_Subscription {
		return wcs_create_subscription( array_merge( [ 'customer_id' => $owner_id ], $this->billing_fixture( $status ) ) );
	}

	/**
	 * Create a group subscription owned by $owner_id.
	 *
	 * @param int    $owner_id The owner user ID.
	 * @param int    $limit    Seat limit (0 = unlimited).
	 * @param string $status   Subscription status.
	 * @param string $name     Group name.
	 * @param string $created  GMT creation datetime.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, int $limit = 5, string $status = 'active', string $name = 'Acme Newsroom', string $created = '2024-02-01 09:00:00' ): WC_Subscription {
		$subscription = wcs_create_subscription(
			array_merge(
				[
					'customer_id'  => $owner_id,
					'date_created' => $created,
				],
				$this->billing_fixture( $status )
			)
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', (string) $limit );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', $name );
		return $subscription;
	}

	/**
	 * Add a member to a group, recording their joined-at timestamp the way
	 * Group_Subscription does.
	 *
	 * @param int             $user_id      The member user ID.
	 * @param WC_Subscription $subscription The group subscription.
	 * @param int             $joined_at    Unix timestamp of joining.
	 */
	private function add_member( int $user_id, WC_Subscription $subscription, int $joined_at ) {
		add_user_meta( $user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		update_user_meta( $user_id, Group_Subscription::get_member_joined_meta_key( $subscription->get_id() ), $joined_at );
		Group_Subscription::reset_cache();
	}

	/**
	 * Dispatch the single-subscriber endpoint.
	 *
	 * @param int $user_id The reader user ID.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch( int $user_id ): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE . $user_id ) );
	}

	/**
	 * The endpoint carries the same `manage_options` gate as the collection reads:
	 * a logged-in reader cannot read their own — or anyone else's — profile through
	 * the admin surface.
	 */
	public function test_forbidden_for_non_admin() {
		$reader_id = $this->create_reader( 'Nosy' );
		wp_set_current_user( $reader_id );

		$this->assertSame( 403, $this->dispatch( $reader_id )->get_status() );
	}

	/**
	 * A logged-out request is rejected too — the gate is a capability check, not
	 * merely "not an admin". It answers 403 rather than the 401 an authentication
	 * challenge would give, because Wizard::api_permissions_check() asks only
	 * `current_user_can()`; the wizard is an admin surface with nowhere to send an
	 * anonymous caller to log in.
	 */
	public function test_forbidden_when_logged_out() {
		$reader_id = $this->create_reader( 'Ghost' );
		wp_set_current_user( 0 );

		$response = $this->dispatch( $reader_id );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'newspack_rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * An ID with no user behind it is a 404, not an empty profile: the screen must
	 * be able to tell "this person does not exist" from "this person has nothing".
	 */
	public function test_not_found_for_unknown_id() {
		$this->login_admin();

		$response = $this->dispatch( 999999 );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'newspack_subscriber_not_found', $response->get_data()['code'] );
	}

	/**
	 * The profile hydrates the reader's identity fields plus the click-through
	 * targets the header actions need.
	 */
	public function test_returns_reader_identity() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Ada Lovelace' );

		$response = $this->dispatch( $reader_id );
		$this->assertSame( 200, $response->get_status() );

		$subscriber = $response->get_data();
		$this->assertSame( $reader_id, $subscriber['id'] );
		$this->assertSame( 'Ada Lovelace', $subscriber['name'] );
		$this->assertStringContainsString( 'user_id=' . $reader_id, $subscriber['editUrl'] );
		$this->assertSame( [], $subscriber['subscriptions'] );
		$this->assertSame( [], $subscriber['groups'] );
		// A reader with nothing on file has no status badge at all.
		$this->assertSame( '', $subscriber['status'] );
	}

	/**
	 * THE case the profile exists for: a reader holding both a cancelled and a live
	 * plan. Both subscriptions come back in full — the profile shows the history —
	 * while the reduced headline status drops the cancelled one, matching the badge
	 * the list showed on the row that was clicked.
	 */
	public function test_returns_both_plans_for_a_mixed_status_reader() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Mixed' );
		$cancelled = $this->create_individual_subscription( $reader_id, 'cancelled' );
		$active    = $this->create_individual_subscription( $reader_id, 'active' );

		$subscriber = $this->dispatch( $reader_id )->get_data();

		$statuses_by_id = array_column( $subscriber['subscriptions'], 'status', 'id' );
		$this->assertCount( 2, $statuses_by_id, 'Both plans are on the profile, not just the live one.' );
		$this->assertSame( 'cancelled', $statuses_by_id[ $cancelled->get_id() ] );
		$this->assertSame( 'active', $statuses_by_id[ $active->get_id() ] );
		$this->assertSame( 'active', $subscriber['status'], 'A live plan hides the cancelled one in the headline status.' );
	}

	/**
	 * Each individual subscription carries the billing detail its card renders:
	 * amount, currency and cadence for the rate line, plus the four dates.
	 */
	public function test_hydrates_individual_subscription_billing() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Solo' );
		$this->create_individual_subscription( $reader_id, 'active' );

		$subscription = $this->dispatch( $reader_id )->get_data()['subscriptions'][0];

		$this->assertSame( 12.5, $subscription['amount'] );
		$this->assertSame( 'USD', $subscription['currency'] );
		$this->assertSame( 'month', $subscription['billingPeriod'] );
		$this->assertSame( 1, $subscription['billingInterval'] );
		// Dates are normalized to bare Y-m-d, which is what fmtDate expects.
		$this->assertSame( '2024-02-01', $subscription['startDate'] );
		$this->assertSame( '2026-08-01', $subscription['nextBillingDate'] );
		$this->assertSame( '2026-07-01', $subscription['lastPayment'] );
		$this->assertNull( $subscription['endDate'], 'A live subscription has no end date.' );
	}

	/**
	 * A cancelled subscription surfaces its cancellation date, which the card shows
	 * in place of the (now meaningless) next-billing row.
	 */
	public function test_cancelled_subscription_carries_its_end_date() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Churned' );
		$this->create_individual_subscription( $reader_id, 'cancelled' );

		$subscription = $this->dispatch( $reader_id )->get_data()['subscriptions'][0];
		$this->assertSame( '2026-06-01', $subscription['endDate'] );
	}

	/**
	 * §3.3, decided: a group arrives as a WHOLE OBJECT, not an ID the client would
	 * have to resolve. The card needs the group's name, status, seat usage, owner
	 * identity, billing rate and this reader's role and joined date — resolving
	 * those client-side would cost a request per group just to draw a card.
	 *
	 * The embedded shape is the group-list shape (prepare_group) plus billing,
	 * `role` and `joinedAt`, so `/groups/<id>` can return the same object.
	 */
	public function test_embeds_the_whole_group_object_for_a_member() {
		$this->login_admin();
		$owner_id  = $this->create_reader( 'Owner' );
		$member_id = $this->create_reader( 'Member' );
		$group     = $this->create_group_subscription( $owner_id, 5 );
		$this->add_member( $member_id, $group, strtotime( '2025-03-04 00:00:00' ) );

		$subscriber = $this->dispatch( $member_id )->get_data();

		$this->assertCount( 1, $subscriber['groups'] );
		$this->assertEmpty( $subscriber['subscriptions'], 'A group is not one of the reader\'s own plans.' );

		$embedded_group = $subscriber['groups'][0];
		$this->assertSame( $group->get_id(), $embedded_group['id'] );
		$this->assertSame( 'Acme Newsroom', $embedded_group['plan'] );
		$this->assertSame( 'active', $embedded_group['status'] );
		$this->assertSame( 'member', $embedded_group['role'] );
		$this->assertSame( '2025-03-04', $embedded_group['joinedAt'] );
		// Seat usage: the count is owner-inclusive, so owner + member is 2 of 5.
		$this->assertSame( 5, $embedded_group['seatLimit'] );
		$this->assertSame( 2, $embedded_group['members'] );
		// The owner is embedded whole so the card can name and link to them.
		$this->assertSame( $owner_id, $embedded_group['ownerId'] );
		$this->assertSame( 'Owner', $embedded_group['owner']['name'] );
		$this->assertStringContainsString( 'user_id=' . $owner_id, $embedded_group['owner']['editUrl'] );
		// Billing rides along in the same shape as an individual subscription, so
		// one card component renders both.
		$this->assertSame( 12.5, $embedded_group['amount'] );
		$this->assertSame( 'month', $embedded_group['billingPeriod'] );
		$this->assertSame( '2026-08-01', $embedded_group['nextBillingDate'] );
	}

	/**
	 * The group's owner reads as `owner`, and their group counts as a plan for the
	 * headline status even though they hold no individual subscription.
	 */
	public function test_group_owner_reads_as_owner() {
		$this->login_admin();
		$owner_id = $this->create_reader( 'Owner' );
		$this->create_group_subscription( $owner_id, 0 );

		$subscriber = $this->dispatch( $owner_id )->get_data();

		$this->assertSame( 'owner', $subscriber['groups'][0]['role'] );
		$this->assertSame( 'active', $subscriber['status'] );
		// A seat limit of 0 means unlimited; it is passed through untouched so the
		// screen (not the endpoint) decides how to word that.
		$this->assertSame( 0, $subscriber['groups'][0]['seatLimit'] );
	}

	/**
	 * A member who never had a joined-at recorded (joined before the meta existed)
	 * reads as null rather than as the Unix epoch.
	 */
	public function test_missing_joined_at_is_null() {
		$this->login_admin();
		$owner_id  = $this->create_reader( 'Owner' );
		$member_id = $this->create_reader( 'Legacy member' );
		$group     = $this->create_group_subscription( $owner_id );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group->get_id() );
		Group_Subscription::reset_cache();

		$this->assertNull( $this->dispatch( $member_id )->get_data()['groups'][0]['joinedAt'] );
	}

	/**
	 * A reader in a group AND on their own plan gets both, each with its own
	 * status — the profile's whole reason for existing over the list row.
	 */
	public function test_returns_group_and_individual_subscriptions_together() {
		$this->login_admin();
		$owner_id  = $this->create_reader( 'Owner' );
		$member_id = $this->create_reader( 'Both' );
		$group     = $this->create_group_subscription( $owner_id, 5, 'on-hold' );
		$this->add_member( $member_id, $group, strtotime( '2025-03-04 00:00:00' ) );
		$this->create_individual_subscription( $member_id, 'active' );

		$subscriber = $this->dispatch( $member_id )->get_data();

		$this->assertCount( 1, $subscriber['groups'] );
		$this->assertSame( 'on-hold', $subscriber['groups'][0]['status'] );
		$this->assertCount( 1, $subscriber['subscriptions'] );
		$this->assertSame( 'active', $subscriber['subscriptions'][0]['status'] );
		$this->assertSame( 'active', $subscriber['status'], 'Active outranks on-hold in the headline.' );
	}

	/**
	 * The collection list stays lean: it must carry the exact lean shape and none
	 * of the detail endpoint's billing hydration, on BOTH the individual and the
	 * group array — the whole reason the $detailed flag exists is to keep that cost
	 * off every row of every page. Asserting exact key sets (not just the absence
	 * of `amount`) is what makes this pin catch a regression that leaks detail into
	 * the group entries, which an `amount`-only check on `subscriptions` misses.
	 */
	public function test_list_rows_carry_the_lean_shape_on_both_arrays() {
		$this->login_admin();
		$owner_id = $this->create_reader( 'Listed owner' );
		$group    = $this->create_group_subscription( $owner_id );
		$this->create_individual_subscription( $owner_id, 'active' );

		$request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-subscribers/subscribers' );
		$request->set_param( 'search', 'Listed owner' );
		$items = rest_get_server()->dispatch( $request )->get_data()['items'];

		$this->assertCount( 1, $items );
		$row = $items[0];

		$this->assertSame(
			[ 'editUrl', 'id', 'plan', 'status' ],
			$this->sorted_keys( $row['subscriptions'][0] ),
			'A list row\'s individual subscription is the lean shape — no billing keys.'
		);
		$this->assertSame(
			[ 'editUrl', 'id', 'plan', 'role', 'status' ],
			$this->sorted_keys( $row['groups'][0] ),
			'A list row\'s group entry is the lean shape — no billing, owner, seats or joinedAt.'
		);
	}

	/**
	 * The counterpart to the lean-list pin: the single-profile read DOES hydrate
	 * the billing keys the cards need, on both arrays. Together the two tests pin
	 * the exact boundary the $detailed flag draws.
	 */
	public function test_detail_rows_carry_the_full_shape_on_both_arrays() {
		$this->login_admin();
		$owner_id = $this->create_reader( 'Full owner' );
		$this->create_group_subscription( $owner_id );
		$this->create_individual_subscription( $owner_id, 'active' );

		$subscriber = $this->dispatch( $owner_id )->get_data();

		foreach ( [ 'amount', 'currency', 'billingPeriod', 'billingInterval', 'startDate', 'nextBillingDate', 'endDate', 'lastPayment' ] as $key ) {
			$this->assertArrayHasKey( $key, $subscriber['subscriptions'][0], "Detail individual subscription is missing $key." );
		}
		foreach ( [ 'amount', 'currency', 'billingPeriod', 'nextBillingDate', 'joinedAt', 'owner', 'seatLimit', 'members' ] as $key ) {
			$this->assertArrayHasKey( $key, $subscriber['groups'][0], "Detail group entry is missing $key." );
		}
	}

	/**
	 * FINDING 2: a subscription WooCommerce is winding down (pending-cancel) still
	 * maps to the "active" status — WCS sets an end date in the prepaid term and
	 * DELETES the next-payment date. The payload must surface the end date and a
	 * null next-billing, so the card can show "Ends <date>" rather than a
	 * meaningless "Next billing —" that hides the fact the plan is ending.
	 */
	public function test_pending_cancel_surfaces_the_end_date_not_next_billing() {
		$this->login_admin();
		$reader_id    = $this->create_reader( 'Winding down' );
		$subscription = wcs_create_subscription(
			[
				'customer_id'      => $reader_id,
				'status'           => 'pending-cancel',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => 12.5,
				'currency'         => 'USD',
				'dates'            => [
					'start'        => '2024-02-01 09:00:00',
					// WCS deletes next_payment on pending-cancel; the end is the prepaid
					// term, the cancelled date is only when cancellation was requested.
					'next_payment' => '',
					'cancelled'    => '2026-06-15 09:00:00',
					'end'          => '2026-09-01 09:00:00',
				],
			]
		);
		$this->assertInstanceOf( WC_Subscription::class, $subscription );

		$entry = $this->dispatch( $reader_id )->get_data()['subscriptions'][0];
		$this->assertSame( 'active', $entry['status'], 'pending-cancel still maps to active — still entitled.' );
		$this->assertNull( $entry['nextBillingDate'], 'WCS deleted the next-payment date.' );
		$this->assertSame( '2026-09-01', $entry['endDate'], 'The access-end date, not the cancellation-request date.' );
	}

	/**
	 * The end date is the access-end (`end`), not the cancellation-request
	 * timestamp (`cancelled`): when both are set, the reader keeps access until
	 * `end`, so that is the date the card shows. `cancelled` is only the fallback.
	 */
	public function test_end_date_prefers_access_end_over_cancellation_request() {
		$this->login_admin();
		$reader_id    = $this->create_reader( 'Both dates' );
		$subscription = wcs_create_subscription(
			[
				'customer_id'      => $reader_id,
				'status'           => 'cancelled',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => 12.5,
				'dates'            => [
					'cancelled' => '2026-06-15 09:00:00',
					'end'       => '2026-09-01 09:00:00',
				],
			]
		);
		$this->assertInstanceOf( WC_Subscription::class, $subscription );

		$entry = $this->dispatch( $reader_id )->get_data()['subscriptions'][0];
		$this->assertSame( '2026-09-01', $entry['endDate'] );
	}

	/**
	 * FINDING 5: dates render in the publisher's timezone, matching WooCommerce's
	 * own admin screens. A GMT instant just after midnight belongs to the previous
	 * calendar day for a negative-offset site, so the endpoint must localize before
	 * dropping the time — otherwise the profile shows a date a day off from every
	 * other Woo screen the publisher cross-checks.
	 */
	public function test_dates_render_in_the_site_timezone() {
		$this->set_site_timezone( '', -5 );

		$this->login_admin();
		$reader_id = $this->create_reader( 'NY reader' );
		wcs_create_subscription(
			[
				'customer_id'      => $reader_id,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => 10,
				// 02:00 UTC on Aug 1 is 21:00 on Jul 31 in a UTC-5 zone.
				'dates'            => [ 'next_payment' => '2026-08-01 02:00:00' ],
			]
		);

		$entry = $this->dispatch( $reader_id )->get_data()['subscriptions'][0];
		$this->assertSame( '2026-07-31', $entry['nextBillingDate'], 'The date is the site-local calendar day, not the GMT one.' );
	}

	/**
	 * EVERY date on the profile shares that one basis. A group-owner card renders
	 * "First subscribed" (the group's createdAt) directly above "Last payment", and
	 * the header renders "Subscriber since" (memberSince) — so a field still
	 * formatted in GMT would read a calendar day apart from the localized field
	 * next to it for the same instant, on the same screen. All three instants here
	 * are just after midnight UTC, which is the previous day for this UTC-5 site.
	 */
	public function test_every_profile_date_shares_the_site_timezone_basis() {
		$this->set_site_timezone( '', -5 );

		$this->login_admin();
		$owner_id = $this->create_reader( 'Owner' );
		wp_update_user(
			[
				'ID'              => $owner_id,
				'user_registered' => '2024-02-01 02:00:00',
			]
		);
		$this->create_group_subscription( $owner_id, 5, 'active', 'Acme Newsroom', '2025-05-06 02:00:00' );
		wc_create_order(
			[
				'customer_id' => $owner_id,
				'status'      => 'completed',
				'total'       => 12.5,
				'date_paid'   => '2026-07-01 02:00:00',
			]
		);

		$subscriber = $this->dispatch( $owner_id )->get_data();

		$this->assertSame( '2024-01-31', $subscriber['memberSince'], 'memberSince is the site-local day.' );
		$this->assertSame( '2026-06-30', $subscriber['lastPayment'], 'lastPayment is the site-local day.' );
		$this->assertSame( '2025-05-05', $subscriber['groups'][0]['createdAt'], "The group's createdAt is the site-local day." );
	}

	/**
	 * FINDING 3: opening one profile must not scan the whole group estate. A
	 * profile for a reader in ONE group resolves that group without touching the
	 * others — proven by hydrating the member's own membership correctly while a
	 * second, unrelated group exists whose members are never walked.
	 */
	public function test_detail_resolves_only_the_users_own_groups() {
		$this->login_admin();
		$owner_id     = $this->create_reader( 'Owner' );
		$member_id    = $this->create_reader( 'Member' );
		$their_group  = $this->create_group_subscription( $owner_id, 5, 'active', 'Their Group' );
		$this->add_member( $member_id, $their_group, strtotime( '2025-03-04 00:00:00' ) );
		// A second, unrelated group the member has nothing to do with.
		$other_owner = $this->create_reader( 'Other owner' );
		$this->create_group_subscription( $other_owner, 5, 'active', 'Other Group' );

		$groups = $this->dispatch( $member_id )->get_data()['groups'];
		$this->assertCount( 1, $groups, 'Only the group the reader belongs to is resolved.' );
		$this->assertSame( 'Their Group', $groups[0]['plan'] );
		$this->assertSame( 'member', $groups[0]['role'] );
	}

	/**
	 * A promoted manager (a member with the manager meta, not the owner) reads as
	 * `manager` on their own profile — the per-user resolution derives the same
	 * role precedence the list index does, without walking every group.
	 */
	public function test_detail_reads_a_promoted_manager_as_manager() {
		$this->login_admin();
		$owner_id   = $this->create_reader( 'Owner' );
		$manager_id = $this->create_reader( 'Manager' );
		$group      = $this->create_group_subscription( $owner_id );
		add_user_meta( $manager_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group->get_id() );
		add_user_meta( $manager_id, Group_Subscription::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY, $group->get_id() );
		Group_Subscription::reset_cache();

		$groups = $this->dispatch( $manager_id )->get_data()['groups'];
		$this->assertCount( 1, $groups );
		$this->assertSame( 'manager', $groups[0]['role'] );
	}

	/**
	 * The sorted key list of an associative array, for exact-shape assertions.
	 *
	 * @param array $array The array.
	 *
	 * @return string[] Its keys, sorted.
	 */
	private function sorted_keys( array $array ): array {
		$keys = array_keys( $array );
		sort( $keys );
		return $keys;
	}
}
