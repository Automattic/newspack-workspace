<?php
/**
 * Newspack Insights — Tab 8 Advertising REST controller (NPPD-1663).
 *
 * Single endpoint: `GET /newspack-insights/v1/advertising`. Same date-arg
 * validation, permission check, and date parsing conventions as the Audience
 * controller (NPPD-1648).
 *
 * Response shape:
 *   current  — Advertising envelope for the requested window (is_tab_visible,
 *              is_report_ready, readiness_issues, data_as_of, has_estimated_data,
 *              estimated_window_start_date, metrics, plus is_loading/is_stale).
 *   previous — same for the comparison window, or null.
 *
 * Data comes from {@see Advertising_Metric}, which reads a transient cache and
 * schedules a background GAM refresh (Action Scheduler) on a miss/stale entry.
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
 * Advertising REST controller.
 */
class Advertising_REST_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'advertising';

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
		return 'advertising';
	}

	/**
	 * Register the Tab 8 route.
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
					'callback'            => [ $this, 'get_advertising_data' ],
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
					'callback'            => [ $this, 'refresh_advertising_data' ],
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
	public function get_advertising_data( WP_REST_Request $request ) {
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		// Dev smoke-test path: serve canned fixture data so the UI renders without
		// a GAM connection. The optional _fixture_state param selects a render
		// path (see dev-notes.md). Never enable in production.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			$compare  = $compare_start && $compare_end;
			$variant  = (string) ( $request->get_param( '_fixture_state' ) ?? 'populated' );
			$response = rest_ensure_response(
				[
					'cache' => [
						'source'         => Cache::SOURCE_LOCAL,
						'computed_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'cooldown_until' => null,
					],
					'data'  => Advertising_Metric::get_fixture( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), $compare, $variant ),
				]
			);
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		}

		return $this->cached_response(
			$request,
			function () use ( $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * POST /advertising/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refresh_advertising_data( WP_REST_Request $request ) {
		// Fixture mode: delegate to GET so refresh is a no-op cache bypass.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return $this->get_advertising_data( $request );
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
	 * Assemble the top-level response.
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
		$response = [
			'current'  => Advertising_Metric::get_all( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), false ),
			'previous' => null,
		];
		if ( $compare_start && $compare_end ) {
			$response['previous'] = Advertising_Metric::get_all( $compare_start->format( 'Y-m-d' ), $compare_end->format( 'Y-m-d' ), false );
		}
		return $response;
	}
}
