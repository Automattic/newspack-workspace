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
	 * A valid window returns 200 with the state envelope. In the test env the
	 * proxy is unconfigured, so every metric surfaces `state: 'error'` and
	 * the controller's `is_window_all_error` derives `tab_error: true`.
	 */
	public function test_valid_window_returns_200_envelope() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		// Phase 2 replaces `tab_pending` with `tab_error`; the Phase 1 key must
		// not leak through to the wire format.
		$this->assertArrayNotHasKey( 'tab_pending', $data );
		$this->assertArrayHasKey( 'tab_error', $data );
		$this->assertTrue( $data['tab_error'], 'Test env has an unconfigured proxy → every metric errors → tab_error: true.' );
		$this->assertArrayHasKey( 'current', $data );
		$this->assertNull( $data['previous'] );

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
		$data = $response->get_data();

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

		// Per-prompt rows honor the locked 15-key schema from Task 3.3.
		$row = $current['performance_by_prompt']['rows'][0];
		$this->assertSame(
			[
				'popup_id',
				'prompt_title',
				'intent',
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
}
