<?php
/**
 * DB-backed characterization test for get_cancellation_reasons() (perf fix).
 *
 * The storage implementation restricts to non-donation subscriptions via
 * `p.ID IN (SELECT DISTINCT oi.order_id FROM ... WHERE oim.meta_value NOT IN
 * (donations))` — a semi-join subquery flagged for timeout risk on large
 * subscriptions datasets.
 *
 * This test pins the query's OUTPUT (cancellation_reason => count buckets)
 * against a small, deliberately adversarial fixture set BEFORE the query is
 * rewritten as a pre-aggregated INNER JOIN semi-join. It must pass unchanged
 * on the OLD query (baseline) and again on the NEW query (equivalence proof)
 * — especially the crux case: a subscription with MULTIPLE non-donation line
 * items must still be counted ONCE (the DISTINCT in the original subquery
 * guards against this; the rewrite's derived table must too).
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

use Newspack\Insights\HPOS_Storage;
use Newspack\Insights\Legacy_Storage;
use Newspack\Insights\Storage_Interface;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Characterization test for get_cancellation_reasons().
 *
 * @group insights
 */
class Test_Cancellation_Reasons_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id, excluded from the cancellation-reason population.
	 */
	const DONATION_PRODUCT_ID = 999001;

	/**
	 * Non-donation subscription product id.
	 */
	const SUB_PRODUCT_ID = 555001;

	/**
	 * A second non-donation subscription product id (for the multi-line-item case).
	 */
	const SUB_PRODUCT_ID_2 = 555002;

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
	 * Set the `newspack_subscriptions_cancellation_reason` meta on a
	 * subscription, in the given backend.
	 *
	 * @param string $backend Backend key.
	 * @param int    $sub_id  Subscription id.
	 * @param string $reason  Reason value.
	 * @return void
	 */
	private function set_reason( string $backend, int $sub_id, string $reason ): void {
		if ( 'hpos' === $backend ) {
			global $wpdb;
			$wpdb->insert(
				"{$wpdb->prefix}wc_orders_meta",
				[
					'order_id'   => $sub_id,
					'meta_key'   => 'newspack_subscriptions_cancellation_reason',
					'meta_value' => $reason,
				]
			);
		} else {
			add_post_meta( $sub_id, 'newspack_subscriptions_cancellation_reason', $reason );
		}
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
	 * Find a reason bucket's count by reason string.
	 *
	 * @param array<int, array{cancellation_reason:string, count:int}> $rows   Result set.
	 * @param string                                                   $reason Reason to find.
	 * @return int|null
	 */
	private function count_for( array $rows, string $reason ): ?int {
		foreach ( $rows as $row ) {
			if ( $row['cancellation_reason'] === $reason ) {
				return $row['count'];
			}
		}
		return null;
	}

	/**
	 * Case A: a cancelled subscription in the window with a reason meta is
	 * bucketed under that reason.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_reason_bucket_counts_cancelled_subscription_in_window( string $backend ): void {
		$base      = 92000 + ( 'hpos' === $backend ? 500 : 0 );
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$sub_id = $this->insert_subscription(
			$backend,
			[
				'order_id'           => $base + 1,
				'customer_id'        => 601,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->set_reason( $backend, $sub_id, 'too_expensive' );

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_cancellation_reasons( $start, $end );

		$this->assertSame( 1, $this->count_for( $rows, 'too_expensive' ) );
	}

	/**
	 * Case B: a cancelled subscription with NO reason meta is bucketed as
	 * 'unknown'.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_missing_reason_bucketed_as_unknown( string $backend ): void {
		$base      = 92100 + ( 'hpos' === $backend ? 500 : 0 );
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$this->insert_subscription(
			$backend,
			[
				'order_id'           => $base + 1,
				'customer_id'        => 602,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-expired',
				'schedule_cancelled' => $in_window,
			]
		);

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_cancellation_reasons( $start, $end );

		$this->assertSame( 1, $this->count_for( $rows, 'unknown' ) );
	}

	/**
	 * Case C: a cancelled subscription OUTSIDE the window is excluded.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_cancellation_outside_window_excluded( string $backend ): void {
		$base       = 92200 + ( 'hpos' === $backend ? 500 : 0 );
		$out_window = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

		$sub_id = $this->insert_subscription(
			$backend,
			[
				'order_id'           => $base + 1,
				'customer_id'        => 603,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $out_window,
			]
		);
		$this->set_reason( $backend, $sub_id, 'out_of_window_reason' );

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_cancellation_reasons( $start, $end );

		$this->assertNull( $this->count_for( $rows, 'out_of_window_reason' ) );
	}

	/**
	 * Case D: a cancelled DONATION-product subscription in window is excluded.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donation_cancellation_excluded( string $backend ): void {
		$base      = 92300 + ( 'hpos' === $backend ? 500 : 0 );
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$sub_id = $this->insert_subscription(
			$backend,
			[
				'order_id'           => $base + 1,
				'customer_id'        => 604,
				'product_id'         => self::DONATION_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		$this->set_reason( $backend, $sub_id, 'donation_reason' );

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_cancellation_reasons( $start, $end );

		$this->assertNull( $this->count_for( $rows, 'donation_reason' ) );
	}

	/**
	 * Case E: multiple cancelled subscriptions sharing the same reason are
	 * summed under one bucket.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_multiple_subscriptions_same_reason_summed( string $backend ): void {
		$base      = 92400 + ( 'hpos' === $backend ? 500 : 0 );
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		foreach ( [ 605, 606, 607 ] as $i => $customer_id ) {
			$sub_id = $this->insert_subscription(
				$backend,
				[
					'order_id'           => $base + 1 + $i,
					'customer_id'        => $customer_id,
					'product_id'         => self::SUB_PRODUCT_ID,
					'status'             => 'wc-cancelled',
					'schedule_cancelled' => $in_window,
				]
			);
			$this->set_reason( $backend, $sub_id, 'switched_provider' );
		}

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_cancellation_reasons( $start, $end );

		$this->assertSame( 3, $this->count_for( $rows, 'switched_provider' ) );
	}

	/**
	 * Crux case: a subscription with TWO non-donation line items must be
	 * counted ONCE, not twice — the original's `DISTINCT oi.order_id` in the
	 * semi-join subquery guards against this; the rewrite's pre-aggregated
	 * derived table must preserve that DISTINCT.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_subscription_with_multiple_line_items_counted_once( string $backend ): void {
		$base      = 92500 + ( 'hpos' === $backend ? 500 : 0 );
		$order_id  = $base + 1;
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$sub_id = $this->insert_subscription(
			$backend,
			[
				'order_id'           => $order_id,
				'customer_id'        => 608,
				'product_id'         => self::SUB_PRODUCT_ID,
				'status'             => 'wc-cancelled',
				'schedule_cancelled' => $in_window,
			]
		);
		// Add a SECOND non-donation line item to the same subscription order.
		$this->insert_subscription_line_item( $sub_id, self::SUB_PRODUCT_ID_2 );
		$this->set_reason( $backend, $sub_id, 'multi_item_reason' );

		[ $start, $end ] = $this->window();
		$rows             = $this->storage_for( $backend )->get_cancellation_reasons( $start, $end );

		$this->assertSame(
			1,
			$this->count_for( $rows, 'multi_item_reason' ),
			'A subscription with multiple non-donation line items must be counted once, not once per line item.'
		);
	}
}
