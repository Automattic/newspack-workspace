<?php
/**
 * DB-backed characterization test for get_churned_subscribers_in_window()
 * (churn-query perf fix).
 *
 * On www.richlandsource.com's large legacy WooCommerce subscriptions dataset,
 * a manual Audience-tab refresh timed out inside this query: the storage
 * implementation used `customer_id NOT IN (SELECT ... multi-join subquery)`
 * for the active-subscriber exclusion — MySQL's slowest anti-join form — plus
 * an unindexed BETWEEN string range scan on the schedule-cancelled meta.
 *
 * This test pins the query's OUTPUT (the churned-customer count) against a
 * small, deliberately adversarial fixture set BEFORE the query is rewritten
 * as a pre-aggregated LEFT JOIN anti-join (mirroring the proven
 * get_winback_subscribers_in_window() pattern in both storage backends). It
 * must pass unchanged on the OLD query (baseline) and again on the NEW query
 * (equivalence proof) — especially case B, the customer who both churned in
 * the window AND holds a separate active subscription, which is the exact
 * scenario the anti-join must continue to exclude.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\HPOS_Storage;
use Newspack\Insights\Legacy_Storage;
use Newspack\Insights\Storage_Interface;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Characterization test for get_churned_subscribers_in_window().
 *
 * @group insights
 */
class Test_Churned_Subscribers_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id, excluded from the churn count via the NOT IN list.
	 */
	const DONATION_PRODUCT_ID = 999001;

	/**
	 * Non-donation subscription product id.
	 */
	const SUB_PRODUCT_ID = 555001;

	/**
	 * Stand up the WC order + line-item tables once (InnoDB → per-test inserts
	 * roll back via the surrounding transaction).
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
	 * The churn window: brackets the "in window" fixture dates while excluding
	 * the "outside window" fixture dates.
	 *
	 * @return DateTimeImmutable[] [ start, end ]
	 */
	private function window(): array {
		$tz = new DateTimeZone( 'UTC' );
		return [ new DateTimeImmutable( '-30 days', $tz ), new DateTimeImmutable( '+1 day', $tz ) ];
	}

	/**
	 * Seed the four anchor cases (A–D) described in the fix plan:
	 *
	 *   A: cancelled/expired sub, `_schedule_cancelled` INSIDE the window, no
	 *      active sub for that customer → COUNTED as churned.
	 *   B: cancelled sub in window AND an active subscription for the SAME
	 *      customer (different order) → NOT counted. This is the crux case:
	 *      it is exactly what the anti-join must continue to exclude.
	 *   C: cancelled sub, `_schedule_cancelled` OUTSIDE the window → not counted.
	 *   D: cancelled sub in window, but on a DONATION product id → excluded.
	 *
	 * Only case A should be counted, so the assertion is `assertSame( 1, ... )`.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return void
	 */
	private function seed_anchor_cases( string $backend ): void {
		$in_window  = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$out_window = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

		// Case A: churned in window, no active sub. customer_id 101.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 7001,
				'customer_id'        => 101,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);

		// Case B: churned in window (customer 102) AND has a separate active sub
		// (customer 102) — must NOT be counted as churned.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 7002,
				'customer_id'        => 102,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 7003,
				'customer_id' => 102,
				'product_id'  => self::SUB_PRODUCT_ID,
				'status'      => 'wc-active',
			]
		);

		// Case C: cancelled but OUTSIDE the window. customer_id 103.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 7004,
				'customer_id'        => 103,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-expired',
				'schedule_cancelled' => $out_window,
			]
		);

		// Case D: cancelled in window, but on the DONATION product. customer_id 104.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 7005,
				'customer_id'        => 104,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
	}

	/**
	 * The characterization assertion: only case A (customer 101) is counted as
	 * churned. This must pass BOTH before and after the anti-join rewrite —
	 * that is the equivalence proof required before shipping the perf fix.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_churned_count_excludes_active_and_out_of_window_and_donation( string $backend ): void {
		$this->seed_anchor_cases( $backend );
		[ $start, $end ] = $this->window();

		$count = $this->storage_for( $backend )->get_churned_subscribers_in_window( $start, $end );

		$this->assertSame(
			1,
			$count,
			'Only case A (churned in window, no active sub) should count. ' .
			'Case B (churned in window but also active) must be excluded — the crux ' .
			'case the anti-join rewrite must preserve. Cases C (out of window) and D ' .
			'(donation product) must also be excluded.'
		);
	}

	/**
	 * Isolate case B on its own (no other fixtures) to pin the exact crux
	 * scenario independent of the combined-count test above: a customer who
	 * both churned inside the window and holds a separate active subscription
	 * must contribute 0 to the churn count.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_customer_with_active_sub_is_never_counted_as_churned( string $backend ): void {
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 8001,
				'customer_id'        => 202,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 8002,
				'customer_id' => 202,
				'product_id'  => self::SUB_PRODUCT_ID,
				'status'      => 'wc-active',
			]
		);

		[ $start, $end ] = $this->window();
		$count            = $this->storage_for( $backend )->get_churned_subscribers_in_window( $start, $end );

		$this->assertSame( 0, $count, 'A customer with a remaining active subscription is never "churned".' );
	}
}
