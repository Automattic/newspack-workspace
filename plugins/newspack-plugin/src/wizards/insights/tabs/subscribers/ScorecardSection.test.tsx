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

describe( 'Subscribers ScorecardSection — at-a-glance snapshot cards', () => {
	it( 'renders the modeled CLV and newsletter-conversion cards when computable', () => {
		render( <ScorecardSection snapshot={ makeSnapshot() } /> );
		expect( screen.getByText( '3-year supporter value' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Newsletter → subscription' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not enough subscription history to model yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the CLV card as an em-dash with explanatory copy when not computable', () => {
		render( <ScorecardSection snapshot={ makeSnapshot( { supporter_clv_3yr: { value: 0, computable: false, denominator: 0 } } ) } /> );
		expect( screen.getByText( '3-year supporter value' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough subscription history to model yet.' ) ).toBeInTheDocument();
	} );

	it( 'renders the newsletter-conversion card as an em-dash when the cohort is not yet mature', () => {
		render( <ScorecardSection snapshot={ makeSnapshot( { newsletter_conversion: { value: 0, computable: false, denominator: 0 } } ) } /> );
		expect( screen.getByText( 'Newsletter → subscription' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough newsletter signups with a full year of history yet.' ) ).toBeInTheDocument();
	} );

	it( 'shows an error state (not the insufficient-history copy) when the hub proxy failed', () => {
		render(
			<ScorecardSection
				snapshot={ makeSnapshot( { newsletter_conversion: { value: 0, computable: false, denominator: 0, state: 'error' } } ) }
			/>
		);
		expect( screen.getByText( 'Newsletter → subscription' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not enough newsletter signups with a full year of history yet.' ) ).not.toBeInTheDocument();
	} );
} );
