<?php
/**
 * DB-backed characterization test for get_recurring_donor_retention()
 * (donor-query perf fix — HIGHEST PRIORITY, feeds the Donors-tab CLV card).
 *
 * `get_recurring_donor_retention()` ran a first query, pulled EVERY
 * active-at-start donor into a PHP array, then serialized them back into
 * `CAST(cust.meta_value AS UNSIGNED) IN ($customer_list)` for a second
 * query — slow, AND a `max_allowed_packet` "MySQL server has gone away"
 * trigger on large active-at-start cohorts.
 *
 * This test pins the query's OUTPUT (value/computable/denominator) against
 * a small, deliberately adversarial fixture set BEFORE the query is
 * rewritten to compute the active-at-start cohort as an in-SQL derived
 * table joined directly into the numerator query, eliminating the PHP
 * round trip. It must pass unchanged on the OLD query (baseline) and again
 * on the NEW query (equivalence proof) — especially:
 *
 *   - the `_schedule_cancelled = '0'` WCS "not cancelled" sentinel (must
 *     count as active-at-start, not excluded),
 *   - a donor active at start whose subscription is cancelled by NOW
 *     (excluded from the numerator, but stays in the denominator), and
 *   - a donor whose subscription started AFTER the window start (excluded
 *     entirely — not active-at-start).
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

use Newspack\Insights\HPOS_Donors_Storage;
use Newspack\Insights\Legacy_Donors_Storage;
use Newspack\Insights\Donors_Storage_Interface;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Characterization test for get_recurring_donor_retention().
 *
 * @group insights
 */
class Test_Recurring_Donor_Retention_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id — the only product id these donor queries scope to.
	 */
	const DONATION_PRODUCT_ID = 999301;

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
	 * The storage instance under test for a backend, scoped to the donation
	 * product.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return Donors_Storage_Interface
	 */
	private function storage_for( string $backend ): Donors_Storage_Interface {
		return 'hpos' === $backend
			? new HPOS_Donors_Storage( [ self::DONATION_PRODUCT_ID ] )
			: new Legacy_Donors_Storage( [ self::DONATION_PRODUCT_ID ] );
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
	 * Window start: 365 days ago (mirrors the CLV card's 12-month cohort).
	 * "End" only disambiguates the cache key — the "still active" check
	 * anchors on NOW, not on end, per the interface docblock.
	 *
	 * @return DateTimeImmutable[] [ start, end ]
	 */
	private function window(): array {
		$tz = new DateTimeZone( 'UTC' );
		return [ new DateTimeImmutable( '-365 days', $tz ), new DateTimeImmutable( 'now', $tz ) ];
	}

	/**
	 * A schedule_start date safely before the window start (well in the past).
	 *
	 * @return string Y-m-d H:i:s (UTC).
	 */
	private function before_start_date(): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) );
	}

	/**
	 * A schedule_start date AFTER the window start (should be excluded from
	 * the active-at-start cohort).
	 *
	 * @return string Y-m-d H:i:s (UTC).
	 */
	private function after_start_date(): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
	}

	/**
	 * A schedule_cancelled date in the FUTURE relative to start (still
	 * "active at start" per the interface: cancelled meta empty/null/'0'/>
	 * start all count as active-at-start).
	 *
	 * @return string Y-m-d H:i:s (UTC).
	 */
	private function future_cancel_date(): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( '+300 days' ) );
	}

	/**
	 * A schedule_cancelled date BEFORE the window start (not active at
	 * start — cancelled before the cohort was measured).
	 *
	 * @return string Y-m-d H:i:s (UTC).
	 */
	private function past_cancel_date(): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-380 days' ) );
	}

	/**
	 * Crux case A: the WCS `'0'` "not cancelled" sentinel must count as
	 * active-at-start, not be excluded by a naive falsy check.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_schedule_cancelled_zero_sentinel_counts_as_active_at_start( string $backend ): void {
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 91001,
				'customer_id'        => 401,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-active',
				'schedule_start'     => $this->before_start_date(),
				'schedule_cancelled' => '0',
			]
		);

		[ $start, $end ] = $this->window();
		$result           = $this->storage_for( $backend )->get_recurring_donor_retention( $start, $end );

		$this->assertTrue( $result['computable'] );
		$this->assertSame( 1, $result['denominator'], "The '0' sentinel must count as active-at-start, not excluded." );
		$this->assertEqualsWithDelta( 1.0, $result['value'], 0.0001, 'Still wc-active now → retained.' );
	}

	/**
	 * Crux case B: a donor active at start whose subscription is cancelled
	 * by NOW must be excluded from the numerator but remain in the
	 * denominator — this is the exact active-at-start vs still-active-now
	 * distinction the in-SQL rewrite must preserve.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_active_at_start_but_since_cancelled_lowers_retention( string $backend ): void {
		// Donor 402: active at start, still wc-active now → retained.
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => 92001,
				'customer_id'    => 402,
				'product_id'     => self::DONATION_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $this->before_start_date(),
			]
		);

		// Donor 403: active at start (cancel date is in the future relative
		// to start), but the subscription's CURRENT status is wc-cancelled —
		// no longer active now → excluded from numerator, stays in denominator.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 92002,
				'customer_id'        => 403,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_start'     => $this->before_start_date(),
				'schedule_cancelled' => $this->future_cancel_date(),
			]
		);

		[ $start, $end ] = $this->window();
		$result           = $this->storage_for( $backend )->get_recurring_donor_retention( $start, $end );

		$this->assertTrue( $result['computable'] );
		$this->assertSame( 2, $result['denominator'], 'Both donors were active-at-start.' );
		$this->assertEqualsWithDelta( 0.5, $result['value'], 0.0001, 'Only donor 402 (1 of 2) is still active now.' );
	}

	/**
	 * Crux case C: a subscription that started AFTER the window start must
	 * never enter the active-at-start cohort at all.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_subscription_started_after_window_excluded( string $backend ): void {
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => 93001,
				'customer_id'    => 404,
				'product_id'     => self::DONATION_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $this->after_start_date(),
			]
		);

		[ $start, $end ] = $this->window();
		$result           = $this->storage_for( $backend )->get_recurring_donor_retention( $start, $end );

		$this->assertFalse( $result['computable'], 'A subscription starting after the window start never enters the cohort.' );
		$this->assertSame( 0, $result['denominator'] );
	}

	/**
	 * Crux case D: a subscription cancelled BEFORE the window start was not
	 * active at start and must not enter the cohort either.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_subscription_cancelled_before_window_excluded( string $backend ): void {
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 94001,
				'customer_id'        => 405,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-expired',
				'schedule_start'     => $this->before_start_date(),
				'schedule_cancelled' => $this->past_cancel_date(),
			]
		);

		[ $start, $end ] = $this->window();
		$result           = $this->storage_for( $backend )->get_recurring_donor_retention( $start, $end );

		$this->assertFalse( $result['computable'], 'A subscription cancelled before window start was not active at start.' );
		$this->assertSame( 0, $result['denominator'] );
	}

	/**
	 * No active-at-start cohort at all → not computable.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_no_cohort_is_not_computable( string $backend ): void {
		[ $start, $end ] = $this->window();
		$result           = $this->storage_for( $backend )->get_recurring_donor_retention( $start, $end );

		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 0.0, $result['value'] );
	}
}
