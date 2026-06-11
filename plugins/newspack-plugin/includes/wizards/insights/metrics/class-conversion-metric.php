<?php
/**
 * Newspack Insights — Conversion Journey Metric orchestrator (NPPD-1609, Phase 1).
 *
 * Phase 1 placeholder layer for Tab 3 (Conversion Journey). Every metric
 * returns a `pending: true` payload in the eventual real shape — zeroed
 * scalars, zeroed funnel stages, empty viz collections — so the React
 * layer can render the full tab (eight sections, all visualizations) with
 * empty states before BigQuery wiring lands in Phase 2 (NPPD-1630). No
 * storage layer, no SQL: the data is intentionally synthetic until Phase 2
 * swaps each method body to a query dispatch against the Newspack Manager
 * BQ catalog without touching signatures or the response envelope.
 *
 * Mirrors {@see Prompts_Metric} (Tab 5) one-for-one: same placeholder
 * shape for scalars, same `pending` + ordered-collection shape for viz,
 * same per-method window signature. Conversion Journey is the widest
 * Insights tab (eight sections, 23 metrics) but the per-metric envelope is
 * identical to the per-surface tabs.
 *
 * Method-signature contract: every method takes the current window
 * (`$start`, `$end`) for parity with the other tabs. The previous-window
 * comparison is a controller concern — {@see Conversion_REST_Controller}
 * builds the `current` and `previous` windows by calling the same methods
 * twice — so individual methods never see the comparison window. Only
 * Section 7 (cross-tab influenced attribution) renders deltas in the UI.
 *
 * Snapshot metrics — Section 5 cohorts and Sections 8.1–8.3 — are
 * current-state, not windowed: they accept `$start`/`$end` for signature
 * consistency but ignore them (noted per-method). Phase 2 computes them
 * independent of the date picker.
 *
 * Local-only metrics that do NOT belong in the BQ catalog (flagged per
 * method for the Phase 2 handoff): the Subscriber → Donor funnel (2.4),
 * the Subscriber → Donor lag distribution (4.4), the subscriber retention
 * cohort (5.2), and the three opportunity-bucket counts (8.1–8.3). These
 * are Woo-only (or Woo-plus-a-recently-active-UID-set), computed locally.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

/**
 * Tab 3 placeholder metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   value: int|float,
 *   computable: bool,
 *   pending: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 * }
 */
final class Conversion_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so cached
	 * payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab3_v1:';

	/**
	 * The three acquisition surfaces every source-attributed metric splits
	 * by. Machine keys (not translated) — the React layer maps them to
	 * display labels. Shared by the Section 3 PieCharts and the Section 4
	 * multi-series cumulative distributions.
	 *
	 * @var string[]
	 */
	const SOURCES = [ 'gate', 'prompt', 'direct' ];

	/**
	 * Build the standard placeholder shape for a single scorecard metric.
	 * Type is encoded in `placeholder_type` so React can pick the right
	 * format token ("0" vs "0%" vs "0.0") without inferring from the field
	 * name. Identical to {@see Prompts_Metric::placeholder()} for cross-tab
	 * parity — this tab uses 'count', 'rate', and 'decimal' (no currency
	 * metrics in v1).
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

	/**
	 * Build a single zeroed funnel stage.
	 *
	 * @param string $label Stage label (translated).
	 * @return array{label: string, count: int, pct_of_top: float}
	 */
	private function funnel_stage( string $label ): array {
		return [
			'label'      => $label,
			'count'      => 0,
			'pct_of_top' => 0.0,
		];
	}

	/**
	 * Build the three zeroed source slices for a Section 3 PieChart. Phase 1
	 * returns all three surfaces with zero values so the PieChart renders
	 * its legend chrome; Phase 2 fills in real counts.
	 *
	 * @return array<int, array{source: string, count: int, pct: float}>
	 */
	private function source_slices(): array {
		return array_map(
			static function ( string $source ): array {
				return [
					'source' => $source,
					'count'  => 0,
					'pct'    => 0.0,
				];
			},
			self::SOURCES
		);
	}

	/**
	 * Build the three empty per-source series for a Section 4 multi-series
	 * cumulative distribution (4.2, 4.3). Each series carries an empty
	 * `points` array; Phase 1 renders the LineChart empty state.
	 *
	 * @return array<int, array{label: string, points: array}>
	 */
	private function cumulative_groups(): array {
		return array_map(
			static function ( string $source ): array {
				return [
					'label'  => $source,
					'points' => [],
				];
			},
			self::SOURCES
		);
	}

	// --- Section 1: The reader lifecycle --------------------------------

	/**
	 * Reader lifecycle funnel — five nested stages from anonymous reader to
	 * supporter. Stage shape kept stable so the React Funnel renders the
	 * same chrome regardless of phase.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	public function get_reader_lifecycle_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'stages'  => [
				$this->funnel_stage( __( 'Anonymous reader', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Engaged reader', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Registered reader', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Newsletter subscriber', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Subscriber or donor', 'newspack-plugin' ) ),
			],
		];
	}

	// --- Section 2: Per-journey conversion funnels ----------------------

	/**
	 * Anonymous → Registered funnel (2.1) — three stages.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	public function get_anonymous_to_registered_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'stages'  => [
				$this->funnel_stage( __( 'Anonymous', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Saw a conversion surface', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Registered', 'newspack-plugin' ) ),
			],
		];
	}

	/**
	 * Registered → Subscriber funnel (2.2) — three stages, non-donation
	 * subscriptions only.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	public function get_registered_to_subscriber_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'stages'  => [
				$this->funnel_stage( __( 'Registered', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Saw a subscription-intent surface', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Became subscriber', 'newspack-plugin' ) ),
			],
		];
	}

	/**
	 * Registered → Donor funnel (2.3) — three stages.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	public function get_registered_to_donor_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'stages'  => [
				$this->funnel_stage( __( 'Registered', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Saw a donation-intent surface', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Became donor', 'newspack-plugin' ) ),
			],
		];
	}

	/**
	 * Subscriber → Donor cross-upsell funnel (2.4) — two stages,
	 * visibility-gated.
	 *
	 * Local-only (Woo-only): does NOT belong in the BQ catalog. Gated on
	 * 50 active subscribers AND 50 active donors. Phase 1 returns
	 * `visibility: 'hidden'` unconditionally (no real cohort sizes yet);
	 * Phase 2 computes visibility from the live cohort counts. The React
	 * side renders the empty-state note when hidden.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, stages: array<int, array{label: string, count: int, pct_of_top: float}>, visibility: string, visibility_reason: string|null}
	 */
	public function get_subscriber_to_donor_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending'           => true,
			'stages'            => [
				$this->funnel_stage( __( 'Active subscriber', 'newspack-plugin' ) ),
				$this->funnel_stage( __( 'Also donor', 'newspack-plugin' ) ),
			],
			'visibility'        => 'hidden',
			'visibility_reason' => 'insufficient_data',
		];
	}

	// --- Section 3: Where conversions come from -------------------------

	/**
	 * Source mix for new registrations (3.1) — gate / prompt / direct.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, total: int, slices: array<int, array{source: string, count: int, pct: float}>}
	 */
	public function get_source_mix_registrations( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'total'   => 0,
			'slices'  => $this->source_slices(),
		];
	}

	/**
	 * Source mix for new subscribers (3.2) — gate / prompt / direct.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, total: int, slices: array<int, array{source: string, count: int, pct: float}>}
	 */
	public function get_source_mix_subscribers( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'total'   => 0,
			'slices'  => $this->source_slices(),
		];
	}

	/**
	 * Source mix for new donors (3.3) — gate / prompt / direct.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, total: int, slices: array<int, array{source: string, count: int, pct: float}>}
	 */
	public function get_source_mix_donors( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'total'   => 0,
			'slices'  => $this->source_slices(),
		];
	}

	// --- Section 4: How long conversions take ---------------------------
	// Cumulative-distribution LineCharts (replaced the v1 BoxPlot framing).
	// Single-series: { points: [{day, cumulative_pct}, ...] }.
	// Multi-series:  { groups: [{ label, points: [...] }, ...] }.

	/**
	 * Time-to-register cumulative distribution (4.1) — single series.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, points: array}
	 */
	public function get_time_to_register_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'points'  => [],
		];
	}

	/**
	 * Time-to-subscribe cumulative distribution (4.2) — three series by
	 * source (gate / prompt / direct).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, groups: array<int, array{label: string, points: array}>}
	 */
	public function get_time_to_subscribe_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'groups'  => $this->cumulative_groups(),
		];
	}

	/**
	 * Time-to-donate cumulative distribution (4.3) — three series by source.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, groups: array<int, array{label: string, points: array}>}
	 */
	public function get_time_to_donate_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'groups'  => $this->cumulative_groups(),
		];
	}

	/**
	 * Subscriber → donor lag cumulative distribution (4.4) — single series,
	 * visibility-gated.
	 *
	 * Local-only (Woo-only): does NOT belong in the BQ catalog. Gated at 50
	 * cross-converters. Phase 1 returns `visibility: 'hidden'`
	 * unconditionally; Phase 2 computes it from the live cohort size.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, points: array, visibility: string, visibility_reason: string|null}
	 */
	public function get_subscriber_to_donor_lag_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending'           => true,
			'points'            => [],
			'visibility'        => 'hidden',
			'visibility_reason' => 'insufficient_data',
		];
	}

	// --- Section 5: Cohort retention ------------------------------------
	// Snapshot metrics: pre-computed weekly, independent of the date picker.
	// The $start/$end params are accepted for signature parity and ignored.

	/**
	 * Registration → conversion cohort retention (5.1). Snapshot — ignores
	 * the window. The reference line (15% at 6 months) is hardcoded per the
	 * spec in Phase 1; Phase 2 makes it publisher-configurable.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array{pending: bool, cohorts: array, reference_line: array{value: float, label: string}}
	 */
	public function get_registration_to_conversion_cohort( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending'        => true,
			'cohorts'        => [],
			'reference_line' => [
				'value' => 0.15,
				'label' => __( '15% at 6 months', 'newspack-plugin' ),
			],
		];
	}

	/**
	 * Subscriber retention cohort (5.2). Snapshot — ignores the window.
	 * Reference line (70% at 12 months) hardcoded in Phase 1.
	 *
	 * Local-only (Woo-only): does NOT belong in the BQ catalog.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array{pending: bool, cohorts: array, reference_line: array{value: float, label: string}}
	 */
	public function get_subscriber_retention_cohort( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending'        => true,
			'cohorts'        => [],
			'reference_line' => [
				'value' => 0.70,
				'label' => __( '70% at 12 months', 'newspack-plugin' ),
			],
		];
	}

	// --- Section 6: Conversion rate trends ------------------------------

	/**
	 * Weekly conversion rates (6) — multi-series LineChart. Phase 1 returns
	 * an empty `weeks` array so the LineChart renders its empty state. The
	 * `series` keys name the two tracked rates.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, weeks: array, series: string[]}
	 */
	public function get_weekly_conversion_rates( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending' => true,
			'weeks'   => [],
			'series'  => [ 'registration_rate', 'subscription_attempt_rate' ],
		];
	}

	// --- Section 7: Cross-tab influenced attribution --------------------
	// The only section with comparison deltas. These duplicate the Tab 4/5/
	// 6/7 Influenced patterns; Phase 2 may re-query independently or call
	// into the existing tab orchestrators. Phase 1 stubs them so neither
	// approach is locked in.

	/**
	 * Influenced registration rate, 7-day lookback (7.1).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_registration_rate_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Influenced subscription rate, 14-day lookback (7.2).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_subscription_rate_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Influenced donation rate, 14-day lookback (7.3).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_donation_rate_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	/**
	 * Influenced newsletter signup rate, 7-day lookback (7.4).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_newsletter_rate_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'rate' );
	}

	// --- Section 8: Opportunity buckets ---------------------------------
	// 8.1–8.3 are current-state snapshot counts: accept the window for
	// signature parity, ignore it. All three are local-only (Woo-only, or
	// Woo plus a recently-active UID set) and do NOT belong in the BQ
	// catalog. 8.3 duplicates Tab 7's Lapsed Donors definition — Phase 2
	// should reuse that orchestrator method rather than re-implement.

	/**
	 * Stale registered readers (8.1). Snapshot — ignores the window.
	 *
	 * Local-only: the count itself is local (Woo plus a recently-active UID
	 * set sourced from BQ). Does NOT belong in the BQ catalog.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array
	 */
	public function get_stale_registered_count( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'count' );
	}

	/**
	 * At-risk subscribers (8.2). Snapshot — ignores the window.
	 *
	 * Local-only (Woo-only): does NOT belong in the BQ catalog.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array
	 */
	public function get_at_risk_subscriber_count( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'count' );
	}

	/**
	 * Lapsed donors (8.3). Snapshot — ignores the window. Same definition
	 * as Tab 7's Lapsed Donors.
	 *
	 * Local-only (Woo-only): does NOT belong in the BQ catalog. Phase 2
	 * should reuse the Tab 7 orchestrator method instead of re-implementing.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array
	 */
	public function get_lapsed_donor_count( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder( 'count' );
	}

	/**
	 * Top pages that don't convert (8.4) — windowed table. Phase 1 returns
	 * an empty `rows` array so the React table renders its empty-state row.
	 * `threshold_pageviews` is the minimum-traffic cutoff (a starting guess
	 * per the spec; tuned in Phase 2).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, rows: array, threshold_pageviews: int}
	 */
	public function get_top_pages_no_conversion( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return [
			'pending'             => true,
			'rows'                => [],
			'threshold_pageviews' => 100,
		];
	}
}
