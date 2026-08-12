/**
 * One scorecard tile: what it renders, and what it announces when there is no number.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import StatTile from './stat-tile';

describe( 'StatTile', () => {
	it( 'renders the label, the value and the description', () => {
		render( <StatTile label="Products affected" value="33" description="Rules currently price these products" /> );

		expect( screen.getByText( 'Products affected' ) ).toBeInTheDocument();
		expect( screen.getByText( '33' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Rules currently price these products' ) ).toBeInTheDocument();
	} );

	// ARIA will not name a bare generic element, so without a role the label is dropped.
	it( 'announces the em-dash when there is no number', () => {
		render( <StatTile label="Protected" value={ null } description="Keep the price they signed up at" /> );

		expect( screen.getByRole( 'img', { name: 'Not applicable' } ) ).toHaveTextContent( '—' );
	} );

	it( 'renders the reason line when one is given', () => {
		render(
			<StatTile label="Protected" value={ null } description="Keep the price they signed up at" secondary="Applies to new sign-ups only" />
		);

		expect( screen.getByText( 'Applies to new sign-ups only' ) ).toBeInTheDocument();
	} );

	// The description explains the label, not the number, so it survives an empty tile.
	it( 'keeps the description on an em-dash tile', () => {
		render(
			<StatTile label="Protected" value={ null } description="Keep the price they signed up at" secondary="Applies to new sign-ups only" />
		);

		expect( screen.getByText( 'Keep the price they signed up at' ) ).toBeInTheDocument();
	} );

	it( 'omits the reason line when none is given', () => {
		render( <StatTile label="Products affected" value="33" description="Rules currently price these products" /> );

		expect( screen.queryByText( 'Applies to new sign-ups only' ) ).not.toBeInTheDocument();
	} );
} );
