/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TableCard from '.';

// @wordpress/ui injects no CSS under Jest, so the layout this component exists
// for (full bleed, edge bumps, the content gap) is invisible here; slot order
// and heading semantics are the assertable contract.
describe( 'TableCard', () => {
	it( 'renders the table children inside the card', () => {
		render(
			<TableCard>
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.getByRole( 'table', { name: 'Prices' } ) ).toBeInTheDocument();
	} );

	it( 'renders the title as a real heading carrying the given id', () => {
		const { rerender } = render(
			<TableCard title="Price schedule" titleId="tc-title">
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.getByRole( 'heading', { level: 3, name: 'Price schedule' } ) ).toHaveAttribute( 'id', 'tc-title' );

		rerender(
			<TableCard title="Price schedule" heading={ 2 }>
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.getByRole( 'heading', { level: 2, name: 'Price schedule' } ) ).toBeInTheDocument();

		rerender(
			<TableCard>
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.queryByRole( 'heading' ) ).not.toBeInTheDocument();
	} );

	it( 'treats 0 as a renderable title and the empty string as none', () => {
		const { rerender } = render(
			<TableCard title={ 0 }>
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.getByRole( 'heading', { level: 3, name: '0' } ) ).toBeInTheDocument();

		rerender(
			<TableCard title="">
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.queryByRole( 'heading' ) ).not.toBeInTheDocument();
	} );

	it( 'renders header actions alongside the title', () => {
		render(
			<TableCard title="Price Schedule" actions={ <button>Add Price</button> }>
				<table aria-label="Prices" />
			</TableCard>
		);
		const heading = screen.getByRole( 'heading', { level: 3, name: 'Price Schedule' } );
		const action = screen.getByRole( 'button', { name: 'Add Price' } );
		expect( heading.parentElement ).toContainElement( action );
	} );

	it( 'renders a header for actions alone, and none for boolean slot leftovers', () => {
		const { rerender } = render(
			<TableCard actions={ <button>Add Price</button> }>
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.getByRole( 'button', { name: 'Add Price' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'heading' ) ).not.toBeInTheDocument();

		rerender(
			<TableCard title={ false } actions={ false }>
				<table aria-label="Prices" />
			</TableCard>
		);
		expect( screen.queryByRole( 'heading' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'orders before, table, after within the card body', () => {
		render(
			<TableCard before={ <p>Stats row</p> } after={ <button>See More</button> }>
				<table aria-label="Prices" />
			</TableCard>
		);
		const before = screen.getByText( 'Stats row' );
		const after = screen.getByRole( 'button', { name: 'See More' } );
		const table = screen.getByRole( 'table', { name: 'Prices' } );
		// Walk the table up to the card body's direct child, so extra upstream
		// wrappers cannot silently repoint the comparison.
		const body = before.parentElement;
		let tableWrap = table;
		while ( tableWrap.parentElement !== body ) {
			tableWrap = tableWrap.parentElement;
		}
		const order = Array.from( body.children );
		expect( order.indexOf( before ) ).toBeLessThan( order.indexOf( tableWrap ) );
		expect( order.indexOf( tableWrap ) ).toBeLessThan( order.indexOf( after ) );
	} );
} );
