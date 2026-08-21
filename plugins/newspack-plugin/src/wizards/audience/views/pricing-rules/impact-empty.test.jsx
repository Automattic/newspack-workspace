/**
 * The card shown in place of the impact table when there is nothing to price.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ImpactEmpty from './impact-empty';

describe( 'ImpactEmpty', () => {
	it( 'points at the scope when nothing matches', () => {
		render( <ImpactEmpty reason="no-products" /> );
		expect( screen.getByRole( 'heading', { name: 'No products match this rule', level: 3 } ) ).toBeInTheDocument();
		expect( screen.getByText( /Applies to/ ) ).toBeInTheDocument();
	} );

	it( 'says so when the engine returned nothing', () => {
		render( <ImpactEmpty reason="unsupported" /> );
		expect( screen.getByRole( 'heading', { name: 'Preview unavailable', level: 3 } ) ).toBeInTheDocument();
	} );

	it( 'takes the heading level its caller asks for', () => {
		render( <ImpactEmpty reason="no-products" headingLevel={ 2 } /> );
		expect( screen.getByRole( 'heading', { name: 'No products match this rule', level: 2 } ) ).toBeInTheDocument();
	} );
} );
