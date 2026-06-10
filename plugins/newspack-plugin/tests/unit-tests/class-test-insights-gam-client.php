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
 * Exposes protected pure-logic methods of the client for testing, and
 * overrides the external-state seams (newspack-ads active, OAuth scope,
 * network code) so the visibility / reporting gates can be exercised
 * without a live GAM connection.
 */
class Insights_GAM_Test_Client extends Client {
	/**
	 * Mock value for is_newspack_ads_active().
	 *
	 * @var bool
	 */
	public static $mock_ads_active = false;

	/**
	 * Mock value for has_gam_scope().
	 *
	 * @var bool
	 */
	public static $mock_has_scope = false;

	/**
	 * Mock value for get_network_code() (when the gates resolve it).
	 *
	 * @var string
	 */
	public static $mock_network = '';

	/**
	 * Expose get_network_code() (the real implementation).
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

	/**
	 * Expose assert_valid_custom_dates().
	 *
	 * @param Report_Query $query The query.
	 * @return void
	 */
	public static function expose_assert_valid_custom_dates( Report_Query $query ) {
		parent::assert_valid_custom_dates( $query );
	}

	/**
	 * Expose friendly_api_error().
	 *
	 * @param \Exception $e The exception.
	 * @return \RuntimeException
	 */
	public static function expose_friendly_api_error( \Exception $e ) {
		return parent::friendly_api_error( $e );
	}

	/**
	 * Overridable seam: whether newspack-ads is active.
	 *
	 * @return bool
	 */
	protected static function is_newspack_ads_active() {
		return self::$mock_ads_active;
	}

	/**
	 * Overridable seam: whether the OAuth token carries the GAM scope.
	 *
	 * @return bool
	 */
	protected static function has_gam_scope() {
		return self::$mock_has_scope;
	}

	/**
	 * Overridable seam: the resolved network code.
	 *
	 * @return string
	 */
	protected static function get_network_code() {
		return self::$mock_network;
	}
}

/**
 * A single GAM API error with a getErrorString() accessor.
 */
class Insights_GAM_Fake_Api_Error {
	/**
	 * The error string.
	 *
	 * @var string
	 */
	private $error_string;

	/**
	 * Constructor.
	 *
	 * @param string $error_string The error string.
	 */
	public function __construct( $error_string ) {
		$this->error_string = $error_string;
	}

	/**
	 * Get the error string.
	 *
	 * @return string
	 */
	public function getErrorString() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the googleads SOAP accessor name.
		return $this->error_string;
	}
}

/**
 * A fake GAM SOAP ApiException exposing getErrors().
 */
class Insights_GAM_Fake_Api_Exception extends \Exception {
	/**
	 * The error objects.
	 *
	 * @var Insights_GAM_Fake_Api_Error[]
	 */
	private $errors;

	/**
	 * Constructor.
	 *
	 * @param string[] $error_strings Error strings to wrap.
	 * @param string   $message       Exception message.
	 */
	public function __construct( array $error_strings, $message = 'fake api error' ) {
		parent::__construct( $message );
		$this->errors = array_map(
			function ( $string ) {
				return new Insights_GAM_Fake_Api_Error( $string );
			},
			$error_strings
		);
	}

	/**
	 * Get the error objects.
	 *
	 * @return Insights_GAM_Fake_Api_Error[]
	 */
	public function getErrors() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the googleads SOAP accessor name.
		return $this->errors;
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
		delete_option( Client::GAM_ACTIVE_OPTION );
		delete_option( Client::AUDIT_LOG_OPTION );
		Insights_GAM_Test_Client::$mock_ads_active = false;
		Insights_GAM_Test_Client::$mock_has_scope  = false;
		Insights_GAM_Test_Client::$mock_network    = '';
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
	 * CUSTOM_DATE validation accepts well-formed dates.
	 */
	public function test_custom_date_validation_accepts_valid_dates() {
		Insights_GAM_Test_Client::expose_assert_valid_custom_dates(
			new Report_Query(
				[
					'start_date' => '2026-01-01',
					'end_date'   => '2026-01-31',
				]
			)
		);
		$this->assertTrue( true ); // Reached here without throwing.
	}

	/**
	 * CUSTOM_DATE validation rejects empty or malformed dates.
	 */
	public function test_custom_date_validation_rejects_bad_dates() {
		$bad = [
			new Report_Query(), // Empty defaults.
			new Report_Query(
				[
					'start_date' => '2026-1-1', // Not zero-padded.
					'end_date'   => '2026-01-31',
				]
			),
			new Report_Query(
				[
					'start_date' => '2026-01-01',
					'end_date'   => '', // Missing end.
				]
			),
			new Report_Query(
				[
					'start_date' => '2026-13-01', // Month 13 — well-formed but impossible.
					'end_date'   => '2026-01-31',
				]
			),
			new Report_Query(
				[
					'start_date' => '2026-02-01',
					'end_date'   => '2026-02-30', // Feb 30 — impossible.
				]
			),
		];
		foreach ( $bad as $index => $query ) {
			$threw = false;
			try {
				Insights_GAM_Test_Client::expose_assert_valid_custom_dates( $query );
			} catch ( \RuntimeException $e ) {
				$threw = true;
			}
			$this->assertTrue( $threw, "Expected query #{$index} to be rejected." );
		}
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
	 * A corrupt gzip payload (valid magic bytes, garbage body) throws rather
	 * than being parsed as text.
	 */
	public function test_parse_gzipped_csv_rejects_corrupt_gzip() {
		$corrupt = "\x1f\x8b" . 'this is not really gzip-compressed data';
		$this->expectException( \RuntimeException::class );
		Insights_GAM_Test_Client::expose_parse_gzipped_csv( $corrupt );
	}

	/**
	 * A leading UTF-8 BOM is stripped so the first header key is clean.
	 */
	public function test_parse_gzipped_csv_strips_bom() {
		$csv  = "\xEF\xBB\xBFColumn.TOTAL_IMPRESSIONS\n7\n";
		$rows = Insights_GAM_Test_Client::expose_parse_gzipped_csv( $csv );
		$this->assertCount( 1, $rows );
		$this->assertArrayHasKey( 'Column.TOTAL_IMPRESSIONS', $rows[0] );
		$this->assertSame( '7', $rows[0]['Column.TOTAL_IMPRESSIONS'] );
	}

	/**
	 * API-error translation maps known error codes and falls back otherwise.
	 */
	public function test_friendly_api_error() {
		$mapped = Insights_GAM_Test_Client::expose_friendly_api_error(
			new Insights_GAM_Fake_Api_Exception( [ 'AuthenticationError.NETWORK_NOT_FOUND' ] )
		);
		$this->assertInstanceOf( \RuntimeException::class, $mapped );
		$this->assertStringContainsString( 'network code is invalid', $mapped->getMessage() );

		$denied = Insights_GAM_Test_Client::expose_friendly_api_error(
			new Insights_GAM_Fake_Api_Exception( [ 'PermissionError.PERMISSION_DENIED' ] )
		);
		$this->assertStringContainsString( 'permission', $denied->getMessage() );

		// Unknown / plain exception falls back to the raw message.
		$fallback = Insights_GAM_Test_Client::expose_friendly_api_error( new \Exception( 'boom' ) );
		$this->assertStringContainsString( 'Google Ad Manager API error', $fallback->getMessage() );
		$this->assertStringContainsString( 'boom', $fallback->getMessage() );
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
		try {
			$rows = Client::fetch_and_parse_csv( 'https://admanager.example.test/report.csv.gz' );
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}

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
	 * Tab visibility (is_gam_active) tracks the GAM provider toggle, and is
	 * false when newspack-ads is inactive regardless of the toggle.
	 */
	public function test_is_gam_active() {
		// newspack-ads inactive: false even when the provider toggle is on.
		Insights_GAM_Test_Client::$mock_ads_active = false;
		update_option( Client::GAM_ACTIVE_OPTION, '1' );
		$this->assertFalse( Insights_GAM_Test_Client::is_gam_active() );

		// newspack-ads active + toggle on: visible.
		Insights_GAM_Test_Client::$mock_ads_active = true;
		$this->assertTrue( Insights_GAM_Test_Client::is_gam_active() );

		// Toggle off: hidden.
		delete_option( Client::GAM_ACTIVE_OPTION );
		$this->assertFalse( Insights_GAM_Test_Client::is_gam_active() );
	}

	/**
	 * Reporting readiness requires GAM active AND scope AND a network code.
	 */
	public function test_can_run_reports_requires_all_preconditions() {
		Insights_GAM_Test_Client::$mock_ads_active = true;
		update_option( Client::GAM_ACTIVE_OPTION, '1' );
		Insights_GAM_Test_Client::$mock_has_scope = true;
		Insights_GAM_Test_Client::$mock_network   = '123456';
		$this->assertTrue( Insights_GAM_Test_Client::can_run_reports() );

		// Missing scope.
		Insights_GAM_Test_Client::$mock_has_scope = false;
		$this->assertFalse( Insights_GAM_Test_Client::can_run_reports() );
		Insights_GAM_Test_Client::$mock_has_scope = true;

		// Missing network code.
		Insights_GAM_Test_Client::$mock_network = '';
		$this->assertFalse( Insights_GAM_Test_Client::can_run_reports() );
		Insights_GAM_Test_Client::$mock_network = '123456';

		// GAM provider toggle off.
		delete_option( Client::GAM_ACTIVE_OPTION );
		$this->assertFalse( Insights_GAM_Test_Client::can_run_reports() );
	}
}
