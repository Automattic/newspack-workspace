<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test file defines an exposure subclass alongside the main test class.
/**
 * Tests the Insights GAM reporting client (NPPD-1662).
 *
 * Covers the pure / mockable logic: currency normalization, the
 * Report_Query value object, Report_Job_Status, CSV parsing, date
 * parsing, network-code resolution, and the connection gate's
 * disconnected path. The SOAP-touching methods (run_report_job,
 * get_report_job_status, get_report_download_url and their helpers)
 * require the googleads library, which is not autoloaded in the unit
 * test environment; they are covered by the deferred integration test
 * against a real publisher network (pre-flight 3).
 *
 * @package Newspack\Tests
 */

use Newspack\Insights\GAM\Client;
use Newspack\Insights\GAM\Report_Query;
use Newspack\Insights\GAM\Report_Job_Status;

/**
 * Exposes protected pure-logic methods of the client for testing.
 */
class Insights_GAM_Test_Client extends Client {
	/**
	 * Expose get_network_code().
	 *
	 * @return string
	 */
	public static function expose_get_network_code() {
		return parent::get_network_code();
	}

	/**
	 * Expose parse_gzipped_csv().
	 *
	 * @param string $body Raw body.
	 * @return array
	 */
	public static function expose_parse_gzipped_csv( $body ) {
		return parent::parse_gzipped_csv( $body );
	}

	/**
	 * Expose parse_ymd().
	 *
	 * @param string $ymd Date string.
	 * @return array
	 */
	public static function expose_parse_ymd( $ymd ) {
		return parent::parse_ymd( $ymd );
	}
}

/**
 * Test the GAM reporting client.
 *
 * @group insights_gam
 */
class Test_Insights_GAM_Client extends WP_UnitTestCase {

	/**
	 * Clean up options touched by tests.
	 */
	public function tear_down() {
		delete_option( '_newspack_ads_gam_network_code' );
		delete_option( Client::AUDIT_LOG_OPTION );
		parent::tear_down();
	}

	/**
	 * Micro-currency normalization.
	 */
	public function test_normalize_currency_micros() {
		$this->assertSame( 1.5, Client::normalize_currency_micros( 1500000 ) );
		$this->assertSame( 0.0, Client::normalize_currency_micros( 0 ) );
		$this->assertSame( 2.5, Client::normalize_currency_micros( '2500000' ) );
		$this->assertSame( -1.0, Client::normalize_currency_micros( -1000000 ) );
	}

	/**
	 * Report_Query defaults.
	 */
	public function test_report_query_defaults() {
		$query = new Report_Query();
		$this->assertSame( 'CUSTOM_DATE', $query->date_range_type );
		$this->assertSame( [], $query->dimensions );
		$this->assertSame( [], $query->columns );
		$this->assertNull( $query->pql_filter );
	}

	/**
	 * Report_Query construction from args and hashing.
	 */
	public function test_report_query_from_args_and_hash() {
		$args  = [
			'dimensions' => [ 'DATE' ],
			'columns'    => [ 'TOTAL_IMPRESSIONS' ],
			'pql_filter' => "WHERE LINE_ITEM_TYPE = 'STANDARD'",
			'start_date' => '2026-01-01',
			'end_date'   => '2026-01-31',
		];
		$query = new Report_Query( $args );
		$this->assertSame( [ 'DATE' ], $query->dimensions );
		$this->assertSame( [ 'TOTAL_IMPRESSIONS' ], $query->columns );
		$this->assertSame( '2026-01-01', $query->start_date );

		// Hash is stable for identical queries and differs for different ones.
		$same      = new Report_Query( $args );
		$different = new Report_Query( array_merge( $args, [ 'columns' => [ 'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' ] ] ) );
		$this->assertSame( $query->hash(), $same->hash() );
		$this->assertNotSame( $query->hash(), $different->hash() );
	}

	/**
	 * Report_Job_Status normalization and terminal detection.
	 */
	public function test_report_job_status() {
		$this->assertSame( Report_Job_Status::COMPLETED, Report_Job_Status::normalize( 'COMPLETED' ) );
		$this->assertSame( Report_Job_Status::IN_PROGRESS, Report_Job_Status::normalize( 'IN_PROGRESS' ) );
		$this->assertSame( Report_Job_Status::FAILED, Report_Job_Status::normalize( 'FAILED' ) );
		$this->assertSame( Report_Job_Status::UNKNOWN, Report_Job_Status::normalize( 'SOMETHING_ELSE' ) );

		$this->assertTrue( Report_Job_Status::is_terminal( Report_Job_Status::COMPLETED ) );
		$this->assertTrue( Report_Job_Status::is_terminal( Report_Job_Status::FAILED ) );
		$this->assertFalse( Report_Job_Status::is_terminal( Report_Job_Status::IN_PROGRESS ) );
	}

	/**
	 * Date string parsing.
	 */
	public function test_parse_ymd() {
		$this->assertSame( [ 2026, 2, 15 ], Insights_GAM_Test_Client::expose_parse_ymd( '2026-02-15' ) );
		$this->assertSame( [ 2026, 12, 1 ], Insights_GAM_Test_Client::expose_parse_ymd( '2026-12-01' ) );
	}

	/**
	 * CSV parsing from gzipped and plain input.
	 */
	public function test_parse_gzipped_csv() {
		$csv  = "Dimension.DATE,Column.TOTAL_IMPRESSIONS\n2026-01-01,1000\n2026-01-02,2500\n";
		$rows = Insights_GAM_Test_Client::expose_parse_gzipped_csv( gzencode( $csv ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( '2026-01-01', $rows[0]['Dimension.DATE'] );
		$this->assertSame( '1000', $rows[0]['Column.TOTAL_IMPRESSIONS'] );
		$this->assertSame( '2500', $rows[1]['Column.TOTAL_IMPRESSIONS'] );

		// Tolerates already-decompressed input.
		$plain_rows = Insights_GAM_Test_Client::expose_parse_gzipped_csv( $csv );
		$this->assertCount( 2, $plain_rows );
		$this->assertSame( '2026-01-02', $plain_rows[1]['Dimension.DATE'] );

		// Empty input yields no rows.
		$this->assertSame( [], Insights_GAM_Test_Client::expose_parse_gzipped_csv( '' ) );
	}

	/**
	 * Downloads, decompresses, and parses a CSV report.
	 */
	public function test_fetch_and_parse_csv_success() {
		$csv    = "Column.TOTAL_IMPRESSIONS\n4242\n";
		$filter = function () use ( $csv ) {
			return [
				'body'     => gzencode( $csv ),
				'response' => [ 'code' => 200 ],
			];
		};
		add_filter( 'pre_http_request', $filter );
		$rows = Client::fetch_and_parse_csv( 'https://admanager.example.test/report.csv.gz' );
		remove_filter( 'pre_http_request', $filter );

		$this->assertCount( 1, $rows );
		$this->assertSame( '4242', $rows[0]['Column.TOTAL_IMPRESSIONS'] );
	}

	/**
	 * Throws when the CSV download fails.
	 */
	public function test_fetch_and_parse_csv_http_error() {
		$filter = function () {
			return new WP_Error( 'http_request_failed', 'boom' );
		};
		add_filter( 'pre_http_request', $filter );
		$this->expectException( \RuntimeException::class );
		try {
			Client::fetch_and_parse_csv( 'https://admanager.example.test/report.csv.gz' );
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}
	}

	/**
	 * Network code resolves from the option (fallback path) and handles
	 * the comma-delimited multi-network case.
	 */
	public function test_get_network_code_option_fallback() {
		update_option( '_newspack_ads_gam_network_code', '123456' );
		$this->assertSame( '123456', Insights_GAM_Test_Client::expose_get_network_code() );

		update_option( '_newspack_ads_gam_network_code', '111111,222222' );
		$this->assertSame( '111111', Insights_GAM_Test_Client::expose_get_network_code() );

		delete_option( '_newspack_ads_gam_network_code' );
		$this->assertSame( '', Insights_GAM_Test_Client::expose_get_network_code() );
	}

	/**
	 * The connection gate is false when newspack-ads is not active (its
	 * GAM_Model class is absent in the unit-test environment), regardless
	 * of any other state.
	 */
	public function test_is_publisher_connected_false_without_newspack_ads() {
		update_option( '_newspack_ads_gam_network_code', '123456' );
		$this->assertFalse( class_exists( '\Newspack_Ads\Providers\GAM_Model' ), 'Guard: newspack-ads must be absent in this env.' );
		$this->assertFalse( Client::is_publisher_connected() );
	}
}
