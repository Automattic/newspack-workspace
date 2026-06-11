/**
 * Phase 1 placeholder fixtures for Tab 3 (Conversion Journey) React tests.
 *
 * Mirrors the shape `Conversion_REST_Controller` assembles — every metric
 * `pending: true` with zero / empty values — so section render tests exercise
 * the exact payload the tab receives in Phase 1. Not a `*.test.*` file, so it
 * is never collected as a suite.
 */

import type {
	ConversionCohortData,
	ConversionCumulativeMulti,
	ConversionFunnelData,
	ConversionGatedCumulativeSingle,
	ConversionGatedFunnelData,
	ConversionScalarMetric,
	ConversionSourceMixData,
	ConversionTopPagesTable,
	ConversionVisibility,
	ConversionWeeklyTrendsData,
	ConversionWindow,
	ConversionCumulativeSingle,
	ConversionPlaceholderType,
} from '../../api/conversion';

const scalar = ( placeholder_type: ConversionPlaceholderType ): ConversionScalarMetric => ( {
	value: placeholder_type === 'decimal' ? 0.0 : 0,
	computable: false,
	pending: true,
	denominator: null,
	placeholder_type,
} );

const funnel = ( ...labels: string[] ): ConversionFunnelData => ( {
	pending: true,
	stages: labels.map( label => ( { label, count: 0, pct_of_top: 0 } ) ),
} );

const gatedFunnel = ( visibility: ConversionVisibility, ...labels: string[] ): ConversionGatedFunnelData => ( {
	...funnel( ...labels ),
	visibility,
	visibility_reason: visibility === 'hidden' ? 'insufficient_data' : null,
} );

const sourceMix = (): ConversionSourceMixData => ( {
	pending: true,
	total: 0,
	slices: [
		{ source: 'gate', count: 0, pct: 0 },
		{ source: 'prompt', count: 0, pct: 0 },
		{ source: 'direct', count: 0, pct: 0 },
	],
} );

const cumulativeSingle = (): ConversionCumulativeSingle => ( { pending: true, points: [] } );

const gatedCumulativeSingle = ( visibility: ConversionVisibility ): ConversionGatedCumulativeSingle => ( {
	pending: true,
	points: [],
	visibility,
	visibility_reason: visibility === 'hidden' ? 'insufficient_data' : null,
} );

const cumulativeMulti = (): ConversionCumulativeMulti => ( {
	pending: true,
	groups: [
		{ label: 'gate', points: [] },
		{ label: 'prompt', points: [] },
		{ label: 'direct', points: [] },
	],
} );

const cohort = ( value: number, label: string ): ConversionCohortData => ( {
	pending: true,
	cohorts: [],
	reference_line: { value, label },
} );

const weekly = (): ConversionWeeklyTrendsData => ( {
	pending: true,
	weeks: [],
	series: [ 'registration_rate', 'subscription_attempt_rate' ],
} );

const topPages = (): ConversionTopPagesTable => ( { pending: true, rows: [], threshold_pageviews: 100 } );

export interface ConversionWindowOverrides {
	crossUpsellVisibility?: ConversionVisibility;
	lagVisibility?: ConversionVisibility;
}

/** Build a full Phase 1 placeholder window, with optional visibility overrides. */
export const makeConversionWindow = ( overrides: ConversionWindowOverrides = {} ): ConversionWindow => ( {
	window: { start: '2026-03-22', end: '2026-04-21' },
	reader_lifecycle_funnel: funnel( 'Anonymous reader', 'Engaged reader', 'Registered reader', 'Newsletter subscriber', 'Subscriber or donor' ),
	anonymous_to_registered_funnel: funnel( 'Anonymous', 'Saw a conversion surface', 'Registered' ),
	registered_to_subscriber_funnel: funnel( 'Registered', 'Saw a subscription-intent surface', 'Became subscriber' ),
	registered_to_donor_funnel: funnel( 'Registered', 'Saw a donation-intent surface', 'Became donor' ),
	subscriber_to_donor_funnel: gatedFunnel( overrides.crossUpsellVisibility ?? 'hidden', 'Active subscriber', 'Also donor' ),
	source_mix_registrations: sourceMix(),
	source_mix_subscribers: sourceMix(),
	source_mix_donors: sourceMix(),
	time_to_register_distribution: cumulativeSingle(),
	time_to_subscribe_distribution: cumulativeMulti(),
	time_to_donate_distribution: cumulativeMulti(),
	subscriber_to_donor_lag_distribution: gatedCumulativeSingle( overrides.lagVisibility ?? 'hidden' ),
	registration_to_conversion_cohort: cohort( 0.15, '15% at 6 months' ),
	subscriber_retention_cohort: cohort( 0.7, '70% at 12 months' ),
	weekly_conversion_rates: weekly(),
	influenced_registration_rate_7d: scalar( 'rate' ),
	influenced_subscription_rate_14d: scalar( 'rate' ),
	influenced_donation_rate_14d: scalar( 'rate' ),
	influenced_newsletter_rate_7d: scalar( 'rate' ),
	stale_registered_count: scalar( 'count' ),
	at_risk_subscriber_count: scalar( 'count' ),
	lapsed_donor_count: scalar( 'count' ),
	top_pages_no_conversion: topPages(),
} );
