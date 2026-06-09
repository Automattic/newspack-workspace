<?php
/**
 * Test BigQuery_Proxy_Client.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use WP_Error;
use WP_UnitTestCase;

/**
 * BigQuery_Proxy_Client test class.
 *
 * @group insights
 */
class Test_BigQuery_Proxy_Client extends WP_UnitTestCase {

	/**
	 * Captured request args from pre_http_request.
	 *
	 * @var array
	 */
	private $captured_args = null;

	/**
	 * Captured request URL.
	 *
	 * @var string
	 */
	private $captured_url = null;

	/**
	 * Stub response or WP_Error to return from pre_http_request.
	 *
	 * @var array|WP_Error
	 */
	private $stub_response = null;

	/**
	 * Reset captured state and install the HTTP capture filter.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->captured_args = null;
		$this->captured_url  = null;
		$this->stub_response = null;
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
	}

	/**
	 * Remove the HTTP capture filter.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		parent::tearDown();
	}

	/**
	 * Pre-HTTP-request filter that captures args and returns the stubbed response.
	 *
	 * @param mixed  $preempt Pre-emptive response (unused).
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array|WP_Error
	 */
	public function capture_request( $preempt, $args, $url ) {
		$this->captured_args = $args;
		$this->captured_url  = $url;
		return $this->stub_response ?? new WP_Error( 'no_stub', 'Test did not stub a response.' );
	}

	/**
	 * Make a UTC DateTimeImmutable from a YYYY-MM-DD string.
	 *
	 * @param string $ymd Date string.
	 * @return DateTimeImmutable
	 */
	private function date( string $ymd ): DateTimeImmutable {
		return new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * When the Newspack Manager isn't configured, query() returns a WP_Error.
	 */
	public function test_query_returns_wp_error_when_not_configured() {
		$client = new BigQuery_Proxy_Client( null, '' );
		$result = $client->query( 'gates_total_impressions', $this->date( '2026-03-22' ), $this->date( '2026-04-21' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'bigquery_proxy_not_configured', $result->get_error_code() );
	}

	/**
	 * Is_configured() returns false when no URL or no API key.
	 */
	public function test_is_configured_false_when_missing_pieces() {
		$this->assertFalse( ( new BigQuery_Proxy_Client( null, '' ) )->is_configured() );
		$this->assertFalse( ( new BigQuery_Proxy_Client( 'https://newspack.com/foo', '' ) )->is_configured() );
		$this->assertFalse( ( new BigQuery_Proxy_Client( null, 'key' ) )->is_configured() );
		$this->assertTrue( ( new BigQuery_Proxy_Client( 'https://newspack.com/foo', 'key' ) )->is_configured() );
	}

	/**
	 * Query() sends the right HTTP body shape and URL.
	 */
	public function test_query_sends_correct_request() {
		$this->stub_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ [ 'gate_impressions' => 42 ] ] ),
		];

		$client = new BigQuery_Proxy_Client( 'https://hub.example.com/wp-json/newspack-manager-admin/v1/bigquery-query?api_key=secret', 'secret' );
		$client->query( 'gates_total_impressions', $this->date( '2026-03-22' ), $this->date( '2026-04-21' ) );

		$this->assertSame( 'https://hub.example.com/wp-json/newspack-manager-admin/v1/bigquery-query?api_key=secret', $this->captured_url );
		$this->assertSame( 'POST', $this->captured_args['method'] );

		$body = json_decode( $this->captured_args['body'], true );
		$this->assertSame( 'gates_total_impressions', $body['query_name'] );
		$this->assertSame( '20260322', $body['start_date'] );
		$this->assertSame( '20260421', $body['end_date'] );

		// UA must contain home_url after a semicolon so the hub can identify the site.
		$ua = $this->captured_args['headers']['User-Agent'];
		$this->assertStringContainsString( ';', $ua );
		$this->assertStringContainsString( home_url(), $ua );
	}

	/**
	 * Non-UTC DateTimeImmutable inputs are normalized to UTC before formatting.
	 *
	 * GA4 daily-shard tables are UTC-partitioned; querying a non-UTC date silently
	 * returns the wrong day's data. The client must defensively convert.
	 */
	public function test_query_normalizes_non_utc_dates() {
		$this->stub_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ [ 'gate_impressions' => 0 ] ] ),
		];

		// 2026-03-22 01:00 JST == 2026-03-21 16:00 UTC.
		$tokyo_start = new \DateTimeImmutable( '2026-03-22 01:00:00', new \DateTimeZone( 'Asia/Tokyo' ) );
		// 2026-04-22 01:00 JST == 2026-04-21 16:00 UTC.
		$tokyo_end = new \DateTimeImmutable( '2026-04-22 01:00:00', new \DateTimeZone( 'Asia/Tokyo' ) );

		$client = new BigQuery_Proxy_Client( 'https://hub.example.com/x?api_key=k', 'k' );
		$client->query( 'gates_total_impressions', $tokyo_start, $tokyo_end );

		$body = json_decode( $this->captured_args['body'], true );
		$this->assertSame( '20260321', $body['start_date'] );
		$this->assertSame( '20260421', $body['end_date'] );
	}

	/**
	 * Query() returns rows on 200 response.
	 */
	public function test_query_returns_rows_on_success() {
		$this->stub_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					[
						'event_name'  => 'page_view',
						'event_count' => 100,
					],
					[
						'event_name'  => 'np_gate_interaction',
						'event_count' => 25,
					],
				]
			),
		];

		$client = new BigQuery_Proxy_Client( 'https://hub.example.com/x?api_key=k', 'k' );
		$result = $client->query( 'events_count', $this->date( '2026-03-22' ), $this->date( '2026-04-21' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 'page_view', $result[0]['event_name'] );
		$this->assertSame( 100, $result[0]['event_count'] );
	}

	/**
	 * Query() returns WP_Error on a non-200 response.
	 */
	public function test_query_returns_wp_error_on_http_error() {
		$this->stub_response = [
			'response' => [ 'code' => 500 ],
			'body'     => wp_json_encode(
				[
					'code'    => 'bigquery_query_failed',
					'message' => 'BQ error: invalid sql',
					'data'    => [ 'status' => 500 ],
				]
			),
		];

		$client = new BigQuery_Proxy_Client( 'https://hub.example.com/x?api_key=k', 'k' );
		$result = $client->query( 'gates_total_impressions', $this->date( '2026-03-22' ), $this->date( '2026-04-21' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'bigquery_query_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'invalid sql', $result->get_error_message() );
	}

	/**
	 * Query() returns WP_Error when the HTTP layer itself errors.
	 */
	public function test_query_returns_wp_error_on_transport_error() {
		$this->stub_response = new WP_Error( 'http_request_failed', 'Could not connect' );

		$client = new BigQuery_Proxy_Client( 'https://hub.example.com/x?api_key=k', 'k' );
		$result = $client->query( 'gates_total_impressions', $this->date( '2026-03-22' ), $this->date( '2026-04-21' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	/**
	 * Query() returns WP_Error when the body isn't decodable JSON.
	 */
	public function test_query_returns_wp_error_on_invalid_json() {
		$this->stub_response = [
			'response' => [ 'code' => 200 ],
			'body'     => 'not json',
		];

		$client = new BigQuery_Proxy_Client( 'https://hub.example.com/x?api_key=k', 'k' );
		$result = $client->query( 'gates_total_impressions', $this->date( '2026-03-22' ), $this->date( '2026-04-21' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'bigquery_proxy_invalid_response', $result->get_error_code() );
	}

	/**
	 * Failures fire the newspack_log action so Logstash can pick them up.
	 */
	public function test_failure_fires_newspack_log() {
		if ( ! class_exists( '\Newspack\Logger' ) ) {
			$this->markTestSkipped( 'Newspack\Logger not loaded.' );
		}

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $data ) use ( &$captured ) {
				if ( 'newspack_insights_bigquery_proxy_failed' === $code ) {
					$captured = compact( 'code', 'message', 'data' );
				}
			},
			10,
			3
		);

		$this->stub_response = new \WP_Error( 'http_request_failed', 'Could not connect' );

		$client = new BigQuery_Proxy_Client( 'https://hub.example.com/x?api_key=k', 'k' );
		$client->query( 'gates_total_impressions', $this->date( '2026-03-22' ), $this->date( '2026-04-21' ) );

		$this->assertNotNull( $captured );
		$this->assertSame( 'newspack_insights_bigquery_proxy_failed', $captured['code'] );
		// Newspack\Logger::newspack_log wraps the caller's $data inside a 'data' sub-key
		// alongside type/user_email/file/log_level metadata.
		$this->assertArrayHasKey( 'data', $captured['data'] );
		$this->assertSame( 'gates_total_impressions', $captured['data']['data']['query_name'] );
		$this->assertSame( 'http_request_failed', $captured['data']['data']['error_code'] );
		$this->assertSame( '20260322', $captured['data']['data']['start_date'] );
	}
}
