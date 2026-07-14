/**
 * Tests for ConversionRateTrendsSection (Section 6).
 *
 * Covers populated / empty / error render paths.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ConversionRateTrendsSection from './ConversionRateTrendsSection';
import { makeConversionWindow } from './fixtures';

describe( 'ConversionRateTrendsSection', () => {
	it( 'renders the heading', () => {
		render( <ConversionRateTrendsSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Conversion Rate Trends' } ) ).toBeInTheDocument();
	} );

	it( 'renders the empty treatment when state is empty', () => {
		render( <ConversionRateTrendsSection current={ makeConversionWindow( { weeklyTrendsState: 'empty' } ) } /> );
		expect( screen.getByText( 'Weekly trends will appear once the timeframe contains at least 4 weeks of data.' ) ).toBeInTheDocument();
	} );

	it( 'renders the error treatment when state is error', () => {
		render( <ConversionRateTrendsSection current={ makeConversionWindow( { weeklyTrendsState: 'error' } ) } /> );
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( screen.getByText( /Unable to load this section/ ) ).toBeInTheDocument();
	} );

	it( 'does not render the section-level error or coming_soon when state is populated', () => {
		render( <ConversionRateTrendsSection current={ makeConversionWindow( { weeklyTrendsState: 'populated' } ) } /> );
		// SectionState passes through children when populated — no alert, no coming_soon note.
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
	} );
} );
