/**
 * Render smoke test for ConversionRateTrendsSection (Section 6).
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
import ConversionRateTrendsSection from './ConversionRateTrendsSection';
import { makeConversionWindow } from './fixtures';

describe( 'ConversionRateTrendsSection', () => {
	it( 'renders the heading and the weekly-trends empty state', () => {
		render( <ConversionRateTrendsSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Conversion rate trends' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Weekly trends will appear once the window contains at least 4 weeks of data.' ) ).toBeInTheDocument();
	} );
} );
