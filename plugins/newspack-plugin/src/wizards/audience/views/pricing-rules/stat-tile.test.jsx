/**
 * One scorecard tile: what it renders, and what it announces when there is no number.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

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

	// Both screens put the grid under a section heading, so the label is its child.
	it( 'renders the label as a heading', () => {
		render( <StatTile label="Products affected" value="33" description="Rules currently price these products" /> );

		expect( screen.getByRole( 'heading', { name: 'Products affected', level: 3 } ) ).toBeInTheDocument();
	} );

	it( 'hides the em-dash and speaks its meaning instead', () => {
		render( <StatTile label="Protected" value={ null } description="Keep the price they signed up at" /> );

		expect( screen.getByText( '—' ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveClass( 'screen-reader-text' );
	} );

	// A caller that knows why the number is absent says so in its own words.
	it( 'prefers the caller’s label over the default on an em-dash tile', () => {
		render( <StatTile label="Protected" value={ null } valueLabel="Unavailable" description="Keep the price they signed up at" /> );

		expect( screen.getByText( 'Unavailable' ) ).toHaveClass( 'screen-reader-text' );
		expect( screen.queryByText( 'Not applicable' ) ).not.toBeInTheDocument();
	} );

	// Punctuation verbosity decides whether the "+" is spoken, so the figure carries
	// its own name rather than resting on the glyph.
	it( 'announces a bounded figure in words', () => {
		render(
			<StatTile label="Subscribers in scope" value="500+" valueLabel="At least 500" description="Renewing subscriptions on those products" />
		);

		expect( screen.getByText( '500+' ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'At least 500' ) ).toHaveClass( 'screen-reader-text' );
	} );

	it( 'renders the reason line when one is given', () => {
		render(
			<StatTile label="Protected" value={ null } description="Keep the price they signed up at" secondary="Applies to new sign-ups only" />
		);

		expect( screen.getByText( 'Applies to new sign-ups only' ) ).toBeInTheDocument();
	} );

	// The description explains the label, not the number, so it survives an empty tile.
	it( 'keeps the description on an em-dash tile', () => {
		render( <StatTile label="Protected" value={ null } description="Keep the price they signed up at" /> );

		expect( screen.getByText( 'Keep the price they signed up at' ) ).toBeInTheDocument();
	} );

	it( 'omits the reason line when none is given', () => {
		render( <StatTile label="Products affected" value="33" description="Rules currently price these products" /> );

		expect( screen.queryByText( 'Applies to new sign-ups only' ) ).not.toBeInTheDocument();
	} );

	// It opens a modal rather than navigating, so a link-styled button, never an anchor.
	it( 'runs the action from a button when both label and callback are given', () => {
		const onAction = jest.fn();
		render(
			<StatTile
				label="Products affected"
				value="33"
				description="Rules currently price these products"
				actionLabel="View Affected Products"
				onAction={ onAction }
			/>
		);

		const button = screen.getByRole( 'button', { name: 'View Affected Products' } );
		expect( button.tagName ).toBe( 'BUTTON' );

		fireEvent.click( button );
		expect( onAction ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'renders no action when only the label is given', () => {
		render(
			<StatTile label="Products affected" value="33" description="Rules currently price these products" actionLabel="View Affected Products" />
		);

		expect( screen.queryByRole( 'button', { name: 'View Affected Products' } ) ).not.toBeInTheDocument();
	} );

	it( 'renders no action when neither prop is given', () => {
		render( <StatTile label="Products affected" value="33" description="Rules currently price these products" /> );

		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );
} );
