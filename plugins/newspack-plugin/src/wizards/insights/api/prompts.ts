/**
 * Prompts API client (NPPD-1607, Phase 1).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 5
 * endpoint: `GET /newspack-insights/v1/prompts`. Type definitions
 * mirror the PHP response shape assembled by `Prompts_REST_Controller`.
 *
 * Phase 1: every metric carries `pending: true` and a zero value.
 * Phase 2 keeps the same shape but flips `pending` to false and
 * surfaces real BQ values; the React layer does not need to know
 * which phase produced a payload — it reads `pending` and the
 * `tab_pending` banner flag.
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
 * The kind of placeholder a metric renders. Encoded server-side so
 * the React format layer doesn't have to guess from the field name.
 */
export type PromptsPlaceholderType = 'count' | 'rate' | 'currency' | 'decimal';

/**
 * Standard scorecard metric payload. Carries the value plus the
 * `pending` and `placeholder_type` markers the UI needs to render
 * the Phase 1 zeros in the correct visual format.
 */
export interface PromptsScalarMetric {
	value: number;
	computable: boolean;
	pending: boolean;
	denominator: number | null;
	placeholder_type: PromptsPlaceholderType;
}

export interface PromptsFunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface PromptsFunnelData {
	pending: boolean;
	stages: PromptsFunnelStage[];
}

export interface PromptsDistributionBucket {
	label: string;
	count: number;
	pct: number;
}

export interface PromptsDistributionData {
	pending: boolean;
	buckets: PromptsDistributionBucket[];
}

/**
 * One row in the Performance by prompt table (Table 7.1). Phase 1
 * returns no rows (the section renders the spec's empty-state copy).
 * Phase 2 populates this from BQ — `prompt_title` comes straight from
 * the event params, no WP enrichment needed. Donation / subscription
 * columns are *attempts* in v1; completion columns are a v1.1
 * candidate. Rate columns are nullable: a button-less prompt has no
 * CTR, which renders as an em-dash (distinct from a real 0%).
 */
export interface PromptsPerformanceByPromptRow {
	newspack_popup_id: number;
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
	donation_attempts: number;
	subscription_attempts: number;
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

export interface PromptsPerformanceByPromptTable {
	pending: boolean;
	rows: PromptsPerformanceByPromptRow[];
}

export interface PromptsPerformanceByIntentTable {
	pending: boolean;
	rows: PromptsPerformanceByIntentRow[];
}

export interface PromptsPerformanceByPlacementTable {
	pending: boolean;
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
	 * True while Tab 5 is in the Phase 1 placeholder phase. React
	 * uses this to render the top-of-tab banner.
	 */
	tab_pending: boolean;
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

/**
 * Fetch Tab 5 data for the given window pair.
 */
export const fetchPromptsData = async ( query: PromptsQuery ): Promise< PromptsResponse > => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return apiFetch< PromptsResponse >( {
		path: `${ ENDPOINT }?${ params.toString() }`,
		method: 'GET',
	} );
};
