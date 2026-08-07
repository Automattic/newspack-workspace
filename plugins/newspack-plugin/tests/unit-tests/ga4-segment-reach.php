<?php
/**
 * Tests for GA4 segment reach: Data API clients and the reach cache.
 *
 * Mocks the GA4 Data API at the HTTP layer (via `pre_http_request`), the same
 * harness pattern as the custom-dimensions tests.
 *
 * @package Newspack\Tests
 */

use Newspack\GA4_Segment_Reach;
use Newspack\Google_OAuth_GA4_Client;

/**
 * GA4 segment reach.
 *
 * @group ga4-segment-reach
 */
class Newspack_Test_GA4_Segment_Reach extends WP_UnitTestCase {

	const SK_SETTINGS_OPTION = 'googlesitekit_analytics-4_settings';

	/**
	 * Recorded HTTP requests, each `[ 'url' => string, 'method' => string, 'body' => string|null ]`.
	 *
	 * @var array
	 */
	private $http_log = [];

	/**
	 * URL-substring => response array or `callable( $url, $args )`.
	 *
	 * @var array
	 */
	private $http_routes = [];

	/**
	 * Administrator user id used as the Site Kit module owner.
	 *
	 * @var int
	 */
	private $owner_id;

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->owner_id    = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->http_log    = [];
		$this->http_routes = [];
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );

		delete_option( 'newspack_ga4_segment_reach' );
		delete_option( self::SK_SETTINGS_OPTION );

		if ( ! defined( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME' ) ) {
			define( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME', 'newspack_manager_api_key' );
		}
		if ( ! defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY' ) ) {
			define( 'NEWSPACK_GOOGLE_OAUTH_PROXY', 'https://oauth.example.test' );
		}
		delete_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME );
		delete_option( '_newspack_google_oauth' );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		parent::tear_down();
	}

	/**
	 * `pre_http_request` handler: records the request and returns the first
	 * registered route whose substring matches the URL, or a 404.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array|WP_Error
	 */
	public function mock_http( $pre, $args, $url ) {
		$method           = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$this->http_log[] = [
			'url'    => $url,
			'method' => $method,
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
		];
		foreach ( $this->http_routes as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return is_callable( $response ) ? call_user_func( $response, $url, $args ) : $response;
			}
		}
		return $this->json_response( 404, [ 'error' => [ 'message' => "Unmocked request to $url" ] ] );
	}

	/**
	 * Build an HTTP response array for `pre_http_request`.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $body Response body, JSON-encoded.
	 * @return array
	 */
	private function json_response( $code, array $body ) {
		return [
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'body'     => wp_json_encode( $body ),
			'headers'  => [],
			'cookies'  => [],
		];
	}

	/**
	 * Make Newspack's Google OAuth count as configured. The reach path must
	 * work without the analytics.edit scope, so the stored token deliberately
	 * carries only the base analytics scope.
	 */
	private function configure_newspack_oauth() {
		update_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME, 'test-key' );
		update_option(
			'_newspack_google_oauth',
			[
				'access_token'  => 'fake-access-token',
				'expires_at'    => time() + HOUR_IN_SECONDS,
				'refresh_token' => 'fake-refresh-token',
			]
		);
		$this->http_routes['oauth2/v1/tokeninfo'] = $this->json_response(
			200,
			[
				'scope' => 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/analytics',
				'email' => 'owner@example.test',
			]
		);
	}

	/**
	 * Record a connected GA4 property (and module owner) in Site Kit's settings.
	 *
	 * @param string $property_id GA4 property ID.
	 */
	private function connect_property( $property_id ) {
		update_option(
			self::SK_SETTINGS_OPTION,
			[
				'propertyID' => $property_id,
				'ownerID'    => $this->owner_id,
			]
		);
	}

	/**
	 * A canned Data API response: segment 12 matched in 1240 sessions and won
	 * 320; segment 45 matched in 90 with no won rows; `none` matched in 500.
	 *
	 * @return array
	 */
	private function report_response() {
		$row = function ( $segment_id, $event_name, $count ) {
			return [
				'dimensionValues' => [ [ 'value' => $segment_id ], [ 'value' => $event_name ] ],
				'metricValues'    => [ [ 'value' => (string) $count ] ],
			];
		};
		return [
			'rows' => [
				$row( '12', 'np_segment_matched', 1240 ),
				$row( '12', 'np_segment_won', 320 ),
				$row( '45', 'np_segment_matched', 90 ),
				$row( 'none', 'np_segment_matched', 500 ),
			],
		];
	}

	/**
	 * The OAuth client posts the request body to the Data API runReport
	 * endpoint and returns the decoded response.
	 */
	public function test_oauth_client_runs_report() {
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 200, $this->report_response() );

		wp_set_current_user( $this->owner_id );
		$client   = Google_OAuth_GA4_Client::build();
		$request  = [
			'dateRanges' => [
				[
					'startDate' => '7daysAgo',
					'endDate'   => 'yesterday',
				],
			],
		];
		$response = $client->run_report( 'PROP-1', $request );

		$this->assertCount( 4, $response['rows'] );
		$last = end( $this->http_log );
		$this->assertStringContainsString( 'analyticsdata.googleapis.com/v1beta/properties/PROP-1:runReport', $last['url'] );
		$this->assertSame( 'POST', $last['method'] );
		$this->assertSame( $request, json_decode( $last['body'], true ) );
	}

	/**
	 * A Data API error surfaces as the exception the auth router expects.
	 */
	public function test_oauth_client_throws_on_report_error() {
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 403, [ 'error' => [ 'message' => 'insufficient scopes' ] ] );

		wp_set_current_user( $this->owner_id );
		$client = Google_OAuth_GA4_Client::build();
		$this->expectException( \RuntimeException::class );
		$client->run_report( 'PROP-1', [] );
	}
}
