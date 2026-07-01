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
 *
 * @group insights
 */
class Newspack_Test_Cached_Controller_Trait extends WP_UnitTestCase {

	/**
	 * Wipe transients and cache pools between tests.
	 */
	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_newspack_insights_%'
				OR option_name LIKE '_transient_timeout_newspack_insights_%'
				OR option_name LIKE 'newspack_insights_index_%'
				OR option_name LIKE 'newspack_insights_warm_%'
				OR option_name LIKE 'newspack_insights_ondemand_%'
				OR option_name LIKE 'newspack_insights_bq_last_manual_refresh_%'"
		);
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
		$response   = $controller->call_cached( $this->request_for_window() );

		$body = $response->get_data();
		$this->assertSame( $controller->window_marker( '2026-01-01', '2026-01-31' ), $body['data']['current'] );
		$this->assertNull( $body['data']['previous'] );
		$this->assertSame( Cache::SOURCE_EXTERNAL, $body['cache']['source'] );
		$this->assertNotEmpty( $body['cache']['computed_at'] );
		$this->assertNull( $body['cache']['cooldown_until'] );
	}

	/**
	 * Second GET in the TTL window returns the cached payload without recomputing.
	 */
	public function test_cached_response_serves_from_transient_on_second_call(): void {
		$controller = new Newspack_Test_Stub_Cached_Controller();

		$controller->call_cached( $this->request_for_window() );
		$controller->call_cached( $this->request_for_window() );

		$this->assertSame( 1, $controller->compute_count() );
	}

	/**
	 * Refresh_response() always recomputes; data shape matches the window marker.
	 */
	public function test_refresh_response_recomputes_payload(): void {
		$controller = new Newspack_Test_Stub_Cached_Controller();

		$controller->call_cached( $this->request_for_window() );
		$response = $controller->call_refresh( $this->request_for_window() );

		$this->assertSame( 2, $controller->compute_count() );
		$this->assertSame( $controller->window_marker( '2026-01-01', '2026-01-31' ), $response->get_data()['data']['current'] );
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
			 * Stub base-window payload (no-op for cooldown test).
			 *
			 * @param \DateTimeImmutable $start Window start.
			 * @param \DateTimeImmutable $end   Window end.
			 * @return array
			 */
			public function build_window_payload( \DateTimeImmutable $start, \DateTimeImmutable $end ): array {
				return [
					'current'  => 'value:1',
					'previous' => null,
				];
			}

			/**
			 * Parse a request's window params into [ start, end, compare_start|null, compare_end|null ].
			 *
			 * @param WP_REST_Request $request Incoming request.
			 * @return array
			 */
			private function parse_windows( WP_REST_Request $request ): array {
				$mk = static function ( $v, bool $eod ): ?\DateTimeImmutable {
					return $v ? new \DateTimeImmutable( (string) $v . ( $eod ? ' 23:59:59' : ' 00:00:00' ) ) : null;
				};
				return [
					$mk( $request->get_param( 'start' ), false ),
					$mk( $request->get_param( 'end' ), true ),
					$mk( $request->get_param( 'compare_start' ), false ),
					$mk( $request->get_param( 'compare_end' ), true ),
				];
			}

			/**
			 * Expose refresh_response.
			 *
			 * @param WP_REST_Request $request Request.
			 */
			public function call_refresh( WP_REST_Request $request ): WP_REST_Response {
				[ $s, $e, $cs, $ce ] = $this->parse_windows( $request );
				return $this->refresh_response( $request, $s, $e, $cs, $ce );
			}
		};

		$controller->call_refresh( $this->request_for_window() );
		$second = $controller->call_refresh( $this->request_for_window() );

		$body = $second->get_data();
		$this->assertSame( 'value:1', $body['data']['current'] );
		$this->assertNotEmpty( $body['cache']['cooldown_until'] );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $body['cache']['source'] );
	}

	/** Compare-on GET assembles { current, previous } from two base-keyed windows. */
	public function test_compared_get_assembles_from_base_windows() {
		$controller = new Newspack_Test_Stub_Cached_Controller();
		$request    = new WP_REST_Request( 'GET', '/stub' );
		$request->set_query_params(
			[
				'start'         => '2026-06-08',
				'end'           => '2026-06-14',
				'compare_start' => '2026-06-01',
				'compare_end'   => '2026-06-07',
			]
		);

		$data = $controller->call_cached( $request )->get_data()['data'];

		$this->assertSame( $controller->window_marker( '2026-06-08', '2026-06-14' ), $data['current'] );
		$this->assertSame( $controller->window_marker( '2026-06-01', '2026-06-07' ), $data['previous'] );
	}

	/** A compare-on current window that is already durable is not recomputed. */
	public function test_compared_current_hits_durable_without_recompute() {
		$controller = new Newspack_Test_Stub_Cached_Controller();
		// Pre-warm the current window into the preset durable pool (trait method).
		$controller->warm_window( new DateTimeImmutable( '2026-06-08 00:00:00' ), new DateTimeImmutable( '2026-06-14 23:59:59' ) );
		$controller->reset_compute_count();

		$request = new WP_REST_Request( 'GET', '/stub' );
		$request->set_query_params(
			[
				'start'         => '2026-06-08',
				'end'           => '2026-06-14',
				'compare_start' => '2026-06-01',
				'compare_end'   => '2026-06-07',
			]
		);
		$controller->call_cached( $request );

		// Only the previous window computes live; the current window is served durable.
		$this->assertSame( 1, $controller->compute_count(), 'Current window must not recompute when durable.' );

		\Newspack\Insights\Cache::prune_durable( $controller->tab_slug_public(), [] );
	}

	/** No-compare GET returns previous => null and caches the current window on-demand. */
	public function test_no_compare_get_caches_current_ondemand() {
		$controller = new Newspack_Test_Stub_Cached_Controller();
		$request    = new WP_REST_Request( 'GET', '/stub' );
		$request->set_query_params(
			[
				'start' => '2026-06-08',
				'end'   => '2026-06-14',
			]
		);

		$data = $controller->call_cached( $request )->get_data()['data'];
		$this->assertNull( $data['previous'] );
		$this->assertNotNull(
			\Newspack\Insights\Cache::peek_ondemand(
				$controller->tab_slug_public(),
				$controller->cache_source_public(),
				$controller->base_key_public( new DateTimeImmutable( '2026-06-08 00:00:00' ), new DateTimeImmutable( '2026-06-14 23:59:59' ) )
			)
		);
		\Newspack\Insights\Cache::purge_ondemand( $controller->tab_slug_public() );
	}
}
