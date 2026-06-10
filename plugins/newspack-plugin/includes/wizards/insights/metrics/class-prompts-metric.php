<?php
/**
 * Newspack Insights — Prompts Metric orchestrator (NPPD-1607, Phase 1).
 *
 * Phase 1 placeholder layer. Every metric returns a `pending: true`
 * payload with a zero value and a `placeholder_type` so the React
 * layer can render the spec's empty-state value ("0", "0%", "$0.00",
 * "0.0") without inferring type. No storage layer, no SQL — the data
 * is intentionally synthetic until Phase 2 wires the BigQuery query
 * proxy into the same method signatures.
 *
 * Mirrors {@see Gates_Metric} (Tab 4) one-for-one: same placeholder
 * shape, same Phase 1 / Phase 2 split. Method names track the query
 * names in `formulas/tab-5-prompts.md` so Phase 2 swaps each method
 * body to a `query_name` dispatch against the Newspack Manager BQ
 * catalog without touching signatures or the response envelope.
 *
 * Tab 5 carries four conversion intents (registration, newsletter
 * signup, donation, subscription) versus Gates' two, so it has more
 * scorecards and a three-table performance breakdown — but the
 * per-metric envelope is identical.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

/**
 * Tab 5 placeholder metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   value: int|float,
 *   computable: bool,
 *   pending: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 * }
 */
final class Prompts_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so
	 * cached payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab5_v1:';

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

	// --- Section 1: Prompt exposure -------------------------------------

	/**
	 * Total prompt impressions in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_prompt_impressions( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'count' );
	}

	/**
	 * Unique readers who saw at least one prompt.
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
	 * Average prompt exposures per reader.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_prompts_per_reader( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'decimal' );
	}

	// --- Section 2: Prompt engagement -----------------------------------

	/**
	 * Click-through rate (clicks ÷ impressions).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_click_through_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Form submission rate (submissions ÷ form-bearing impressions).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_form_submission_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Dismissal rate (explicit dismissals ÷ impressions).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_dismissal_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	// --- Section 3: Free reader conversion ------------------------------

	/**
	 * Registration conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Registration conversion rate, influenced (7-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Newsletter signup conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Newsletter signup conversion rate, influenced (7-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	// --- Section 4: Paid reader conversion ------------------------------

	/**
	 * Donation conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Donation conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Subscription conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Subscription conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	// --- Section 5: Revenue from prompts --------------------------------

	/**
	 * Total donation revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'currency' );
	}

	/**
	 * Total donation revenue from prompts, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'currency' );
	}

	/**
	 * Total subscription revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'currency' );
	}

	/**
	 * Total subscription revenue from prompts, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'currency' );
	}

	// --- Section 6: How readers convert ---------------------------------

	/**
	 * Conversion funnel — three stages (impression → engagement →
	 * conversion) with zeros and a pending flag. Stage shape kept
	 * stable so the React Funnel viz renders the same chrome regardless
	 * of phase. Stage 3 (Conversion) is a rollup across the four
	 * conversion intents; the per-intent breakdowns live in Sections
	 * 3 / 4 / 5.
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

	// --- Section 7: Performance breakdown -------------------------------

	/**
	 * Per-prompt breakdown. Phase 1 returns an empty `rows` array; the
	 * React PerformanceByPromptTable renders the spec's empty-state copy
	 * when the array is empty. Phase 2 will populate this with real BQ
	 * rows — `prompt_title` is captured directly in event params, so no
	 * WP enrichment is needed (unlike Gates' `gate_post_id` → post_title
	 * lookup). Donation/subscription columns ship as *attempts* in v1;
	 * completion columns are a v1.1 candidate via the Woo join.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, rows: array}
	 */
	public function get_performance_by_prompt( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'rows'    => [],
		];
	}

	/**
	 * Per-intent breakdown (donation / registration / newsletter signup),
	 * aggregated across all prompts of that intent. Phase 1 returns an
	 * empty `rows` array.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, rows: array}
	 */
	public function get_performance_by_intent( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'rows'    => [],
		];
	}

	/**
	 * Per-placement breakdown (overlay / inline / above-header / etc.),
	 * aggregated across all prompts at that placement. Phase 1 returns an
	 * empty `rows` array.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, rows: array}
	 */
	public function get_performance_by_placement( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'rows'    => [],
		];
	}
}
