<?php
/**
 * DB-backed characterization test for get_new_subscriber_cohort_intervals()
 * (perf fix).
 *
 * The storage implementation determines cohort membership via
 * `customer_id IN (SELECT cohort.customer_id FROM (... GROUP BY ... HAVING
 * ...) cohort)` — a semi-join subquery pattern flagged for timeout risk on
 * large subscriptions datasets (full outer scan + a two-pass evaluation of
 * the cohort subquery).
 *
 * This test pins the query's OUTPUT (one row per customer+subscription for
 * every trailing-365-day cohort member) against a small, deliberately
 * adversarial fixture set BEFORE the query is rewritten as a pre-aggregated
 * INNER JOIN semi-join. It must pass unchanged on the OLD query (baseline)
 * and again on the NEW query (equivalence proof) — especially the
 * multi-subscription case (a cohort customer's SECOND subscription must also
 * be returned, not just their first) and the out-of-window exclusion (a
 * customer whose first-ever subscription predates the 365-day cutoff must be
 * excluded even if they have a LATER subscription that falls inside it).
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\HPOS_Storage;
use Newspack\Insights\Legacy_Storage;
use Newspack\Insights\Storage_Interface;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Characterization test for get_new_subscriber_cohort_intervals().
 *
 * @group insights
 */
class Test_New_Subscriber_Cohort_Intervals_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id, excluded from the cohort population.
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
	 * Rows for a given customer_id from a
	 * get_new_subscriber_cohort_intervals() result set.
	 *
	 * @param array<int, array<string, mixed>> $rows        Result set.
	 * @param int                              $customer_id Customer id to find.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_for( array $rows, int $customer_id ): array {
		return array_values( array_filter( $rows, static fn( $row ) => $row['customer_id'] === $customer_id ) );
	}

	/**
	 * Case A: customer whose first-ever subscription starts INSIDE the
	 * trailing 365 days is a cohort member — their subscription row is
	 * returned with start/cancelled/end.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_customer_with_recent_first_subscription_is_in_cohort( string $backend ): void {
		$base      = 91000 + ( 'hpos' === $backend ? 500 : 0 );
		$in_365    = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$customer_id = 501;

		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 1,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_365,
			]
		);

		$rows = $this->storage_for( $backend )->get_new_subscriber_cohort_intervals();
		$mine = $this->rows_for( $rows, $customer_id );

		$this->assertCount( 1, $mine );
		$this->assertSame( $in_365, $mine[0]['start'] );
		$this->assertNull( $mine[0]['cancelled'] );
		$this->assertNull( $mine[0]['end'] );
	}

	/**
	 * Crux case: a customer whose first-ever subscription predates the
	 * trailing 365-day cutoff must be excluded from the cohort ENTIRELY, even
	 * though they have a LATER subscription that falls inside the window —
	 * cohort membership is keyed on the customer's first-ever start, not any
	 * qualifying subscription.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_customer_with_old_first_subscription_excluded_despite_later_one_in_window( string $backend ): void {
		$base        = 91100 + ( 'hpos' === $backend ? 500 : 0 );
		$too_old     = gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) );
		$in_365      = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$customer_id = 502;

		// First-ever subscription, predates the 365-day cutoff.
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 1,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-cancelled',
				'schedule_start' => $too_old,
			]
		);
		// A second subscription, inside the window.
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 2,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_365,
			]
		);

		$rows = $this->storage_for( $backend )->get_new_subscriber_cohort_intervals();
		$mine = $this->rows_for( $rows, $customer_id );

		$this->assertCount( 0, $mine, 'Cohort membership is keyed on the FIRST-EVER subscription start, not any qualifying one.' );
	}

	/**
	 * Case: a customer whose first-ever subscription start is in the FUTURE
	 * (beyond "now") is excluded — the upper bound guards against
	 * scheduled/pending subscriptions.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_customer_with_future_first_subscription_excluded( string $backend ): void {
		$base        = 91200 + ( 'hpos' === $backend ? 500 : 0 );
		$future      = gmdate( 'Y-m-d H:i:s', strtotime( '+10 days' ) );
		$customer_id = 503;

		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 1,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $future,
			]
		);

		$rows = $this->storage_for( $backend )->get_new_subscriber_cohort_intervals();
		$mine = $this->rows_for( $rows, $customer_id );

		$this->assertCount( 0, $mine, 'A first subscription scheduled in the future must not count as a cohort member yet.' );
	}

	/**
	 * Donation-product subscriptions never establish cohort membership.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donation_subscription_does_not_establish_cohort_membership( string $backend ): void {
		$base        = 91300 + ( 'hpos' === $backend ? 500 : 0 );
		$in_365      = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$customer_id = 504;

		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 1,
				'customer_id'    => $customer_id,
				'product_id'     => self::DONATION_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_365,
			]
		);

		$rows = $this->storage_for( $backend )->get_new_subscriber_cohort_intervals();
		$mine = $this->rows_for( $rows, $customer_id );

		$this->assertCount( 0, $mine );
	}

	/**
	 * Guest subscriptions (customer_id = 0) are excluded.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_guest_subscription_excluded( string $backend ): void {
		$base      = 91400 + ( 'hpos' === $backend ? 500 : 0 );
		$in_365    = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 1,
				'customer_id'    => 0,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_365,
			]
		);

		$rows = $this->storage_for( $backend )->get_new_subscriber_cohort_intervals();
		$mine = $this->rows_for( $rows, 0 );

		$this->assertCount( 0, $mine );
	}

	/**
	 * Crux case: multiplicity. A cohort customer with TWO non-donation
	 * subscriptions (both inside history) must have BOTH rows returned, not
	 * just their first — the outer query returns every subscription of cohort
	 * members, not just the qualifying one.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_cohort_customer_with_two_subscriptions_returns_both_rows( string $backend ): void {
		$base        = 91500 + ( 'hpos' === $backend ? 500 : 0 );
		$first_start = gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) );
		$second_start = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 505;

		$this->insert_subscription(
			$backend,
			[
				'order_id'           => $base + 1,
				'customer_id'        => $customer_id,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_start'     => $first_start,
				'schedule_cancelled' => $second_start,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 2,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $second_start,
			]
		);

		$rows = $this->storage_for( $backend )->get_new_subscriber_cohort_intervals();
		$mine = $this->rows_for( $rows, $customer_id );

		$this->assertCount( 2, $mine, 'Both subscriptions of a cohort member must be returned, not just the qualifying first one.' );

		$starts = array_column( $mine, 'start' );
		sort( $starts );
		$this->assertSame( [ $first_start, $second_start ], $starts );

		// The first (cancelled) row must carry its cancelled date; the second (active) row's cancelled/end stay null.
		foreach ( $mine as $row ) {
			if ( $row['start'] === $first_start ) {
				$this->assertSame( $second_start, $row['cancelled'] );
			} else {
				$this->assertNull( $row['cancelled'] );
			}
		}
	}
}
