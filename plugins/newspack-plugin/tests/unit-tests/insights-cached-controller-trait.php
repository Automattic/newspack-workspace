<?php
/**
 * Tests for Newspack\Insights\Cached_Controller_Trait.
 *
 * @package Newspack
 */

use Newspack\Insights\Cache;
use Newspack\Insights\Cached_Controller_Trait;

/**
 * Trait integration tests.
 */
class Newspack_Test_Cached_Controller_Trait extends WP_UnitTestCase {

	/**
	 * Wipe transients between tests.
	 */
	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_newspack_insights_%' OR option_name LIKE 'newspack_insights_%'" );
		parent::tear_down();
	}

	/**
	 * Build a request carrying a standard 30-day window.
	 */
	private function request_for_window(): WP_REST_Request {
		$req = new WP_REST_Request( 'GET', '/stub' );
		$req->set_query_params(
			[
				'start' => '2026-01-01',
				'end'   => '2026-01-31',
			]
		);
		return $req;
	}

	/**
	 * GET wrapper builds the {cache,data} envelope.
	 */
	public function test_cached_response_wraps_payload_in_envelope(): void {
		$controller = new Newspack_Test_Stub_Cached_Controller();
		$response   = $controller->call_cached(
			$this->request_for_window(),
			function () {
				return [ 'foo' => 'bar' ];
			}
		);

		$body = $response->get_data();
		$this->assertSame( [ 'foo' => 'bar' ], $body['data'] );
		$this->assertSame( Cache::SOURCE_EXTERNAL, $body['cache']['source'] );
		$this->assertNotEmpty( $body['cache']['computed_at'] );
		$this->assertNull( $body['cache']['cooldown_until'] );
	}

	/**
	 * Second GET in the TTL window returns the cached payload.
	 */
	public function test_cached_response_serves_from_transient_on_second_call(): void {
		$controller = new Newspack_Test_Stub_Cached_Controller();
		$calls      = 0;
		$compute    = function () use ( &$calls ) {
			$calls++;
			return [ 'value' => $calls ];
		};

		$controller->call_cached( $this->request_for_window(), $compute );
		$controller->call_cached( $this->request_for_window(), $compute );

		$this->assertSame( 1, $calls );
	}

	/**
	 * Refresh_response() always recomputes.
	 */
	public function test_refresh_response_recomputes_payload(): void {
		$controller = new Newspack_Test_Stub_Cached_Controller();
		$calls      = 0;
		$compute    = function () use ( &$calls ) {
			$calls++;
			return [ 'value' => $calls ];
		};

		$controller->call_cached( $this->request_for_window(), $compute );
		$response = $controller->call_refresh( $this->request_for_window(), $compute );

		$this->assertSame( 2, $calls );
		$this->assertSame( [ 'value' => 2 ], $response->get_data()['data'] );
	}

	/**
	 * Cooldown rejection from a BQ-source controller returns a 200 response
	 * whose envelope carries cooldown_until — never a WP_Error / 429.
	 */
	public function test_refresh_response_returns_envelope_with_cooldown_during_bq_cooldown(): void {
		$controller = new class() extends WP_REST_Controller {
			use Cached_Controller_Trait;

			/**
			 * Get cache source.
			 */
			protected function cache_source(): string {
				return Cache::SOURCE_BIGQUERY;
			}

			/**
			 * Get tab slug.
			 */
			protected function tab_slug(): string {
				return 'stub_bq';
			}

			/**
			 * Expose refresh_response.
			 *
			 * @param WP_REST_Request $request Request.
			 * @param callable        $cb      Callback.
			 */
			public function call_refresh( WP_REST_Request $request, callable $cb ): WP_REST_Response {
				return $this->refresh_response( $request, $cb );
			}
		};

		$compute = function () {
			return [ 'value' => 1 ];
		};

		$controller->call_refresh( $this->request_for_window(), $compute );
		$second = $controller->call_refresh( $this->request_for_window(), $compute );

		$body = $second->get_data();
		$this->assertSame( [ 'value' => 1 ], $body['data'] );
		$this->assertNotEmpty( $body['cache']['cooldown_until'] );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $body['cache']['source'] );
	}
}
