/**
 * Render smoke test for OpportunityBucketsSection (Section 8): the three
 * snapshot scorecards and the 8.4 top-pages table empty-state row.
 *
 * NOTE: `.test.tsx` is not collected by CI (NPPD-1683).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import OpportunityBucketsSection from './OpportunityBucketsSection';
import { makeConversionWindow } from './fixtures';

describe( 'OpportunityBucketsSection', () => {
	it( 'renders the heading and the three opportunity scorecards', () => {
		render( <OpportunityBucketsSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Opportunity buckets' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Stale Registered Readers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'At-Risk Subscribers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Lapsed Donors' ) ).toBeInTheDocument();
	} );

	it( 'renders the top-pages table empty-state row', () => {
		render( <OpportunityBucketsSection current={ makeConversionWindow() } /> );
		expect( screen.getByText( /No qualifying pages yet/ ) ).toBeInTheDocument();
	} );
} );
