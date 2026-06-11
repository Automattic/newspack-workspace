/**
 * Render smoke test for CohortRetentionSection (Section 5).
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
import CohortRetentionSection from './CohortRetentionSection';
import { makeConversionWindow } from './fixtures';

describe( 'CohortRetentionSection', () => {
	it( 'renders the heading, both cohort titles, and the empty state', () => {
		render( <CohortRetentionSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Cohort retention' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Registration → conversion' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Subscriber retention' } ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Cohort data will appear after the first weekly refresh.' ) ).toHaveLength( 2 );
	} );
} );
