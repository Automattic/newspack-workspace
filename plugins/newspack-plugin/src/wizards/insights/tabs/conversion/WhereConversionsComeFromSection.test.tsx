/**
 * Render smoke test for WhereConversionsComeFromSection (Section 3).
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
import WhereConversionsComeFromSection from './WhereConversionsComeFromSection';
import { makeConversionWindow } from './fixtures';

describe( 'WhereConversionsComeFromSection', () => {
	it( 'renders the heading and the three pie empty states', () => {
		render( <WhereConversionsComeFromSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Where conversions come from' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Source data will appear once registrations occur in this window.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Source data will appear once donations occur in this window.' ) ).toBeInTheDocument();
	} );
} );
