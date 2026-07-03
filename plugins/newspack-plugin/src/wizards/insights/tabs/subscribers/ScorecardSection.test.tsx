/**
 * Tests for the Subscribers ScorecardSection — focused on the NEWS-2603
 * 3-year supporter CLV card's computable / not-computable rendering.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ScorecardSection from './ScorecardSection';
import type { SubscribersSnapshot } from '../../api/subscribers';

const makeSnapshot = ( over: Partial< SubscribersSnapshot > = {} ): SubscribersSnapshot => ( {
	active_subscribers: 200,
	mrr: 1000,
	arr: 12000,
	tenure_distribution: [],
	upcoming_renewals_30d: { count: 5, total_value: 250 },
	upcoming_cancellations_30d: { count: 1, total_value: 20 },
	newsletter_conversion: { value: 0.05, computable: true, denominator: 100 },
	supporter_clv_3yr: { value: 185.44, computable: true, denominator: 200 },
	...over,
} );

describe( 'Subscribers ScorecardSection — 3-year supporter value card', () => {
	it( 'renders the modeled CLV card when computable', () => {
		render( <ScorecardSection snapshot={ makeSnapshot() } /> );
		expect( screen.getByText( '3-year supporter value' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not enough subscription history to model yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'renders an em-dash with explanatory copy when not computable', () => {
		render( <ScorecardSection snapshot={ makeSnapshot( { supporter_clv_3yr: { value: 0, computable: false, denominator: 0 } } ) } /> );
		expect( screen.getByText( '3-year supporter value' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough subscription history to model yet.' ) ).toBeInTheDocument();
	} );
} );
