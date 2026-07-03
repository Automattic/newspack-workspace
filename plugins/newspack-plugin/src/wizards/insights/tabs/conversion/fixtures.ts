/**
 * Phase 2 fixtures for Tab 3 (Conversion Journey) React tests.
 *
 * Mirrors the shape `Conversion_REST_Controller` assembles — the default
 * `makeConversionWindow()` carries `state: 'coming_soon'` for Phase-B metrics
 * (4.2, 4.3, 4.4, 5.1, 5.2) and `state: 'populated'` for Phase-A metrics.
 *
 * Helper overrides let individual tests exercise 'empty' and 'error' states
 * on any collection metric.
 *
 * Not a `*.test.*` file, so it is never collected as a suite.
 */

import type {
	ConversionCohortData,
	ConversionCumulativeMulti,
	ConversionFunnelData,
	ConversionGatedCumulativeSingle,
	ConversionGatedFunnelData,
	ConversionMetricState,
	ConversionReferenceLine,
	ConversionScalarMetric,
	ConversionSourceMixData,
	ConversionTopPagesTable,
	ConversionVisibility,
	ConversionVisibilityReason,
	ConversionWeeklyTrendsData,
	ConversionWindow,
	ConversionCumulativeSingle,
	ConversionPlaceholderType,
} from '../../api/conversion';

const scalar = (
	placeholder_type: ConversionPlaceholderType,
	state: 'error' | 'populated' | 'coming_soon' = 'coming_soon'
): ConversionScalarMetric => ( {
	state,
	value: placeholder_type === 'decimal' ? 0.0 : 0,
	computable: state === 'populated',
	denominator: null,
	placeholder_type,
	data_missing: false,
} );

const funnel = ( state: ConversionMetricState, ...labels: string[] ): ConversionFunnelData => ( {
	state,
	stages: labels.map( label => ( { label, count: 0, pct_of_top: 0 } ) ),
} );

const gatedFunnel = ( visibility: ConversionVisibility, state: ConversionMetricState, ...labels: string[] ): ConversionGatedFunnelData => ( {
	...funnel( state, ...labels ),
	visibility,
	visibility_reason: visibility === 'hidden' ? 'insufficient_data' : null,
} );

/**
 * A config-matrix conversion leg (NPPD-1742) — Section 2.2 / 2.3, and the
 * always-visible registration leg (NPPD-1743). Builds a three-stage gated funnel
 * with controllable entry/prior/conversion counts so tests can drive the
 * funnel-shaped states: entry 0 → no_opportunity; entry > 0 & conversion 0 →
 * no_conversions; all > 0 → normal. `visibility: 'hidden'` models an
 * unconfigured stream (reason `'not_configured'`). `labels` overrides the stage
 * labels for legs other than subscription/donation.
 */
export const conversionLeg = (
	opts: {
		visibility?: ConversionVisibility;
		state?: ConversionMetricState;
		entry?: number;
		prior?: number;
		conversion?: number;
		reason?: ConversionVisibilityReason;
		labels?: [ string, string, string ];
	} = {}
): ConversionGatedFunnelData => {
	const {
		visibility = 'visible',
		state = 'populated',
		entry = 1000,
		prior = 400,
		conversion = 80,
		reason,
		labels = [ 'Registered', 'Saw a conversion-intent surface', 'Converted' ],
	} = opts;
	const top = entry > 0 ? entry : 1;
	return {
		state,
		stages:
			state === 'populated'
				? [
						{ label: labels[ 0 ], count: entry, pct_of_top: entry / top },
						{ label: labels[ 1 ], count: prior, pct_of_top: prior / top },
						{ label: labels[ 2 ], count: conversion, pct_of_top: conversion / top },
				  ]
				: [],
		visibility,
		visibility_reason: reason ?? ( visibility === 'hidden' ? 'not_configured' : null ),
	};
};

const sourceMix = ( state: ConversionMetricState = 'coming_soon' ): ConversionSourceMixData => ( {
	state,
	total: 0,
	slices: [
		{ source: 'gate', count: 0, pct: 0 },
		{ source: 'prompt', count: 0, pct: 0 },
		{ source: 'direct', count: 0, pct: 0 },
	],
} );

const cumulativeSingle = ( state: ConversionMetricState = 'coming_soon' ): ConversionCumulativeSingle => ( { state, points: [] } );

const gatedCumulativeSingle = (
	visibility: ConversionVisibility,
	state: ConversionMetricState = 'coming_soon'
): ConversionGatedCumulativeSingle => ( {
	state,
	points: [],
	visibility,
	visibility_reason: visibility === 'hidden' ? 'insufficient_data' : null,
} );

const cumulativeMulti = ( state: ConversionMetricState = 'coming_soon' ): ConversionCumulativeMulti => ( {
	state,
	groups: [
		{ label: 'gate', points: [] },
		{ label: 'prompt', points: [] },
		{ label: 'direct', points: [] },
	],
} );

const cohort = ( referenceLine: ConversionReferenceLine | null, state: ConversionMetricState = 'coming_soon' ): ConversionCohortData => ( {
	state,
	cohorts: [],
	reference_line: referenceLine,
} );

const weekly = ( state: ConversionMetricState = 'coming_soon' ): ConversionWeeklyTrendsData => ( {
	state,
	weeks: [],
	series: [ 'registration_rate', 'subscription_conversion_rate' ],
} );

const topPages = ( state: ConversionMetricState = 'coming_soon' ): ConversionTopPagesTable => ( { state, rows: [], threshold_pageviews: 100 } );

export interface ConversionWindowOverrides {
	crossUpsellVisibility?: ConversionVisibility;
	lagVisibility?: ConversionVisibility;
	/** Config-matrix visibility for the subscription leg (2.2), NPPD-1742. */
	subscriptionVisibility?: ConversionVisibility;
	/** Config-matrix visibility for the donation leg (2.3), NPPD-1742. */
	donationVisibility?: ConversionVisibility;
	/** Full override of the registration leg (for the funnel-shaped states), NPPD-1743. */
	anonymousToRegisteredFunnel?: ConversionGatedFunnelData;
	/** Full override of the subscription leg (for the funnel-shaped states). */
	registeredToSubscriberFunnel?: ConversionGatedFunnelData;
	/** Full override of the donation leg (for the funnel-shaped states). */
	registeredToDonorFunnel?: ConversionGatedFunnelData;
	/** Override the lifecycle funnel state (Section 1). */
	lifecycleState?: ConversionMetricState;
	/** Override the source-mix state (Section 3, all three pies). */
	sourceMixState?: ConversionMetricState;
	/** Override the time-to-register distribution state (Section 4.1). */
	timeToRegisterState?: ConversionMetricState;
	/** Override the weekly-trends state (Section 6). */
	weeklyTrendsState?: ConversionMetricState;
	/** Override the cohort retention states (Section 5.1 and 5.2). */
	cohortState?: ConversionMetricState;
	/** Override the top-pages table state (Section 8.4). */
	topPagesState?: ConversionMetricState;
}

/** Build a full Phase 2 window, with optional state and visibility overrides. */
export const makeConversionWindow = ( overrides: ConversionWindowOverrides = {} ): ConversionWindow => ( {
	window: { start: '2026-03-22', end: '2026-04-21' },
	// Section 1 — Phase A, wired.
	reader_lifecycle_funnel: funnel(
		overrides.lifecycleState ?? 'populated',
		'Anonymous reader',
		'Engaged reader',
		'Registered reader',
		'Newsletter subscriber',
		'Subscriber or donor'
	),
	// Section 2 — Phase A, wired. Registration leg is always visible (NPPD-1743)
	// and carries the gated shape so it can use the shared empty-state cell.
	anonymous_to_registered_funnel:
		overrides.anonymousToRegisteredFunnel ??
		conversionLeg( { entry: 5000, prior: 1800, conversion: 600, labels: [ 'Anonymous', 'Saw a conversion surface', 'Registered' ] } ),
	registered_to_subscriber_funnel:
		overrides.registeredToSubscriberFunnel ?? conversionLeg( { visibility: overrides.subscriptionVisibility ?? 'visible' } ),
	registered_to_donor_funnel: overrides.registeredToDonorFunnel ?? conversionLeg( { visibility: overrides.donationVisibility ?? 'visible' } ),
	subscriber_to_donor_funnel: gatedFunnel( overrides.crossUpsellVisibility ?? 'hidden', 'populated', 'Active subscriber', 'Also donor' ),
	// Section 3 — Phase A, wired.
	source_mix_registrations: sourceMix( overrides.sourceMixState ?? 'populated' ),
	source_mix_subscribers: sourceMix( overrides.sourceMixState ?? 'populated' ),
	source_mix_donors: sourceMix( overrides.sourceMixState ?? 'populated' ),
	// Section 4 — 4.1 Phase A; 4.2, 4.3, 4.4 Phase B coming_soon.
	time_to_register_distribution: cumulativeSingle( overrides.timeToRegisterState ?? 'populated' ),
	time_to_subscribe_distribution: cumulativeMulti( 'coming_soon' ),
	time_to_donate_distribution: cumulativeMulti( 'coming_soon' ),
	subscriber_to_donor_lag_distribution: gatedCumulativeSingle( overrides.lagVisibility ?? 'hidden', 'coming_soon' ),
	// Section 5 — Phase B coming_soon.
	registration_to_conversion_cohort: cohort( null, overrides.cohortState ?? 'coming_soon' ),
	subscriber_retention_cohort: cohort( { value: 0.7, label: '70% at 12 months' }, overrides.cohortState ?? 'coming_soon' ),
	// Section 6 — Phase A, wired.
	weekly_conversion_rates: weekly( overrides.weeklyTrendsState ?? 'populated' ),
	// Section 7 — Phase A, wired (scalar).
	influenced_registration_rate_7d: scalar( 'rate', 'populated' ),
	influenced_subscription_rate_14d: scalar( 'rate', 'populated' ),
	influenced_donation_rate_14d: scalar( 'rate', 'populated' ),
	influenced_newsletter_rate_7d: scalar( 'rate', 'populated' ),
	// Section 8 — Phase A, wired (scalar + table).
	stale_registered_count: scalar( 'count', 'populated' ),
	at_risk_subscriber_count: scalar( 'count', 'populated' ),
	lapsed_donor_count: scalar( 'count', 'populated' ),
	top_pages_no_conversion: topPages( overrides.topPagesState ?? 'populated' ),
} );
