<?php
/**
 * Test Engagement_REST_Controller (NPPD-1729 Task B6).
 *
 * Exercises the Tab 2 endpoint's request lifecycle: a valid window returns 200
 * with the cache envelope wrapping the data payload; the response carries a
 * `current` key (no `tab_pending`, no `tab_error` in the test env since the BQ
 * path requires no OAuth gate); comparison mode populates a `previous` window;
 * invalid / mismatched date params return 400; the `/engagement/refresh` POST
 * route is registered.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Cache;
use Newspack\Insights\Engagement_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Engagement_REST_Controller test class.
 *
 * @group insights
 */
class Test_Engagement_REST_Controller extends WP_UnitTestCase {

	const ROUTE = '/newspack-insights/v1/engagement';

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up: an admin user + a registered Engagement route on a fresh server.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Register on the rest_api_init action (as production does) — calling
		// register_rest_route outside that action triggers a _doing_it_wrong notice.
		add_action( 'rest_api_init', [ $this, 'register_engagement_route' ] );

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		// Wipe transients + cooldown markers so cache state doesn't leak between tests.
		Cache::purge( 'engagement' );
	}

	/**
	 * Register the Engagement route. Hooked to rest_api_init in set_up().
	 *
	 * @return void
	 */
	public function register_engagement_route() {
		( new Engagement_REST_Controller() )->register_routes();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_action( 'rest_api_init', [ $this, 'register_engagement_route' ] );
		global $wp_rest_server;
		$wp_rest_server = null;
		Cache::purge( 'engagement' );
		parent::tear_down();
	}

	/**
	 * Build + dispatch a GET request to the Engagement route.
	 *
	 * @param array $params Query params.
	 * @return \WP_REST_Response
	 */
	private function dispatch( array $params ) {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * A valid window returns 200 with the cache envelope wrapping the data.
	 *
	 * The BQ path requires no OAuth gate, so `connection_error()` always
	 * returns null — the Engagement tab is live and no `tab_pending` key
	 * (Phase 1 placeholder) and no `tab_error` key (BQ not disconnectable)
	 * appear in the response. The data envelope carries a `current` key.
	 */
	public function test_valid_window_returns_200_envelope() {
		$response = $this->dispatch(
			[
				'start' => '2026-01-01',
				'end'   => '2026-01-31',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();

		// Outer cache envelope shape ({ cache, data }).
		$this->assertArrayHasKey( 'cache', $body );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertSame( Cache::SOURCE_EXTERNAL, $body['cache']['source'] );
		$this->assertNotEmpty( $body['cache']['computed_at'] );
		$this->assertArrayHasKey( 'cooldown_until', $body['cache'] );

		$data = $body['data'];

		// The BQ path carries no OAuth gate, so neither the Phase 1 tab_pending
		// placeholder nor the GA4 connect-banner tab_error key should appear.
		$this->assertArrayNotHasKey( 'tab_pending', $data );
		$this->assertArrayNotHasKey( 'tab_error', $data );

		// Normal data shape: current window present, previous null (no comparison).
		$this->assertArrayHasKey( 'current', $data );
		$this->assertNull( $data['previous'] );
	}

	/**
	 * Comparison mode (both compare params) adds a populated `previous` window
	 * with the same structure as `current`.
	 */
	public function test_comparison_mode_populates_previous() {
		$response = $this->dispatch(
			[
				'start'         => '2026-01-01',
				'end'           => '2026-01-31',
				'compare_start' => '2025-12-01',
				'compare_end'   => '2025-12-31',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];

		$this->assertIsArray( $data['previous'] );
	}

	/**
	 * An unparseable date is rejected by the route's validate_callback.
	 */
	public function test_invalid_date_returns_400() {
		$response = $this->dispatch(
			[
				'start' => 'not-a-date',
				'end'   => '2026-01-31',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Start after end is rejected by the handler.
	 */
	public function test_start_after_end_returns_400() {
		$response = $this->dispatch(
			[
				'start' => '2026-01-31',
				'end'   => '2026-01-01',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A lone comparison bound (start without end) is rejected.
	 */
	public function test_partial_comparison_returns_400() {
		$response = $this->dispatch(
			[
				'start'         => '2026-01-01',
				'end'           => '2026-01-31',
				'compare_start' => '2025-12-01',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An inverted comparison window (compare_start after compare_end) is rejected.
	 */
	public function test_inverted_comparison_window_returns_400() {
		$response = $this->dispatch(
			[
				'start'         => '2026-01-01',
				'end'           => '2026-01-31',
				'compare_start' => '2025-12-31',
				'compare_end'   => '2025-12-01',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The refresh route mirrors the GET route's envelope shape and is
	 * registered alongside it.
	 */
	public function test_refresh_route_returns_cache_envelope() {
		$request = new WP_REST_Request( 'POST', self::ROUTE . '/refresh' );
		$request->set_param( 'start', '2026-01-01' );
		$request->set_param( 'end', '2026-01-31' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'cache', $body );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertSame( Cache::SOURCE_EXTERNAL, $body['cache']['source'] );
		$this->assertArrayNotHasKey( 'tab_pending', $body['data'] );
	}
}
