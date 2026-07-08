/**
 * Tests for the Audience NewsletterValueSection (NEWS-2603 Phase 3): the modeled
 * newsletter-subscriber value card's computable / not-computable rendering.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import NewsletterValueSection from './NewsletterValueSection';

describe( 'Audience NewsletterValueSection', () => {
	it( 'renders the modeled value when computable', () => {
		render( <NewsletterValueSection value={ { value: 27.3, computable: true, denominator: 1200 } } /> );
		expect( screen.getByText( 'Value per newsletter subscriber' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not enough newsletter or supporter history to model yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'renders an em-dash with explanatory copy when not computable', () => {
		render( <NewsletterValueSection value={ { value: 0, computable: false, denominator: 0 } } /> );
		expect( screen.getByText( 'Value per newsletter subscriber' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough newsletter or supporter history to model yet.' ) ).toBeInTheDocument();
	} );

	it( 'suppresses the insufficient-history copy when the payload carries an error', () => {
		render( <NewsletterValueSection value={ { value: 0, computable: false, error: 'Hub unavailable' } } /> );
		expect( screen.getByText( 'Value per newsletter subscriber' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not enough newsletter or supporter history to model yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the warming note when the hub snapshot is still backfilling (NEWS-2603)', () => {
		render( <NewsletterValueSection value={ { value: 0, computable: false, state: 'warming' } } /> );
		expect( screen.getByText( 'Value per newsletter subscriber' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Still calculating — check back shortly.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not enough newsletter or supporter history to model yet.' ) ).not.toBeInTheDocument();
	} );
} );
