<?php
/**
 * Test Woo_Order_Resolver.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Woo_Order_Resolver;
use WP_UnitTestCase;

/**
 * Test class.
 *
 * @group insights
 */
class Test_Woo_Order_Resolver extends WP_UnitTestCase {

	/**
	 * Customer A.
	 *
	 * @var int
	 */
	private int $customer_a;

	/**
	 * Customer B.
	 *
	 * @var int
	 */
	private int $customer_b;

	/**
	 * Customer C.
	 *
	 * @var int
	 */
	private int $customer_c;

	/**
	 * Set up customers.
	 */
	public function setUp(): void {
		parent::setUp();
		// Reset the shared mock orders DB (see tests/mocks/wc-mocks.php — `$orders_database`
		// is a process-global the WC mocks append to, so it leaks across tests unless cleared).
		global $orders_database;
		$orders_database  = [];
		$this->customer_a = $this->factory->user->create( [ 'role' => 'customer' ] );
		$this->customer_b = $this->factory->user->create( [ 'role' => 'customer' ] );
		$this->customer_c = $this->factory->user->create( [ 'role' => 'customer' ] );
	}

	/**
	 * Build a row in the shape Gates BQ paywall queries return (back-compat `user_pseudo_id` key).
	 *
	 * @param int    $uid        Customer id used as user_pseudo_id.
	 * @param string $session    Session identifier.
	 * @param int    $attempt_ts GA4 microsecond timestamp.
	 * @return array
	 */
	private function row( int $uid, string $session, int $attempt_ts ): array {
		return [
			'user_pseudo_id' => (string) $uid,
			'session_id'     => $session,
			'attempt_ts'     => (string) $attempt_ts,
		];
	}

	/**
	 * Build a row in the shape Prompts BQ paid attempt queries return (`uid` key).
	 *
	 * The hub's prompts catalog projects `COALESCE(user_id, user_pseudo_id) AS uid`
	 * (renamed per Copilot review on newspack-manager-admin#457). The resolver
	 * reads `uid` first and falls back to `user_pseudo_id` so both shapes work.
	 *
	 * @param int    $uid        Customer id used as uid.
	 * @param string $session    Session identifier.
	 * @param int    $attempt_ts GA4 microsecond timestamp.
	 * @return array
	 */
	private function row_uid( int $uid, string $session, int $attempt_ts ): array {
		return [
			'uid'        => (string) $uid,
			'session_id' => $session,
			'attempt_ts' => (string) $attempt_ts,
		];
	}

	/**
	 * Seed a Woo order. Returns the order ID.
	 *
	 * @param int    $customer_id Customer.
	 * @param string $status      Order status.
	 * @param string $date_gmt    Date created (GMT).
	 * @param float  $total       Total.
	 * @return int
	 */
	private function make_order( int $customer_id, string $status, string $date_gmt, float $total ): int {
		// The newspack-plugin test suite uses a `WC_Order` mock (tests/mocks/wc-mocks.php)
		// that takes the full payload at construction and has no `set_*` setters. Pass
		// `date_paid` so the resolver's `get_date_paid()` fallback resolves to the
		// expected GMT timestamp in the test environment.
		$order = wc_create_order(
			[
				'customer_id' => $customer_id,
				'status'      => $status,
				'total'       => $total,
				'date_paid'   => $date_gmt,
			]
		);
		return $order->get_id();
	}

	/**
	 * Returns zero counts when no rows.
	 */
	public function test_empty_rows_return_zero() {
		$resolver = new Woo_Order_Resolver();
		$this->assertSame( 0, $resolver->count_completed_orders( [] ) );
		$this->assertSame( 0.0, $resolver->sum_completed_revenue( [] ) );
		$this->assertSame( 0, $resolver->count_unique_completed_users( [] ) );
	}

	/**
	 * Counts a single completed order within the 30-min window.
	 */
	public function test_counts_completed_order_in_window() {
		$attempt_ts_micros = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_a, 'completed', '2026-04-15 12:10:00 UTC', 25.00 );

		$rows = [ $this->row( $this->customer_a, 'sess1', $attempt_ts_micros ) ];
		$resolver = new Woo_Order_Resolver();

		$this->assertSame( 1, $resolver->count_completed_orders( $rows ) );
		$this->assertSame( 25.00, $resolver->sum_completed_revenue( $rows ) );
		$this->assertSame( 1, $resolver->count_unique_completed_users( $rows ) );
	}

	/**
	 * Excludes orders outside the 30-min window.
	 */
	public function test_excludes_orders_outside_window() {
		$attempt_ts_micros = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_a, 'completed', '2026-04-15 12:45:00 UTC', 25.00 ); // 45 min later — out of window.

		$rows = [ $this->row( $this->customer_a, 'sess1', $attempt_ts_micros ) ];
		$resolver = new Woo_Order_Resolver();

		$this->assertSame( 0, $resolver->count_completed_orders( $rows ) );
		$this->assertSame( 0.0, $resolver->sum_completed_revenue( $rows ) );
	}

	/**
	 * Excludes orders with non-completed status.
	 */
	public function test_excludes_non_completed_orders() {
		$attempt_ts_micros = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_a, 'pending', '2026-04-15 12:05:00 UTC', 25.00 );

		$rows = [ $this->row( $this->customer_a, 'sess1', $attempt_ts_micros ) ];
		$resolver = new Woo_Order_Resolver();

		$this->assertSame( 0, $resolver->count_completed_orders( $rows ) );
	}

	/**
	 * Counts processing as completed (Woo's convention).
	 */
	public function test_processing_counts_as_completed() {
		$attempt_ts_micros = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_a, 'processing', '2026-04-15 12:05:00 UTC', 30.00 );

		$rows = [ $this->row( $this->customer_a, 'sess1', $attempt_ts_micros ) ];
		$resolver = new Woo_Order_Resolver();

		$this->assertSame( 1, $resolver->count_completed_orders( $rows ) );
	}

	/**
	 * Counts each (user, session) attempt once even if multiple orders exist in the window.
	 */
	public function test_counts_attempt_once_when_multiple_orders_match() {
		$attempt_ts_micros = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_a, 'completed', '2026-04-15 12:05:00 UTC', 25.00 );
		$this->make_order( $this->customer_a, 'completed', '2026-04-15 12:25:00 UTC', 30.00 );

		$rows = [ $this->row( $this->customer_a, 'sess1', $attempt_ts_micros ) ];
		$resolver = new Woo_Order_Resolver();

		// One attempt = one conversion; revenue picks the first matching order.
		$this->assertSame( 1, $resolver->count_completed_orders( $rows ) );
		$this->assertSame( 25.00, $resolver->sum_completed_revenue( $rows ) );
	}

	/**
	 * Rows with the Prompts-style `uid` key are matched the same as Gates-style
	 * `user_pseudo_id` rows. Guards against a regression where the resolver
	 * stopped reading the `uid` shape after the Copilot-driven hub-side rename.
	 */
	public function test_counts_completed_order_in_window_with_uid_field() {
		$attempt_ts_micros = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_a, 'completed', '2026-04-15 12:10:00 UTC', 25.00 );

		$rows = [ $this->row_uid( $this->customer_a, 'sess1', $attempt_ts_micros ) ];
		$resolver = new Woo_Order_Resolver();

		$this->assertSame( 1, $resolver->count_completed_orders( $rows ) );
		$this->assertSame( 25.00, $resolver->sum_completed_revenue( $rows ) );
		$this->assertSame( 1, $resolver->count_unique_completed_users( $rows ) );
	}

	/**
	 * The `uid` field takes precedence over `user_pseudo_id` when both are present
	 * on the same row. Documents the resolver's resolution order: read `uid` first,
	 * fall back to `user_pseudo_id`. Customer B is the `uid` value (matches the
	 * order). Customer A is the `user_pseudo_id` fallback (no matching order).
	 */
	public function test_uid_takes_precedence_over_user_pseudo_id() {
		$attempt_ts_micros = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_b, 'completed', '2026-04-15 12:10:00 UTC', 42.00 );

		$rows = [
			[
				'uid'            => (string) $this->customer_b,
				'user_pseudo_id' => (string) $this->customer_a,
				'session_id'     => 'sess1',
				'attempt_ts'     => (string) $attempt_ts_micros,
			],
		];
		$resolver = new Woo_Order_Resolver();

		$this->assertSame( 1, $resolver->count_completed_orders( $rows ) );
		$this->assertSame( 42.00, $resolver->sum_completed_revenue( $rows ) );
	}

	/**
	 * Distinct user count dedupes across multiple sessions.
	 */
	public function test_unique_users_dedupes_across_sessions() {
		$attempt_a = ( strtotime( '2026-04-15 12:00:00 UTC' ) * 1000000 );
		$attempt_b = ( strtotime( '2026-04-16 12:00:00 UTC' ) * 1000000 );
		$this->make_order( $this->customer_a, 'completed', '2026-04-15 12:05:00 UTC', 25.00 );
		$this->make_order( $this->customer_a, 'completed', '2026-04-16 12:05:00 UTC', 25.00 );

		$rows = [
			$this->row( $this->customer_a, 'sess1', $attempt_a ),
			$this->row( $this->customer_a, 'sess2', $attempt_b ),
		];
		$resolver = new Woo_Order_Resolver();

		$this->assertSame( 2, $resolver->count_completed_orders( $rows ) );
		$this->assertSame( 1, $resolver->count_unique_completed_users( $rows ) );
	}
}
