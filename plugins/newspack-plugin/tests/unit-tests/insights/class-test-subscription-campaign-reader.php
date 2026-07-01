<?php
/**
 * DB-backed integration tests for the subscription campaign reader
 * (get_new_subscription_campaign_rows) on BOTH storage backends (NEWS-2591).
 *
 * The subscription twin of {@see Test_Donation_Campaign_Reader}. Mirrors the
 * scope of get_attributed_subscription_orders (initial non-renewal orders with a
 * non-donation subscription product) but keyed on `utm_campaign` and WITHOUT the
 * gate/popup requirement, so untagged subscription orders are emitted too (for
 * the "(no campaign)" bucket). Non-unique `utm_campaign` meta must not inflate
 * (correlated MIN, no JOIN); renewals and donation-product orders are excluded.
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
 * Integration tests for the subscription campaign reader.
 *
 * @group insights
 */
class Test_Subscription_Campaign_Reader extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	const DONATION_PRODUCT_ID = 999001;

	/**
	 * Non-donation subscription product id, created fresh per test.
	 *
	 * @var int
	 */
	private $sub_product_id;

	/**
	 * Stand up the WC order tables once (InnoDB → per-test inserts roll back).
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
	 * Create a non-donation subscription product and the (excluded) donation
	 * product, both subscription-typed so `subscription_product_ids_sql()` returns
	 * them and the donation NOT IN filter is exercised.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! taxonomy_exists( 'product_type' ) ) {
			register_taxonomy( 'product_type', 'product' );
		}
		$this->sub_product_id = (int) wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Membership',
			]
		);
		wp_set_object_terms( $this->sub_product_id, 'subscription', 'product_type' );

		wp_insert_post(
			[
				'import_id'   => self::DONATION_PRODUCT_ID,
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Recurring donation',
			]
		);
		wp_set_object_terms( self::DONATION_PRODUCT_ID, 'subscription', 'product_type' );
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
	 * The reader under test for a backend, scoped to exclude the donation product.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return Storage_Interface
	 */
	private function reader_for( string $backend ): Storage_Interface {
		return 'hpos' === $backend
			? new HPOS_Storage( [ self::DONATION_PRODUCT_ID ] )
			: new Legacy_Storage( [ self::DONATION_PRODUCT_ID ] );
	}

	/**
	 * Insert an order (defaults product to the subscription product).
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @param array  $args    Order spec (see the fixtures trait).
	 * @return void
	 */
	private function insert_order( string $backend, array $args ): void {
		$args['product_id'] = $args['product_id'] ?? $this->sub_product_id;
		if ( 'hpos' === $backend ) {
			$this->insert_hpos_order( $args );
		} else {
			$this->insert_legacy_order( $args );
		}
	}

	/**
	 * Run the reader for a window bracketing the fixtures' order dates.
	 *
	 * @param string $backend Backend key.
	 * @return array
	 */
	private function run_reader( string $backend ): array {
		$tz = new DateTimeZone( 'UTC' );
		return $this->reader_for( $backend )->get_new_subscription_campaign_rows(
			new DateTimeImmutable( '-15 days', $tz ),
			new DateTimeImmutable( '+1 day', $tz )
		);
	}

	/**
	 * Sort per-order rows deterministically (no ORDER BY in the reader).
	 *
	 * @param array $rows Reader output.
	 * @return array
	 */
	private function sorted( array $rows ): array {
		usort(
			$rows,
			static function ( $a, $b ) {
				return [ $a['utm_campaign'], $a['revenue'] ] <=> [ $b['utm_campaign'], $b['revenue'] ];
			}
		);
		return $rows;
	}

	/**
	 * A tagged subscription order yields one row: its campaign + initial order total.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_tagged_order_row( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7001,
				'total'     => 120.00,
				'campaigns' => [ 'news-sub-drive' ],
			]
		);

		$this->assertEquals(
			[
				[
					'utm_campaign' => 'news-sub-drive',
					'revenue'      => 120.00,
				],
			],
			$this->run_reader( $backend )
		);
	}

	/**
	 * An untagged subscription order is still emitted, carrying '' for the
	 * "(no campaign)" fold.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_untagged_order_emitted_with_empty_campaign( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7002,
				'total'     => 60.00,
				'campaigns' => [],
			]
		);

		$this->assertEquals(
			[
				[
					'utm_campaign' => '',
					'revenue'      => 60.00,
				],
			],
			$this->run_reader( $backend )
		);
	}

	/**
	 * ANCHOR: non-unique `utm_campaign` meta must NOT inflate (correlated MIN, no
	 * JOIN) — one row, un-doubled revenue.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_duplicate_campaign_meta_counted_once( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7003,
				'total'     => 90.00,
				'campaigns' => [ 'buffer', 'buffer' ],
			]
		);

		$result = $this->run_reader( $backend );

		$this->assertCount( 1, $result, 'duplicate meta must not multiply rows' );
		$this->assertSame( 'buffer', $result[0]['utm_campaign'] );
		$this->assertSame( 90.00, $result[0]['revenue'], 'revenue must not double' );
	}

	/**
	 * Renewal orders are excluded; only the initial subscription order is emitted.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_renewal_order_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7004,
				'total'     => 45.00,
				'campaigns' => [ 'x' ],
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7005,
				'total'     => 30.00,
				'campaigns' => [ 'x' ],
				'renewal'   => '9001',
			]
		);

		$this->assertEquals(
			[
				[
					'utm_campaign' => 'x',
					'revenue'      => 45.00,
				],
			],
			$this->run_reader( $backend )
		);
	}

	/**
	 * A donation-product order (subscription-typed, but a recurring donation) is
	 * excluded — donations belong to the Donors tab, not here.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donation_product_order_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'   => 7006,
				'total'      => 200.00,
				'campaigns'  => [ 'y' ],
				'product_id' => self::DONATION_PRODUCT_ID,
			]
		);

		$this->assertSame( [], $this->run_reader( $backend ) );
	}

	/**
	 * Mixed set: one row per initial subscription order (tagged + untagged); the
	 * fold groups later.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_emits_one_row_per_order( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7007,
				'total'     => 10.00,
				'campaigns' => [ 'alpha' ],
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7008,
				'total'     => 20.00,
				'campaigns' => [ 'alpha' ],
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 7009,
				'total'     => 30.00,
				'campaigns' => [],
			]
		);

		$result = $this->sorted( $this->run_reader( $backend ) );

		$this->assertCount( 3, $result );
		$this->assertSame( [ '', 'alpha', 'alpha' ], array_column( $result, 'utm_campaign' ) );
		$this->assertSame( 60.00, array_sum( array_column( $result, 'revenue' ) ) );
	}
}
