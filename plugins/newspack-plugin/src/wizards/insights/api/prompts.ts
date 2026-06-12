/**
 * Prompts API client (NPPD-1607).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 5
 * endpoint: `GET /newspack-insights/v1/prompts`. Type definitions
 * mirror the PHP response shape assembled by `Prompts_REST_Controller`.
 *
 * Every metric carries an explicit `state`: 'error' (query failed —
 * with `error_code` / `error_message`), 'empty' (succeeded, no rows),
 * or 'populated'. Scalars use 'error' | 'populated' only. The
 * `tab_error` flag is true only when every section is in the error state.
 *
 * Mirrors `api/gates.ts` (Tab 4) one-for-one. Tab 5 carries four
 * conversion intents instead of two, so it has more scorecards and a
 * three-table performance breakdown.
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
 * The kind of placeholder a metric renders. Encoded server-side so
 * the React format layer doesn't have to guess from the field name.
 */
export type PromptsPlaceholderType = 'count' | 'rate' | 'currency' | 'decimal';

/** Collection metrics report all three states; scalars use 'error' | 'populated'. */
export type PromptsMetricState = 'error' | 'empty' | 'populated';

/** Fields present on any metric in the error state. */
export interface PromptsErrorFields {
	error_code?: string;
	error_message?: string;
}

/**
 * Standard scorecard metric payload. `state` is 'error' or 'populated'
 * (an absent value is a non-computable zero, not an 'empty' state).
 */
export interface PromptsScalarMetric extends PromptsErrorFields {
	state: 'error' | 'populated';
	value: number;
	computable: boolean;
	denominator: number | null;
	placeholder_type: PromptsPlaceholderType;
}

export interface PromptsFunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface PromptsFunnelData extends PromptsErrorFields {
	state: PromptsMetricState;
	stages: PromptsFunnelStage[];
}

export interface PromptsDistributionBucket {
	label: string;
	count: number;
	pct: number;
}

export interface PromptsDistributionData extends PromptsErrorFields {
	state: PromptsMetricState;
	buckets: PromptsDistributionBucket[];
}

/**
 * One row in the Performance by prompt table (Table 7.1). The 15-key
 * schema was locked by Task 3.3 (NPPD-1607). `prompt_title` comes
 * straight from the event params, no WP enrichment needed. Donation /
 * subscription columns report *conversions* (Woo-completed outcomes),
 * not attempts, matching the Gates v1.1 decision (NPPD-1684). Count and
 * rate columns are nullable: a non-applicable cell (e.g. CTR on a
 * button-less prompt, or donation conversions on a registration prompt)
 * renders as an em-dash, distinct from a real 0 / 0%.
 */
export interface PromptsPerformanceByPromptRow {
	popup_id: number;
	prompt_title: string;
	intent: string;
	placement: string;
	impressions: number;
	unique_viewers: number;
	ctr: number | null;
	form_submission_rate: number | null;
	dismissal_rate: number | null;
	registrations: number;
	newsletter_signups: number;
	donation_conversions: number | null;
	donation_conversion_rate: number | null;
	subscription_conversions: number | null;
	subscription_conversion_rate: number | null;
}

/**
 * One row in the Performance by prompt intent table (Table 7.2).
 * Aggregated across all prompts of a given intent.
 */
export interface PromptsPerformanceByIntentRow {
	intent: string;
	impressions: number;
	unique_viewers: number;
	ctr: number | null;
	form_submission_rate: number | null;
	dismissal_rate: number | null;
}

/**
 * One row in the Performance by prompt placement table (Table 7.3).
 * Aggregated across all prompts at a given placement.
 */
export interface PromptsPerformanceByPlacementRow {
	placement: string;
	impressions: number;
	unique_viewers: number;
	ctr: number | null;
	dismissal_rate: number | null;
}

export interface PromptsPerformanceByPromptTable extends PromptsErrorFields {
	state: PromptsMetricState;
	rows: PromptsPerformanceByPromptRow[];
}

export interface PromptsPerformanceByIntentTable extends PromptsErrorFields {
	state: PromptsMetricState;
	rows: PromptsPerformanceByIntentRow[];
}

export interface PromptsPerformanceByPlacementTable extends PromptsErrorFields {
	state: PromptsMetricState;
	rows: PromptsPerformanceByPlacementRow[];
}

export interface PromptsWindow {
	window: { start: string; end: string };
	// Section 1 — Prompt exposure.
	total_prompt_impressions: PromptsScalarMetric;
	unique_readers_reached: PromptsScalarMetric;
	avg_prompts_per_reader: PromptsScalarMetric;
	// Section 2 — Prompt engagement.
	click_through_rate: PromptsScalarMetric;
	form_submission_rate: PromptsScalarMetric;
	dismissal_rate: PromptsScalarMetric;
	// Section 3 — Free reader conversion.
	registration_conversion_direct: PromptsScalarMetric;
	registration_conversion_influenced_7d: PromptsScalarMetric;
	newsletter_signup_conversion_direct: PromptsScalarMetric;
	newsletter_signup_conversion_influenced_7d: PromptsScalarMetric;
	// Section 4 — Paid reader conversion.
	donation_conversion_direct: PromptsScalarMetric;
	donation_conversion_influenced_14d: PromptsScalarMetric;
	subscription_conversion_direct: PromptsScalarMetric;
	subscription_conversion_influenced_14d: PromptsScalarMetric;
	// Section 5 — Revenue from prompts.
	donation_revenue_direct: PromptsScalarMetric;
	donation_revenue_influenced_14d: PromptsScalarMetric;
	subscription_revenue_direct: PromptsScalarMetric;
	subscription_revenue_influenced_14d: PromptsScalarMetric;
	// Section 6 — How readers convert.
	conversion_funnel: PromptsFunnelData;
	exposures_distribution: PromptsDistributionData;
	// Section 7 — Performance breakdown.
	performance_by_prompt: PromptsPerformanceByPromptTable;
	performance_by_intent: PromptsPerformanceByIntentTable;
	performance_by_placement: PromptsPerformanceByPlacementTable;
}

export interface PromptsResponse {
	/**
	 * True only when every section in the current window is in the error
	 * state. React renders a tab-level error banner when set.
	 */
	tab_error: boolean;
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

export interface PromptsQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/prompts';

const queryString = ( query: PromptsQuery ): string => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return params.toString();
};

/**
 * Fetch Tab 5 data for the given window pair.
 */
export const fetchPromptsData = async ( query: PromptsQuery ): Promise< CachedEnvelope< PromptsResponse > > =>
	apiFetch< CachedEnvelope< PromptsResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshPromptsData = async ( query: PromptsQuery ): Promise< CachedEnvelope< PromptsResponse > > =>
	apiFetch< CachedEnvelope< PromptsResponse > >( {
		path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
		method: 'POST',
	} );
