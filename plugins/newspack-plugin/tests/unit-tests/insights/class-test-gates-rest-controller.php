<?php
/**
 * Test Gates_REST_Controller — tab-error banner scoping (NPPD-1746).
 *
 * Mirrors the Prompts controller's banner coverage for the Gates tab: the
 * "whole tab failed" banner is scoped to hub-backed metrics via
 * {@see Gates_Metric::METRIC_SOURCES} so a surviving `local` card (paywall
 * revenue from order meta) can't suppress it, and — the NPPD-1745 #1 banner-hole
 * fix, mirrored here — a non-WC `hybrid` card that short-circuits before reaching
 * the hub doesn't count as a hub-backed survivor either. A drift guard pins that
 * every state-bearing build_window metric is classified in METRIC_SOURCES.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Cache;
use Newspack\Insights\Gates_Metric;
use Newspack\Insights\Gates_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Gates_REST_Controller test class.
 *
 * @group insights
 */
class Test_Gates_REST_Controller extends WP_UnitTestCase {

	const ROUTE = '/newspack-insights/v1/gates';

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up: an admin user + a registered Gates route on a fresh server.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_action( 'rest_api_init', [ $this, 'register_gates_route' ] );

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		Cache::purge( 'gates' );
	}

	/**
	 * Register the Gates route. Hooked to rest_api_init in set_up().
	 *
	 * @return void
	 */
	public function register_gates_route() {
		( new Gates_REST_Controller() )->register_routes();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_action( 'rest_api_init', [ $this, 'register_gates_route' ] );
		global $wp_rest_server;
		$wp_rest_server = null;
		Cache::purge( 'gates' );
		parent::tear_down();
	}

	/**
	 * Build + dispatch a GET request to the Gates route.
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
	 * Invoke the private static is_window_all_error() on a synthetic window.
	 *
	 * @param array $window             Window payload.
	 * @param bool  $woocommerce_active Whether WC is active (defaults to the WC-active path).
	 * @return bool
	 */
	private function invoke_is_window_all_error( array $window, bool $woocommerce_active = true ): bool {
		$method = new \ReflectionMethod( Gates_REST_Controller::class, 'is_window_all_error' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $window, $woocommerce_active );
	}

	/**
	 * Build a window where every hub-backed metric errors and every local card is
	 * populated (the WC-active hub-outage-with-local-survivor scenario).
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
		foreach ( Gates_Metric::METRIC_SOURCES as $key => $source ) {
			$window[ $key ] = [ 'state' => 'local' === $source ? 'populated' : 'error' ];
		}
		return $window;
	}

	/**
	 * Build the non-WC hub-outage window: every pure-hub card errors, while the
	 * hybrid cards short-circuit to a populated empty state (they never reach the
	 * hub on a non-WC publisher) and the local cards render.
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
		foreach ( Gates_Metric::METRIC_SOURCES as $key => $source ) {
			$window[ $key ] = [ 'state' => 'hub' === $source ? 'error' : 'populated' ];
		}
		return $window;
	}

	/**
	 * The banner STILL fires when every hub-backed metric errors, even though a
	 * surviving local card (paywall revenue) renders.
	 */
	public function test_tab_error_fires_on_hub_outage_despite_local_survivor() {
		$window = $this->window_hub_down_local_alive();

		$this->assertSame( 'local', Gates_Metric::METRIC_SOURCES['total_paywall_revenue_direct'] );
		$this->assertSame( 'populated', $window['total_paywall_revenue_direct']['state'] );

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
		$window['total_gate_impressions'] = [ 'state' => 'populated' ]; // one hub card recovers.

		$this->assertFalse( $this->invoke_is_window_all_error( $window ) );
	}

	/**
	 * NPPD-1745 #1 banner-hole fix, mirrored to Gates: on a non-WC publisher the
	 * paywall rate (hybrid) short-circuits to a populated empty state without ever
	 * calling the hub, so it must NOT count as a hub-backed survivor. With every
	 * pure-hub card erroring, the banner fires even though the hybrid + local cards
	 * render. (Pre-fix, the populated hybrid card returned false here, silently
	 * suppressing the banner.)
	 */
	public function test_tab_error_fires_on_non_wc_hub_outage() {
		$window = $this->window_non_wc_hub_down();

		// Sanity: the paywall rate is a hybrid card, genuinely populated here.
		$this->assertSame( 'hybrid', Gates_Metric::METRIC_SOURCES['paywall_conversion_direct'] );
		$this->assertSame( 'populated', $window['paywall_conversion_direct']['state'] );

		// Paywall influenced is fully hub-computed (BQ-internal rate + denominator, no
		// local Woo component) — it must error on hub outage, not short-circuit to
		// populated like a hybrid card.
		$this->assertSame( 'hub', Gates_Metric::METRIC_SOURCES['paywall_conversion_influenced_14d'] );
		$this->assertSame( 'error', $window['paywall_conversion_influenced_14d']['state'] );

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
	 * Gates_REST_Controller no longer overrides cache_schema_version(); it inherits
	 * the global Cache::ENVELOPE_SCHEMA_VERSION from Cached_Controller_Trait, so
	 * bumping that constant busts all tabs at once on a shape-changing deploy.
	 */
	public function test_gates_controller_cache_version_is_global_envelope_version() {
		$controller = new Gates_REST_Controller();
		$ref        = new \ReflectionMethod( $controller, 'cache_schema_version' );
		$ref->setAccessible( true );
		$this->assertSame( Cache::ENVELOPE_SCHEMA_VERSION, $ref->invoke( $controller ) );
		$this->assertNotEmpty( Cache::ENVELOPE_SCHEMA_VERSION );
	}

	/**
	 * NPPD-1745 #5 drift guard, mirrored to Gates: every state-bearing metric that
	 * build_window() emits must have a METRIC_SOURCES entry, or it would silently
	 * drop out of the banner decision with no test to catch it.
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
			if ( ! array_key_exists( $key, Gates_Metric::METRIC_SOURCES ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			[],
			$missing,
			'every state-bearing build_window metric must be classified in Gates_Metric::METRIC_SOURCES'
		);
	}
}
