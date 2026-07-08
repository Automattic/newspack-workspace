<?php
/**
 * DB-backed characterization test for get_new_subscriber_records_in_window()
 * (perf fix).
 *
 * The storage implementation resolves gate_post_id / popup_id attribution via
 * 3 correlated scalar subqueries per first-subscription row, each a 4-table
 * join (posts/postmeta × order_items × itemmeta) filtered by
 * `customer_id = first_subs.customer_id AND start = first_subs.first_start`
 * — HIGH timeout risk on large subscriptions datasets, since the correlation
 * re-executes the whole join for every row in the outer result.
 *
 * This test pins the query's OUTPUT (customer_id, ts, gate_post_id, popup_id
 * per new-subscriber record) against a small, deliberately adversarial
 * fixture set BEFORE the query is rewritten as pre-aggregated LEFT JOINs. It
 * must pass unchanged on the OLD query (baseline) and again on the NEW query
 * (equivalence proof) — especially the NULL-preservation case (a customer
 * with no gate/popup attribution must still appear, with '' for both) and the
 * gate-precedence case (_gate_post_id wins over the legacy
 * _memberships_content_gate fallback when both are present).
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
 * Characterization test for get_new_subscriber_records_in_window().
 *
 * @group insights
 */
class Test_New_Subscriber_Records_Query extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id, excluded from the new-subscriber population.
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
	 * Insert a parent shop_order carrying gate/popup/legacy-gate meta, into
	 * the given backend.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @param array  $args    Order spec (see the fixtures trait): `order_id`,
	 *                        `gate_ids`, `popup_ids`, and `legacy_gate_id`
	 *                        (maps to `_memberships_content_gate`, not natively
	 *                        supported by the trait's `insert_*_order()`).
	 * @return int The created order id.
	 */
	private function insert_parent_order( string $backend, array $args ): int {
		global $wpdb;
		$order_id = 'hpos' === $backend
			? $this->insert_hpos_order( $args )
			: $this->insert_legacy_order( $args );

		if ( isset( $args['legacy_gate_id'] ) ) {
			if ( 'hpos' === $backend ) {
				$wpdb->insert(
					"{$wpdb->prefix}wc_orders_meta",
					[
						'order_id'   => $order_id,
						'meta_key'   => '_memberships_content_gate',
						'meta_value' => $args['legacy_gate_id'],
					]
				);
			} else {
				add_post_meta( $order_id, '_memberships_content_gate', $args['legacy_gate_id'] );
			}
		}
		return $order_id;
	}

	/**
	 * Insert a shop_subscription row (optionally parented to an initiating
	 * order) into the given backend.
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
	 * Find a record by customer_id in a get_new_subscriber_records_in_window()
	 * result set.
	 *
	 * @param array<int, array<string, mixed>> $records Result set.
	 * @param int                              $customer_id Customer id to find.
	 * @return array<string, mixed>|null
	 */
	private function find_record( array $records, int $customer_id ): ?array {
		foreach ( $records as $record ) {
			if ( $record['customer_id'] === $customer_id ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Isolated case: customer with a `_gate_post_id` on the parent order gets
	 * gate_post_id populated and popup_id blank.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_gate_attributed_first_subscriber_is_recorded( string $backend ): void {
		$base       = 81000 + ( 'hpos' === $backend ? 500 : 0 );
		$order_id   = $base + 1;
		$sub_id     = $base + 2;
		$in_window  = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 301;

		$this->insert_parent_order(
			$backend,
			[
				'order_id'    => $order_id,
				'customer_id' => $customer_id,
				'product_id'  => self::SUB_PRODUCT_ID,
				'gate_ids'    => [ 4001 ],
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $sub_id,
				'customer_id'     => $customer_id,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $order_id,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );
		$record           = $this->find_record( $records, $customer_id );

		$this->assertNotNull( $record, 'Customer with a gate-attributed first subscription must be recorded.' );
		$this->assertSame( '4001', $record['gate_post_id'] );
		$this->assertSame( '', $record['popup_id'] );
	}

	/**
	 * Isolated case: customer with a `_newspack_popup_id` on the parent order
	 * gets popup_id populated and gate_post_id blank.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_popup_attributed_first_subscriber_is_recorded( string $backend ): void {
		$base        = 81100 + ( 'hpos' === $backend ? 500 : 0 );
		$order_id    = $base + 1;
		$sub_id      = $base + 2;
		$in_window   = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 302;

		$this->insert_parent_order(
			$backend,
			[
				'order_id'    => $order_id,
				'customer_id' => $customer_id,
				'product_id'  => self::SUB_PRODUCT_ID,
				'popup_ids'   => [ 5001 ],
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $sub_id,
				'customer_id'     => $customer_id,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $order_id,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );
		$record           = $this->find_record( $records, $customer_id );

		$this->assertNotNull( $record, 'Customer with a popup-attributed first subscription must be recorded.' );
		$this->assertSame( '', $record['gate_post_id'] );
		$this->assertSame( '5001', $record['popup_id'] );
	}

	/**
	 * Crux case: customer with NO gate/popup attribution on the parent order
	 * must still be recorded (NULL-preservation), with both fields blank —
	 * not silently dropped by the rewrite's LEFT JOINs.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_unattributed_first_subscriber_still_recorded_with_blank_attribution( string $backend ): void {
		$base        = 81200 + ( 'hpos' === $backend ? 500 : 0 );
		$order_id    = $base + 1;
		$sub_id      = $base + 2;
		$in_window   = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 303;

		$this->insert_parent_order(
			$backend,
			[
				'order_id'    => $order_id,
				'customer_id' => $customer_id,
				'product_id'  => self::SUB_PRODUCT_ID,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $sub_id,
				'customer_id'     => $customer_id,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $order_id,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );
		$record           = $this->find_record( $records, $customer_id );

		$this->assertNotNull( $record, 'A customer with no gate/popup attribution must still appear in the result set.' );
		$this->assertSame( '', $record['gate_post_id'], 'No attribution must yield blank, not a dropped/null row.' );
		$this->assertSame( '', $record['popup_id'] );
	}

	/**
	 * Crux case: gate precedence. When BOTH `_gate_post_id` and the legacy
	 * `_memberships_content_gate` are present on the parent order,
	 * `_gate_post_id` must win.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_gate_post_id_takes_precedence_over_legacy_gate( string $backend ): void {
		$base        = 81300 + ( 'hpos' === $backend ? 500 : 0 );
		$order_id    = $base + 1;
		$sub_id      = $base + 2;
		$in_window   = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 304;

		$this->insert_parent_order(
			$backend,
			[
				'order_id'       => $order_id,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'gate_ids'       => [ 4002 ],
				'legacy_gate_id' => 4999,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $sub_id,
				'customer_id'     => $customer_id,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $order_id,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );
		$record           = $this->find_record( $records, $customer_id );

		$this->assertNotNull( $record );
		$this->assertSame( '4002', $record['gate_post_id'], '_gate_post_id must take precedence over the legacy _memberships_content_gate.' );
	}

	/**
	 * Crux case: legacy gate fallback. When ONLY the legacy
	 * `_memberships_content_gate` is present (no `_gate_post_id`), it must be
	 * used as the gate_post_id.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_legacy_gate_used_as_fallback_when_gate_post_id_absent( string $backend ): void {
		$base        = 81400 + ( 'hpos' === $backend ? 500 : 0 );
		$order_id    = $base + 1;
		$sub_id      = $base + 2;
		$in_window   = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 305;

		$this->insert_parent_order(
			$backend,
			[
				'order_id'       => $order_id,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'legacy_gate_id' => 4998,
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $sub_id,
				'customer_id'     => $customer_id,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $order_id,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );
		$record           = $this->find_record( $records, $customer_id );

		$this->assertNotNull( $record );
		$this->assertSame( '4998', $record['gate_post_id'] );
	}

	/**
	 * Guest subscriptions (customer_id = 0) are excluded entirely.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_guest_subscription_excluded( string $backend ): void {
		$base      = 81500 + ( 'hpos' === $backend ? 500 : 0 );
		$sub_id    = $base + 1;
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $sub_id,
				'customer_id'    => 0,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_window,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );

		$this->assertNull( $this->find_record( $records, 0 ), 'Guest (customer_id = 0) subscriptions must be excluded.' );
	}

	/**
	 * Only the customer's FIRST-EVER subscription start counts; a later
	 * subscription start inside the window for a customer whose true first
	 * subscription predates the window must NOT re-trigger inclusion.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_only_first_ever_subscription_counts_not_a_later_one_in_window( string $backend ): void {
		$base         = 81600 + ( 'hpos' === $backend ? 500 : 0 );
		$first_sub_id = $base + 1;
		$second_sub_id = $base + 2;
		$out_window   = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$in_window    = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id  = 306;

		// First-ever subscription, OUTSIDE the window.
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $first_sub_id,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-cancelled',
				'schedule_start' => $out_window,
			]
		);
		// A SECOND subscription (e.g. a resubscribe), inside the window.
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $second_sub_id,
				'customer_id'    => $customer_id,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_window,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );

		$this->assertNull(
			$this->find_record( $records, $customer_id ),
			'A customer whose FIRST-EVER subscription predates the window must be excluded, even if a later subscription falls inside it.'
		);
	}

	/**
	 * Donation-product subscriptions are excluded from the new-subscriber
	 * population entirely.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donation_subscription_excluded( string $backend ): void {
		$base      = 81700 + ( 'hpos' === $backend ? 500 : 0 );
		$sub_id    = $base + 1;
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 307;

		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $sub_id,
				'customer_id'    => $customer_id,
				'product_id'     => self::DONATION_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_window,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );

		$this->assertNull( $this->find_record( $records, $customer_id ), 'Donation-product subscriptions must be excluded.' );
	}

	/**
	 * Crux case: tied first subscriptions (same customer, same
	 * `_schedule_start`) with DIFFERENT parent orders — the lower-id parent
	 * order carries NO gate/popup meta, but the tied sibling's (higher-id)
	 * parent order DOES carry `_gate_post_id`. Attribution must be recovered
	 * from the tied sibling rather than lost because the resolver collapsed
	 * to the lowest parent id before the meta lookup.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_tied_first_subscriptions_recover_attribution_from_sibling_parent( string $backend ): void {
		$base        = 81900 + ( 'hpos' === $backend ? 500 : 0 );
		$in_window   = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$customer_id = 501;

		// Lower-id parent order: NO gate/popup meta at all.
		$this->insert_parent_order(
			$backend,
			[
				'order_id'    => $base + 1,
				'customer_id' => $customer_id,
				'product_id'  => self::SUB_PRODUCT_ID,
			]
		);
		// Its subscription — tied first-start, lower-id parent.
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $base + 2,
				'customer_id'     => $customer_id,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $base + 1,
			]
		);

		// Higher-id parent order: HAS `_gate_post_id`.
		$this->insert_parent_order(
			$backend,
			[
				'order_id'    => $base + 3,
				'customer_id' => $customer_id,
				'product_id'  => self::SUB_PRODUCT_ID,
				'gate_ids'    => [ 7001 ],
			]
		);
		// Its subscription — the SAME tied first-start, higher-id parent.
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $base + 4,
				'customer_id'     => $customer_id,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $base + 3,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );
		$record           = $this->find_record( $records, $customer_id );

		$this->assertNotNull( $record, 'Customer with tied first subscriptions must still be recorded.' );
		$this->assertSame(
			'7001',
			$record['gate_post_id'],
			'Attribution must be recovered from the tied sibling parent order, not lost because the MIN(parent) resolver picked the meta-less lower-id parent.'
		);
	}

	/**
	 * Combined scenario: run all the anchor cases together in one fixture set
	 * and assert the full result set — proving the rewrite doesn't cross-
	 * contaminate attribution between customers when multiple derived-table
	 * joins are active simultaneously.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_combined_anchor_cases_produce_expected_record_set( string $backend ): void {
		$in_window = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$base      = 81800 + ( 'hpos' === $backend ? 500 : 0 );

		// Gate-attributed customer 401.
		$this->insert_parent_order(
			$backend,
			[
				'order_id'    => $base + 1,
				'customer_id' => 401,
				'product_id'  => self::SUB_PRODUCT_ID,
				'gate_ids'    => [ 9001 ],
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $base + 2,
				'customer_id'     => 401,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $base + 1,
			]
		);

		// Popup-attributed customer 402.
		$this->insert_parent_order(
			$backend,
			[
				'order_id'    => $base + 3,
				'customer_id' => 402,
				'product_id'  => self::SUB_PRODUCT_ID,
				'popup_ids'   => [ 9002 ],
			]
		);
		$this->insert_subscription(
			$backend,
			[
				'order_id'        => $base + 4,
				'customer_id'     => 402,
				'product_id'      => self::SUB_PRODUCT_ID,
				'status'          => 'wc-active',
				'schedule_start'  => $in_window,
				'parent_order_id' => $base + 3,
			]
		);

		// Unattributed customer 403 (no parent order meta at all — organic).
		$this->insert_subscription(
			$backend,
			[
				'order_id'       => $base + 5,
				'customer_id'    => 403,
				'product_id'     => self::SUB_PRODUCT_ID,
				'status'         => 'wc-active',
				'schedule_start' => $in_window,
			]
		);

		[ $start, $end ] = $this->window();
		$records          = $this->storage_for( $backend )->get_new_subscriber_records_in_window( $start, $end );

		$this->assertCount( 3, $records, 'Exactly the three seeded customers must be present.' );

		$rec401 = $this->find_record( $records, 401 );
		$rec402 = $this->find_record( $records, 402 );
		$rec403 = $this->find_record( $records, 403 );

		$this->assertSame( '9001', $rec401['gate_post_id'] );
		$this->assertSame( '', $rec401['popup_id'] );

		$this->assertSame( '', $rec402['gate_post_id'] );
		$this->assertSame( '9002', $rec402['popup_id'] );

		$this->assertSame( '', $rec403['gate_post_id'] );
		$this->assertSame( '', $rec403['popup_id'] );
	}
}
