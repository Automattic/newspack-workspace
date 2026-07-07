<?php
/**
 * DB-backed characterization test for get_lapsed_donors_in_window()
 * (donor-query perf fix).
 *
 * The Donors tab's `get_lapsed_donors_in_window()` used
 * `customer_id NOT IN (SELECT ... multi-join subquery)` for the
 * active-donation-subscriber exclusion — MySQL's slowest anti-join form,
 * a timeout risk on large legacy WooCommerce subscriptions datasets. This
 * test pins the query's OUTPUT (the lapsed-donor count) against a small,
 * deliberately adversarial fixture set BEFORE the query is rewritten as a
 * pre-aggregated LEFT JOIN anti-join (mirroring
 * get_churned_subscribers_in_window()). It must pass unchanged on the OLD
 * query (baseline) and again on the NEW query (equivalence proof) —
 * especially case B, the donor who both lapsed in the window AND holds a
 * separate active donation subscription, which is the exact scenario the
 * anti-join must continue to exclude.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\HPOS_Donors_Storage;
use Newspack\Insights\Legacy_Donors_Storage;
use Newspack\Insights\Donors_Storage_Interface;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Characterization test for get_lapsed_donors_in_window().
 *
 * @group insights
 */
class Test_Lapsed_Donors_In_Window_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id — the only product id these donor queries scope to.
	 */
	const DONATION_PRODUCT_ID = 999101;

	/**
	 * A different (non-donation) subscription product id, used to prove the
	 * donation product_id filter is preserved.
	 */
	const OTHER_SUB_PRODUCT_ID = 555101;

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
	 * The window: brackets the "in window" fixture dates while excluding the
	 * "outside window" fixture dates.
	 *
	 * @return DateTimeImmutable[] [ start, end ]
	 */
	private function window(): array {
		$tz = new DateTimeZone( 'UTC' );
		return [ new DateTimeImmutable( '-30 days', $tz ), new DateTimeImmutable( '+1 day', $tz ) ];
	}

	/**
	 * Seed the four anchor cases (A–D):
	 *
	 *   A: donation sub cancelled/expired, `_schedule_cancelled` INSIDE the
	 *      window, no active donation sub for that customer → COUNTED.
	 *   B: donation sub cancelled in window AND a separate active donation
	 *      sub for the SAME customer → NOT counted. This is the crux case —
	 *      exactly what the anti-join must continue to exclude.
	 *   C: donation sub cancelled, `_schedule_cancelled` OUTSIDE the window
	 *      → not counted.
	 *   D: cancelled in window, but on a non-donation product id → excluded
	 *      by the product_id filter, not counted.
	 *
	 * Only case A should be counted, so the assertion is `assertSame( 1, ... )`.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return void
	 */
	private function seed_anchor_cases( string $backend ): void {
		$in_window  = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$out_window = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

		// Case A: lapsed in window, no active donation sub. customer_id 101.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 71001,
				'customer_id'        => 101,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);

		// Case B: lapsed in window (customer 102) AND has a separate active
		// donation sub (customer 102) — must NOT be counted as lapsed.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 71002,
				'customer_id'        => 102,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 71003,
				'customer_id' => 102,
				'product_id'  => self::DONATION_PRODUCT_ID,
				'status'      => 'wc-active',
			]
		);

		// Case C: cancelled but OUTSIDE the window. customer_id 103.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 71004,
				'customer_id'        => 103,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-expired',
				'schedule_cancelled' => $out_window,
			]
		);

		// Case D: cancelled in window, but on a non-donation product. customer_id 104.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 71005,
				'customer_id'        => 104,
				'product_id'         => self::OTHER_SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
	}

	/**
	 * The characterization assertion: only case A (customer 101) is counted
	 * as lapsed. This must pass BOTH before and after the anti-join rewrite.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_lapsed_count_excludes_active_and_out_of_window_and_other_product( string $backend ): void {
		$this->seed_anchor_cases( $backend );
		[ $start, $end ] = $this->window();

		$count = $this->storage_for( $backend )->get_lapsed_donors_in_window( $start, $end );

		$this->assertSame(
			1,
			$count,
			'Only case A (lapsed in window, no active donation sub) should count. ' .
			'Case B (lapsed in window but also active) must be excluded — the crux ' .
			'case the anti-join rewrite must preserve. Cases C (out of window) and D ' .
			'(non-donation product) must also be excluded.'
		);
	}

	/**
	 * Isolate case B on its own (no other fixtures) to pin the exact crux
	 * scenario independent of the combined-count test above: a donor who
	 * both lapsed inside the window and holds a separate active donation
	 * subscription must contribute 0 to the lapsed count.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donor_with_active_donation_sub_is_never_counted_as_lapsed( string $backend ): void {
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 72001,
				'customer_id'        => 202,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 72002,
				'customer_id' => 202,
				'product_id'  => self::DONATION_PRODUCT_ID,
				'status'      => 'wc-active',
			]
		);

		[ $start, $end ] = $this->window();
		$count            = $this->storage_for( $backend )->get_lapsed_donors_in_window( $start, $end );

		$this->assertSame( 0, $count, 'A donor with a remaining active donation subscription is never "lapsed".' );
	}
}
