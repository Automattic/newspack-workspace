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
use DateTimeZone;
use Exception;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Gates REST controller.
 */
class Gates_REST_Controller extends WP_REST_Controller {

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
	public function get_gates_data( WP_REST_Request $request ) {
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

		$metric = new Gates_Metric();
		return rest_ensure_response( $this->build_response( $metric, $start, $end, $compare_start, $compare_end ) );
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
			'tab_error' => self::is_window_all_error( $current ),
			'current'   => $current,
			'previous'  => null,
		];
		if ( $compare_start && $compare_end ) {
			$response['previous'] = $this->build_window( $metric, $compare_start, $compare_end );
		}
		return $response;
	}

	/**
	 * Whether every metric in a window payload reports `state: 'error'`.
	 *
	 * Returns `false` as soon as any metric is not in the error state (the `window`
	 * key is date metadata, not a metric, so it's skipped). A metric missing a
	 * `state` key is treated as non-error, so the banner only shows on an
	 * unambiguous all-failed window.
	 *
	 * @param array $window The shape returned by `build_window()`.
	 * @return bool
	 */
	private static function is_window_all_error( array $window ): bool {
		foreach ( $window as $key => $value ) {
			if ( 'window' === $key ) {
				continue;
			}
			if ( ! is_array( $value ) || ! isset( $value['state'] ) || 'error' !== $value['state'] ) {
				return false;
			}
		}
		return true;
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
		return [
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
			'regwall_conversion_direct'          => $metric->get_regwall_conversion_direct( $start, $end ),
			'regwall_conversion_influenced_7d'   => $metric->get_regwall_conversion_influenced_7d( $start, $end ),
			// Section 3.
			'paywall_conversion_direct'          => $metric->get_paywall_conversion_direct( $start, $end ),
			'paywall_conversion_influenced_14d'  => $metric->get_paywall_conversion_influenced_14d( $start, $end ),
			'total_paywall_revenue_direct'       => $metric->get_total_paywall_revenue_direct( $start, $end ),
			'avg_revenue_per_paywall_conversion' => $metric->get_avg_revenue_per_paywall_conversion( $start, $end ),
			// Section 4.
			'conversion_funnel'                  => $metric->get_conversion_funnel( $start, $end ),
			'exposures_distribution'             => $metric->get_exposures_distribution( $start, $end ),
			// Section 5.
			'performance_by_gate'                => $metric->get_performance_by_gate( $start, $end ),
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
