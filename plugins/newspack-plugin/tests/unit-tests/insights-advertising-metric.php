<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test file defines a test-double subclass alongside the main test class.
/**
 * Tests the Advertising (Tab 8) metric orchestrator (NPPD-1663).
 *
 * The GAM Client is final + static and can't be mocked, so per-metric tests use
 * a subclass that overrides the protected run_gam_report() seam to inject canned
 * parsed rows. Envelope / readiness / runner-skip behavior is exercised against
 * the real (disconnected) test environment.
 *
 * @package Newspack\Tests
 */

use Newspack\Insights\Advertising_Metric;
use Newspack\Insights\GAM\Report_Query;

/**
 * Test double: injects a canned run_gam_report() result.
 */
class Insights_Advertising_Test_Metric extends Advertising_Metric {
	/**
	 * The next run_gam_report() result to return.
	 *
	 * @var array
	 */
	public static $next_report = [ 'rows' => [] ];

	/**
	 * The next resolve_site_key_id() result to return (NPPD-1671).
	 *
	 * @var int|null
	 */
	public static $next_site_key_id = 42;

	/**
	 * Override the GAM-touching seam with the injected result.
	 *
	 * @param Report_Query $query The query (ignored).
	 * @return array
	 */
	protected static function run_gam_report( Report_Query $query ): array {
		return self::$next_report;
	}

	/**
	 * Override the CustomTargetingService-touching seam with the injected key ID.
	 *
	 * @return int|null
	 */
	protected static function resolve_site_key_id(): ?int {
		return static::$next_site_key_id;
	}
}

/**
 * Test the Advertising metric orchestrator.
 *
 * @group insights_advertising
 */
class Newspack_Test_Insights_Advertising_Metric extends WP_UnitTestCase {

	/**
	 * Invoke a private/protected static method on Advertising_Metric.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke( $method, array $args = [] ) {
		$ref = new ReflectionMethod( Advertising_Metric::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	/**
	 * Reset the double between tests.
	 */
	public function tear_down() {
		Insights_Advertising_Test_Metric::$next_report      = [ 'rows' => [] ];
		Insights_Advertising_Test_Metric::$next_site_key_id = 42;
		Advertising_Metric::reset_readiness_cache();
		parent::tear_down();
	}

	/**
	 * Inject canned rows for the next metric call.
	 *
	 * @param array $rows Parsed CSV rows.
	 */
	private function with_rows( array $rows ) {
		Insights_Advertising_Test_Metric::$next_report = [ 'rows' => $rows ];
	}

	/**
	 * Total impressions sums the column and returns a count.
	 */
	public function test_total_impressions() {
		$this->with_rows( [ [ 'TOTAL_IMPRESSIONS' => '2400000' ] ] );
		$payload = Insights_Advertising_Test_Metric::total_impressions( '2026-01-01', '2026-01-31' );
		$this->assertSame( 2400000, $payload['value'] );
		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'count', $payload['type'] );
	}

	/**
	 * Total revenue normalizes micro-currency to standard currency.
	 */
	public function test_total_revenue_normalizes_micros() {
		$this->with_rows( [ [ 'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '4200000000' ] ] );
		$payload = Insights_Advertising_Test_Metric::total_revenue( '2026-01-01', '2026-01-31' );
		$this->assertSame( 4200.0, $payload['value'] );
		$this->assertSame( 'currency', $payload['type'] );
	}

	/**
	 * Revenue by day (NPPD-1674) sorts chronologically regardless of GAM's return
	 * order and normalizes each day's revenue from micros.
	 */
	public function test_revenue_by_day_sorts_and_normalizes() {
		$this->with_rows(
			[
				[
					'DATE'                              => '2026-01-03',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 150 * 1000000 ),
				],
				[
					'DATE'                              => '2026-01-01',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 100 * 1000000 ),
				],
				[
					'DATE'                              => '2026-01-02',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 120 * 1000000 ),
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::revenue_by_day( '2026-01-01', '2026-01-31' );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'timeseries', $payload['type'] );
		$this->assertSame( [ '2026-01-01', '2026-01-02', '2026-01-03' ], array_column( $payload['rows'], 'date' ) );
		$this->assertSame( 100.0, $payload['rows'][0]['value'] );
		$this->assertSame( 150.0, $payload['rows'][2]['value'] );
	}

	/**
	 * Revenue by day is not computable when the window has no daily rows.
	 */
	public function test_revenue_by_day_empty_not_computable() {
		$this->with_rows( [] );
		$payload = Insights_Advertising_Test_Metric::revenue_by_day( '2026-01-01', '2026-01-31' );
		$this->assertFalse( $payload['computable'] );
		$this->assertSame( [], $payload['rows'] );
	}

	/**
	 * Average eCPM derives from normalized revenue and coded impressions.
	 */
	public function test_avg_ecpm() {
		$this->with_rows(
			[
				[
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '4200000000',
					'TOTAL_CODE_SERVED_COUNT'           => '2400000',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::avg_ecpm( '2026-01-01', '2026-01-31' );
		$this->assertSame( 1.75, round( $payload['value'], 2 ) );
		$this->assertTrue( $payload['computable'] );
	}

	/**
	 * Average eCPM is not computable with zero coded impressions.
	 */
	public function test_avg_ecpm_zero_coded_not_computable() {
		$this->with_rows(
			[
				[
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '4200000000',
					'TOTAL_CODE_SERVED_COUNT'           => '0',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::avg_ecpm( '2026-01-01', '2026-01-31' );
		$this->assertFalse( $payload['computable'] );
		$this->assertSame( 0.0, $payload['value'] );
	}

	/**
	 * Fill rate is coded / total impressions, capped at 1.
	 */
	public function test_fill_rate_capped_at_one() {
		$this->with_rows(
			[
				[
					'TOTAL_CODE_SERVED_COUNT' => '2088000',
					'TOTAL_IMPRESSIONS'       => '2400000',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::fill_rate( '2026-01-01', '2026-01-31' );
		$this->assertSame( 0.87, round( $payload['value'], 2 ) );
		$this->assertSame( 'rate', $payload['type'] );
	}

	/**
	 * Viewability rate is viewable / measurable Active View impressions.
	 */
	public function test_viewability_rate() {
		$this->with_rows(
			[
				[
					'TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS'   => '640',
					'TOTAL_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS' => '1000',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::viewability_rate( '2026-01-01', '2026-01-31' );
		$this->assertSame( 0.64, round( $payload['value'], 2 ) );
	}

	/**
	 * Viewability degrades to a data_unavailable overlay without Active View.
	 */
	public function test_viewability_rate_overlay_when_no_active_view() {
		$this->with_rows(
			[
				[
					'TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS'   => '0',
					'TOTAL_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS' => '0',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::viewability_rate( '2026-01-01', '2026-01-31' );
		$this->assertFalse( $payload['computable'] );
		$this->assertSame( 'data_unavailable', $payload['overlay']['type'] );
	}

	/**
	 * Direct vs programmatic buckets LINE_ITEM_TYPE values correctly.
	 */
	public function test_direct_vs_programmatic_buckets_line_item_types() {
		$this->with_rows(
			[
				[
					'LINE_ITEM_TYPE'                    => 'STANDARD',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '3000000000',
					'TOTAL_IMPRESSIONS'                 => '100',
				],
				[
					'LINE_ITEM_TYPE'                    => 'AD_EXCHANGE',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '2000000000',
					'TOTAL_IMPRESSIONS'                 => '200',
				],
				[
					'LINE_ITEM_TYPE'                    => 'HOUSE',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '0',
					'TOTAL_IMPRESSIONS'                 => '50',
				],
			]
		);
		$payload  = Insights_Advertising_Test_Metric::direct_vs_programmatic( '2026-01-01', '2026-01-31' );
		$by_label = array_column( $payload['rows'], null, 'label' );
		$this->assertSame( 3000.0, $by_label['direct']['revenue'] );
		$this->assertSame( 2000.0, $by_label['programmatic']['revenue'] );
		$this->assertSame( 50, $by_label['house']['impressions'] );
		$this->assertTrue( $payload['computable'] );
	}

	/**
	 * Top ad units rank by revenue and derive eCPM from coded impressions.
	 */
	public function test_top_ad_units_ranks_by_revenue_and_derives_ecpm() {
		$this->with_rows(
			[
				[
					'AD_UNIT_NAME'                      => 'Low',
					'TOTAL_IMPRESSIONS'                 => '100',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '1000000',
					'TOTAL_CODE_SERVED_COUNT'           => '100',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '1',
				],
				[
					'AD_UNIT_NAME'                      => 'High',
					'TOTAL_IMPRESSIONS'                 => '500',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '5000000',
					'TOTAL_CODE_SERVED_COUNT'           => '500',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '5',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::top_ad_units( '2026-01-01', '2026-01-31' );
		$this->assertSame( 'High', $payload['rows'][0]['ad_unit'] );
		$this->assertSame( 5.0, $payload['rows'][0]['revenue'] );
		$this->assertSame( 10.0, round( $payload['rows'][0]['ecpm'], 2 ) ); // 5.0 / 500 * 1000.
		$this->assertSame( 'table', $payload['type'] );
	}

	/**
	 * Top ad units respect the row limit.
	 */
	public function test_top_ad_units_respects_limit() {
		$rows = [];
		for ( $i = 1; $i <= 30; $i++ ) {
			$rows[] = [
				'AD_UNIT_NAME'                      => "U$i",
				'TOTAL_IMPRESSIONS'                 => (string) $i,
				'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( $i * 1000000 ),
				'TOTAL_CODE_SERVED_COUNT'           => (string) $i,
				'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '0',
			];
		}
		$this->with_rows( $rows );
		$payload = Insights_Advertising_Test_Metric::top_ad_units( '2026-01-01', '2026-01-31', 25 );
		$this->assertCount( 25, $payload['rows'] );
	}

	/**
	 * Top ad units carry clicks and CTR = clicks / impressions.
	 */
	public function test_top_ad_units_clicks_and_ctr() {
		$this->with_rows(
			[
				[
					'AD_UNIT_NAME'                      => 'Leaderboard',
					'TOTAL_IMPRESSIONS'                 => '1000',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '5000000',
					'TOTAL_CODE_SERVED_COUNT'           => '1000',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '4',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::top_ad_units( '2026-01-01', '2026-01-31' );
		$this->assertSame( 4, $payload['rows'][0]['clicks'] );
		$this->assertSame( 0.004, $payload['rows'][0]['ctr'] );
	}

	/**
	 * CTR is null — never 0% — when a row has no impressions.
	 */
	public function test_top_ad_units_ctr_null_on_zero_impressions() {
		$this->with_rows(
			[
				[
					'AD_UNIT_NAME'                      => 'Unfilled',
					'TOTAL_IMPRESSIONS'                 => '0',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '0',
					'TOTAL_CODE_SERVED_COUNT'           => '0',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '0',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::top_ad_units( '2026-01-01', '2026-01-31' );
		$this->assertNull( $payload['rows'][0]['ctr'] );
	}

	/**
	 * Top advertisers carry clicks and CTR (null with zero impressions).
	 */
	public function test_top_advertisers_clicks_and_ctr() {
		$this->with_rows(
			[
				[
					'ADVERTISER_NAME'                   => 'Acme Co',
					'TOTAL_IMPRESSIONS'                 => '2000',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '10000000',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '10',
				],
				[
					'ADVERTISER_NAME'                   => 'No Delivery Inc',
					'TOTAL_IMPRESSIONS'                 => '0',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '0',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '0',
				],
			]
		);
		$payload  = Insights_Advertising_Test_Metric::top_advertisers( '2026-01-01', '2026-01-31' );
		$by_label = array_column( $payload['rows'], null, 'advertiser' );
		$this->assertSame( 10, $by_label['Acme Co']['clicks'] );
		$this->assertSame( 0.005, $by_label['Acme Co']['ctr'] );
		$this->assertNull( $by_label['No Delivery Inc']['ctr'] );
	}

	/**
	 * By channel groups raw LINE_ITEM_TYPE values into the documented buckets,
	 * normalizes micros, computes each bucket's share of total impressions
	 * (house line items are unpaid, so the pie is impressions-weighted), sorts
	 * by impressions desc, and drops fully-empty buckets.
	 */
	public function test_by_channel_buckets_and_shares() {
		$this->with_rows(
			[
				[
					'LINE_ITEM_TYPE'                    => 'STANDARD',
					'TOTAL_IMPRESSIONS'                 => '100',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '1000000000', // 1000 dollars in micros.
				],
				[
					'LINE_ITEM_TYPE'                    => 'SPONSORSHIP',
					'TOTAL_IMPRESSIONS'                 => '100',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '500000000', // 500 dollars in micros.
				],
				[
					'LINE_ITEM_TYPE'                    => 'AD_EXCHANGE',
					'TOTAL_IMPRESSIONS'                 => '400',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '2000000000', // 2000 dollars in micros.
				],
				[
					'LINE_ITEM_TYPE'                    => 'PRICE_PRIORITY',
					'TOTAL_IMPRESSIONS'                 => '100',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '500000000', // 500 dollars in micros → programmatic.
				],
				[
					'LINE_ITEM_TYPE'                    => 'HOUSE',
					'TOTAL_IMPRESSIONS'                 => '250',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '0',
				],
				[
					'LINE_ITEM_TYPE'                    => 'CLICK_TRACKING',
					'TOTAL_IMPRESSIONS'                 => '50',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '1000000000', // 1000 dollars in micros → other.
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::by_channel( '2026-01-01', '2026-01-31' );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'breakdown', $payload['type'] );

		$by_channel = array_column( $payload['rows'], null, 'channel' );
		$this->assertSame( 1500.0, $by_channel['Direct-sold']['revenue'] );
		$this->assertSame( 200, $by_channel['Direct-sold']['impressions'] );
		$this->assertSame( 2500.0, $by_channel['Programmatic']['revenue'] );
		$this->assertSame( 0.0, $by_channel['House']['revenue'] );
		$this->assertSame( 250, $by_channel['House']['impressions'] );
		$this->assertSame( 1000.0, $by_channel['Other']['revenue'] );

		// Shares are fractions of total impressions (1,000) — impressions-weighted
		// so the unpaid House bucket keeps a real share.
		$this->assertSame( 0.2, $by_channel['Direct-sold']['share'] );
		$this->assertSame( 0.5, $by_channel['Programmatic']['share'] );
		$this->assertSame( 0.25, $by_channel['House']['share'] );

		// Sorted by impressions desc: programmatic (500) leads, then house (250).
		$this->assertSame( 'Programmatic', $payload['rows'][0]['channel'] );
		$this->assertSame( 'House', $payload['rows'][1]['channel'] );
	}

	/**
	 * Unmapped EXCHANGE-suffixed line item types fall back to programmatic;
	 * buckets with no revenue AND no impressions are dropped.
	 */
	public function test_by_channel_exchange_fallback_and_empty_buckets() {
		$this->with_rows(
			[
				[
					'LINE_ITEM_TYPE'                    => 'OPEN_EXCHANGE',
					'TOTAL_IMPRESSIONS'                 => '100',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '1000000000',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::by_channel( '2026-01-01', '2026-01-31' );
		$this->assertCount( 1, $payload['rows'] );
		$this->assertSame( 'Programmatic', $payload['rows'][0]['channel'] );
		$this->assertSame( 1.0, $payload['rows'][0]['share'] );
	}

	/**
	 * Performance by device sorts by impressions desc, normalizes micros, and
	 * derives per-row eCPM (null when the device served no impressions).
	 */
	public function test_by_device_sorts_and_derives_ecpm() {
		$this->with_rows(
			[
				[
					'DEVICE_CATEGORY_NAME'              => 'Desktop',
					'TOTAL_IMPRESSIONS'                 => '400000',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 900 * 1000000 ),
				],
				[
					'DEVICE_CATEGORY_NAME'              => 'Smartphone',
					'TOTAL_IMPRESSIONS'                 => '1200000',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 1800 * 1000000 ),
				],
				[
					'DEVICE_CATEGORY_NAME'              => 'Connected TV',
					'TOTAL_IMPRESSIONS'                 => '0',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '0',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::by_device( '2026-01-01', '2026-01-31' );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'table', $payload['type'] );
		$this->assertSame( [ 'Smartphone', 'Desktop', 'Connected TV' ], array_column( $payload['rows'], 'device' ) );
		$this->assertSame( 1800.0, $payload['rows'][0]['revenue'] );
		$this->assertSame( ( 1800.0 / 1200000 ) * 1000, $payload['rows'][0]['ecpm'] ); // $1.50.
		$this->assertNull( $payload['rows'][2]['ecpm'] );
	}

	/**
	 * Top campaigns shape ORDER_NAME × ADVERTISER_NAME rows: rank by revenue,
	 * carry clicks/CTR, and filter the order-less (programmatic) rows GAM emits
	 * with an empty or "-" order name.
	 */
	public function test_top_campaigns_shapes_ranks_and_filters_orderless_rows() {
		$this->with_rows(
			[
				[
					'ORDER_NAME'                        => 'Hometown Hardware — Spring Flight',
					'ADVERTISER_NAME'                   => 'Hometown Hardware',
					'TOTAL_IMPRESSIONS'                 => '10000',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '40',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 300 * 1000000 ),
				],
				[
					'ORDER_NAME'                        => 'Riverside Credit Union — Auto Loans',
					'ADVERTISER_NAME'                   => 'Riverside Credit Union',
					'TOTAL_IMPRESSIONS'                 => '20000',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '50',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 800 * 1000000 ),
				],
				[
					'ORDER_NAME'                        => '-',
					'ADVERTISER_NAME'                   => '',
					'TOTAL_IMPRESSIONS'                 => '900000',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '100',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 5000 * 1000000 ),
				],
				[
					'ORDER_NAME'                        => '',
					'ADVERTISER_NAME'                   => '',
					'TOTAL_IMPRESSIONS'                 => '100',
					'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '0',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '1000000',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::top_campaigns( '2026-01-01', '2026-01-31' );

		// Order-less rows filtered; direct-sold orders ranked by revenue desc.
		$this->assertCount( 2, $payload['rows'] );
		$top = $payload['rows'][0];
		$this->assertSame( 'Riverside Credit Union — Auto Loans', $top['campaign'] );
		$this->assertSame( 'Riverside Credit Union', $top['advertiser'] );
		$this->assertSame( 800.0, $top['revenue'] );
		$this->assertSame( 50, $top['clicks'] );
		$this->assertSame( 0.0025, $top['ctr'] );
	}

	/**
	 * Top campaigns cap at the top 10 by revenue.
	 */
	public function test_top_campaigns_respects_limit() {
		$rows = [];
		for ( $i = 1; $i <= 15; $i++ ) {
			$rows[] = [
				'ORDER_NAME'                        => "Campaign $i",
				'ADVERTISER_NAME'                   => "Advertiser $i",
				'TOTAL_IMPRESSIONS'                 => '100',
				'TOTAL_LINE_ITEM_LEVEL_CLICKS'      => '1',
				'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( $i * 1000000 ),
			];
		}
		$this->with_rows( $rows );
		$payload = Insights_Advertising_Test_Metric::top_campaigns( '2026-01-01', '2026-01-31' );
		$this->assertCount( 10, $payload['rows'] );
		$this->assertSame( 'Campaign 15', $payload['rows'][0]['campaign'] );
	}

	/**
	 * Per-site breakdown (NPPD-1671) humanizes the `site` URL to a domain,
	 * normalizes revenue micros, derives eCPM, and ranks by revenue desc.
	 */
	public function test_top_sites_shapes_and_ranks_rows() {
		$this->with_rows(
			[
				[
					'CUSTOM_DIMENSION'                  => 'https://www.mv-voice.com',
					'TOTAL_IMPRESSIONS'                 => '410000',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 705 * 1000000 ),
				],
				[
					'CUSTOM_DIMENSION'                  => 'https://www.almanacnews.com',
					'TOTAL_IMPRESSIONS'                 => '980000',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => (string) ( 1720 * 1000000 ),
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::top_sites( '2026-01-01', '2026-01-31', 25 );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'table', $payload['type'] );
		$this->assertCount( 2, $payload['rows'] );

		// Ranked by revenue desc → almanacnews (higher) first; URL humanized to domain.
		$top = $payload['rows'][0];
		$this->assertSame( 'almanacnews.com', $top['site'] );
		$this->assertSame( 980000, $top['impressions'] );
		$this->assertSame( 1720.0, $top['revenue'] );
		$this->assertSame( round( ( 1720.0 / 980000 ) * 1000, 2 ), $top['ecpm'] );
	}

	/**
	 * Returns an empty, non-computable table (and never runs a report) when the
	 * `site` dimension can't be resolved — e.g. not a network member.
	 */
	public function test_top_sites_empty_when_dimension_unresolved() {
		Insights_Advertising_Test_Metric::$next_site_key_id = null;
		// A report result that WOULD shape into rows, to prove it isn't consulted.
		$this->with_rows(
			[
				[
					'CUSTOM_DIMENSION'  => 'https://x.com',
					'TOTAL_IMPRESSIONS' => '5',
				],
			]
		);

		$payload = Insights_Advertising_Test_Metric::top_sites( '2026-01-01', '2026-01-31', 25 );
		$this->assertFalse( $payload['computable'] );
		$this->assertSame( [], $payload['rows'] );
	}

	/**
	 * Without newspack-network active (test env), the site is not a network member,
	 * so the per-site breakdown stays gated off.
	 */
	public function test_is_network_member_false_without_network() {
		$this->assertFalse( Advertising_Metric::is_network_member() );
	}

	/**
	 * Dotted-qualified GAM CSV headers are matched by their enum suffix.
	 */
	public function test_qualified_csv_headers() {
		$this->with_rows(
			[
				[
					'Dimension.AD_UNIT_NAME'              => 'Homepage Leaderboard',
					'Column.TOTAL_IMPRESSIONS'            => '1000',
					'Column.TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '2000000',
					'Column.TOTAL_CODE_SERVED_COUNT'      => '1000',
					'Column.TOTAL_LINE_ITEM_LEVEL_CLICKS' => '0',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::top_ad_units( '2026-01-01', '2026-01-31' );
		$this->assertSame( 'Homepage Leaderboard', $payload['rows'][0]['ad_unit'] );
		$this->assertSame( 1000, $payload['rows'][0]['impressions'] );
		$this->assertSame( 2.0, $payload['rows'][0]['revenue'] );
	}

	/**
	 * A report error payload passes through unchanged.
	 */
	public function test_metric_passes_through_report_error() {
		Insights_Advertising_Test_Metric::$next_report = [
			'value'      => null,
			'computable' => false,
			'error'      => 'boom',
		];
		$payload = Insights_Advertising_Test_Metric::total_revenue( '2026-01-01', '2026-01-31' );
		$this->assertSame( 'boom', $payload['error'] );
		$this->assertFalse( $payload['computable'] );
	}

	/**
	 * Returns the envelope (no metrics) when GAM is inactive.
	 */
	public function test_get_all_envelope_when_gam_inactive() {
		$payload = Advertising_Metric::get_all( '2026-01-01', '2026-01-31' );
		$this->assertFalse( $payload['is_tab_visible'] );
		$this->assertFalse( $payload['is_report_ready'] );
		$this->assertSame( [], $payload['metrics'] );
		$this->assertArrayHasKey( 'data_as_of', $payload );
	}

	/**
	 * Short-circuits to empty readiness issues when GAM is inactive (tab hidden),
	 * so it never makes the remote scope check for the common not-configured case.
	 */
	public function test_readiness_issues_empty_when_gam_inactive() {
		// GAM is inactive in the test environment (newspack-ads absent), so the
		// tab is hidden and there are no actionable readiness issues to surface.
		$this->assertFalse( Advertising_Metric::is_tab_visible() );
		$this->assertSame( [], Advertising_Metric::readiness_issues() );
	}

	/**
	 * The Action Scheduler runner skips (no cache write) when not ready.
	 */
	public function test_run_scheduled_refresh_skips_when_not_ready() {
		$ref = new ReflectionMethod( Advertising_Metric::class, 'cache_key' );
		$ref->setAccessible( true );
		$key = $ref->invoke( null, '2026-01-01', '2026-01-31' );

		Advertising_Metric::run_scheduled_refresh(
			[
				'start' => '2026-01-01',
				'end'   => '2026-01-31',
			]
		);
		$this->assertFalse( get_transient( $key ) );
	}

	/**
	 * Injects the cross-system derived scorecards (NPPD-1675) onto a resolved
	 * window via read_window(): RPM and avg impressions per session, joining the
	 * cached GAM volume/revenue with GA4 sessions (fed here via the filter).
	 */
	public function test_read_window_injects_cross_system_metrics() {
		add_filter( 'newspack_insights_pre_total_sessions', [ $this, 'return_fixed_sessions' ], 10, 3 );

		$start = '2026-01-01';
		$end   = '2026-01-31';
		$key   = $this->invoke( 'cache_key', [ $start, $end ] );
		set_transient(
			$key,
			[
				'computed_at' => time(),
				'payload'     => [
					'window'  => [
						'start' => $start,
						'end'   => $end,
					],
					'metrics' => [
						'total_impressions' => [
							'value'      => 2400000,
							'computable' => true,
							'type'       => 'count',
						],
						'total_revenue'     => [
							'value'      => 4200.0,
							'computable' => true,
							'type'       => 'currency',
						],
					],
				],
			]
		);

		$window = $this->invoke( 'read_window', [ $start, $end ] );
		delete_transient( $key );
		remove_filter( 'newspack_insights_pre_total_sessions', [ $this, 'return_fixed_sessions' ], 10 );

		$this->assertArrayHasKey( 'rpm', $window['metrics'] );
		$this->assertTrue( $window['metrics']['rpm']['computable'] );
		$this->assertSame( ( 4200.0 / 800000 ) * 1000, $window['metrics']['rpm']['value'] );

		$this->assertArrayHasKey( 'avg_impressions_per_session', $window['metrics'] );
		$this->assertSame( 3.0, $window['metrics']['avg_impressions_per_session']['value'] );
	}

	/**
	 * Filter callback: a known session count for the cross-system injection test.
	 *
	 * @param int|null $pre        Incoming value.
	 * @param string   $start_date Window start.
	 * @param string   $end_date   Window end.
	 * @return int
	 */
	public function return_fixed_sessions( $pre, $start_date, $end_date ): int {
		return 800000;
	}

	/**
	 * Prior period is the contiguous, equal-length preceding window.
	 */
	public function test_prior_period() {
		$result = $this->invoke( 'prior_period', [ '2026-02-01', '2026-02-28' ] );
		$this->assertSame( [ '2026-01-04', '2026-01-31' ], $result );
	}

	/**
	 * The direct-sold PQL filter lists the direct line item types.
	 */
	public function test_direct_sold_pql_filter() {
		$filter = $this->invoke( 'direct_sold_pql_filter' );
		$this->assertStringContainsString( "LINE_ITEM_TYPE IN ('SPONSORSHIP','STANDARD','BULK','PRICE_PRIORITY')", $filter );
	}

	/**
	 * A recent window is flagged as carrying estimated data.
	 */
	public function test_data_lag_info_recent_window_is_estimated() {
		$tz    = wp_timezone();
		$today = ( new DateTimeImmutable( 'today', $tz ) )->format( 'Y-m-d' );
		$info  = $this->invoke( 'data_lag_info', [ $today ] );
		$this->assertTrue( $info['has_estimated_data'] );
		$this->assertNotNull( $info['estimated_window_start_date'] );
	}

	/**
	 * An old window is not flagged as estimated.
	 */
	public function test_data_lag_info_old_window_not_estimated() {
		$info = $this->invoke( 'data_lag_info', [ '2020-01-31' ] );
		$this->assertFalse( $info['has_estimated_data'] );
		$this->assertNull( $info['estimated_window_start_date'] );
	}

	/**
	 * Only a structurally valid cache wrapper is returned, else null — the
	 * single validity definition shared with the refresh guard.
	 */
	public function test_read_cache_entry_validates_structure() {
		$key_ref = new ReflectionMethod( Advertising_Metric::class, 'cache_key' );
		$key_ref->setAccessible( true );
		$key = $key_ref->invoke( null, '2026-03-01', '2026-03-31' );

		set_transient( $key, 'not-an-array', 60 );
		$this->assertNull( $this->invoke( 'read_cache_entry', [ '2026-03-01', '2026-03-31' ] ) );

		set_transient( $key, [ 'payload' => 'not-an-array' ], 60 );
		$this->assertNull( $this->invoke( 'read_cache_entry', [ '2026-03-01', '2026-03-31' ] ) );

		$valid = [
			'computed_at' => time(),
			'payload'     => [ 'metrics' => [] ],
		];
		set_transient( $key, $valid, 60 );
		$got = $this->invoke( 'read_cache_entry', [ '2026-03-01', '2026-03-31' ] );
		$this->assertIsArray( $got );
		$this->assertSame( $valid['computed_at'], $got['computed_at'] );

		delete_transient( $key );
	}

	/**
	 * Reports a failure when at least one metric errored.
	 */
	public function test_any_failed() {
		$this->assertTrue(
			$this->invoke(
				'any_failed',
				[
					[
						'a' => [ 'value' => 1 ],
						'b' => [ 'error' => 'x' ],
					],
				]
			)
		);
		$this->assertFalse(
			$this->invoke(
				'any_failed',
				[
					[
						'a' => [ 'value' => 1 ],
						'b' => [ 'value' => 2 ],
					],
				]
			)
		);
		$this->assertFalse( $this->invoke( 'any_failed', [ [] ] ) );
	}

	/**
	 * Direct vs programmatic is computable with impressions but zero revenue
	 * (house/unsold inventory must still render).
	 */
	public function test_direct_vs_programmatic_computable_with_impressions_no_revenue() {
		$this->with_rows(
			[
				[
					'LINE_ITEM_TYPE'                    => 'HOUSE',
					'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => '0',
					'TOTAL_IMPRESSIONS'                 => '5000',
				],
			]
		);
		$payload = Insights_Advertising_Test_Metric::direct_vs_programmatic( '2026-01-01', '2026-01-31' );
		$this->assertTrue( $payload['computable'] );
	}

	/**
	 * The fixture comparison window is the immediately-preceding period, not a
	 * copy of the current window.
	 */
	public function test_fixture_compare_window_is_prior_period() {
		$payload = Advertising_Metric::get_fixture( '2026-02-01', '2026-02-28', true, 'populated' );
		$this->assertArrayHasKey( 'current', $payload );
		$this->assertArrayHasKey( 'previous', $payload );
		$this->assertSame( '2026-01-31', $payload['previous']['window']['end'] );
		$this->assertNotSame( $payload['current']['window']['start'], $payload['previous']['window']['start'] );
	}
}
