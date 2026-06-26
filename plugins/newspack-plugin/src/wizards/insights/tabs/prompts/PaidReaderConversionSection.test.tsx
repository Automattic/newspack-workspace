/**
 * Tests for PaidReaderConversionSection's not-capable treatment (NPPD-1720):
 * the donation cards render the donation nudge (which addresses the hand-rolled
 * CTA case) and the subscription cards render the checkout-button nudge when
 * those intents are incapable.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PaidReaderConversionSection from './PaidReaderConversionSection';
import { NOT_CAPABLE_COPY } from './notCapableCopy';
import type { PromptsScalarMetric, PromptsWindow } from '../../api/prompts';

const scalar = ( over: Partial< PromptsScalarMetric > = {} ): PromptsScalarMetric => ( {
	state: 'populated',
	value: 0,
	computable: false,
	denominator: null,
	placeholder_type: 'rate',
	data_missing: false,
	...over,
} );

const makeWindow = ( over: Partial< PromptsWindow > = {} ): PromptsWindow => ( {
	window: { start: '2026-05-09', end: '2026-06-08' },
	total_prompt_impressions: scalar( { placeholder_type: 'count' } ),
	unique_readers_reached: scalar( { placeholder_type: 'count' } ),
	avg_prompts_per_reader: scalar( { placeholder_type: 'decimal' } ),
	click_through_rate: scalar(),
	form_submission_rate: scalar(),
	dismissal_rate: scalar(),
	registration_conversion_direct: scalar(),
	registration_conversion_influenced_7d: scalar(),
	newsletter_signup_conversion_direct: scalar(),
	newsletter_signup_conversion_influenced_7d: scalar(),
	donation_conversion_direct: scalar(),
	donation_conversion_influenced_14d: scalar(),
	subscription_conversion_direct: scalar(),
	subscription_conversion_influenced_14d: scalar(),
	donation_revenue_direct: scalar( { placeholder_type: 'currency' } ),
	donation_revenue_influenced_14d: scalar( { placeholder_type: 'currency' } ),
	subscription_revenue_direct: scalar( { placeholder_type: 'currency' } ),
	subscription_revenue_influenced_14d: scalar( { placeholder_type: 'currency' } ),
	conversion_funnel: { state: 'empty', stages: [] },
	exposures_distribution: { state: 'empty', buckets: [] },
	performance_by_prompt: { state: 'empty', rows: [] },
	performance_by_intent: { state: 'empty', rows: [] },
	performance_by_placement: { state: 'empty', rows: [] },
	...over,
} );

describe( 'PaidReaderConversionSection not-capable treatment (NPPD-1720)', () => {
	it( 'shows the donation nudge on both donation cards when that intent is incapable', () => {
		const current = makeWindow( {
			donation_conversion_direct: scalar( { has_capability: false } ),
			donation_conversion_influenced_14d: scalar( { has_capability: false } ),
			subscription_conversion_direct: scalar( { has_capability: true } ),
			subscription_conversion_influenced_14d: scalar( { has_capability: true } ),
		} );
		render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( screen.getAllByText( NOT_CAPABLE_COPY.donation ) ).toHaveLength( 2 );
		expect( screen.queryByText( NOT_CAPABLE_COPY.checkout ) ).not.toBeInTheDocument();
	} );

	it( 'shows the checkout nudge on both subscription cards when that intent is incapable', () => {
		const current = makeWindow( {
			subscription_conversion_direct: scalar( { has_capability: false } ),
			subscription_conversion_influenced_14d: scalar( { has_capability: false } ),
		} );
		render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( screen.getAllByText( NOT_CAPABLE_COPY.checkout ) ).toHaveLength( 2 );
		expect( screen.queryByText( NOT_CAPABLE_COPY.donation ) ).not.toBeInTheDocument();
	} );
} );
