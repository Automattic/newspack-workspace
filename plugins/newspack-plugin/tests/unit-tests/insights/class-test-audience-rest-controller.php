<?php
/**
 * Test Audience_REST_Controller (NPPD-1729 Task B6).
 *
 * Exercises the Tab 1 endpoint's request lifecycle: a valid window returns 200
 * with the cache envelope wrapping the data payload; the response carries
 * `current` and `registered_readers` keys (no `tab_pending`, no `tab_error`
 * in the test env since the BQ path requires no OAuth gate); comparison mode
 * populates a `previous` window; invalid / mismatched date params return 400;
 * the `/audience/refresh` POST route is registered.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use Newspack\Insights\Cache;
use Newspack\Insights\Audience_REST_Controller;
use Newspack\Insights\Metric_Status;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use ReflectionMethod;

// Registered-readers metric reads reader roles via Reader_Activation and
// product detection via WC stubs — pull in the shared stubs.
require_once __DIR__ . '/../../mocks/wc-mocks.php';

// Newspack_Manager stub (the real class lives in a separate, non-monorepo
// plugin), declared in the global namespace to match
// BigQuery_Proxy_Client's `\Newspack_Manager` reference. Off (not connected)
// by default — armed only around the two data_status end-to-end tests below
// via \Newspack_Manager::enable_stub_connection()/disable_stub_connection()
// — so every other test in this file (and this file's presence doesn't
// affect other test files, since none of them load this mock) keeps
// exercising the default "hub not configured" path.
require_once __DIR__ . '/../../mocks/class-newspack-manager.php';

/**
 * Audience_REST_Controller test class.
 *
 * @group insights
 */
class Test_Audience_REST_Controller extends WP_UnitTestCase {

	const ROUTE = '/newspack-insights/v1/audience';

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Which canned response `stub_bq_hub_response()` returns for the
	 * newsletter-conversion catalog queries: 'warming' | 'error'. Set by each
	 * end-to-end data_status test right before dispatching.
	 *
	 * @var string
	 */
	private $bq_hub_response_variant = 'warming';

	/**
	 * Set up: an admin user + a registered Audience route on a fresh server.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Register on the rest_api_init action (as production does) — calling
		// register_rest_route outside that action triggers a _doing_it_wrong notice.
		add_action( 'rest_api_init', [ $this, 'register_audience_route' ] );

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		// Wipe transients + cooldown markers so cache state doesn't leak between tests.
		Cache::purge( 'audience' );
	}

	/**
	 * Register the Audience route. Hooked to rest_api_init in set_up().
	 *
	 * @return void
	 */
	public function register_audience_route() {
		( new Audience_REST_Controller() )->register_routes();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_action( 'rest_api_init', [ $this, 'register_audience_route' ] );
		global $wp_rest_server;
		$wp_rest_server = null;
		Cache::purge( 'audience' );
		parent::tear_down();
	}

	/**
	 * Build + dispatch a GET request to the Audience route.
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
	 * returns null — the Audience tab is live and no `tab_pending` key
	 * (Phase 1 placeholder) and no `tab_error` key (BQ not disconnectable)
	 * appear in the response. The data envelope carries `current` and
	 * `registered_readers` keys.
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

		// Normal data shape: current window + registered readers present.
		$this->assertArrayHasKey( 'current', $data );
		$this->assertNull( $data['previous'] );
		$this->assertArrayHasKey( 'registered_readers', $data );
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
	 * Per-window assembly parity: registered_readers.new.previous in compare mode
	 * matches the legacy build_response() oracle, and the comparison value reflects
	 * the PREVIOUS window (not the current one), proving graft_previous() pulled the
	 * right per-window payload.
	 *
	 * The legacy oracle is build_response() invoked directly via reflection with
	 * both current and previous windows — that path computes registered_readers
	 * inline in one call, so it represents the ground-truth value for the delta.
	 * The assembled path caches each window independently (base payload has null
	 * comparison) and then grafts the previous window in via graft_previous().
	 * Both paths see the same wp_users state, so the counts must match.
	 *
	 * A subscriber seeded inside the compare window (2025-12-15, inside Dec 2025)
	 * but outside the current window (Jan 2026) gives the test discriminating teeth:
	 * a missing graft would yield null vs the legacy array (equality fails), and a
	 * wrong-window graft (current instead of previous) would yield 0 vs 1 (value
	 * assertions fail).
	 */
	public function test_graft_previous_preserves_registered_readers_new_previous() {
		// Seed a reader-role user registered inside the compare window (2025-12-15)
		// but outside the current window (Jan 2026). This ensures registered_readers
		// is non-zero in the previous window and zero in the current window, making
		// the test sensitive to both a missing graft and a wrong-window graft.
		$uid = wp_insert_user(
			[
				'user_login'      => 'reader_dec2025',
				'user_pass'       => 'x',
				'user_email'      => 'reader_dec2025@example.com',
				'role'            => 'subscriber',
				'user_registered' => '2025-12-15 10:00:00',
			]
		);
		if ( is_wp_error( $uid ) ) {
			$this->fail( 'Failed to create seeded reader: ' . $uid->get_error_message() );
		}

		$controller = new Audience_REST_Controller();

		$start  = new DateTimeImmutable( '2026-01-01' );
		$end    = new DateTimeImmutable( '2026-01-31' );
		$cstart = new DateTimeImmutable( '2025-12-01' );
		$cend   = new DateTimeImmutable( '2025-12-31' );

		// Legacy oracle: direct call to build_response() with both windows.
		$m = new ReflectionMethod( $controller, 'build_response' );
		$m->setAccessible( true );
		$legacy = $m->invoke( $controller, $start, $end, $cstart, $cend );

		// Assembled path: dispatch a compare-mode GET through the REST server.
		$response = $this->dispatch(
			[
				'start'         => '2026-01-01',
				'end'           => '2026-01-31',
				'compare_start' => '2025-12-01',
				'compare_end'   => '2025-12-31',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$assembled = $response->get_data()['data'];

		// The assembled registered_readers.new.previous must equal the legacy value.
		// A missing graft yields null (assembled) vs a non-null array (legacy), so
		// this assertion catches both a missing graft and any value mismatch.
		$this->assertSame(
			$legacy['registered_readers']['new']['previous'],
			$assembled['registered_readers']['new']['previous']
		);

		// Top-level current must also match.
		$this->assertSame( $legacy['current'], $assembled['current'] );

		// The seeded subscriber is in the compare window (Dec 2025) so Reader
		// Activation counts exactly 1 there. Pin the value on both paths to prove
		// the graft pulled the previous-window payload, not the current-window one
		// (which would yield 0 because no readers were registered in Jan 2026).
		$this->assertSame( 1, $legacy['registered_readers']['new']['previous']['value'] );
		$this->assertSame( 1, $assembled['registered_readers']['new']['previous']['value'] );
		$this->assertSame( 0, $assembled['registered_readers']['new']['current']['value'] );

		Cache::purge_ondemand( 'audience' );
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

	/**
	 * With no BQ hub configured (the default test-env state), no metric in the
	 * Audience envelope carries a 'warming' or 'error' state, so the NEWS-2603
	 * top-level `data_status` field is 'complete'.
	 */
	public function test_data_status_is_complete_by_default() {
		$response = $this->dispatch(
			[
				'start' => '2026-01-01',
				'end'   => '2026-01-31',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( 'data_status', $data );
		$this->assertSame( 'complete', $data['data_status'] );
	}

	/**
	 * NEWS-2603: `data_status` is stamped centrally by the shared
	 * `Cached_Controller_Trait::status_stamped_window_payload()` — the single
	 * path every cached/refreshed/pre-warmed current window flows through — by
	 * calling `Metric_Status::derive()` on the assembled window, never by
	 * hardcoding 'complete'. Proven directly: the stamped `data_status` must
	 * equal `Metric_Status::derive()` run independently over the same payload
	 * with `data_status` stripped out, so the two can never drift apart.
	 */
	public function test_data_status_matches_independent_deriver_call() {
		$controller = new Audience_REST_Controller();
		$m          = new ReflectionMethod( $controller, 'status_stamped_window_payload' );
		$m->setAccessible( true );

		$start = new DateTimeImmutable( '2026-01-01' );
		$end   = new DateTimeImmutable( '2026-01-31' );

		$response = $m->invoke( $controller, $start, $end );

		$this->assertArrayHasKey( 'data_status', $response );

		$without_status = $response;
		unset( $without_status['data_status'] );

		$this->assertSame(
			Metric_Status::derive( $without_status ),
			$response['data_status']
		);
	}

	/**
	 * NEWS-2603 end-to-end: when the hub's newsletter-conversion snapshot
	 * query returns the warming marker (a cache-miss-being-backfilled signal
	 * — see Conversion_Metric::warming_scalar()), that state now propagates
	 * through Conversion_Metric::get_newsletter_subscriber_value_3yr() (fixed
	 * alongside this test — it previously dropped the state entirely) into
	 * the Audience envelope's top-level `data_status`. Requires WooCommerce
	 * "active" (via the newspack_insights_woocommerce_active filter, mirroring
	 * how other Insights tests toggle it) so get_newsletter_subscriber_value_3yr()
	 * reaches the hub call at all, and the stubbed Newspack_Manager +
	 * pre_http_request so BigQuery_Proxy_Client::is_configured() is true and
	 * the hub round-trip is reachable in-process.
	 */
	public function test_data_status_is_warming_when_newsletter_metric_is_warming() {
		add_filter( 'newspack_insights_woocommerce_active', '__return_true' );
		add_filter( 'pre_http_request', [ $this, 'stub_bq_hub_response' ], 10, 3 );
		\Newspack_Manager::enable_stub_connection();
		$this->bq_hub_response_variant = 'warming';

		try {
			$response = $this->dispatch(
				[
					'start' => '2026-01-01',
					'end'   => '2026-01-31',
				]
			);

			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data()['data'];
			$this->assertSame( 'warming', $data['data_status'] );
		} finally {
			remove_filter( 'newspack_insights_woocommerce_active', '__return_true' );
			remove_filter( 'pre_http_request', [ $this, 'stub_bq_hub_response' ], 10 );
			\Newspack_Manager::disable_stub_connection();
		}
	}

	/**
	 * NEWS-2603 end-to-end: an errored newsletter-conversion hub query makes
	 * `data_status` 'incomplete' — error takes precedence over any concurrent
	 * warming signal elsewhere in the envelope.
	 */
	public function test_data_status_is_incomplete_when_newsletter_metric_errors() {
		add_filter( 'newspack_insights_woocommerce_active', '__return_true' );
		add_filter( 'pre_http_request', [ $this, 'stub_bq_hub_response' ], 10, 3 );
		\Newspack_Manager::enable_stub_connection();
		$this->bq_hub_response_variant = 'error';

		try {
			$response = $this->dispatch(
				[
					'start' => '2026-01-01',
					'end'   => '2026-01-31',
				]
			);

			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data()['data'];
			$this->assertSame( 'incomplete', $data['data_status'] );
		} finally {
			remove_filter( 'newspack_insights_woocommerce_active', '__return_true' );
			remove_filter( 'pre_http_request', [ $this, 'stub_bq_hub_response' ], 10 );
			\Newspack_Manager::disable_stub_connection();
		}
	}

	/**
	 * NEWS-2603 follow-up end-to-end: a failed CORE Audience BigQuery metric
	 * (not the newsletter snapshot) makes `data_status` 'incomplete'. Core
	 * metrics signal a hub failure as `computable:false` + an `error` string
	 * with no `state` key; Metric_Status::derive() must recognise that legacy
	 * convention so the warning banner reflects a genuine core-BQ outage.
	 * WooCommerce is left inactive so the newsletter metric short-circuits to
	 * `not_configured` (a populated, non-error state) — isolating the core
	 * metric error as the sole driver.
	 */
	public function test_data_status_is_incomplete_when_core_bq_metric_errors() {
		add_filter( 'pre_http_request', [ $this, 'stub_bq_hub_response' ], 10, 3 );
		\Newspack_Manager::enable_stub_connection();
		$this->bq_hub_response_variant = 'core_error';

		try {
			$response = $this->dispatch(
				[
					'start' => '2026-01-01',
					'end'   => '2026-01-31',
				]
			);

			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data()['data'];
			$this->assertSame( 'incomplete', $data['data_status'] );
		} finally {
			remove_filter( 'pre_http_request', [ $this, 'stub_bq_hub_response' ], 10 );
			\Newspack_Manager::disable_stub_connection();
		}
	}

	/**
	 * `pre_http_request` responder: the newsletter-conversion catalog queries
	 * return either the hub's warming marker or an HTTP error, per
	 * $this->bq_hub_response_variant; every other catalog query (the 19
	 * Audience metrics) returns an empty result set, which the corresponding
	 * metric shapers already degrade to a non-computable, non-`state`-bearing
	 * value for (see Audience_Metric::proxy_scalar()/proxy_rows()) — so only
	 * the newsletter metric contributes a state to the envelope.
	 *
	 * @param mixed  $preempt Pre-emptive response (unused).
	 * @param array  $args    Request args, including the JSON-encoded body.
	 * @param string $url     Request URL (unused).
	 * @return array
	 */
	public function stub_bq_hub_response( $preempt, $args, $url ) {
		$decoded    = json_decode( $args['body'] ?? '{}', true );
		$query_name = is_array( $decoded ) ? ( $decoded['query_name'] ?? '' ) : '';

		$is_newsletter_conversion = in_array(
			$query_name,
			[
				'conversion_journey_newsletter_to_subscription',
				'conversion_journey_newsletter_to_donation',
			],
			true
		);

		// Core-metric-error variant (NEWS-2603 follow-up): every core Audience
		// BigQuery query fails with an HTTP error (which BigQuery_Proxy_Client
		// turns into a WP_Error, shaped by proxy_scalar/proxy_rows into a
		// `computable:false` + `error` payload), isolating a core-metric error
		// as the sole data_status driver. The test leaves WooCommerce inactive so
		// the newsletter metric short-circuits to `not_configured` before calling
		// the hub — so the newsletter branch below normally isn't reached; it
		// returns an empty (non-error) set defensively, only in case that query
		// is ever invoked, so it never contributes an error of its own.
		if ( 'core_error' === $this->bq_hub_response_variant ) {
			if ( $is_newsletter_conversion ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [] ),
				];
			}
			return [
				'response' => [ 'code' => 500 ],
				'body'     => wp_json_encode(
					[
						'code'    => 'bigquery_proxy_http_error',
						'message' => 'Simulated core metric failure.',
					]
				),
			];
		}

		if ( ! $is_newsletter_conversion ) {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [] ),
			];
		}

		if ( 'error' === $this->bq_hub_response_variant ) {
			return [
				'response' => [ 'code' => 500 ],
				'body'     => wp_json_encode(
					[
						'code'    => 'bigquery_proxy_http_error',
						'message' => 'Simulated hub failure.',
					]
				),
			];
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ [ '__status' => 'warming' ] ] ),
		];
	}
}
