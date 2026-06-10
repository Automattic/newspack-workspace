<?php
/**
 * Newspack Insights — Tab 3 Conversion Journey REST controller (NPPD-1609, Phase 1).
 *
 * Single endpoint: `GET /newspack-insights/v1/conversion`. Same date-arg
 * validation, permission check, and date-parsing conventions as
 * {@see Prompts_REST_Controller} — Tab 3 mirrors the per-surface tabs'
 * request/response lifecycle exactly.
 *
 * Response shape:
 *   tab_pending: bool             — true in Phase 1 (placeholder phase);
 *                                   React reads it for the top-of-tab banner.
 *   current:     ConversionWindow — the eight sections' 23 metric payloads.
 *   previous:    ConversionWindow | null — only populated when the request
 *                                   passes `compare_start` + `compare_end`.
 *                                   Only Section 7 renders the deltas.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Conversion Journey REST controller.
 */
class Conversion_REST_Controller extends WP_REST_Controller {

	/**
	 * Shared Insights namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'newspack-insights/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'conversion';

	/**
	 * Register the Tab 3 route.
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
					'callback'            => [ $this, 'get_conversion_data' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'newspack_insights_rest_forbidden',
				__( 'You do not have permission to view Insights data.', 'newspack-plugin' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * GET handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_conversion_data( WP_REST_Request $request ) {
		$tz = $this->site_timezone();

		try {
			$start = $this->parse_date( $request->get_param( 'start' ), $tz, false );
			$end   = $this->parse_date( $request->get_param( 'end' ), $tz, true );
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_insights_invalid_date', $e->getMessage(), [ 'status' => 400 ] );
		}
		if ( $start > $end ) {
			return new WP_Error(
				'newspack_insights_invalid_window',
				__( 'Start date must be on or before end date.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$compare_start_param = $request->get_param( 'compare_start' );
		$compare_end_param   = $request->get_param( 'compare_end' );
		$compare_start       = null;
		$compare_end         = null;
		if ( $compare_start_param || $compare_end_param ) {
			if ( ! $compare_start_param || ! $compare_end_param ) {
				return new WP_Error(
					'newspack_insights_invalid_comparison',
					__( 'Both compare_start and compare_end must be provided to enable comparison mode.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
			try {
				$compare_start = $this->parse_date( $compare_start_param, $tz, false );
				$compare_end   = $this->parse_date( $compare_end_param, $tz, true );
			} catch ( Exception $e ) {
				return new WP_Error( 'newspack_insights_invalid_date', $e->getMessage(), [ 'status' => 400 ] );
			}
			if ( $compare_start > $compare_end ) {
				return new WP_Error(
					'newspack_insights_invalid_comparison_window',
					__( 'compare_start must be on or before compare_end.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
		}

		$metric = new Conversion_Metric();
		return rest_ensure_response( $this->build_response( $metric, $start, $end, $compare_start, $compare_end ) );
	}

	/**
	 * Assemble the top-level response.
	 *
	 * `tab_pending` is true in Phase 1 (placeholder phase). React uses it to
	 * render the top-of-tab banner; have it return false based on real data
	 * state when Phase 2 wires up BigQuery.
	 *
	 * @param Conversion_Metric      $metric        Orchestrator.
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Prior window start.
	 * @param DateTimeImmutable|null $compare_end   Prior window end.
	 * @return array
	 */
	private function build_response(
		Conversion_Metric $metric,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): array {
		$response = [
			'tab_pending' => true,
			'current'     => $this->build_window( $metric, $start, $end ),
			'previous'    => null,
		];
		if ( $compare_start && $compare_end ) {
			$response['previous'] = $this->build_window( $metric, $compare_start, $compare_end );
		}
		return $response;
	}

	/**
	 * Window-bound payload covering all eight sections (23 metrics).
	 *
	 * @param Conversion_Metric $metric Orchestrator.
	 * @param DateTimeImmutable $start  Start.
	 * @param DateTimeImmutable $end    End.
	 * @return array
	 */
	private function build_window( Conversion_Metric $metric, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return [
			'window'                               => [
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			],
			// Section 1 — The reader lifecycle.
			'reader_lifecycle_funnel'              => $metric->get_reader_lifecycle_funnel( $start, $end ),
			// Section 2 — Per-journey conversion funnels.
			'anonymous_to_registered_funnel'       => $metric->get_anonymous_to_registered_funnel( $start, $end ),
			'registered_to_subscriber_funnel'      => $metric->get_registered_to_subscriber_funnel( $start, $end ),
			'registered_to_donor_funnel'           => $metric->get_registered_to_donor_funnel( $start, $end ),
			'subscriber_to_donor_funnel'           => $metric->get_subscriber_to_donor_funnel( $start, $end ),
			// Section 3 — Where conversions come from.
			'source_mix_registrations'             => $metric->get_source_mix_registrations( $start, $end ),
			'source_mix_subscribers'               => $metric->get_source_mix_subscribers( $start, $end ),
			'source_mix_donors'                    => $metric->get_source_mix_donors( $start, $end ),
			// Section 4 — How long conversions take (cumulative LineCharts).
			'time_to_register_distribution'        => $metric->get_time_to_register_distribution( $start, $end ),
			'time_to_subscribe_distribution'       => $metric->get_time_to_subscribe_distribution( $start, $end ),
			'time_to_donate_distribution'          => $metric->get_time_to_donate_distribution( $start, $end ),
			'subscriber_to_donor_lag_distribution' => $metric->get_subscriber_to_donor_lag_distribution( $start, $end ),
			// Section 5 — Cohort retention (snapshot).
			'registration_to_conversion_cohort'    => $metric->get_registration_to_conversion_cohort( $start, $end ),
			'subscriber_retention_cohort'          => $metric->get_subscriber_retention_cohort( $start, $end ),
			// Section 6 — Conversion rate trends.
			'weekly_conversion_rates'              => $metric->get_weekly_conversion_rates( $start, $end ),
			// Section 7 — Cross-tab influenced attribution (comparison-enabled).
			'influenced_registration_rate_7d'      => $metric->get_influenced_registration_rate_7d( $start, $end ),
			'influenced_subscription_rate_14d'     => $metric->get_influenced_subscription_rate_14d( $start, $end ),
			'influenced_donation_rate_14d'         => $metric->get_influenced_donation_rate_14d( $start, $end ),
			'influenced_newsletter_rate_7d'        => $metric->get_influenced_newsletter_rate_7d( $start, $end ),
			// Section 8 — Opportunity buckets.
			'stale_registered_count'               => $metric->get_stale_registered_count( $start, $end ),
			'at_risk_subscriber_count'             => $metric->get_at_risk_subscriber_count( $start, $end ),
			'lapsed_donor_count'                   => $metric->get_lapsed_donor_count( $start, $end ),
			'top_pages_no_conversion'              => $metric->get_top_pages_no_conversion( $start, $end ),
		];
	}

	/**
	 * Args spec.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$base = [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => [ $this, 'validate_date_string' ],
		];
		return [
			'start'         => array_merge(
				$base,
				[
					'description' => __( 'Inclusive window start date (YYYY-MM-DD, site timezone).', 'newspack-plugin' ),
					'required'    => true,
				]
			),
			'end'           => array_merge(
				$base,
				[
					'description' => __( 'Inclusive window end date (YYYY-MM-DD, site timezone).', 'newspack-plugin' ),
					'required'    => true,
				]
			),
			'compare_start' => array_merge(
				$base,
				[
					'description' => __( 'Optional comparison window start. Must pair with compare_end.', 'newspack-plugin' ),
					'required'    => false,
				]
			),
			'compare_end'   => array_merge(
				$base,
				[
					'description' => __( 'Optional comparison window end. Must pair with compare_start.', 'newspack-plugin' ),
					'required'    => false,
				]
			),
		];
	}

	/**
	 * REST validate_callback.
	 *
	 * @param mixed $value Value.
	 * @return bool|WP_Error
	 */
	public function validate_date_string( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return new WP_Error(
				'newspack_insights_invalid_date',
				__( 'Date must be a non-empty YYYY-MM-DD string.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $value, $this->site_timezone() );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error(
				'newspack_insights_invalid_date',
				/* translators: %s: the invalid date string */
				sprintf( __( 'Invalid date "%s". Expected YYYY-MM-DD.', 'newspack-plugin' ), $value ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Parse a Y-m-d string into a DateTimeImmutable.
	 *
	 * @param mixed        $value      Raw value.
	 * @param DateTimeZone $tz         Timezone.
	 * @param bool         $end_of_day If true, 23:59:59; else 00:00:00.
	 * @return DateTimeImmutable
	 * @throws Exception On parse failure.
	 */
	private function parse_date( $value, DateTimeZone $tz, bool $end_of_day ): DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			throw new Exception( esc_html__( 'Missing date value.', 'newspack-plugin' ) );
		}
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $value, $tz );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			/* translators: %s: the invalid date string */
			throw new Exception( esc_html( sprintf( __( 'Invalid date "%s". Expected YYYY-MM-DD.', 'newspack-plugin' ), $value ) ) );
		}
		return $end_of_day ? $parsed->setTime( 23, 59, 59 ) : $parsed->setTime( 0, 0, 0 );
	}

	/**
	 * Site timezone.
	 *
	 * @return DateTimeZone
	 */
	private function site_timezone(): DateTimeZone {
		return wp_timezone();
	}
}
