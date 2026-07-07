<?php
/**
 * Newspack Insights — Newsletter Ads REST controller (NPPD-1861).
 *
 * Endpoints: `GET /newspack-insights/v1/newsletter-ads` and
 * `POST /newspack-insights/v1/newsletter-ads/refresh`. Same date-arg
 * validation, permission check, and date parsing conventions as the other
 * Insights controllers (shared via {@see Insights_REST_Trait}).
 *
 * Response shape:
 *   current  — Newsletter Ads envelope for the requested window
 *              (is_tab_visible, is_report_ready, readiness_issues,
 *              data_as_of, metrics, has_window_activity).
 *   previous — same for the comparison window, or null.
 *
 * Data comes from {@see Newsletter_Ads_Metric}, which computes synchronously
 * from local SQL (ads CPT meta + the newsletters ad-stats table) behind a
 * short transient cache.
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
 * Newsletter Ads REST controller.
 */
class Newsletter_Ads_REST_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'newsletter-ads';

	/**
	 * Cache source classification for this controller. Local SQL (ads CPT
	 * meta + the newsletters stats table) — no external API or BigQuery.
	 *
	 * @return string
	 */
	protected function cache_source(): string {
		return Cache::SOURCE_LOCAL;
	}

	/**
	 * Tab slug used as the cache namespace.
	 *
	 * @return string
	 */
	protected function tab_slug(): string {
		return 'newsletter_ads';
	}

	/**
	 * Register the Newsletter Ads routes.
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
					'callback'            => [ $this, 'get_newsletter_ads_data' ],
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
					'callback'            => [ $this, 'refresh_newsletter_ads_data' ],
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
	public function get_newsletter_ads_data( WP_REST_Request $request ) {
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		// Dev smoke-test path: serve canned fixture data so the UI renders
		// without the newsletters plugin. The optional _fixture_state param
		// selects a render path (see dev-notes.md). Never enable in production.
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
					'data'  => Newsletter_Ads_Metric::get_fixture( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), $compare, $variant ),
				]
			);
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		}

		return $this->cached_response( $request, $start, $end, $compare_start, $compare_end );
	}

	/**
	 * POST /newsletter-ads/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refresh_newsletter_ads_data( WP_REST_Request $request ) {
		// Fixture mode: delegate to GET so refresh is a no-op cache bypass.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return $this->get_newsletter_ads_data( $request );
		}
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		return $this->refresh_response( $request, $start, $end, $compare_start, $compare_end );
	}

	/**
	 * Base-window payload (no comparison) — satisfies the
	 * {@see Cached_Controller_Trait} contract. Like Advertising, this
	 * controller is NOT registered for the daily pre-warm: the metric layer's
	 * own transient makes recomputes cheap.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	public function build_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return $this->build_response( $start, $end, null, null );
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
			'current'  => Newsletter_Ads_Metric::get_all( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), false ),
			'previous' => null,
		];
		if ( $compare_start && $compare_end ) {
			$response['previous'] = Newsletter_Ads_Metric::get_all( $compare_start->format( 'Y-m-d' ), $compare_end->format( 'Y-m-d' ), false );
		}
		return $response;
	}
}
