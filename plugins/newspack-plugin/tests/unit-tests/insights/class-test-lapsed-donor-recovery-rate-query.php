<?php
/**
 * DB-backed characterization test for get_lapsed_donor_recovery_rate()
 * (donor-query perf fix).
 *
 * `get_lapsed_donor_recovery_rate()` used a `NOT IN (subquery)` anti-join
 * for the active-donation-subscriber exclusion (MySQL's slowest anti-join
 * form) PLUS a PHP round trip: it pulled the entire prior-window lapsed
 * cohort into a PHP array and serialized it back into a
 * `CAST(cust.meta_value AS UNSIGNED) IN ($lapsed_list)` filter for the
 * "recovered" query — slow and a `max_allowed_packet` risk on large
 * cohorts.
 *
 * This test pins the query's OUTPUT (value/computable/denominator) against
 * a small, deliberately adversarial fixture set BEFORE the query is
 * rewritten to compute the lapsed cohort as an in-SQL derived table
 * (anti-join for exclusion, semi-join/derived-table for the recovered-in
 * check) instead of a PHP-built id list. It must pass unchanged on the OLD
 * query (baseline) and again on the NEW query (equivalence proof) —
 * especially:
 *   - a donor who lapsed in the PRIOR window but holds a separate active
 *     donation sub (must never enter the lapsed cohort — the anti-join
 *     crux case), and
 *   - a donor who lapsed in the prior window and DID make a new donation
 *     order inside the CURRENT window (the "recovered" numerator case).
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
 * Characterization test for get_lapsed_donor_recovery_rate().
 *
 * @group insights
 */
class Test_Lapsed_Donor_Recovery_Rate_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id — the only product id these donor queries scope to.
	 */
	const DONATION_PRODUCT_ID = 999201;

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
	 * Insert a shop_order row (used for the "recovered" donation order) into
	 * the given backend, with `customer_id` set. Neither `insert_hpos_order()`
	 * nor `insert_legacy_order()` persists a customer id on a plain shop_order
	 * (only the subscription helpers do), so this wires it up directly: HPOS
	 * via `wc_orders.customer_id`, legacy via `_customer_user` postmeta —
	 * same approach as {@see Test_Stale_Registered_Users_Query::insert_order()}.
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
	 * Current window: [-30 days, +1 day]. The prior window (computed by the
	 * method under test) is the same-length window immediately preceding it.
	 *
	 * @return DateTimeImmutable[] [ start, end ]
	 */
	private function current_window(): array {
		$tz = new DateTimeZone( 'UTC' );
		return [ new DateTimeImmutable( '-30 days', $tz ), new DateTimeImmutable( '+1 day', $tz ) ];
	}

	/**
	 * A date safely inside the prior window (roughly -61 days, well before
	 * the current window's -30 day start).
	 *
	 * @return string Y-m-d H:i:s (UTC).
	 */
	private function prior_window_date(): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-61 days' ) );
	}

	/**
	 * A date inside the current window (used for the "recovered" order).
	 *
	 * @return string Y-m-d H:i:s (UTC).
	 */
	private function current_window_date(): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
	}

	/**
	 * Crux case: a donor who lapsed in the PRIOR window but holds a SEPARATE
	 * active donation subscription must never enter the lapsed cohort at
	 * all — denominator must exclude them, and recovery must be computable
	 * only from genuinely lapsed donors.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donor_with_active_sub_excluded_from_lapsed_cohort( string $backend ): void {
		// Donor 301: cancelled a donation sub in the prior window, but ALSO
		// has a separate active donation sub — never "lapsed".
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 81001,
				'customer_id'        => 301,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $this->prior_window_date(),
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 81002,
				'customer_id' => 301,
				'product_id'  => self::DONATION_PRODUCT_ID,
				'status'      => 'wc-active',
			]
		);

		[ $start, $end ] = $this->current_window();
		$result           = $this->storage_for( $backend )->get_lapsed_donor_recovery_rate( $start, $end );

		$this->assertFalse(
			$result['computable'],
			'Donor 301 has an active donation sub and must never enter the lapsed cohort; ' .
			'with no other lapsed donors, the cohort is empty and the rate is not computable.'
		);
		$this->assertSame( 0, $result['denominator'], 'The lapsed cohort must exclude a donor with an active donation sub.' );
	}

	/**
	 * The recovery-rate numerator: a donor who lapsed in the prior window
	 * (no active sub) and placed a NEW completed donation order inside the
	 * CURRENT window counts as "recovered". A second lapsed donor who did
	 * NOT donate again in the current window keeps the denominator at 2 and
	 * the rate at 0.5.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_recovery_rate_counts_new_current_window_donation( string $backend ): void {
		// Donor 302: lapsed in prior window, no active sub, DONATES again in
		// the current window → recovered.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 82001,
				'customer_id'        => 302,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $this->prior_window_date(),
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'    => 82002,
				'customer_id' => 302,
				'product_id'  => self::DONATION_PRODUCT_ID,
				'status'      => 'wc-completed',
				'date'        => $this->current_window_date(),
			]
		);

		// Donor 303: lapsed in prior window, no active sub, does NOT donate
		// again → not recovered, but still in the denominator.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 82003,
				'customer_id'        => 303,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-expired',
				'schedule_cancelled' => $this->prior_window_date(),
			]
		);

		[ $start, $end ] = $this->current_window();
		$result           = $this->storage_for( $backend )->get_lapsed_donor_recovery_rate( $start, $end );

		$this->assertTrue( $result['computable'], 'Two genuinely lapsed donors form a computable cohort.' );
		$this->assertSame( 2, $result['denominator'], 'Both donor 302 and 303 are in the lapsed cohort.' );
		$this->assertEqualsWithDelta( 0.5, $result['value'], 0.0001, 'Only donor 302 (1 of 2) recovered.' );
	}

	/**
	 * No lapsed cohort at all → not computable, denominator 0, value 0.0.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_no_lapsed_cohort_is_not_computable( string $backend ): void {
		[ $start, $end ] = $this->current_window();
		$result           = $this->storage_for( $backend )->get_lapsed_donor_recovery_rate( $start, $end );

		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 0.0, $result['value'] );
	}
}
