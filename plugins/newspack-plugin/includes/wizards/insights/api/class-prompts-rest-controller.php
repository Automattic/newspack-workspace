<?php
/**
 * Newspack Insights — Tab 5 Prompts REST controller (NPPD-1607, Phase 2).
 *
 * Single endpoint: `GET /newspack-insights/v1/prompts`. Same date-arg
 * validation, permission check, and date parsing conventions as
 * {@see Gates_REST_Controller} — Tab 5 mirrors Tab 4's request/response
 * lifecycle exactly.
 *
 * Response shape:
 *   tab_error: bool          — true only when every section in the current
 *                              window failed to load; React renders a
 *                              tab-level error banner.
 *   current:     PromptsWindow — scorecards + funnel + distribution + tables
 *   previous:    PromptsWindow | null — only populated when the request
 *                              passes `compare_start` + `compare_end`.
 *
 * Each metric from {@see Prompts_Metric} carries its own `state`
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
 * Prompts REST controller.
 */
class Prompts_REST_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'prompts';

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
		return 'prompts';
	}

	/**
	 * Register the Tab 5 route.
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
					'callback'            => [ $this, 'get_prompts_data' ],
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
					'callback'            => [ $this, 'refresh_prompts_data' ],
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
	public function get_prompts_data( WP_REST_Request $request ) {
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
					'data'  => Prompts_Metric::get_fixture( $variant, $compare ),
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

		$metric = new Prompts_Metric();
		return $this->cached_response(
			$request,
			function () use ( $metric, $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $metric, $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * POST /prompts/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refresh_prompts_data( WP_REST_Request $request ) {
		// Fixture mode: delegate to GET so refresh is a no-op cache bypass.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return $this->get_prompts_data( $request );
		}
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;
		$metric = new Prompts_Metric();
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
		return $this->build_response( new Prompts_Metric(), $start, $end, null, null );
	}

	/**
	 * Assemble the top-level response.
	 *
	 * `tab_error` is true only when every **hub-backed** metric in the current
	 * window reports `state: 'error'` — i.e. the hub (BigQuery proxy) is
	 * down/misconfigured. Local (Woo order-meta) cards survive a hub outage and do
	 * not suppress the banner (NPPD-1745; see {@see self::is_window_all_error()}).
	 * React renders a tab-level error banner in that case; otherwise each section
	 * renders its own error/empty/populated treatment.
	 *
	 * @param Prompts_Metric         $metric        Orchestrator.
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Prior window start.
	 * @param DateTimeImmutable|null $compare_end   Prior window end.
	 * @return array
	 */
	private function build_response(
		Prompts_Metric $metric,
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
	 * Whether every **hub-backed** metric in a window reports `state: 'error'`.
	 *
	 * Scoped to hub-backed metrics (NPPD-1745): as cards move to local Woo order-meta
	 * sourcing, a local card always succeeds even when the hub is fully down. Under
	 * the old "every metric errored" rule the "whole tab failed" banner could then
	 * never fire — a half-broken tab that looks trustworthy. So the banner now fires
	 * when all hub-backed (and hybrid) metrics error, even though a surviving local
	 * card still renders. The hub-backed-vs-local classification is declared
	 * explicitly on {@see \Newspack\Insights\Prompts_Metric::METRIC_SOURCES}, not
	 * inferred and not hardcoded here.
	 *
	 * Returns `false` as soon as any hub-backed metric is not in the error state.
	 * Returns `false` for a window with no recognizable hub-backed metric at all
	 * (nothing to declare failed).
	 *
	 * NPPD-1745 (banner hole on non-WC): a `hybrid` card (local order-meta numerator
	 * over a hub denominator) short-circuits to a not-applicable empty state on a
	 * non-WooCommerce publisher BEFORE it ever calls the hub — so a hub outage can't
	 * make it error, and treating its surviving empty state as a healthy hub-backed
	 * card would mask the outage and suppress the banner. On a non-WC publisher,
	 * hybrid cards are therefore skipped (treated like `local`); the genuinely
	 * hub-backed cards (pure `hub`) still drive the decision.
	 *
	 * @param array $window             The shape returned by `build_window()`.
	 * @param bool  $woocommerce_active Whether WooCommerce is active for this publisher.
	 * @return bool
	 */
	private static function is_window_all_error( array $window, bool $woocommerce_active ): bool {
		$saw_hub_backed = false;
		foreach ( Prompts_Metric::METRIC_SOURCES as $key => $source ) {
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
	 * Window-bound payload covering all seven sections.
	 *
	 * @param Prompts_Metric    $metric Orchestrator.
	 * @param DateTimeImmutable $start  Start.
	 * @param DateTimeImmutable $end    End.
	 * @return array
	 */
	private function build_window( Prompts_Metric $metric, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$window = [
			'window'                                     => [
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			],
			// Section 1 — Prompt exposure.
			'total_prompt_impressions'                   => $metric->get_total_prompt_impressions( $start, $end ),
			'unique_readers_reached'                     => $metric->get_unique_readers_reached( $start, $end ),
			'avg_prompts_per_reader'                     => $metric->get_avg_prompts_per_reader( $start, $end ),
			// Section 2 — Prompt engagement.
			'click_through_rate'                         => $metric->get_click_through_rate( $start, $end ),
			'form_submission_rate'                       => $metric->get_form_submission_rate( $start, $end ),
			'dismissal_rate'                             => $metric->get_dismissal_rate( $start, $end ),
			// Section 3 — Free reader conversion.
			'registration_conversion_direct'             => $metric->get_registration_conversion_direct( $start, $end ),
			'registration_conversion_influenced_7d'      => $metric->get_registration_conversion_influenced_7d( $start, $end ),
			'newsletter_signup_conversion_direct'        => $metric->get_newsletter_signup_conversion_direct( $start, $end ),
			'newsletter_signup_conversion_influenced_7d' => $metric->get_newsletter_signup_conversion_influenced_7d( $start, $end ),
			// Section 4 — Paid reader conversion.
			'donation_conversion_direct'                 => $metric->get_donation_conversion_direct( $start, $end ),
			'donation_conversion_influenced_14d'         => $metric->get_donation_conversion_influenced_14d( $start, $end ),
			'subscription_conversion_direct'             => $metric->get_subscription_conversion_direct( $start, $end ),
			'subscription_conversion_influenced_14d'     => $metric->get_subscription_conversion_influenced_14d( $start, $end ),
			// Section 5 — Revenue from prompts.
			'donation_revenue_direct'                    => $metric->get_donation_revenue_direct( $start, $end ),
			'donation_revenue_influenced_14d'            => $metric->get_donation_revenue_influenced_14d( $start, $end ),
			'subscription_revenue_direct'                => $metric->get_subscription_revenue_direct( $start, $end ),
			'subscription_revenue_influenced_14d'        => $metric->get_subscription_revenue_influenced_14d( $start, $end ),
			// Section 6 — How readers convert.
			'conversion_funnel'                          => $metric->get_conversion_funnel( $start, $end ),
			'exposures_distribution'                     => $metric->get_exposures_distribution( $start, $end ),
			// Section 7 — Performance breakdown.
			'performance_by_prompt'                      => $metric->get_performance_by_prompt( $start, $end ),
			'performance_by_intent'                      => $metric->get_performance_by_intent( $start, $end ),
			'performance_by_placement'                   => $metric->get_performance_by_placement( $start, $end ),
		];

		// Stamp the per-intent capability gate (NPPD-1720) onto the conversion-tied
		// scalars. The flag is window-independent — it reflects which conversion
		// blocks the publisher's active prompts contain — but rides on each
		// window's metric so the React adapter reads it off the entry it renders.
		foreach ( $metric->get_capability_flags() as $metric_key => $has_capability ) {
			if ( isset( $window[ $metric_key ] ) && is_array( $window[ $metric_key ] ) ) {
				$window[ $metric_key ]['has_capability'] = $has_capability;
			}
		}

		return $window;
	}
}
