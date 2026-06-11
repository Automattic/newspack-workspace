/**
 * Render smoke test for PerJourneyConversionFunnelsSection (Section 2),
 * including the visibility-gated 2.4 cross-upsell funnel.
 *
 * NOTE: `.test.tsx` is not collected by CI (testMatch matches only `.js` /
 * `.jsx`, NPPD-1683) — written to the sibling convention.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PerJourneyConversionFunnelsSection from './PerJourneyConversionFunnelsSection';
import { makeConversionWindow } from './fixtures';

describe( 'PerJourneyConversionFunnelsSection', () => {
	it( 'renders the heading and all four journey funnel titles', () => {
		render( <PerJourneyConversionFunnelsSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Per-journey conversion funnels' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Anonymous → Registered' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Subscriber → Donor (cross-upsell)' } ) ).toBeInTheDocument();
	} );

	it( 'shows the cross-upsell empty-state note when 2.4 is hidden', () => {
		render( <PerJourneyConversionFunnelsSection current={ makeConversionWindow( { crossUpsellVisibility: 'hidden' } ) } /> );
		expect( screen.getByText( /Cross-upsell view appears when both subscription and donation programs/ ) ).toBeInTheDocument();
	} );
} );
