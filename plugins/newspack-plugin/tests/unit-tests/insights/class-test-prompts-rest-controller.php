<?php
/**
 * Test Prompts_REST_Controller (NPPD-1607, Phase 1).
 *
 * Exercises the Tab 5 endpoint's request lifecycle: a valid window
 * returns 200 with the placeholder envelope; comparison mode adds a
 * `previous` window; invalid / mismatched date params return 400.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

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
	 * A valid window returns 200 with the full placeholder envelope and a
	 * null `previous` (no comparison requested).
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

		$this->assertArrayHasKey( 'tab_pending', $data );
		$this->assertTrue( $data['tab_pending'] );
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

		// A scalar carries the placeholder envelope.
		$this->assertTrue( $current['total_prompt_impressions']['pending'] );
		$this->assertSame( 'count', $current['total_prompt_impressions']['placeholder_type'] );
		// Tables ship empty rows for the empty-state UI.
		$this->assertSame( [], $current['performance_by_prompt']['rows'] );
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
}
