/**
 * Tests for FreeReaderConversionSection's not-capable treatment (NPPD-1720):
 * the registration and newsletter cards render their block-scoped nudge copy
 * when the envelope marks that intent incapable, and render normally otherwise.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import FreeReaderConversionSection from './FreeReaderConversionSection';
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

describe( 'FreeReaderConversionSection not-capable treatment (NPPD-1720)', () => {
	it( 'shows the registration nudge on both registration cards when that intent is incapable', () => {
		const current = makeWindow( {
			registration_conversion_direct: scalar( { has_capability: false } ),
			registration_conversion_influenced_7d: scalar( { has_capability: false } ),
			newsletter_signup_conversion_direct: scalar( { has_capability: true } ),
			newsletter_signup_conversion_influenced_7d: scalar( { has_capability: true } ),
		} );
		render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( screen.getAllByText( NOT_CAPABLE_COPY.registration ) ).toHaveLength( 2 );
		// Newsletter cards are capable → no newsletter nudge.
		expect( screen.queryByText( NOT_CAPABLE_COPY.newsletter ) ).not.toBeInTheDocument();
	} );

	it( 'shows the newsletter nudge on both newsletter cards when that intent is incapable', () => {
		const current = makeWindow( {
			newsletter_signup_conversion_direct: scalar( { has_capability: false } ),
			newsletter_signup_conversion_influenced_7d: scalar( { has_capability: false } ),
		} );
		render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( screen.getAllByText( NOT_CAPABLE_COPY.newsletter ) ).toHaveLength( 2 );
		// Registration cards default to capable (no flag) → no registration nudge.
		expect( screen.queryByText( NOT_CAPABLE_COPY.registration ) ).not.toBeInTheDocument();
	} );
} );
