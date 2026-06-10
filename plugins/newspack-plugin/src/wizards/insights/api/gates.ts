/**
 * Gates API client (NPPD-1604).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 4
 * endpoint: `GET /newspack-insights/v1/gates`. Type definitions
 * mirror the PHP response shape assembled by `Gates_REST_Controller`.
 *
 * Every metric carries an explicit `state`: 'error' (query failed —
 * with `error_code` / `error_message`), 'empty' (succeeded, no rows),
 * or 'populated'. Scalars use 'error' | 'populated' only. The
 * `tab_error` flag is true only when every section is in the error state.
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

/** Collection metrics report all three states; scalars use 'error' | 'populated'. */
export type GatesMetricState = 'error' | 'empty' | 'populated';

/** Fields present on any metric in the error state. */
export interface GatesErrorFields {
	error_code?: string;
	error_message?: string;
}

/**
 * Standard scorecard metric payload. `state` is 'error' or 'populated'
 * (an absent value is a non-computable zero, not an 'empty' state).
 */
export interface GatesScalarMetric extends GatesErrorFields {
	state: 'error' | 'populated';
	value: number;
	computable: boolean;
	denominator: number | null;
	placeholder_type: GatesPlaceholderType;
}

export interface GatesFunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface GatesFunnelData extends GatesErrorFields {
	state: GatesMetricState;
	stages: GatesFunnelStage[];
}

export interface GatesDistributionBucket {
	label: string;
	count: number;
	pct: number;
}

export interface GatesDistributionData extends GatesErrorFields {
	state: GatesMetricState;
	buckets: GatesDistributionBucket[];
}

/**
 * One row in the Performance by gate table, enriched server-side with each
 * gate's `wp_posts.post_title` keyed on `gate_post_id`.
 */
export interface GatesPerformanceRow {
	gate_post_id: number;
	gate_name: string;
	impressions: number;
	unique_viewers: number;
	registrations: number;
	regwall_conversion_rate: number | null;
	paywall_attempts: number;
	paywall_attempt_rate: number | null;
}

export interface GatesPerformanceTable extends GatesErrorFields {
	state: GatesMetricState;
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
	 * True only when every section in the current window is in the error
	 * state. React renders a tab-level error banner when set.
	 */
	tab_error: boolean;
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
