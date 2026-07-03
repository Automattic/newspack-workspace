/**
 * Conversion Journey API client (NPPD-1609, Phase 2).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 3
 * endpoint: `GET /newspack-insights/v1/conversion`. Type definitions
 * mirror the PHP response shape assembled by `Conversion_REST_Controller`.
 *
 * Phase 2: every metric carries an explicit `state`:
 *   - 'populated'   — query succeeded with data.
 *   - 'empty'       — query succeeded, no rows in window.
 *   - 'error'       — query failed (with `error_code` / `error_message`).
 *   - 'coming_soon' — Phase B deferred metric; React renders a placeholder.
 *
 * The `tab_error` flag is true only when every section in the current window
 * is in the error state. React renders a tab-level error banner when set.
 *
 * Response is wrapped in the shared `CachedEnvelope` shape:
 *   `{ data: ConversionResponse, cache: { source, computed_at, cooldown_until } }`.
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
 * Internal dependencies
 */
import { type CachedEnvelope } from '../state/insightsCache';

/**
 * The kind of placeholder a scalar metric renders. Encoded server-side so
 * the React format layer doesn't have to guess from the field name. Tab 3
 * uses 'count', 'rate', and 'decimal' (no currency metrics in v1); the full
 * union is kept for parity with the shared scalar shape.
 */
export type ConversionPlaceholderType = 'count' | 'rate' | 'currency' | 'decimal';

/**
 * Visibility gate for Sections 2.4 and 4.4. Hidden when cohort size is
 * below threshold; visible when the live cohort sizes meet the threshold.
 */
export type ConversionVisibility = 'hidden' | 'visible';
// 'insufficient_data' — cohort below threshold (2.4 / 4.4 cross-upsell gates).
// 'not_configured' — the conversion stream isn't a reader-revenue product for
// this publisher (NPPD-1742 config matrix: subscription / donation legs).
export type ConversionVisibilityReason = 'insufficient_data' | 'not_configured' | null;

/**
 * Collection metrics report all four states; scalars use
 * 'error' | 'populated' (an absent value is a non-computable zero,
 * not an 'empty' state).
 */
export type ConversionMetricState = 'error' | 'empty' | 'populated' | 'coming_soon';

/** Fields present on any metric in the error state. */
export interface ConversionErrorFields {
	error_code?: string;
	error_message?: string;
}

/**
 * Standard scorecard metric payload (Sections 7 and 8.1–8.3).
 * `state` is 'error' | 'populated' | 'coming_soon' (scalars have no 'empty' state).
 * `data_missing` flags a non-computable zero caused by a hub row missing required
 * column(s) (schema drift) so the card can warn instead of showing a misleading zero.
 */
export interface ConversionScalarMetric extends ConversionErrorFields {
	state: 'error' | 'populated' | 'coming_soon';
	value: number;
	computable: boolean;
	denominator: number | null;
	placeholder_type: ConversionPlaceholderType;
	data_missing: boolean;
}

/* --- Section 1 / 2: funnels ----------------------------------------- */

export interface ConversionFunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface ConversionFunnelData extends ConversionErrorFields {
	state: ConversionMetricState;
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

export interface ConversionSourceMixData extends ConversionErrorFields {
	state: ConversionMetricState;
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
export interface ConversionCumulativeSingle extends ConversionErrorFields {
	state: ConversionMetricState;
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
export interface ConversionCumulativeMulti extends ConversionErrorFields {
	state: ConversionMetricState;
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

export interface ConversionCohortData extends ConversionErrorFields {
	state: ConversionMetricState;
	cohorts: ConversionCohortSeries[];
	reference_line: ConversionReferenceLine | null;
}

/* --- Section 6: weekly conversion-rate trends ----------------------- */

export interface ConversionWeekPoint {
	week: string;
	registration_conversion_rate: number;
	subscription_conversion_rate: number;
}

export interface ConversionWeeklyTrendsData extends ConversionErrorFields {
	state: ConversionMetricState;
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

export interface ConversionTopPagesTable extends ConversionErrorFields {
	state: ConversionMetricState;
	rows: ConversionTopPageRow[];
	threshold_pageviews: number;
}

/* --- Window + response ---------------------------------------------- */

export interface ConversionWindow {
	window: { start: string; end: string };
	// Section 1 — The reader lifecycle.
	reader_lifecycle_funnel: ConversionFunnelData;
	// Section 2 — Per-journey conversion funnels.
	// The registration leg is always visible (no config gate), but carries the
	// same gated shape so it can use the shared empty-state cell (NPPD-1743):
	// `visibility: 'visible'`, `visibility_reason: null`.
	anonymous_to_registered_funnel: ConversionGatedFunnelData;
	// Subscription / donation legs carry the config-matrix visibility gate
	// (NPPD-1742): `visibility: 'hidden'` + reason `'not_configured'` when the
	// publisher doesn't run that reader-revenue stream.
	registered_to_subscriber_funnel: ConversionGatedFunnelData;
	registered_to_donor_funnel: ConversionGatedFunnelData;
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
	 * True only when every section in the current window is in the error
	 * state. React renders a tab-level error banner when set.
	 */
	tab_error: boolean;
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

const queryString = ( query: ConversionQuery ): string => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	// Forward the `_fixture_state` URL param so fixture mode's render variants
	// (empty / error / conversion_registrations_only) are reachable from the UI
	// for smoke testing. A no-op in production: the server ignores it unless
	// NEWSPACK_INSIGHTS_FIXTURE_MODE is enabled. Mirrors api/gates.ts.
	if ( typeof window !== 'undefined' ) {
		const fixtureState = new URLSearchParams( window.location.search ).get( '_fixture_state' );
		if ( fixtureState ) {
			params.set( '_fixture_state', fixtureState );
		}
	}
	return params.toString();
};

/**
 * Fetch Tab 3 data for the given window pair.
 */
export const fetchConversionData = async ( query: ConversionQuery ): Promise< CachedEnvelope< ConversionResponse > > =>
	apiFetch< CachedEnvelope< ConversionResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshConversionData = async ( query: ConversionQuery ): Promise< CachedEnvelope< ConversionResponse > > =>
	apiFetch< CachedEnvelope< ConversionResponse > >( {
		path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
		method: 'POST',
	} );
