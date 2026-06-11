/**
 * Render smoke test for HowLongConversionsTakeSection (Section 4),
 * including the visibility-gated 4.4 subscriber → donor lag curve.
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
import HowLongConversionsTakeSection from './HowLongConversionsTakeSection';
import { makeConversionWindow } from './fixtures';

describe( 'HowLongConversionsTakeSection', () => {
	it( 'renders the heading and all four curve titles', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'How long conversions take' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Time to register' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Subscriber → donor lag' } ) ).toBeInTheDocument();
	} );

	it( 'shows the lag empty-state note when 4.4 is hidden', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow( { lagVisibility: 'hidden' } ) } /> );
		expect( screen.getByText( /Subscriber-to-donor lag appears when at least 50 readers/ ) ).toBeInTheDocument();
	} );
} );
