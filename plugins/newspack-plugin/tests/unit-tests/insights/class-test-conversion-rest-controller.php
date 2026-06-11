<?php
/**
 * Test Conversion_REST_Controller (NPPD-1609, Phase 1).
 *
 * Exercises the Tab 3 endpoint's request lifecycle: a valid window returns
 * 200 with the placeholder envelope (all eight sections present); comparison
 * mode adds a `previous` window; invalid / mismatched date params return 400.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Conversion_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

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
	 * A valid window returns 200 with the full placeholder envelope and a
	 * null `previous` (no comparison requested). One representative metric
	 * key per section is asserted present.
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

		$current = $data['current'];
		$this->assertSame( '2026-03-22', $current['window']['start'] );
		$this->assertSame( '2026-04-21', $current['window']['end'] );
		foreach (
			[
				'reader_lifecycle_funnel',                // Section 1.
				'subscriber_to_donor_funnel',             // Section 2.
				'source_mix_registrations',               // Section 3.
				'time_to_register_distribution',          // Section 4.
				'subscriber_retention_cohort',            // Section 5.
				'weekly_conversion_rates',                // Section 6.
				'influenced_registration_rate_7d',        // Section 7.
				'top_pages_no_conversion',                // Section 8.
			] as $key
		) {
			$this->assertArrayHasKey( $key, $current, "Missing window key: $key" );
		}

		// A scalar carries the placeholder envelope.
		$this->assertTrue( $current['influenced_registration_rate_7d']['pending'] );
		$this->assertSame( 'rate', $current['influenced_registration_rate_7d']['placeholder_type'] );
		// The opportunity table ships empty rows for the empty-state UI.
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
		$current = $response->get_data()['current'];

		// 23 metrics + the `window` echo.
		$this->assertCount( 24, $current );
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
}
