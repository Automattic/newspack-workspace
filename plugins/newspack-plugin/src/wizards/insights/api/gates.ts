/**
 * Gates API client (NPPD-1604, Phase 1).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 4
 * endpoint: `GET /newspack-insights/v1/gates`. Type definitions
 * mirror the PHP response shape assembled by `Gates_REST_Controller`.
 *
 * Phase 1: every metric carries `pending: true` and a zero value.
 * Phase 2 (NPPD-1630) keeps the same shape but flips `pending` to
 * false and surfaces real BQ values; the React layer does not need
 * to know which phase produced a payload — it reads `pending` and
 * the `tab_pending` banner flag.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * The kind of placeholder a metric renders. Encoded server-side so
 * the React format layer doesn't have to guess from the field name.
 */
export type GatesPlaceholderType = 'count' | 'rate' | 'currency' | 'decimal';

/**
 * Standard scorecard metric payload. Carries the value plus the
 * `pending` and `placeholder_type` markers the UI needs to render
 * the Phase 1 zeros in the correct visual format.
 */
export interface GatesScalarMetric {
	value: number;
	computable: boolean;
	pending: boolean;
	denominator: number | null;
	placeholder_type: GatesPlaceholderType;
}

export interface GatesFunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface GatesFunnelData {
	pending: boolean;
	stages: GatesFunnelStage[];
}

export interface GatesDistributionBucket {
	label: string;
	count: number;
	pct: number;
}

export interface GatesDistributionData {
	pending: boolean;
	buckets: GatesDistributionBucket[];
}

/**
 * One row in the Performance by gate table. Phase 1 returns no rows
 * (the section renders the spec's empty-state copy). Phase 2 will
 * populate this server-side with `wp_posts.post_title` enrichment
 * keyed on `gate_post_id`.
 */
export interface GatesPerformanceRow {
	gate_post_id: number;
	gate_name: string;
	impressions: number;
	unique_viewers: number;
	regwall_conversions: number | null;
	regwall_conversion_rate: number | null;
	paywall_conversions: number | null;
	paywall_conversion_rate: number | null;
}

export interface GatesPerformanceTable {
	pending: boolean;
	rows: GatesPerformanceRow[];
}

export interface GatesWindow {
	window: { start: string; end: string };
	// Section 1 — Gate exposure.
	total_gate_impressions: GatesScalarMetric;
	unique_readers_reached: GatesScalarMetric;
	avg_exposures_per_reader: GatesScalarMetric;
	sessions_with_gate: GatesScalarMetric;
	// Section 2 — Free reader conversion.
	regwall_conversion_direct: GatesScalarMetric;
	regwall_conversion_influenced_7d: GatesScalarMetric;
	// Section 3 — Paid reader conversion.
	paywall_conversion_direct: GatesScalarMetric;
	paywall_conversion_influenced_14d: GatesScalarMetric;
	total_paywall_revenue_direct: GatesScalarMetric;
	avg_revenue_per_paywall_conversion: GatesScalarMetric;
	// Section 4 — How readers convert.
	conversion_funnel: GatesFunnelData;
	exposures_distribution: GatesDistributionData;
	// Section 5 — Performance by gate.
	performance_by_gate: GatesPerformanceTable;
}

export interface GatesResponse {
	/**
	 * True while Tab 4 is in the Phase 1 placeholder phase. React
	 * uses this to render the top-of-tab banner.
	 */
	tab_pending: boolean;
	current: GatesWindow;
	previous: GatesWindow | null;
}

export interface GatesQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/gates';

/**
 * Fetch Tab 4 data for the given window pair.
 */
export const fetchGatesData = async ( query: GatesQuery ): Promise< GatesResponse > => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return apiFetch< GatesResponse >( {
		path: `${ ENDPOINT }?${ params.toString() }`,
		method: 'GET',
	} );
};
