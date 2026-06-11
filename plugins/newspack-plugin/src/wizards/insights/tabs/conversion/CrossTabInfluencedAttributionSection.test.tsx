/**
 * Render smoke test for CrossTabInfluencedAttributionSection (Section 7) —
 * the only section with comparison deltas.
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
import CrossTabInfluencedAttributionSection from './CrossTabInfluencedAttributionSection';
import { makeConversionWindow } from './fixtures';

describe( 'CrossTabInfluencedAttributionSection', () => {
	it( 'renders the heading and the four influenced-rate scorecards', () => {
		render( <CrossTabInfluencedAttributionSection current={ makeConversionWindow() } previous={ null } /> );
		expect( screen.getByRole( 'heading', { name: 'Cross-tab influenced attribution' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Influenced Registration Rate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Influenced Newsletter Signup Rate' ) ).toBeInTheDocument();
	} );

	it( 'renders with a comparison window provided', () => {
		render( <CrossTabInfluencedAttributionSection current={ makeConversionWindow() } previous={ makeConversionWindow() } /> );
		expect( screen.getByText( 'Influenced Subscription Rate' ) ).toBeInTheDocument();
	} );
} );
