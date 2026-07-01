<?php
/**
 * Newspack Insights — Tab 2 Engagement REST controller (NPPD-1648).
 *
 * Single endpoint: `GET /newspack-insights/v1/engagement`. Mirrors the
 * date-arg validation, permission check, and response shape of
 * {@see Audience_REST_Controller}.
 *
 * Data comes from {@see Engagement_Metric}, which dispatches all metrics
 * through the BigQuery proxy (NPPD-1729). The GA4 path has been removed.
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
 * Engagement REST controller.
 */
class Engagement_REST_Controller extends WP_REST_Controller {

	use Cached_Controller_Trait;
	use Insights_REST_Trait;

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
	 * GET handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_engagement_data( WP_REST_Request $request ) {
		// Dev smoke-test path: serve canned fixture data so the UI renders
		// without a BQ connection. Never enable in production.
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
	 * Base-window payload (no comparison) for the pre-warm path.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	public function build_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return $this->build_response( $start, $end, null, null );
	}

	/**
	 * Assemble the top-level response (tab_error surfaced when the metric errors).
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
}
