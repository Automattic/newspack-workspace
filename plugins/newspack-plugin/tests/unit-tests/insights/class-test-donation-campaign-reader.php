<?php
/**
 * DB-backed integration tests for the donation campaign reader
 * (get_donation_campaign_rows) on BOTH storage backends (NEWS-2580).
 *
 * Stands up the real WooCommerce order tables (via {@see Insights_Woo_Order_Fixtures})
 * so the reader SQL runs against actual rows on legacy AND HPOS. Anchor case = the
 * non-unique `utm_campaign` meta must not inflate: the reader derives one campaign
 * per order via a correlated MIN() subquery (no JOIN), so a duplicate-meta order
 * yields ONE row with un-doubled revenue.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Donors_Storage_Interface;
use Newspack\Insights\HPOS_Donors_Storage;
use Newspack\Insights\Legacy_Donors_Storage;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Integration tests for the donation campaign reader.
 *
 * @group insights
 */
class Test_Donation_Campaign_Reader extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	const DONATION_PRODUCT_ID = 999001;

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
	 * The reader under test for a backend.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return Donors_Storage_Interface
	 */
	private function reader_for( string $backend ): Donors_Storage_Interface {
		return 'hpos' === $backend
			? new HPOS_Donors_Storage( [ self::DONATION_PRODUCT_ID ] )
			: new Legacy_Donors_Storage( [ self::DONATION_PRODUCT_ID ] );
	}

	/**
	 * Insert an order into the given backend.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @param array  $args    Order spec (see the fixtures trait).
	 * @return void
	 */
	private function insert_order( string $backend, array $args ): void {
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
		return $this->reader_for( $backend )->get_donation_campaign_rows(
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
	 * A tagged donation order yields one row: its campaign + order total.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_tagged_order_row( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6001,
				'total'     => 100.00,
				'campaigns' => [ 'spring-drive' ],
			]
		);

		$this->assertEquals(
			[
				[
					'utm_campaign' => 'spring-drive',
					'revenue'      => 100.00,
				],
			],
			$this->run_reader( $backend )
		);
	}

	/**
	 * An untagged donation order is still emitted, carrying '' so the grouping
	 * layer can fold it into "(no campaign)".
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_untagged_order_emitted_with_empty_campaign( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6002,
				'total'     => 50.00,
				'campaigns' => [],
			]
		);

		$this->assertEquals(
			[
				[
					'utm_campaign' => '',
					'revenue'      => 50.00,
				],
			],
			$this->run_reader( $backend )
		);
	}

	/**
	 * ANCHOR: non-unique `utm_campaign` meta (two identical rows) must NOT inflate.
	 * The correlated MIN() subquery yields one campaign per order and there is no
	 * JOIN, so the order appears once with un-doubled revenue.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_duplicate_campaign_meta_counted_once( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6003,
				'total'     => 80.00,
				'campaigns' => [ 'buffer', 'buffer' ],
			]
		);

		$result = $this->run_reader( $backend );

		$this->assertCount( 1, $result, 'duplicate meta must not multiply rows' );
		$this->assertSame( 'buffer', $result[0]['utm_campaign'] );
		$this->assertSame( 80.00, $result[0]['revenue'], 'revenue must not double' );
	}

	/**
	 * Renewal orders (carry `_subscription_renewal`) are excluded; only the initial
	 * donation is emitted.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_renewal_order_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6004,
				'total'     => 40.00,
				'campaigns' => [ 'x' ],
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6005,
				'total'     => 25.00,
				'campaigns' => [ 'x' ],
				'renewal'   => '9001',
			]
		);

		$this->assertEquals(
			[
				[
					'utm_campaign' => 'x',
					'revenue'      => 40.00,
				],
			],
			$this->run_reader( $backend )
		);
	}

	/**
	 * Mixed set: one row per order (tagged + untagged), no grouping at the reader
	 * layer — the fold groups later.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_emits_one_row_per_order( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6006,
				'total'     => 10.00,
				'campaigns' => [ 'alpha' ],
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6007,
				'total'     => 20.00,
				'campaigns' => [ 'alpha' ],
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6008,
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
