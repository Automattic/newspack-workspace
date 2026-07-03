/**
 * Tests for the Donors ScorecardSection — focused on the NEWS-2603 3-year
 * supporter CLV card's computable / not-computable rendering.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ScorecardSection from './ScorecardSection';
import type { DonorsSnapshot } from '../../api/donors';

const makeSnapshot = ( over: Partial< DonorsSnapshot > = {} ): DonorsSnapshot => ( {
	active_donors: 300,
	active_recurring_donors: 180,
	donation_mrr: 900,
	donation_arr: 10800,
	upcoming_donation_renewals_30d: { count: 4, total_value: 200 },
	upcoming_donation_cancellations_30d: { count: 2, total_value: 40 },
	newsletter_conversion: { value: 0.04, computable: true, denominator: 100 },
	supporter_clv_3yr: { value: 152.0, computable: true, denominator: 180 },
	...over,
} );

describe( 'Donors ScorecardSection — at-a-glance snapshot cards', () => {
	it( 'renders the modeled CLV and newsletter-conversion cards when computable', () => {
		render( <ScorecardSection snapshot={ makeSnapshot() } /> );
		expect( screen.getByText( '3-year supporter value' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Newsletter → donation' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not enough recurring-donor history to model yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the CLV card as an em-dash with explanatory copy when not computable', () => {
		render( <ScorecardSection snapshot={ makeSnapshot( { supporter_clv_3yr: { value: 0, computable: false, denominator: 0 } } ) } /> );
		expect( screen.getByText( '3-year supporter value' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough recurring-donor history to model yet.' ) ).toBeInTheDocument();
	} );

	it( 'renders the newsletter-conversion card as an em-dash when the cohort is not yet mature', () => {
		render( <ScorecardSection snapshot={ makeSnapshot( { newsletter_conversion: { value: 0, computable: false, denominator: 0 } } ) } /> );
		expect( screen.getByText( 'Newsletter → donation' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough newsletter signups with a full year of history yet.' ) ).toBeInTheDocument();
	} );
} );
