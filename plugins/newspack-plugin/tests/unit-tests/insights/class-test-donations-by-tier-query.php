<?php
/**
 * DB-backed characterization test for get_donations_by_tier()
 * (donor-query perf fix).
 *
 * `get_donations_by_tier()` runs 3 passes of wide joins. Pass 2 (lapsed
 * donors per tier) used a `cust.meta_value NOT IN (SELECT ... multi-join
 * subquery)` for the active-subscriber exclusion — MySQL's slowest
 * anti-join form, plus an unindexed `_schedule_cancelled` range scan. Pass
 * 3 already uses a pre-aggregated (non-correlated) derived table for
 * first-donation-date, so it is untouched by this fix.
 *
 * This test pins the query's OUTPUT (the per-product tier rows) against a
 * small, deliberately adversarial fixture set BEFORE Pass 2 is rewritten
 * as a pre-aggregated LEFT JOIN anti-join (mirroring
 * get_lapsed_donors_in_window()). It must pass unchanged on the OLD query
 * (baseline) and again on the NEW query (equivalence proof) — especially
 * the crux case: a donor who cancelled a donation subscription for a given
 * product INSIDE the window but holds a SEPARATE active donation
 * subscription, which must be excluded from that product's
 * `lapsed_donors_in_window` count.
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
 * Characterization test for get_donations_by_tier().
 *
 * @group insights
 */
class Test_Donations_By_Tier_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Simple (non-variable) donation product A — a real `wp_posts` row is
	 * required because the tier SQL JOINs `{$prefix}posts pv ON pv.ID = ...`.
	 */
	const PRODUCT_A_ID = 999401;

	/**
	 * Simple (non-variable) donation product B, used to prove Pass 2 buckets
	 * lapsed donors per-product, not globally.
	 */
	const PRODUCT_B_ID = 999402;

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
	 * Create the real `wp_posts` rows the tier SQL's `pv` JOIN requires for
	 * each simple donation product, once per test.
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( [ self::PRODUCT_A_ID, self::PRODUCT_B_ID ] as $product_id ) {
			wp_insert_post(
				[
					'import_id'   => $product_id,
					'post_type'   => 'product',
					'post_status' => 'publish',
					'post_title'  => 'Donation Product ' . $product_id,
				]
			);
		}
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
	 * The storage instance under test for a backend, scoped to both donation
	 * products.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return Donors_Storage_Interface
	 */
	private function storage_for( string $backend ): Donors_Storage_Interface {
		return 'hpos' === $backend
			? new HPOS_Donors_Storage( [ self::PRODUCT_A_ID, self::PRODUCT_B_ID ] )
			: new Legacy_Donors_Storage( [ self::PRODUCT_A_ID, self::PRODUCT_B_ID ] );
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
	 * Find the tier row for a given (effective) product id — since neither
	 * fixture product here is a variation, `variation_id` === the product id.
	 *
	 * @param array $rows   get_donations_by_tier() output.
	 * @param int   $prod_id Product id to find.
	 * @return array|null
	 */
	private function find_row( array $rows, int $prod_id ): ?array {
		foreach ( $rows as $row ) {
			if ( (int) $row['product_id'] === $prod_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The crux case: a donor who cancelled a donation subscription for
	 * PRODUCT_A inside the window, but holds a SEPARATE active donation
	 * subscription (for PRODUCT_A too — the exclusion is per the
	 * scorecard's global "any active donation sub" cohort, not
	 * per-product), must be excluded from PRODUCT_A's
	 * `lapsed_donors_in_window`. A second donor who cancelled PRODUCT_B
	 * with no active sub anywhere IS counted for PRODUCT_B — proving the
	 * bucketing stays per-product while the exclusion cohort is preserved.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_lapsed_bucket_excludes_donor_with_active_sub( string $backend ): void {
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		// Donor 501: cancelled PRODUCT_A in window AND has a separate active
		// PRODUCT_A sub — must NOT be counted as lapsed for PRODUCT_A.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 95001,
				'customer_id'        => 501,
				'product_id'         => self::PRODUCT_A_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 95002,
				'customer_id' => 501,
				'product_id'  => self::PRODUCT_A_ID,
				'status'      => 'wc-active',
			]
		);

		// Donor 502: cancelled PRODUCT_B in window, no active sub anywhere —
		// counted as lapsed for PRODUCT_B.
		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 95003,
				'customer_id'        => 502,
				'product_id'         => self::PRODUCT_B_ID,
				'status'             => 'wc-expired',
				'schedule_cancelled' => $in_window,
			]
		);

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_donations_by_tier( $start, $end );

		$row_a = $this->find_row( $rows, self::PRODUCT_A_ID );
		$row_b = $this->find_row( $rows, self::PRODUCT_B_ID );

		$this->assertNotNull( $row_a, 'PRODUCT_A must appear (it has an active_recurring_donor).' );
		$this->assertSame(
			0,
			$row_a['lapsed_donors_in_window'],
			'Donor 501 has an active donation sub and must be excluded from the lapsed bucket — the crux case.'
		);

		$this->assertNotNull( $row_b, 'PRODUCT_B must appear (it has a lapsed donor).' );
		$this->assertSame(
			1,
			$row_b['lapsed_donors_in_window'],
			'Donor 502 genuinely lapsed with no active sub anywhere and must be counted for PRODUCT_B.'
		);
	}

	/**
	 * Isolate the crux case on its own product (no cross-product fixtures)
	 * to pin the exact scenario independent of the bucketing test above.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donor_with_active_sub_never_counted_as_lapsed_for_any_product( string $backend ): void {
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$this->insert_subscription(
			$backend,
			[
				'order_id'           => 96001,
				'customer_id'        => 601,
				'product_id'         => self::PRODUCT_A_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'    => 96002,
				'customer_id' => 601,
				'product_id'  => self::PRODUCT_A_ID,
				'status'      => 'wc-active',
			]
		);

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_donations_by_tier( $start, $end );
		$row_a            = $this->find_row( $rows, self::PRODUCT_A_ID );

		$this->assertNotNull( $row_a );
		$this->assertSame( 0, $row_a['lapsed_donors_in_window'] );
	}
}
