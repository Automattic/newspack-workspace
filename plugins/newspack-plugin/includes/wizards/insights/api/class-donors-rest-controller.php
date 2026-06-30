<?php
/**
 * Newspack Insights — Tab 7 Donors REST controller (NPPD-1617).
 *
 * Single endpoint: `GET /newspack-insights/v1/donors`. Same date-arg
 * validation, permissions, and response-shape conventions as
 * {@see Subscribers_REST_Controller}.
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
 * Donors REST controller.
 */
class Donors_REST_Controller extends WP_REST_Controller {

	use Cached_Controller_Trait;
	use Insights_REST_Trait;

	/**
	 * Dedicated namespace shared with Tab 6.
	 *
	 * @var string
	 */
	protected $namespace = 'newspack-insights/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'donors';

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
		return 'donors';
	}

	/**
	 * Register the Tab 7 route.
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
					'callback'            => [ $this, 'get_donors_data' ],
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
					'callback'            => [ $this, 'refresh_donors_data' ],
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
	public function get_donors_data( WP_REST_Request $request ) {
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		$metric = new Donors_Metric();
		return $this->cached_response(
			$request,
			function () use ( $metric, $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $metric, $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * POST /donors/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refresh_donors_data( WP_REST_Request $request ) {
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		$metric = new Donors_Metric();
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
		return $this->build_response( new Donors_Metric(), $start, $end, null, null );
	}

	/**
	 * Assemble response.
	 *
	 * @param Donors_Metric          $metric        Orchestrator.
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Prior window start.
	 * @param DateTimeImmutable|null $compare_end   Prior window end.
	 * @return array
	 */
	private function build_response(
		Donors_Metric $metric,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): array {
		$response = [
			'classification' => $metric->get_classification_metadata(),
			'snapshot'       => [
				'active_donors'                       => $metric->get_active_donors(),
				'active_recurring_donors'             => $metric->get_active_recurring_donors(),
				'donation_mrr'                        => $metric->get_donation_mrr(),
				'donation_arr'                        => $metric->get_donation_arr(),
				'upcoming_donation_renewals_30d'      => $metric->get_upcoming_donation_renewals_30d(),
				'upcoming_donation_cancellations_30d' => $metric->get_upcoming_donation_cancellations_30d(),
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
	 * Window-bound payload.
	 *
	 * @param Donors_Metric     $metric Orchestrator.
	 * @param DateTimeImmutable $start  Start.
	 * @param DateTimeImmutable $end    End.
	 * @return array
	 */
	private function build_window( Donors_Metric $metric, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$new_donors    = $metric->get_new_donors_in_window( $start, $end );
		$lapsed_donors = $metric->get_lapsed_donors_in_window( $start, $end );
		$total_revenue = $metric->get_total_donation_revenue( $start, $end );

		return [
			'window'                     => [
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			],
			'new_donors'                 => $new_donors,
			'lapsed_donors'              => $lapsed_donors,
			'one_time_revenue'           => $metric->get_one_time_donation_revenue( $start, $end ),
			'recurring_revenue'          => $metric->get_recurring_donation_revenue( $start, $end ),
			'total_revenue'              => $total_revenue,
			'average_gift'               => $metric->get_average_donation_gift( $start, $end ),
			'lapsed_donor_recovery_rate' => $metric->get_lapsed_donor_recovery_rate( $start, $end ),
			'recurring_donor_retention'  => $metric->get_recurring_donor_retention( $start, $end ),
			'donations_by_tier'          => $metric->get_donations_by_tier( $start, $end ),
			// Derived empty-state signal (NPPD-1696): true when the window saw any
			// donation activity at all. Pure derivation from values already fetched
			// above — no extra query — kept in the metric class alongside the other
			// derived signals, mirroring Gates_Metric::paywall_section_totals().
			'has_window_activity'        => Donors_Metric::window_activity_signal( $new_donors, $lapsed_donors, $total_revenue ),
		];
	}
}
