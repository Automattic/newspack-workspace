<?php
/**
 * Newspack Insights — Tab 4 Gates REST controller (NPPD-1604).
 *
 * Single endpoint: `GET /newspack-insights/v1/gates`. Same date-arg
 * validation, permission check, and date parsing conventions as
 * {@see Subscribers_REST_Controller} and {@see Donors_REST_Controller}.
 *
 * Response shape:
 *   tab_error: bool         — true only when every section in the current
 *                              window failed to load; React renders a
 *                              tab-level error banner.
 *   current:     GatesWindow — scorecards + funnel + distribution + table
 *   previous:    GatesWindow | null — only populated when the request
 *                              passes `compare_start` + `compare_end`.
 *
 * Each metric from {@see Gates_Metric} carries its own `state`
 * ('error' | 'empty' | 'populated'); sections render their own treatments,
 * so the tab banner is reserved for the all-failed case.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Gates REST controller.
 */
class Gates_REST_Controller extends WP_REST_Controller {

	use Cached_Controller_Trait;
	use Insights_REST_Trait;

	/**
	 * Shared Tab 4–7 namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'newspack-insights/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'gates';

	/**
	 * Cache source classification for this controller.
	 *
	 * @return string
	 */
	protected function cache_source(): string {
		return Cache::SOURCE_BIGQUERY;
	}

	/**
	 * Tab slug used as the cache namespace.
	 *
	 * @return string
	 */
	protected function tab_slug(): string {
		return 'gates';
	}

	/**
	 * Register the Tab 4 route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_gates_data' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/refresh',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'refresh_gates_data' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
	}

	/**
	 * GET handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_gates_data( WP_REST_Request $request ) {
		// Dev smoke-test path: serve canned fixture data so the UI renders without
		// a BigQuery proxy connection. The optional _fixture_state param selects a
		// render path ('populated' | 'empty' | 'error'). Never enable in production.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			$parsed = $this->parse_window_args( $request );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			[ , , $compare_start, $compare_end ] = $parsed;
			$variant  = (string) ( $request->get_param( '_fixture_state' ) ?? 'populated' );
			$compare  = null !== $compare_start && null !== $compare_end;
			$response = rest_ensure_response(
				[
					'cache' => [
						'source'         => Cache::SOURCE_LOCAL,
						'computed_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'cooldown_until' => null,
					],
					'data'  => Gates_Metric::get_fixture( $variant, $compare ),
				]
			);
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		}

		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		return $this->cached_response( $request, $start, $end, $compare_start, $compare_end );
	}

	/**
	 * POST /gates/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refresh_gates_data( WP_REST_Request $request ) {
		// Fixture mode: delegate to GET so refresh is a no-op cache bypass.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return $this->get_gates_data( $request );
		}
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;
		return $this->refresh_response( $request, $start, $end, $compare_start, $compare_end );
	}

	/**
	 * Base-window payload (no comparison) for the pre-warm path.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	public function build_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return $this->build_response( new Gates_Metric(), $start, $end, null, null );
	}

	/**
	 * Assemble the top-level response.
	 *
	 * `tab_error` is true only when every metric in the current window reports
	 * `state: 'error'` — i.e. the whole tab failed to load (e.g. the BigQuery
	 * proxy is down/misconfigured). React renders a tab-level error banner in
	 * that case; otherwise each section renders its own error/empty/populated
	 * treatment.
	 *
	 * @param Gates_Metric           $metric        Orchestrator.
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Prior window start.
	 * @param DateTimeImmutable|null $compare_end   Prior window end.
	 * @return array
	 */
	private function build_response(
		Gates_Metric $metric,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): array {
		$current  = $this->build_window( $metric, $start, $end );
		$response = [
			'tab_error' => self::is_window_all_error( $current, $metric->woocommerce_active() ),
			'current'   => $current,
			'previous'  => null,
		];
		if ( $compare_start && $compare_end ) {
			$response['previous'] = $this->build_window( $metric, $compare_start, $compare_end );
		}
		return $response;
	}

	/**
	 * Whether every HUB-BACKED metric in a window payload reports `state: 'error'`
	 * — i.e. the whole tab failed to load (e.g. the BigQuery proxy is down).
	 *
	 * Scoped to hub-backed metrics via {@see Gates_Metric::METRIC_SOURCES} (NPPD-1746):
	 * `local` cards (paywall revenue-direct, sourced from Woo order meta) keep
	 * rendering during a hub outage, so including them would silently suppress the
	 * banner even when every hub query failed. `hybrid` cards count as hub-backed (a
	 * hub denominator failure makes them error). Returns `false` as soon as any
	 * hub-backed metric is not in the error state, and `false` for a window that
	 * surfaced no hub-backed metric at all (nothing to declare "all failed").
	 *
	 * NPPD-1745 #1 (banner hole on non-WC, mirrored from the Prompts tab): a `hybrid`
	 * card short-circuits to a not-applicable empty state on a non-WooCommerce
	 * publisher BEFORE it ever calls the hub — so a hub outage can't make it error,
	 * and treating its surviving empty state as a healthy hub-backed card would mask
	 * the outage. On a non-WC publisher, hybrid cards are therefore skipped (treated
	 * like `local`); the genuinely hub-backed `hub` cards still drive the decision.
	 *
	 * @param array $window             The shape returned by `build_window()`.
	 * @param bool  $woocommerce_active Whether WooCommerce is active for this publisher.
	 * @return bool
	 */
	private static function is_window_all_error( array $window, bool $woocommerce_active ): bool {
		$saw_hub_backed = false;
		foreach ( Gates_Metric::METRIC_SOURCES as $key => $source ) {
			if ( 'local' === $source ) {
				continue;
			}
			// On a non-WC publisher a hybrid card never reaches the hub (it empties out
			// first), so it can't error on a hub outage — don't let its surviving empty
			// state mask the outage.
			if ( ! $woocommerce_active && 'hybrid' === $source ) {
				continue;
			}
			if ( ! isset( $window[ $key ] ) || ! is_array( $window[ $key ] ) || ! isset( $window[ $key ]['state'] ) ) {
				continue;
			}
			$saw_hub_backed = true;
			if ( 'error' !== $window[ $key ]['state'] ) {
				return false;
			}
		}
		return $saw_hub_backed;
	}

	/**
	 * Window-bound payload covering all five sections.
	 *
	 * @param Gates_Metric      $metric Orchestrator.
	 * @param DateTimeImmutable $start  Start.
	 * @param DateTimeImmutable $end    End.
	 * @return array
	 */
	private function build_window( Gates_Metric $metric, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		// Captured for the section totals below — derived from these scalars, not
		// re-queried. Paid (NPPD-1694) and Free (NPPD-1702) follow the same shape.
		$regwall_direct     = $metric->get_regwall_conversion_direct( $start, $end );
		$regwall_influenced = $metric->get_regwall_conversion_influenced_7d( $start, $end );
		$paywall_direct     = $metric->get_paywall_conversion_direct( $start, $end );
		$paywall_influenced = $metric->get_paywall_conversion_influenced_14d( $start, $end );

		return array_merge(
			[
				'window'                             => [
					'start' => $start->format( 'Y-m-d' ),
					'end'   => $end->format( 'Y-m-d' ),
				],
				// Section 1.
				'total_gate_impressions'             => $metric->get_total_gate_impressions( $start, $end ),
				'unique_readers_reached'             => $metric->get_unique_readers_reached( $start, $end ),
				'avg_exposures_per_reader'           => $metric->get_avg_exposures_per_reader( $start, $end ),
				'sessions_with_gate'                 => $metric->get_sessions_with_gate( $start, $end ),
				// Section 2.
				'regwall_conversion_direct'          => $regwall_direct,
				'regwall_conversion_influenced_7d'   => $regwall_influenced,
				// Section 3.
				'paywall_conversion_direct'          => $paywall_direct,
				'paywall_conversion_influenced_14d'  => $paywall_influenced,
				'total_paywall_revenue_direct'       => $metric->get_total_paywall_revenue_direct( $start, $end ),
				'avg_revenue_per_paywall_conversion' => $metric->get_avg_revenue_per_paywall_conversion( $start, $end ),
				// Section 4.
				'conversion_funnel'                  => $metric->get_conversion_funnel( $start, $end ),
				'exposures_distribution'             => $metric->get_exposures_distribution( $start, $end ),
				// Section 5.
				'performance_by_gate'                => $metric->get_performance_by_gate( $start, $end ),
			],
			// Section 2 empty-state totals (NPPD-1702) — int|null; null = hub count
			// fields not yet deployed, so the Free section degrades to percentages.
			Gates_Metric::regwall_section_totals( $regwall_direct, $regwall_influenced ),
			// Section 3 empty-state totals (NPPD-1694).
			Gates_Metric::paywall_section_totals( $paywall_direct, $paywall_influenced )
		);
	}
}
