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
use DateTimeZone;
use Exception;
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
	 * Permission check. Mirrors the Insights wizard capability so the
	 * data layer is only available to users who can view the tab.
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
	 * Validate and parse the window args. Returns [start, end, compare_start, compare_end] on
	 * success; WP_Error on validation failure.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array|WP_Error
	 */
	private function parse_window_args( WP_REST_Request $request ) {
		$tz = $this->site_timezone();
		try {
			$start = $this->parse_date( $request->get_param( 'start' ), $tz, false );
			$end   = $this->parse_date( $request->get_param( 'end' ), $tz, true );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_insights_invalid_date',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
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
				return new WP_Error(
					'newspack_insights_invalid_date',
					$e->getMessage(),
					[ 'status' => 400 ]
				);
			}
			if ( $compare_start > $compare_end ) {
				return new WP_Error(
					'newspack_insights_invalid_comparison_window',
					__( 'compare_start must be on or before compare_end.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
		}

		return [ $start, $end, $compare_start, $compare_end ];
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
		return [
			'window'                    => [
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			],
			'new_subscribers'           => $metric->get_new_subscribers_in_window( $start, $end ),
			'churned_subscribers'       => $metric->get_churned_subscribers_in_window( $start, $end ),
			'revenue_gross'             => $metric->get_subscription_revenue_gross( $start, $end ),
			'revenue_net'               => $metric->get_subscription_revenue_net( $start, $end ),
			'refund_rate'               => $metric->get_subscription_refund_rate( $start, $end ),
			'failed_payment_retry_rate' => $metric->get_failed_payment_retry_rate( $start, $end ),
			'subscriptions_by_product'  => $metric->get_subscriptions_by_product( $start, $end ),
			'cancellation_reasons'      => $metric->get_cancellation_reasons( $start, $end ),
		];
	}

	/**
	 * Build the args spec for query parameters. Validation runs before
	 * the handler so we can reject malformed input early.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return [
			'start'         => [
				'description'       => __( 'Inclusive window start date (YYYY-MM-DD, site timezone).', 'newspack-plugin' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => [ $this, 'validate_date_string' ],
			],
			'end'           => [
				'description'       => __( 'Inclusive window end date (YYYY-MM-DD, site timezone).', 'newspack-plugin' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => [ $this, 'validate_date_string' ],
			],
			'compare_start' => [
				'description'       => __( 'Optional comparison window start (YYYY-MM-DD). Must be paired with compare_end.', 'newspack-plugin' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => [ $this, 'validate_date_string' ],
			],
			'compare_end'   => [
				'description'       => __( 'Optional comparison window end (YYYY-MM-DD). Must be paired with compare_start.', 'newspack-plugin' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => [ $this, 'validate_date_string' ],
			],
		];
	}

	/**
	 * REST validate_callback for date params.
	 *
	 * @param mixed $value Value provided by the client.
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
	 * Parse a Y-m-d string into a DateTimeImmutable at the start or end
	 * of day in the site's timezone.
	 *
	 * @param mixed        $value      The raw value from the request.
	 * @param DateTimeZone $tz         Site timezone.
	 * @param bool         $end_of_day If true, sets time to 23:59:59 (inclusive).
	 * @return DateTimeImmutable
	 * @throws Exception If the value cannot be parsed.
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
	 * Site timezone resolver.
	 *
	 * @return DateTimeZone
	 */
	private function site_timezone(): DateTimeZone {
		return wp_timezone();
	}
}
