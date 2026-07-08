<?php
/**
 * DB-backed characterization test for get_stale_registered_users() (perf fix).
 *
 * The storage implementation combines 3 correlated `EXISTS` subqueries (base
 * population + admin/editor exclusion) with 2 `NOT IN (SELECT ... multi-join
 * subquery)` exclusions (active non-donation subscribers; trailing-365-day
 * donors) — all HIGH timeout risk on large usermeta/subscription datasets.
 *
 * This test pins the query's OUTPUT (the stale-reader count) against a small,
 * deliberately adversarial fixture set BEFORE the query is rewritten as
 * pre-aggregated LEFT JOIN anti-joins. It must pass unchanged on the OLD
 * query (baseline) and again on the NEW query (equivalence proof).
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

use Newspack\Insights\HPOS_Storage;
use Newspack\Insights\Legacy_Storage;
use Newspack\Insights\Storage_Interface;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Characterization test for get_stale_registered_users().
 *
 * @group insights
 */
class Test_Stale_Registered_Users_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id, excluded from the subscriber exclusion / matched by
	 * the donation exclusion.
	 */
	const DONATION_PRODUCT_ID = 999001;

	/**
	 * Non-donation subscription product id.
	 */
	const SUB_PRODUCT_ID = 555001;

	/**
	 * Stand up the WC order + line-item tables once.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::create_woo_order_tables();
	}

	/**
	 * Drop the integration tables after the class.
	 */
	public static function tearDownAfterClass(): void {
		self::drop_woo_order_tables();
		parent::tearDownAfterClass();
	}

	/**
	 * Both storage backends. Every test runs against legacy AND HPOS.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function backends(): array {
		return [
			'legacy' => [ 'legacy' ],
			'hpos'   => [ 'hpos' ],
		];
	}

	/**
	 * The storage instance under test for a backend, scoped to exclude the
	 * donation product.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return Storage_Interface
	 */
	private function storage_for( string $backend ): Storage_Interface {
		return 'hpos' === $backend
			? new HPOS_Storage( [ self::DONATION_PRODUCT_ID ] )
			: new Legacy_Storage( [ self::DONATION_PRODUCT_ID ] );
	}

	/**
	 * Insert a shop_subscription row into the given backend.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @param array  $args    Subscription spec (see the fixtures trait).
	 * @return int The created subscription id.
	 */
	private function insert_subscription( string $backend, array $args ): int {
		return 'hpos' === $backend
			? $this->insert_hpos_subscription( $args )
			: $this->insert_legacy_subscription( $args );
	}

	/**
	 * Insert a shop_order row (used for the donation-order exclusion) into the
	 * given backend, with `customer_id` set for the stale-reader exclusion
	 * query. Neither `insert_hpos_order()` nor `insert_legacy_order()` persists
	 * a customer id on a plain shop_order (only the subscription helpers do),
	 * so this wires it up directly: HPOS via `wc_orders.customer_id`, legacy
	 * via `_customer_user` postmeta.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @param array  $args    Order spec (see the fixtures trait); `customer_id` required.
	 * @return int The created order id.
	 */
	private function insert_order( string $backend, array $args ): int {
		global $wpdb;
		$order_id = 'hpos' === $backend
			? $this->insert_hpos_order( $args )
			: $this->insert_legacy_order( $args );

		if ( 'hpos' === $backend ) {
			$wpdb->update( "{$wpdb->prefix}wc_orders", [ 'customer_id' => (int) $args['customer_id'] ], [ 'id' => $order_id ] );
		} else {
			add_post_meta( $order_id, '_customer_user', (string) $args['customer_id'] );
		}
		return $order_id;
	}

	/**
	 * Create a WordPress user with the given roles and optional np_reader meta.
	 *
	 * @param string      $login      Unique login.
	 * @param string[]    $roles      Roles to set (first is primary via wp_insert_user; extras via add_role).
	 * @param string|null $np_reader  Value for the np_reader user meta (null = do not set).
	 * @return int The created user id.
	 */
	private function make_user( string $login, array $roles, ?string $np_reader = '1' ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.com',
				'role'       => $roles[0] ?? 'subscriber',
			]
		);
		$this->assertIsInt( $user_id, "Failed to create user {$login}" );
		$user = new \WP_User( $user_id );
		foreach ( array_slice( $roles, 1 ) as $extra_role ) {
			$user->add_role( $extra_role );
		}
		if ( null !== $np_reader ) {
			update_user_meta( $user_id, 'np_reader', $np_reader );
		}
		return $user_id;
	}

	/**
	 * Seed the anchor cases described in the fix plan:
	 *
	 *   A: np_reader user, no active sub, no donation ever → COUNTED as stale.
	 *   B: np_reader user WITH an active non-donation subscription → NOT counted.
	 *   C: np_reader user with a completed donation order INSIDE the trailing
	 *      365 days → NOT counted.
	 *   D: np_reader user with a completed donation order OUTSIDE the trailing
	 *      365 days (400 days ago) → COUNTED (donation exclusion is time-bound;
	 *      the crux case the anti-join rewrite must preserve, since a naive
	 *      unbounded exclusion would wrongly drop this user).
	 *   E: no np_reader meta, but 'subscriber' role → COUNTED (fallback path).
	 *   F: np_reader user who is ALSO an administrator → NOT counted (admin/
	 *      editor exclusion overrides reader status).
	 *   G: plain user, no np_reader meta, 'editor' role (not subscriber/customer)
	 *      → NOT counted (fails base population — editor isn't a reader role,
	 *      and the restricted-role exclusion is moot since it never entered
	 *      the base population).
	 *
	 * Only A, D, and E should count => 3.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return void
	 */
	private function seed_anchor_cases( string $backend ): void {
		$in_365      = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$outside_365 = gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) );

		// Case A: stale reader, no sub, no donation.
		$this->make_user( "stale_a_{$backend}", [ 'subscriber' ] );

		// Case B: reader with an active non-donation subscription.
		$user_b = $this->make_user( "active_b_{$backend}", [ 'subscriber' ] );
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 71001 + ( 'hpos' === $backend ? 1 : 0 ),
				'customer_id' => $user_b,
				'product_id'  => self::SUB_PRODUCT_ID,
				'status'      => 'wc-active',
			]
		);

		// Case C: reader with a recent (in-window) completed donation order.
		$user_c = $this->make_user( "donor_c_{$backend}", [ 'subscriber' ] );
		$this->insert_order(
			$backend,
			[
				'order_id'    => 71003 + ( 'hpos' === $backend ? 1 : 0 ),
				'customer_id' => $user_c,
				'product_id'  => self::DONATION_PRODUCT_ID,
				'status'      => 'wc-completed',
				'date'        => $in_365,
			]
		);

		// Case D: reader with a completed donation order OUTSIDE the trailing
		// 365-day window — must still count as stale (crux case).
		$user_d = $this->make_user( "donor_d_{$backend}", [ 'subscriber' ] );
		$this->insert_order(
			$backend,
			[
				'order_id'    => 71005 + ( 'hpos' === $backend ? 1 : 0 ),
				'customer_id' => $user_d,
				'product_id'  => self::DONATION_PRODUCT_ID,
				'status'      => 'wc-completed',
				'date'        => $outside_365,
			]
		);

		// Case E: no np_reader meta, but 'subscriber' role (fallback path).
		$this->make_user( "fallback_e_{$backend}", [ 'subscriber' ], null );

		// Case F: np_reader user who is ALSO an administrator.
		$this->make_user( "admin_f_{$backend}", [ 'subscriber', 'administrator' ] );

		// Case G: plain editor, no np_reader meta, not subscriber/customer role.
		$this->make_user( "editor_g_{$backend}", [ 'editor' ], null );
	}

	/**
	 * The characterization assertion: only cases A, D, E count as stale (3).
	 * Must pass BOTH before and after the anti-join rewrite.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_stale_count_excludes_active_subs_and_recent_donors( string $backend ): void {
		$this->seed_anchor_cases( $backend );

		$count = $this->storage_for( $backend )->get_stale_registered_users();

		$this->assertSame(
			3,
			$count,
			'Only cases A (stale, no activity), D (donation outside 365d window), and E ' .
			'(fallback subscriber role, no np_reader meta) should count. Case B (active sub) ' .
			'and case C (donation inside 365d) must be excluded. Case D is the crux case: the ' .
			'donation exclusion is time-bound, not unbounded — a customer with only an OLD ' .
			'donation is still stale today.'
		);
	}

	/**
	 * Isolate case D on its own (no other fixtures) to pin the exact crux
	 * scenario independent of the combined-count test above: a reader whose
	 * only donation order falls OUTSIDE the trailing 365-day window must
	 * still be counted as stale.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_reader_with_old_donation_outside_window_is_still_stale( string $backend ): void {
		$outside_365 = gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) );
		$user_id     = $this->make_user( "isolated_d_{$backend}", [ 'subscriber' ] );
		$order_id    = 72001 + ( 'hpos' === $backend ? 1 : 0 );

		$this->insert_order(
			$backend,
			[
				'order_id'    => $order_id,
				'customer_id' => $user_id,
				'product_id'  => self::DONATION_PRODUCT_ID,
				'status'      => 'wc-completed',
				'date'        => $outside_365,
			]
		);

		$count = $this->storage_for( $backend )->get_stale_registered_users();

		$this->assertSame( 1, $count, 'A reader whose only donation is outside the trailing 365 days is still stale.' );
	}

	/**
	 * Isolate case B on its own: a reader with a remaining active non-donation
	 * subscription must never be counted as stale.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_reader_with_active_subscription_is_never_stale( string $backend ): void {
		$user_id = $this->make_user( "isolated_b_{$backend}", [ 'subscriber' ] );
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 73001 + ( 'hpos' === $backend ? 1 : 0 ),
				'customer_id' => $user_id,
				'product_id'  => self::SUB_PRODUCT_ID,
				'status'      => 'wc-active',
			]
		);

		$count = $this->storage_for( $backend )->get_stale_registered_users();

		$this->assertSame( 0, $count, 'A reader with a remaining active subscription is never "stale".' );
	}
}
