<?php
/**
 * Newspack Insights — Tab 2 Engagement REST controller (NPPD-1648).
 *
 * Single endpoint: `GET /newspack-insights/v1/engagement`. Mirrors the
 * date-arg validation, permission check, and response shape of
 * {@see Audience_REST_Controller}.
 *
 * Data comes from {@see Engagement_Metric}, which dispatches GA4 (v1) vs BQ
 * (v1.1, NPPD-1630) per the NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4 constant.
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
 * Engagement REST controller.
 */
class Engagement_REST_Controller extends WP_REST_Controller {

	use Cached_Controller_Trait;

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
	protected $rest_base = 'engagement';

	/**
	 * Cache source classification for this controller.
	 *
	 * @return string
	 */
	protected function cache_source(): string {
		return Cache::SOURCE_EXTERNAL;
	}

	/**
	 * Tab slug used as the cache namespace.
	 *
	 * @return string
	 */
	protected function tab_slug(): string {
		return 'engagement';
	}

	/**
	 * Register the Tab 2 route.
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
					'callback'            => [ $this, 'get_engagement_data' ],
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
					'callback'            => [ $this, 'refresh_engagement_data' ],
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
	public function get_engagement_data( WP_REST_Request $request ) {
		// Dev smoke-test path: serve canned fixture data so the UI renders
		// without a GA4 connection. Never enable in production.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			$response = rest_ensure_response(
				[
					'cache' => [
						'source'         => Cache::SOURCE_LOCAL,
						'computed_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'cooldown_until' => null,
					],
					'data'  => Engagement_Metric::get_fixture(),
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

		return $this->cached_response(
			$request,
			function () use ( $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * POST /engagement/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refresh_engagement_data( WP_REST_Request $request ) {
		// Fixture mode: delegate to GET so refresh is a no-op cache bypass.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return $this->get_engagement_data( $request );
		}
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		return $this->refresh_response(
			$request,
			function () use ( $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $start, $end, $compare_start, $compare_end );
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

		return [ $start, $end, $compare_start, $compare_end ];
	}

	/**
	 * Assemble the top-level response (tab_error surfaced when GA4 is unconnected).
	 *
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Prior window start.
	 * @param DateTimeImmutable|null $compare_end   Prior window end.
	 * @return array
	 */
	private function build_response(
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): array {
		$current = Engagement_Metric::get_all( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), false );
		if ( isset( $current['tab_error'] ) ) {
			return $current;
		}

		$response = [
			'current'  => $current,
			'previous' => null,
		];
		if ( $compare_start && $compare_end ) {
			$previous             = Engagement_Metric::get_all( $compare_start->format( 'Y-m-d' ), $compare_end->format( 'Y-m-d' ), false );
			$response['previous'] = isset( $previous['tab_error'] ) ? null : $previous;
		}
		return $response;
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
