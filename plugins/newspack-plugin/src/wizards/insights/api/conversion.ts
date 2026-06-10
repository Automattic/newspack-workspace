/**
 * Conversion Journey API client (NPPD-1609, Phase 1).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 3
 * endpoint: `GET /newspack-insights/v1/conversion`. Type definitions
 * mirror the PHP response shape assembled by `Conversion_REST_Controller`.
 *
 * Phase 1: every metric carries `pending: true` and a zero / empty value.
 * Phase 2 keeps the same shape but flips `pending` to false and surfaces
 * real BQ values; the React layer reads `pending` and the `tab_pending`
 * banner flag rather than knowing which phase produced a payload.
 *
 * Mirrors `api/prompts.ts` (Tab 5). Conversion Journey is the widest tab
 * (eight sections, 23 metrics): a marquee lifecycle funnel, four per-
 * journey funnels, three source-mix PieCharts, four cumulative-distribution
 * LineCharts, two cohort LineCharts, a weekly-trends LineChart, four
 * cross-tab influenced scorecards, and an opportunity-bucket block.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * The kind of placeholder a scalar metric renders. Encoded server-side so
 * the React format layer doesn't have to guess from the field name. Tab 3
 * uses 'count', 'rate', and 'decimal' (no currency metrics in v1); the full
 * union is kept for parity with the shared scalar shape.
 */
export type ConversionPlaceholderType = 'count' | 'rate' | 'currency' | 'decimal';

/**
 * Visibility gate for Sections 2.4 and 4.4. Hidden in Phase 1; Phase 2
 * computes it from the live cohort sizes.
 */
export type ConversionVisibility = 'hidden' | 'visible';
export type ConversionVisibilityReason = 'insufficient_data' | null;

/**
 * Standard scorecard metric payload (Sections 7 and 8.1–8.3).
 */
export interface ConversionScalarMetric {
	value: number;
	computable: boolean;
	pending: boolean;
	denominator: number | null;
	placeholder_type: ConversionPlaceholderType;
}

/* --- Section 1 / 2: funnels ----------------------------------------- */

export interface ConversionFunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface ConversionFunnelData {
	pending: boolean;
	stages: ConversionFunnelStage[];
}

/**
 * Visibility-gated funnel (Section 2.4 Subscriber → Donor). Carries the
 * gate flags on top of the standard funnel shape.
 */
export interface ConversionGatedFunnelData extends ConversionFunnelData {
	visibility: ConversionVisibility;
	visibility_reason: ConversionVisibilityReason;
}

/* --- Section 3: source-mix PieCharts -------------------------------- */

export interface ConversionSourceSlice {
	source: 'gate' | 'prompt' | 'direct';
	count: number;
	pct: number;
}

export interface ConversionSourceMixData {
	pending: boolean;
	total: number;
	slices: ConversionSourceSlice[];
}

/* --- Section 4: cumulative-distribution LineCharts ------------------ */

export interface ConversionCumulativePoint {
	day: number;
	cumulative_pct: number;
}

/**
 * Single-series cumulative distribution (Section 4.1).
 */
export interface ConversionCumulativeSingle {
	pending: boolean;
	points: ConversionCumulativePoint[];
}

/**
 * Visibility-gated single-series cumulative distribution (Section 4.4).
 */
export interface ConversionGatedCumulativeSingle extends ConversionCumulativeSingle {
	visibility: ConversionVisibility;
	visibility_reason: ConversionVisibilityReason;
}

export interface ConversionCumulativeGroup {
	label: 'gate' | 'prompt' | 'direct';
	points: ConversionCumulativePoint[];
}

/**
 * Multi-series cumulative distribution (Sections 4.2, 4.3).
 */
export interface ConversionCumulativeMulti {
	pending: boolean;
	groups: ConversionCumulativeGroup[];
}

/* --- Section 5: cohort retention LineCharts ------------------------- */

export interface ConversionReferenceLine {
	value: number;
	label: string;
}

export interface ConversionCohortPoint {
	period: number;
	value: number;
}

export interface ConversionCohortSeries {
	label: string;
	points: ConversionCohortPoint[];
}

export interface ConversionCohortData {
	pending: boolean;
	cohorts: ConversionCohortSeries[];
	reference_line: ConversionReferenceLine;
}

/* --- Section 6: weekly conversion-rate trends ----------------------- */

export interface ConversionWeekPoint {
	week: string;
	registration_rate: number;
	subscription_attempt_rate: number;
}

export interface ConversionWeeklyTrendsData {
	pending: boolean;
	weeks: ConversionWeekPoint[];
	series: string[];
}

/* --- Section 8.4: top pages that don't convert ---------------------- */

export interface ConversionTopPageRow {
	post_id: number;
	page_title: string;
	page_url: string;
	pageviews: number;
	unique_readers: number;
	conversion_rate: number;
}

export interface ConversionTopPagesTable {
	pending: boolean;
	rows: ConversionTopPageRow[];
	threshold_pageviews: number;
}

/* --- Window + response ---------------------------------------------- */

export interface ConversionWindow {
	window: { start: string; end: string };
	// Section 1 — The reader lifecycle.
	reader_lifecycle_funnel: ConversionFunnelData;
	// Section 2 — Per-journey conversion funnels.
	anonymous_to_registered_funnel: ConversionFunnelData;
	registered_to_subscriber_funnel: ConversionFunnelData;
	registered_to_donor_funnel: ConversionFunnelData;
	subscriber_to_donor_funnel: ConversionGatedFunnelData;
	// Section 3 — Where conversions come from.
	source_mix_registrations: ConversionSourceMixData;
	source_mix_subscribers: ConversionSourceMixData;
	source_mix_donors: ConversionSourceMixData;
	// Section 4 — How long conversions take (cumulative LineCharts).
	time_to_register_distribution: ConversionCumulativeSingle;
	time_to_subscribe_distribution: ConversionCumulativeMulti;
	time_to_donate_distribution: ConversionCumulativeMulti;
	subscriber_to_donor_lag_distribution: ConversionGatedCumulativeSingle;
	// Section 5 — Cohort retention (snapshot).
	registration_to_conversion_cohort: ConversionCohortData;
	subscriber_retention_cohort: ConversionCohortData;
	// Section 6 — Conversion rate trends.
	weekly_conversion_rates: ConversionWeeklyTrendsData;
	// Section 7 — Cross-tab influenced attribution (comparison-enabled).
	influenced_registration_rate_7d: ConversionScalarMetric;
	influenced_subscription_rate_14d: ConversionScalarMetric;
	influenced_donation_rate_14d: ConversionScalarMetric;
	influenced_newsletter_rate_7d: ConversionScalarMetric;
	// Section 8 — Opportunity buckets.
	stale_registered_count: ConversionScalarMetric;
	at_risk_subscriber_count: ConversionScalarMetric;
	lapsed_donor_count: ConversionScalarMetric;
	top_pages_no_conversion: ConversionTopPagesTable;
}

export interface ConversionResponse {
	/**
	 * True while Tab 3 is in the Phase 1 placeholder phase. React uses this
	 * to render the top-of-tab banner.
	 */
	tab_pending: boolean;
	current: ConversionWindow;
	previous: ConversionWindow | null;
}

export interface ConversionQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/conversion';

/**
 * Fetch Tab 3 data for the given window pair.
 */
export const fetchConversionData = async ( query: ConversionQuery ): Promise< ConversionResponse > => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return apiFetch< ConversionResponse >( {
		path: `${ ENDPOINT }?${ params.toString() }`,
		method: 'GET',
	} );
};
