/**
 * The Pricing Rules empty state carries the Add Rule action that the wizard
 * header withholds while the list has nothing in it.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PricingRulesOnboarding from './onboarding';

describe( 'PricingRulesOnboarding', () => {
	it( 'names the screen and says what a pricing rule is for', () => {
		render( <PricingRulesOnboarding /> );

		expect( screen.getByRole( 'heading', { name: 'Get started with pricing rules', level: 2 } ) ).toBeInTheDocument();
		expect( screen.getByText( /adjust product prices automatically/ ) ).toBeInTheDocument();
	} );

	it( 'carries the Add Rule action to the same route as the header button', () => {
		render( <PricingRulesOnboarding /> );

		expect( screen.getByRole( 'link', { name: 'Add Rule' } ) ).toHaveAttribute( 'href', '#/new' );
	} );
} );
