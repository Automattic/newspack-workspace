<?php
/**
 * Newspack Insights — Gates Metric orchestrator (NPPD-1604, Phase 1).
 *
 * Phase 1 placeholder layer. Every metric returns a `pending: true`
 * payload with a zero value and a `placeholder_type` so the React
 * layer can render the spec's empty-state value ("0", "0%", "$0.00",
 * "0.0") without inferring type. No storage layer, no SQL — the data
 * is intentionally synthetic until NPPD-1630 wires the BigQuery
 * query proxy into the same method signatures.
 *
 * Phase 2 swap point: each method in this class will move from
 * returning a placeholder to dispatching a `query_name` against the
 * Newspack Manager BQ catalog. The orchestrator's responsibility
 * (caching, response shape) stays here; storage rolls in beneath.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

/**
 * Tab 4 placeholder metric orchestrator.
 *
 * @phpstan-type RateMetric array{
 *   value: int|float,
 *   computable: bool,
 *   pending: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 * }
 */
final class Gates_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so
	 * cached payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab4_v1:';

	/**
	 * Build the standard placeholder shape for a single scorecard
	 * metric. Type is encoded in `placeholder_type` so React can pick
	 * the right format token ("0" vs "0%" vs "$0.00" vs "0.0") without
	 * inferring from the field name.
	 *
	 * @param string $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @return array{value: int|float, computable: bool, pending: bool, denominator: null, placeholder_type: string}
	 */
	private function placeholder( string $placeholder_type ): array {
		return [
			'value'            => 'decimal' === $placeholder_type ? 0.0 : 0,
			'computable'       => false,
			'pending'          => true,
			'denominator'      => null,
			'placeholder_type' => $placeholder_type,
		];
	}

	// --- Section 1: Gate exposure ---------------------------------------

	/**
	 * Total gate impressions in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_gate_impressions( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'count' );
	}

	/**
	 * Unique readers who saw at least one gate.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_unique_readers_reached( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'count' );
	}

	/**
	 * Average gate exposures per reader.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_exposures_per_reader( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'decimal' );
	}

	/**
	 * Percentage of sessions that hit at least one gate.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_sessions_with_gate( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	// --- Section 2: Free reader conversion ------------------------------

	/**
	 * Regwall conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_regwall_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Regwall conversion rate, influenced (7-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_regwall_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	// --- Section 3: Paid reader conversion ------------------------------

	/**
	 * Paywall conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_paywall_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Paywall conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_paywall_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Total revenue from paywall conversions, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_paywall_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'currency' );
	}

	/**
	 * Average revenue per paywall conversion.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_revenue_per_paywall_conversion( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'currency' );
	}

	// --- Section 4: How readers convert ---------------------------------

	/**
	 * Conversion funnel — three stages with zeros and a pending flag.
	 * Stage shape kept stable so the React Funnel viz can render the
	 * same chrome regardless of phase.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   pending: bool,
	 *   stages: array<int, array{label: string, count: int, pct_of_top: float}>
	 * }
	 */
	public function get_conversion_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'stages'  => [
				[
					'label'      => __( 'Impression', 'newspack-plugin' ),
					'count'      => 0,
					'pct_of_top' => 0.0,
				],
				[
					'label'      => __( 'Engagement', 'newspack-plugin' ),
					'count'      => 0,
					'pct_of_top' => 0.0,
				],
				[
					'label'      => __( 'Conversion', 'newspack-plugin' ),
					'count'      => 0,
					'pct_of_top' => 0.0,
				],
			],
		];
	}

	/**
	 * Exposures-before-conversion distribution buckets.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   pending: bool,
	 *   buckets: array<int, array{label: string, count: int, pct: float}>
	 * }
	 */
	public function get_exposures_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'buckets' => [
				[
					'label' => __( '1 exposure', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
				[
					'label' => __( '2 exposures', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
				[
					'label' => __( '3–5 exposures', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
				[
					'label' => __( '6+ exposures', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
			],
		];
	}

	// --- Section 5: Performance by gate ---------------------------------

	/**
	 * Per-gate breakdown. Phase 1 returns an empty `rows` array; the
	 * React PerformanceByGateSection renders the spec's empty-state
	 * copy when the array is empty. Phase 2 will populate this with
	 * real BQ rows enriched server-side from `wp_posts.post_title`
	 * keyed on `gate_post_id`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, rows: array}
	 */
	public function get_performance_by_gate( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'rows'    => [],
		];
	}
}
