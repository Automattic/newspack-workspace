/**
 * Tests for OpportunityBucketsSection (Section 8).
 *
 * Covers the three scorecards and the 8.4 top-pages table across
 * populated / empty / error / coming_soon states.
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
		expect( screen.getByRole( 'heading', { name: 'Opportunity Buckets' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Stale Registered Readers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'At-Risk Subscribers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Lapsed Donors' ) ).toBeInTheDocument();
	} );

	it( 'renders the top-pages table empty treatment when state is empty', () => {
		render( <OpportunityBucketsSection current={ makeConversionWindow( { topPagesState: 'empty' } ) } /> );
		expect( screen.getByText( /No qualifying articles yet/ ) ).toBeInTheDocument();
	} );

	it( 'renders the top-pages table error treatment when state is error', () => {
		render( <OpportunityBucketsSection current={ makeConversionWindow( { topPagesState: 'error' } ) } /> );
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( screen.getByText( /Unable to load this section/ ) ).toBeInTheDocument();
	} );

	it( 'renders the top-pages table coming_soon treatment when state is coming_soon', () => {
		render( <OpportunityBucketsSection current={ makeConversionWindow( { topPagesState: 'coming_soon' } ) } /> );
		expect( screen.getByText( /Coming soon/ ) ).toBeInTheDocument();
	} );

	it( 'renders the top-pages note regardless of table state', () => {
		render( <OpportunityBucketsSection current={ makeConversionWindow() } /> );
		// Note: the rendered string uses a right single quotation mark (U+2019) in "don't".
		expect( screen.getByText( /These articles get traffic but don/ ) ).toBeInTheDocument();
	} );
} );
