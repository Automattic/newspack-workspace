/**
 * Tests for CrossTabInfluencedAttributionSection (Section 7).
 *
 * Scalar metrics map through scalarToMetricCardProps, which uses the metric's
 * `state` envelope: 'error' renders the MetricCard error/MetricNote treatment
 * (not a misleading "0"), 'coming_soon' renders the pending placeholder, and
 * 'populated' renders the computed value.
 * Covers heading, all four cards, comparison window, and the error path.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import type { ConversionScalarMetric } from '../../api/conversion';
import CrossTabInfluencedAttributionSection from './CrossTabInfluencedAttributionSection';
import { makeConversionWindow } from './fixtures';

const errorScalar = (): ConversionScalarMetric => ( {
	state: 'error',
	value: 0,
	computable: false,
	denominator: null,
	placeholder_type: 'rate',
	data_missing: false,
} );

describe( 'CrossTabInfluencedAttributionSection', () => {
	it( 'renders the heading and the four influenced-rate scorecards', () => {
		render( <CrossTabInfluencedAttributionSection current={ makeConversionWindow() } previous={ null } /> );
		expect( screen.getByRole( 'heading', { name: 'Cross-Tab Influenced Attribution' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Influenced Registration Rate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Influenced Subscription Rate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Influenced Donation Rate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Influenced Newsletter Signup Rate' ) ).toBeInTheDocument();
	} );

	it( 'renders with a comparison window provided', () => {
		render( <CrossTabInfluencedAttributionSection current={ makeConversionWindow() } previous={ makeConversionWindow() } /> );
		expect( screen.getByText( 'Influenced Subscription Rate' ) ).toBeInTheDocument();
	} );

	it( 'renders the MetricNote error treatment (not "0") when a scalar has state error', () => {
		const current = {
			...makeConversionWindow(),
			influenced_registration_rate_7d: errorScalar(),
		};
		render( <CrossTabInfluencedAttributionSection current={ current } previous={ null } /> );
		// MetricNote renders "Data temporarily unavailable." for the error prop.
		expect( screen.getByText( 'Data temporarily unavailable.' ) ).toBeInTheDocument();
		// The card must NOT fall through to showing a plain "0".
		expect( screen.queryByText( '0' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the data-missing note when an influenced scalar is missing required data', () => {
		const current = makeConversionWindow();
		current.influenced_subscription_rate_14d = {
			state: 'populated',
			value: 0,
			computable: false,
			denominator: null,
			placeholder_type: 'rate',
			data_missing: true,
		};
		render( <CrossTabInfluencedAttributionSection current={ current } previous={ null } /> );
		expect( screen.getByText( 'Some data could not be loaded.' ) ).toBeInTheDocument();
	} );
} );
