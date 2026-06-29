<?php
/**
 * Test Prompts_REST_Controller (NPPD-1607, Phase 2).
 *
 * Exercises the Tab 5 endpoint's request lifecycle: a valid window
 * returns 200 with the state-envelope (Phase 2 replaces the Phase 1
 * `tab_pending` placeholder with `tab_error`); comparison mode adds a
 * `previous` window; invalid / mismatched date params return 400; the
 * fixture-mode branch returns canned data when
 * NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Cache;
use Newspack\Insights\Prompts_Metric;
use Newspack\Insights\Prompts_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Prompts_REST_Controller test class.
 *
 * @group insights
 */
class Test_Prompts_REST_Controller extends WP_UnitTestCase {

	const ROUTE = '/newspack-insights/v1/prompts';

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up: an admin user + a registered Prompts route on a fresh server.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Register on the rest_api_init action (as production does) — calling
		// register_rest_route outside that action triggers a _doing_it_wrong notice.
		add_action( 'rest_api_init', [ $this, 'register_prompts_route' ] );

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		// Wipe transients + cooldown markers so cache state doesn't leak between tests.
		Cache::purge( 'prompts' );
	}

	/**
	 * Register the Prompts route. Hooked to rest_api_init in set_up().
	 *
	 * @return void
	 */
	public function register_prompts_route() {
		( new Prompts_REST_Controller() )->register_routes();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_action( 'rest_api_init', [ $this, 'register_prompts_route' ] );
		global $wp_rest_server;
		$wp_rest_server = null;
		Cache::purge( 'prompts' );
		parent::tear_down();
	}

	/**
	 * Build + dispatch a GET request to the Prompts route.
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
	 * A valid window returns 200 with the cache envelope wrapping the state
	 * envelope. In the test env the proxy is unconfigured, so every metric
	 * surfaces `state: 'error'` and the controller's `is_window_all_error`
	 * derives `tab_error: true`.
	 */
	public function test_valid_window_returns_200_envelope() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();

		// Outer cache envelope shape ({ cache, data }).
		$this->assertArrayHasKey( 'cache', $body );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $body['cache']['source'] );
		$this->assertNotEmpty( $body['cache']['computed_at'] );
		$this->assertArrayHasKey( 'cooldown_until', $body['cache'] );

		$data = $body['data'];

		// Phase 2 replaces `tab_pending` with `tab_error`; the Phase 1 key must
		// not leak through to the wire format.
		$this->assertArrayNotHasKey( 'tab_pending', $data );
		$this->assertArrayHasKey( 'tab_error', $data );
		// NPPD-1745: tab_error is scoped to hub-backed metrics. The test env has an
		// unconfigured proxy (every hub metric errors) AND no WooCommerce. The two
		// migrated donation cards still render a NON-error empty state (revenue: local;
		// rate: hybrid, short-circuited on non-WC) — but a non-WC hybrid card never
		// reaches the hub, so it is NOT a hub-backed survivor and must not mask the
		// outage. Every genuinely hub-backed (pure-hub) card errored, so the banner
		// fires. Before the banner-hole fix this asserted false — that was asserting
		// the bug, where the empty hybrid card silently suppressed the banner.
		$this->assertTrue( $data['tab_error'] );
		$this->assertArrayHasKey( 'current', $data );
		$this->assertNull( $data['previous'] );
		$this->assertSame( 'populated', $data['current']['donation_revenue_direct']['state'], 'local card is non-error' );
		$this->assertSame( 'populated', $data['current']['donation_conversion_direct']['state'], 'hybrid card empties on non-WC' );

		// Window echo + one representative metric per section is present.
		$current = $data['current'];
		$this->assertSame( '2026-03-22', $current['window']['start'] );
		$this->assertSame( '2026-04-21', $current['window']['end'] );
		foreach (
			[
				'total_prompt_impressions',
				'click_through_rate',
				'registration_conversion_direct',
				'donation_conversion_direct',
				'donation_revenue_direct',
				'conversion_funnel',
				'exposures_distribution',
				'performance_by_prompt',
				'performance_by_intent',
				'performance_by_placement',
			] as $key
		) {
			$this->assertArrayHasKey( $key, $current, "Missing window key: $key" );
		}

		// A scalar carries the state-envelope (Phase 2). In the test env the proxy
		// is unconfigured, so wired metrics surface as state 'error' (with
		// `bigquery_proxy_not_configured`) — the envelope under test is the
		// `state` + `placeholder_type` contract.
		$this->assertArrayHasKey( 'state', $current['total_prompt_impressions'] );
		$this->assertArrayNotHasKey( 'pending', $current['total_prompt_impressions'] );
		$this->assertSame( 'error', $current['total_prompt_impressions']['state'] );
		$this->assertSame( 'count', $current['total_prompt_impressions']['placeholder_type'] );
		// Tables ship a `rows` key for the empty-state UI; wired collection
		// metrics surface as state 'error' with `bigquery_proxy_not_configured`.
		$this->assertSame( [], $current['performance_by_prompt']['rows'] );
		$this->assertSame( 'error', $current['performance_by_prompt']['state'] );
	}

	/**
	 * The per-intent capability gate (NPPD-1720) rides only on the 13
	 * conversion-tied scalars; exposure / engagement-rate / collection metrics
	 * never carry it. In the test env newspack-popups is absent, so the detector
	 * fails open and every gated metric reports has_capability: true.
	 */
	public function test_capability_flag_rides_on_conversion_metrics_only() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);
		$current = $response->get_data()['data']['current'];

		foreach (
			[
				'form_submission_rate',
				'registration_conversion_direct',
				'registration_conversion_influenced_7d',
				'newsletter_signup_conversion_direct',
				'newsletter_signup_conversion_influenced_7d',
				'donation_conversion_direct',
				'donation_conversion_influenced_14d',
				'subscription_conversion_direct',
				'subscription_conversion_influenced_14d',
				'donation_revenue_direct',
				'donation_revenue_influenced_14d',
				'subscription_revenue_direct',
				'subscription_revenue_influenced_14d',
			] as $key
		) {
			$this->assertArrayHasKey( 'has_capability', $current[ $key ], "Missing has_capability on $key" );
			$this->assertTrue( $current[ $key ]['has_capability'], "Fail-open should mark $key capable when popups is absent" );
		}

		// Exposure / engagement-rate scalars and collection metrics never carry it.
		foreach (
			[
				'total_prompt_impressions',
				'unique_readers_reached',
				'avg_prompts_per_reader',
				'click_through_rate',
				'dismissal_rate',
				'conversion_funnel',
				'performance_by_prompt',
			] as $key
		) {
			$this->assertArrayNotHasKey( 'has_capability', $current[ $key ], "$key must not carry has_capability" );
		}
	}

	/**
	 * Comparison mode (both compare params) adds a populated `previous`
	 * window with the same shape.
	 */
	public function test_comparison_mode_populates_previous() {
		$response = $this->dispatch(
			[
				'start'         => '2026-03-22',
				'end'           => '2026-04-21',
				'compare_start' => '2026-02-20',
				'compare_end'   => '2026-03-21',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$data = $body['data'];

		$this->assertIsArray( $data['previous'] );
		$this->assertSame( '2026-02-20', $data['previous']['window']['start'] );
		$this->assertSame( '2026-03-21', $data['previous']['window']['end'] );
		$this->assertArrayHasKey( 'total_prompt_impressions', $data['previous'] );
	}

	/**
	 * An unparseable date is rejected by the route's validate_callback.
	 */
	public function test_invalid_date_returns_400() {
		$response = $this->dispatch(
			[
				'start' => 'not-a-date',
				'end'   => '2026-04-21',
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
				'start' => '2026-04-21',
				'end'   => '2026-03-22',
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
				'start'         => '2026-03-22',
				'end'           => '2026-04-21',
				'compare_start' => '2026-02-20',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The mirror of the above: a lone comparison end (no start) is rejected.
	 */
	public function test_partial_comparison_end_only_returns_400() {
		$response = $this->dispatch(
			[
				'start'       => '2026-03-22',
				'end'         => '2026-04-21',
				'compare_end' => '2026-03-21',
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
				'start'         => '2026-03-22',
				'end'           => '2026-04-21',
				'compare_start' => '2026-03-21',
				'compare_end'   => '2026-02-20',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The refresh route mirrors the GET route's envelope shape and is
	 * registered alongside it. The route accepts POST (Cached_Controller_Trait
	 * uses WP_REST_Server::CREATABLE).
	 */
	public function test_refresh_route_returns_cache_envelope() {
		$request = new WP_REST_Request( 'POST', self::ROUTE . '/refresh' );
		$request->set_param( 'start', '2026-03-22' );
		$request->set_param( 'end', '2026-04-21' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'cache', $body );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $body['cache']['source'] );
		// First refresh seeds the BQ cooldown stamp so the React layer can render the throttle UI.
		$this->assertNotEmpty( $body['cache']['cooldown_until'] );
		$this->assertArrayHasKey( 'tab_error', $body['data'] );
	}

	/**
	 * The fixture closure (which the REST controller invokes via
	 * `Prompts_Metric::get_fixture` when `NEWSPACK_INSIGHTS_FIXTURE_MODE` is on)
	 * produces a populated payload with `tab_error: false` and every section
	 * in state 'populated'.
	 *
	 * The controller's fixture-mode branch is a thin wrapper around this
	 * static; exercising the static directly avoids redefining the
	 * NEWSPACK_INSIGHTS_FIXTURE_MODE constant per test (PHP constants can't be
	 * undefined once set, which would taint the rest of the suite).
	 */
	public function test_fixture_populated_variant() {
		$payload = Prompts_Metric::get_fixture( 'populated', false );

		$this->assertArrayHasKey( 'tab_error', $payload );
		$this->assertFalse( $payload['tab_error'] );
		$this->assertNull( $payload['previous'] );

		$current = $payload['current'];
		$this->assertSame( 'populated', $current['total_prompt_impressions']['state'] );
		$this->assertSame( 'count', $current['total_prompt_impressions']['placeholder_type'] );
		$this->assertTrue( $current['total_prompt_impressions']['computable'] );
		$this->assertGreaterThan( 0, $current['total_prompt_impressions']['value'] );

		$this->assertSame( 'populated', $current['conversion_funnel']['state'] );
		$this->assertCount( 3, $current['conversion_funnel']['stages'] );
		$this->assertSame( 'populated', $current['performance_by_prompt']['state'] );
		$this->assertNotEmpty( $current['performance_by_prompt']['rows'] );

		// Per-prompt rows honor the locked row schema (Task 3.3; + intent_label NPPD-1758).
		$row = $current['performance_by_prompt']['rows'][0];
		$this->assertSame(
			[
				'popup_id',
				'prompt_title',
				'intent',
				'intent_label',
				'placement',
				'impressions',
				'unique_viewers',
				'ctr',
				'form_submission_rate',
				'dismissal_rate',
				'registrations',
				'newsletter_signups',
				'donation_conversions',
				'donation_conversion_rate',
				'subscription_conversions',
				'subscription_conversion_rate',
			],
			array_keys( $row )
		);
	}

	/**
	 * The empty fixture variant reports collections as state 'empty' (queries
	 * succeeded with zero rows) and `tab_error: false`.
	 */
	public function test_fixture_empty_variant() {
		$payload = Prompts_Metric::get_fixture( 'empty', false );

		$this->assertFalse( $payload['tab_error'] );
		$current = $payload['current'];
		$this->assertSame( 'empty', $current['conversion_funnel']['state'] );
		$this->assertSame( [], $current['conversion_funnel']['stages'] );
		$this->assertSame( 'empty', $current['exposures_distribution']['state'] );
		$this->assertSame( 'empty', $current['performance_by_prompt']['state'] );
		$this->assertSame( 'empty', $current['performance_by_intent']['state'] );
		$this->assertSame( 'empty', $current['performance_by_placement']['state'] );

		// Scalars in the empty variant report 'populated' with a non-computable
		// zero — 'empty' has no meaning for a single scalar.
		$this->assertSame( 'populated', $current['total_prompt_impressions']['state'] );
		$this->assertFalse( $current['total_prompt_impressions']['computable'] );
		$this->assertSame( 0, $current['total_prompt_impressions']['value'] );
	}

	/**
	 * The error fixture variant reports every section in state 'error' and
	 * `tab_error: true`.
	 */
	public function test_fixture_error_variant() {
		$payload = Prompts_Metric::get_fixture( 'error', false );

		$this->assertTrue( $payload['tab_error'] );
		$current = $payload['current'];
		$this->assertSame( 'error', $current['total_prompt_impressions']['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $current['total_prompt_impressions']['error_code'] );
		$this->assertSame( 'error', $current['conversion_funnel']['state'] );
		$this->assertSame( 'error', $current['performance_by_prompt']['state'] );
	}

	/**
	 * The fixture closure populates `previous` when comparison is requested.
	 */
	public function test_fixture_compare_populates_previous() {
		$payload = Prompts_Metric::get_fixture( 'populated', true );

		$this->assertIsArray( $payload['previous'] );
		$this->assertArrayHasKey( 'total_prompt_impressions', $payload['previous'] );
		$this->assertSame( 'populated', $payload['previous']['total_prompt_impressions']['state'] );
	}

	/**
	 * Invoke the private static is_window_all_error() on a synthetic window.
	 *
	 * @param array $window             Window payload.
	 * @param bool  $woocommerce_active Whether WC is active (defaults to the WC-active path).
	 * @return bool
	 */
	private function invoke_is_window_all_error( array $window, bool $woocommerce_active = true ): bool {
		$method = new \ReflectionMethod( Prompts_REST_Controller::class, 'is_window_all_error' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $window, $woocommerce_active );
	}

	/**
	 * Build a window where every hub-backed metric errors and every local card is
	 * populated (the hub-outage-with-local-survivor scenario).
	 *
	 * @return array
	 */
	private function window_hub_down_local_alive(): array {
		$window = [
			'window' => [
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			],
		];
		foreach ( Prompts_Metric::METRIC_SOURCES as $key => $source ) {
			$window[ $key ] = [ 'state' => 'local' === $source ? 'populated' : 'error' ];
		}
		return $window;
	}

	/**
	 * NPPD-1745 regression guard: the "whole tab failed" banner STILL fires when
	 * every hub-backed metric errors, even though a surviving local card renders.
	 * This is the exact thing the old all-error logic would have silently killed.
	 */
	public function test_tab_error_fires_on_hub_outage_despite_local_survivor() {
		$window = $this->window_hub_down_local_alive();

		// The local survivor is genuinely present and populated.
		$this->assertSame( 'local', Prompts_Metric::METRIC_SOURCES['donation_revenue_direct'] );
		$this->assertSame( 'populated', $window['donation_revenue_direct']['state'] );

		$this->assertTrue(
			$this->invoke_is_window_all_error( $window ),
			'all hub-backed errored → banner fires even though the local card rendered'
		);
	}

	/**
	 * The banner does NOT fire if any hub-backed metric recovers.
	 */
	public function test_tab_error_does_not_fire_when_a_hub_metric_recovers() {
		$window = $this->window_hub_down_local_alive();
		$window['total_prompt_impressions'] = [ 'state' => 'populated' ]; // one hub card recovers.

		$this->assertFalse( $this->invoke_is_window_all_error( $window ) );
	}

	/**
	 * Build the non-WC hub-outage window: every pure-hub card errors, while the
	 * hybrid cards short-circuit to a populated empty state (they never reach the
	 * hub on a non-WC publisher) and the local cards render. The banner-hole case.
	 *
	 * @return array
	 */
	private function window_non_wc_hub_down(): array {
		$window = [
			'window' => [
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			],
		];
		foreach ( Prompts_Metric::METRIC_SOURCES as $key => $source ) {
			// hub → error (outage); hybrid + local → populated (non-WC: a hybrid card
			// empties out before it reaches the hub; local is always local).
			$window[ $key ] = [ 'state' => 'hub' === $source ? 'error' : 'populated' ];
		}
		return $window;
	}

	/**
	 * NPPD-1745 banner-hole fix: on a non-WC publisher a hybrid card short-circuits
	 * to a populated empty state without ever calling the hub, so it must NOT count
	 * as a hub-backed survivor. With every pure-hub card erroring, the banner fires
	 * even though the hybrid + local cards render. (Pre-fix, the populated hybrid
	 * card returned false here, silently suppressing the banner.)
	 */
	public function test_tab_error_fires_on_non_wc_hub_outage() {
		$window = $this->window_non_wc_hub_down();

		// Sanity: a hybrid card is genuinely populated (not error) in this window.
		$this->assertSame( 'hybrid', Prompts_Metric::METRIC_SOURCES['donation_conversion_direct'] );
		$this->assertSame( 'populated', $window['donation_conversion_direct']['state'] );

		$this->assertTrue(
			$this->invoke_is_window_all_error( $window, false ),
			'non-WC: the hybrid card is skipped, so all-pure-hub-errored fires the banner'
		);
		// Guard: were WC active, the same populated hybrid card would be a genuine
		// hub-backed survivor and (correctly) suppress the banner.
		$this->assertFalse(
			$this->invoke_is_window_all_error( $window, true ),
			'WC-active: a populated hybrid card is a real survivor → no banner'
		);
	}

	/**
	 * Prompts_REST_Controller overrides cache_schema_version() with Prompts_Metric::CACHE_PREFIX
	 * (tab5_v2), so bumping the prefix on a response-shape change busts stale-shape
	 * transients on deploy.
	 */
	public function test_prompts_controller_cache_version_is_tab5_v2_prefix() {
		$controller = new Prompts_REST_Controller();
		$ref        = new \ReflectionMethod( $controller, 'cache_schema_version' );
		$ref->setAccessible( true );
		$this->assertSame( Prompts_Metric::CACHE_PREFIX, $ref->invoke( $controller ) );
		$this->assertStringContainsString( 'v2', Prompts_Metric::CACHE_PREFIX );
	}

	/**
	 * NPPD-1745 #5 drift guard: every state-bearing metric that build_window()
	 * emits must have a METRIC_SOURCES entry. is_window_all_error() iterates
	 * METRIC_SOURCES, so a card added to the window but never classified in the map
	 * would silently drop out of the tab-error banner with no test to catch it. The
	 * cleaner long-term fix is to carry the source on the metric envelope itself
	 * (tracked as a follow-up); this guard closes the silent-failure path now.
	 */
	public function test_every_window_metric_is_classified_in_metric_sources() {
		$data = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		)->get_data()['data'];

		$missing = [];
		foreach ( $data['current'] as $key => $value ) {
			// Skip date metadata and non-metric scalar section-totals (int|null); only
			// state-bearing metric envelopes participate in the banner decision.
			if ( 'window' === $key || ! is_array( $value ) || ! isset( $value['state'] ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, Prompts_Metric::METRIC_SOURCES ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			[],
			$missing,
			'every state-bearing build_window metric must be classified in Prompts_Metric::METRIC_SOURCES'
		);
	}
}
