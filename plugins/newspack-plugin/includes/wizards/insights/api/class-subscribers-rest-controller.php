<?php
/**
 * Newspack Insights — Tab 6 Subscribers REST controller (NPPD-1616).
 *
 * Exposes the single endpoint that powers the Subscribers tab.
 * Namespace: `newspack-insights/v1`. Route: `/subscribers`.
 *
 * Response shape — see {@see self::build_response()}. Split into:
 *
 *   - `classification` — banner metadata (backend, donation product count).
 *   - `snapshot`       — "right now" metrics that do not depend on the
 *                        date window (active subs, MRR, ARR, tenure
 *                        distribution, upcoming renewals).
 *   - `current`        — windowed metrics for the requested window.
 *   - `previous`       — windowed metrics for the optional comparison
 *                        window (`null` if compare params omitted).
 *
 * Date inputs are `Y-m-d` strings in the site's timezone. Start dates
 * resolve to 00:00:00; end dates resolve to 23:59:59 inclusive. The
 * controller delegates caching to {@see Subscribers_Metric}, so the
 * comparison-mode second call is free on cache hit.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Subscribers REST controller.
 */
class Subscribers_REST_Controller extends WP_REST_Controller {

	use Cached_Controller_Trait;
	use Insights_REST_Trait;

	/**
	 * Dedicated namespace for Insights endpoints, separate from
	 * `newspack/v1` (which is reserved for wizard infrastructure).
	 *
	 * @var string
	 */
	protected $namespace = 'newspack-insights/v1';

	/**
	 * Route base under the namespace.
	 *
	 * @var string
	 */
	protected $rest_base = 'subscribers';

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
		return 'subscribers';
	}

	/**
	 * Register the single Tab 6 route.
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
					'callback'            => [ $this, 'get_subscribers_data' ],
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
					'callback'            => [ $this, 'refresh_subscribers_data' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
	}

	/**
	 * GET /newspack-insights/v1/subscribers handler.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscribers_data( WP_REST_Request $request ) {
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		$metric = new Subscribers_Metric();

		return $this->cached_response(
			$request,
			function () use ( $metric, $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $metric, $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * POST /newspack-insights/v1/subscribers/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh_subscribers_data( WP_REST_Request $request ) {
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		$metric = new Subscribers_Metric();

		return $this->refresh_response(
			$request,
			function () use ( $metric, $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $metric, $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * Base-window payload (no comparison) for the pre-warm path.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	public function build_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return $this->build_response( new Subscribers_Metric(), $start, $end, null, null );
	}

	/**
	 * Assemble the response payload.
	 *
	 * @param Subscribers_Metric     $metric        Metric orchestrator.
	 * @param DateTimeImmutable      $start         Current window start (00:00:00).
	 * @param DateTimeImmutable      $end           Current window end (23:59:59).
	 * @param DateTimeImmutable|null $compare_start Prior window start (or null).
	 * @param DateTimeImmutable|null $compare_end   Prior window end (or null).
	 * @return array
	 */
	private function build_response(
		Subscribers_Metric $metric,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): array {
		$response = [
			'classification' => $metric->get_classification_metadata(),
			'snapshot'       => [
				'active_subscribers'         => $metric->get_active_non_donation_subscribers(),
				'mrr'                        => $metric->get_mrr(),
				'arr'                        => $metric->get_arr(),
				'tenure_distribution'        => $metric->get_subscription_tenure_distribution(),
				'upcoming_renewals_30d'      => $metric->get_upcoming_renewals_30d(),
				'upcoming_cancellations_30d' => $metric->get_upcoming_cancellations_30d(),
			],
			'current'        => $this->build_window( $metric, $start, $end ),
			'previous'       => null,
		];

		if ( $compare_start && $compare_end ) {
			$response['previous'] = $this->build_window( $metric, $compare_start, $compare_end );
		}

		return $response;
	}

	/**
	 * Window-bound metric payload.
	 *
	 * @param Subscribers_Metric $metric Metric orchestrator.
	 * @param DateTimeImmutable  $start  Window start.
	 * @param DateTimeImmutable  $end    Window end.
	 * @return array
	 */
	private function build_window( Subscribers_Metric $metric, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$new_subscribers     = $metric->get_new_subscribers_in_window( $start, $end );
		$churned_subscribers = $metric->get_churned_subscribers_in_window( $start, $end );
		$revenue_gross       = $metric->get_subscription_revenue_gross( $start, $end );
		$revenue_net         = $metric->get_subscription_revenue_net( $start, $end );

		return [
			'window'                    => [
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			],
			'new_subscribers'           => $new_subscribers,
			'churned_subscribers'       => $churned_subscribers,
			'revenue_gross'             => $revenue_gross,
			'revenue_net'               => $revenue_net,
			'refund_rate'               => $metric->get_subscription_refund_rate( $start, $end ),
			'failed_payment_retry_rate' => $metric->get_failed_payment_retry_rate( $start, $end ),
			'subscriptions_by_product'  => $metric->get_subscriptions_by_product( $start, $end ),
			'cancellation_reasons'      => $metric->get_cancellation_reasons( $start, $end ),
			// Derived empty-state signal (NPPD-1695): true when the window saw any
			// subscription activity. Pure derivation from values already fetched
			// above — no extra query — kept in the metric class alongside the other
			// derived signals, mirroring Donors_Metric::window_activity_signal().
			'has_window_activity'       => Subscribers_Metric::window_activity_signal( $new_subscribers, $churned_subscribers, $revenue_gross, $revenue_net ),
		];
	}
}
