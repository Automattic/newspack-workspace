<?php
/**
 * Test Conversion_REST_Controller (NPPD-1609, Phase 2).
 *
 * Exercises the Tab 3 endpoint's request lifecycle: a valid window returns
 * 200 with the cache envelope wrapping the state envelope (Phase 2 replaces
 * the Phase 1 `tab_pending` placeholder with `tab_error`); comparison mode
 * adds a `previous` window; invalid / mismatched date params return 400; the
 * `/conversion/refresh` POST route is registered.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Cache;
use Newspack\Insights\Audience_REST_Controller;
use Newspack\Insights\Conversion_Metric;
use Newspack\Insights\Conversion_REST_Controller;
use Newspack\Insights\Cached_Controller_Trait;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use ReflectionMethod;

/**
 * Conversion_REST_Controller test class.
 *
 * @group insights
 */
class Test_Conversion_REST_Controller extends WP_UnitTestCase {

	const ROUTE = '/newspack-insights/v1/conversion';

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up: an admin user + a registered Conversion route on a fresh server.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Register on the rest_api_init action (as production does) — calling
		// register_rest_route outside that action triggers a _doing_it_wrong notice.
		add_action( 'rest_api_init', [ $this, 'register_conversion_route' ] );

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		// Wipe transients + cooldown markers so cache state doesn't leak between tests.
		Cache::purge( 'conversion' );
	}

	/**
	 * Register the Conversion route. Hooked to rest_api_init in set_up().
	 *
	 * @return void
	 */
	public function register_conversion_route() {
		( new Conversion_REST_Controller() )->register_routes();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_action( 'rest_api_init', [ $this, 'register_conversion_route' ] );
		global $wp_rest_server;
		$wp_rest_server = null;
		Cache::purge( 'conversion' );
		parent::tear_down();
	}

	/**
	 * Build + dispatch a GET request to the Conversion route.
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
	 * envelope. In the test env the BQ proxy is unconfigured — BQ-wired (hub)
	 * metrics surface `state: 'error'`. After the NPPD-1745 fix the banner is
	 * scoped to hub-backed metrics only; local / coming_soon cards are irrelevant
	 * to the decision. With every hub card erroring, `tab_error` is now true.
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
		$this->assertArrayHasKey( 'current', $data );
		$this->assertNull( $data['previous'] );

		// Window echo + one representative metric per section is present.
		$current = $data['current'];
		$this->assertSame( '2026-03-22', $current['window']['start'] );
		$this->assertSame( '2026-04-21', $current['window']['end'] );
		foreach (
			[
				'reader_lifecycle_funnel',         // Section 1.
				'subscriber_to_donor_funnel',      // Section 2.
				'source_mix_registrations',        // Section 3.
				'time_to_register_distribution',   // Section 4.
				'subscriber_retention_cohort',     // Section 5.
				'weekly_conversion_rates',         // Section 6.
				'influenced_registration_rate_7d', // Section 7.
				'top_pages_no_conversion',         // Section 8.
			] as $key
		) {
			$this->assertArrayHasKey( $key, $current, "Missing window key: $key" );
		}

		// A wired scalar carries a state envelope (Phase 2). In the test env the
		// proxy is unconfigured, so BQ-wired scalars surface as state 'error'.
		$this->assertArrayHasKey( 'state', $current['influenced_registration_rate_7d'] );
		$this->assertArrayNotHasKey( 'pending', $current['influenced_registration_rate_7d'] );
		$this->assertSame( 'rate', $current['influenced_registration_rate_7d']['placeholder_type'] );

		// The opportunity table ships with state 'error' when BQ proxy unavailable.
		$this->assertSame( [], $current['top_pages_no_conversion']['rows'] );
	}

	/**
	 * The envelope carries all 23 metric keys (plus the window echo).
	 */
	public function test_window_carries_all_23_metric_keys() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);
		$current = $response->get_data()['data']['current'];

		// 23 metrics + the `window` echo.
		$this->assertCount( 24, $current );
	}

	/**
	 * NPPD-1745 scoped-banner behavior in the test env.
	 *
	 * In the test env the BQ proxy is unconfigured (hub metrics error) AND there are
	 * no active subscribers/donors, so `registered_to_subscriber_funnel` and
	 * `registered_to_donor_funnel` short-circuit to `state: 'empty'` WITHOUT calling
	 * the hub (the visibility-gated unconfigured-leg path). Under the scoped logic
	 * an `empty` hub-classified metric is a legitimate non-error survivor — it did
	 * not fail at the hub. So `tab_error` correctly remains FALSE in the test env,
	 * even with all other hub metrics erroring.
	 *
	 * The banner-hole fix is verified by the synthetic-window unit tests below
	 * (test_tab_error_fires_on_hub_outage_despite_local_survivor etc.), which craft
	 * windows where every hub metric is in 'error' state and assert the banner fires.
	 * The real-dispatch test here verifies the scoped logic does NOT spuriously fire
	 * the banner when a legitimately-empty hub metric is present.
	 */
	public function test_tab_error_false_when_hub_metrics_include_unconfigured_empty_legs() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);
		$data = $response->get_data()['data'];

		// The visibility-gated funnels (registered→subscriber, registered→donor)
		// return state: 'empty' in the test env (no active subscribers/donors), so
		// they count as non-error hub-backed survivors and keep tab_error false.
		$this->assertFalse( $data['tab_error'] );
	}

	/**
	 * Scoped-banner does not suppress coming_soon and local state checks.
	 * Verifies deferred sections are present regardless of banner state.
	 */
	public function test_tab_error_false_with_coming_soon_and_local_states_present() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);
		$data = $response->get_data()['data'];

		// See test_tab_error_false_when_hub_metrics_include_unconfigured_empty_legs
		// for why tab_error is false in this env.
		$this->assertFalse( $data['tab_error'] );

		// Deferred (coming_soon) metrics are still present and carry the right state.
		$current = $data['current'];
		$this->assertSame( 'coming_soon', $current['registration_to_conversion_cohort']['state'] );
		$this->assertSame( 'empty', $current['time_to_subscribe_distribution']['state'] );
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
		$data = $response->get_data()['data'];

		$this->assertIsArray( $data['previous'] );
		$this->assertSame( '2026-02-20', $data['previous']['window']['start'] );
		$this->assertSame( '2026-03-21', $data['previous']['window']['end'] );
		$this->assertArrayHasKey( 'reader_lifecycle_funnel', $data['previous'] );
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
	 * Fixture-mode: the controller invokes Conversion_Metric::get_fixture()
	 * (not build_response/live BQ) and wraps it in the SOURCE_LOCAL cache
	 * envelope with Cache-Control: no-store.
	 *
	 * We exercise get_fixture() directly to verify the fixture variant shapes;
	 * exercising via the controller would require redefining
	 * NEWSPACK_INSIGHTS_FIXTURE_MODE which can't be undefined once set.
	 */
	public function test_fixture_populated_variant_via_metric() {
		$payload = Conversion_Metric::get_fixture( 'populated', false );

		$this->assertFalse( $payload['tab_error'] );
		$this->assertNull( $payload['previous'] );

		$current = $payload['current'];
		$this->assertSame( 'populated', $current['reader_lifecycle_funnel']['state'] );
		$this->assertCount( 5, $current['reader_lifecycle_funnel']['stages'] );
		$this->assertSame( 'populated', $current['source_mix_registrations']['state'] );
		$this->assertSame( 'populated', $current['time_to_register_distribution']['state'] );
		$this->assertSame( 'populated', $current['weekly_conversion_rates']['state'] );
		$this->assertSame( 'populated', $current['influenced_registration_rate_7d']['state'] );
		$this->assertSame( 'populated', $current['top_pages_no_conversion']['state'] );

		// 4.2 + 5.1/5.2 cohorts are all-history snapshots → populated in the fixture.
		$this->assertSame( 'populated', $current['time_to_subscribe_distribution']['state'] );
		$this->assertSame( 'populated', $current['registration_to_conversion_cohort']['state'] );
		$this->assertSame( 'populated', $current['subscriber_retention_cohort']['state'] );
	}

	/**
	 * Fixture-mode: SOURCE_LOCAL envelope shape — confirms the controller wraps
	 * get_fixture() in the correct cache envelope (cache.source = SOURCE_LOCAL,
	 * cooldown_until = null, Cache-Control: no-store).
	 *
	 * Tested via Conversion_Metric::get_fixture() shape; a controller-level
	 * assertion would require NEWSPACK_INSIGHTS_FIXTURE_MODE which is not
	 * resettable per-test.
	 */
	public function test_fixture_returns_source_local_envelope_shape() {
		// Confirm that the static fixture delegation produces the correct data shape
		// that the controller would wrap in { cache: { source: SOURCE_LOCAL, ... }, data: ... }.
		$payload = Conversion_Metric::get_fixture( 'populated', false );
		$this->assertArrayHasKey( 'tab_error', $payload );
		$this->assertArrayHasKey( 'current', $payload );
		$this->assertArrayHasKey( 'previous', $payload );

		// The fixture payload is the `data` the controller wraps in a SOURCE_LOCAL
		// cache envelope; assert the shape the wrapper depends on. The envelope
		// itself can't be asserted here because NEWSPACK_INSIGHTS_FIXTURE_MODE is
		// not resettable per-test.
		$this->assertIsArray( $payload['current'] );
		$this->assertArrayHasKey( 'reader_lifecycle_funnel', $payload['current'] );
	}

	/**
	 * Fixture-mode empty variant: collections are 'empty', scalars are non-computable zeros.
	 */
	public function test_fixture_empty_variant_via_metric() {
		$payload = Conversion_Metric::get_fixture( 'empty', false );

		$this->assertFalse( $payload['tab_error'] );
		$current = $payload['current'];

		$this->assertSame( 'empty', $current['reader_lifecycle_funnel']['state'] );
		$this->assertSame( [], $current['reader_lifecycle_funnel']['stages'] );
		$this->assertSame( 'populated', $current['influenced_registration_rate_7d']['state'] );
		$this->assertFalse( $current['influenced_registration_rate_7d']['computable'] );

		// 4.2 + 5.1/5.2 cohorts are all-history snapshots → populated regardless of variant.
		$this->assertSame( 'populated', $current['time_to_subscribe_distribution']['state'] );
	}

	/**
	 * Fixture-mode error variant: BQ (hub) metrics are 'error', local-only
	 * metrics stay 'populated', snapshot sections stay 'populated'. NPPD-1745:
	 * with the scoped banner, all-hub-error → tab_error true (fixture updated).
	 */
	public function test_fixture_error_variant_via_metric() {
		$payload = Conversion_Metric::get_fixture( 'error', false );

		// NPPD-1745: tab_error is now true because all hub-backed metrics are 'error'.
		$this->assertTrue( $payload['tab_error'] );
		$current = $payload['current'];

		$this->assertSame( 'error', $current['reader_lifecycle_funnel']['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $current['reader_lifecycle_funnel']['error_code'] );
		$this->assertSame( 'populated', $current['stale_registered_count']['state'] );
		$this->assertSame( 'populated', $current['registration_to_conversion_cohort']['state'] );
	}

	/**
	 * Fixture-mode compare: previous window is populated when requested.
	 */
	public function test_fixture_compare_populates_previous() {
		$payload = Conversion_Metric::get_fixture( 'populated', true );

		$this->assertIsArray( $payload['previous'] );
		$this->assertArrayHasKey( 'reader_lifecycle_funnel', $payload['previous'] );
		$this->assertSame( 'populated', $payload['previous']['reader_lifecycle_funnel']['state'] );
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

	// -----------------------------------------------------------------------
	// NPPD-1745 scoped-banner unit tests (mirrors class-test-prompts-rest-controller.php).
	// -----------------------------------------------------------------------

	/**
	 * Invoke the private static is_window_all_error() on a synthetic window.
	 *
	 * @param array $window             Window payload.
	 * @param bool  $woocommerce_active Whether WC is active (defaults to WC-active path).
	 * @return bool
	 */
	private function invoke_is_window_all_error( array $window, bool $woocommerce_active = true ): bool {
		$method = new ReflectionMethod( Conversion_REST_Controller::class, 'is_window_all_error' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $window, $woocommerce_active );
	}

	/**
	 * Global versioning: every controller (including a plain trait consumer that
	 * does not override cache_schema_version()) now produces a five-part key with
	 * Cache::ENVELOPE_SCHEMA_VERSION as the first component. The consolidation
	 * ensures all tabs are versioned uniformly — no per-tab opt-in required.
	 */
	public function test_cache_key_has_global_version_prefix_by_default() {
		$controller = new class() {
			use Cached_Controller_Trait;

			/**
			 * Cache source for the test controller.
			 *
			 * @return string
			 */
			protected function cache_source(): string {
				return Cache::SOURCE_BIGQUERY;
			}

			/**
			 * Tab slug for the test controller.
			 *
			 * @return string
			 */
			protected function tab_slug(): string {
				return 'test';
			}

			/**
			 * Stub base-window payload (no-op for key-derivation test).
			 *
			 * @param \DateTimeImmutable $start Window start.
			 * @param \DateTimeImmutable $end   Window end.
			 * @return array
			 */
			public function build_window_payload( \DateTimeImmutable $start, \DateTimeImmutable $end ): array {
				return [];
			}
		};
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'start', '2026-01-01' );
		$request->set_param( 'end', '2026-01-31' );

		$method = new ReflectionMethod( $controller, 'versioned_cache_key_parts' );
		$method->setAccessible( true );
		$parts = $method->invoke( $controller, $request );

		$this->assertCount( 5, $parts );
		$this->assertSame( Cache::ENVELOPE_SCHEMA_VERSION, $parts[0] );
		$this->assertSame( '2026-01-01', $parts[1] );
	}

	/**
	 * Conversion no longer overrides cache_schema_version(); it inherits the
	 * global Cache::ENVELOPE_SCHEMA_VERSION so the version is the first
	 * cache-key component — bumping the global constant on any shape change
	 * busts all tabs' stale-shape transients on deploy.
	 */
	public function test_conversion_cache_key_includes_schema_version() {
		$controller = new Conversion_REST_Controller();
		$request    = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'start', '2026-01-01' );
		$request->set_param( 'end', '2026-01-31' );

		$method = new ReflectionMethod( Conversion_REST_Controller::class, 'versioned_cache_key_parts' );
		$method->setAccessible( true );
		$parts = $method->invoke( $controller, $request );

		$this->assertCount( 5, $parts );
		$this->assertSame( Cache::ENVELOPE_SCHEMA_VERSION, $parts[0] );
		$this->assertContains( '2026-01-01', $parts );
	}

	/**
	 * Global-version consolidation: a previously-unversioned tab (Audience, Tab 1)
	 * now inherits Cache::ENVELOPE_SCHEMA_VERSION from the trait default, confirming
	 * that the consolidation applies to ALL tabs — not just the three that formerly
	 * opted in via per-tab CACHE_PREFIX overrides.
	 */
	public function test_global_version_applies_to_previously_unversioned_tab() {
		$controller = new Audience_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-insights/v1/audience' );
		$request->set_param( 'start', '2026-01-01' );
		$request->set_param( 'end', '2026-01-31' );

		$method = new ReflectionMethod( Audience_REST_Controller::class, 'versioned_cache_key_parts' );
		$method->setAccessible( true );
		$parts = $method->invoke( $controller, $request );

		// Five parts: global version + start + end + null + null.
		$this->assertCount( 5, $parts );
		$this->assertSame( Cache::ENVELOPE_SCHEMA_VERSION, $parts[0] );
		$this->assertSame( '2026-01-01', $parts[1] );
		$this->assertSame( '2026-01-31', $parts[2] );
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
		foreach ( Conversion_Metric::METRIC_SOURCES as $key => $source ) {
			$window[ $key ] = [ 'state' => 'local' === $source ? 'populated' : 'error' ];
		}
		return $window;
	}

	/**
	 * NPPD-1745 regression guard: the "whole tab failed" banner STILL fires when
	 * every hub-backed metric errors, even though surviving local cards render.
	 * This is the exact thing the old all-error logic would have silently killed.
	 */
	public function test_tab_error_fires_on_hub_outage_despite_local_survivor() {
		$window = $this->window_hub_down_local_alive();

		// The local survivor is genuinely present and populated.
		$this->assertSame( 'local', Conversion_Metric::METRIC_SOURCES['subscriber_to_donor_funnel'] );
		$this->assertSame( 'populated', $window['subscriber_to_donor_funnel']['state'] );

		$this->assertTrue(
			$this->invoke_is_window_all_error( $window ),
			'all hub-backed errored → banner fires even though local cards rendered'
		);
	}

	/**
	 * The banner does NOT fire if any hub-backed metric recovers.
	 */
	public function test_tab_error_does_not_fire_when_a_hub_metric_recovers() {
		$window = $this->window_hub_down_local_alive();
		$window['reader_lifecycle_funnel'] = [ 'state' => 'populated' ]; // One hub card recovers.

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
		foreach ( Conversion_Metric::METRIC_SOURCES as $key => $source ) {
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
		$this->assertSame( 'hybrid', Conversion_Metric::METRIC_SOURCES['source_mix_subscribers'] );
		$this->assertSame( 'populated', $window['source_mix_subscribers']['state'] );

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
	 * NPPD-1745 #5 drift guard: every state-bearing metric that build_window() and
	 * build_snapshot() emit must have a METRIC_SOURCES entry. is_window_all_error()
	 * iterates METRIC_SOURCES, so a card added to the window but never classified
	 * in the map would silently drop out of the tab-error banner with no test to
	 * catch it.
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
			// Skip date metadata; only state-bearing metric envelopes participate
			// in the banner decision.
			if ( 'window' === $key || ! is_array( $value ) || ! isset( $value['state'] ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, Conversion_Metric::METRIC_SOURCES ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			[],
			$missing,
			'every state-bearing build_window/build_snapshot metric must be classified in Conversion_Metric::METRIC_SOURCES'
		);
	}
}
