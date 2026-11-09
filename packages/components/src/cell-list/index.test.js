/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CellList from '.';

const items = count => Array.from( { length: count }, ( _, i ) => <span key={ i }>{ `Item ${ i + 1 }` }</span> );

describe( 'CellList', () => {
	it( 'shows every item and no toggle when they fit the limit', () => {
		render( <CellList>{ items( 2 ) }</CellList> );

		expect( screen.getByText( 'Item 2' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the first two, then opens and closes the rest', () => {
		render( <CellList>{ items( 3 ) }</CellList> );
		const toggle = screen.getByRole( 'button', { name: 'Show 1 more' } );

		expect( screen.queryByText( 'Item 3' ) ).not.toBeInTheDocument();
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( document.getElementById( toggle.getAttribute( 'aria-controls' ) ) ).not.toBeNull();

		fireEvent.click( toggle );
		expect( screen.getByText( 'Item 3' ) ).toBeInTheDocument();
		expect( toggle ).toHaveTextContent( 'Show less' );

		fireEvent.click( toggle );
		expect( screen.queryByText( 'Item 3' ) ).not.toBeInTheDocument();
	} );

	it( 'starts open when asked and reports each toggle', () => {
		const onToggle = jest.fn();
		render(
			<CellList defaultExpanded onToggle={ onToggle }>
				{ items( 3 ) }
			</CellList>
		);

		expect( screen.getByText( 'Item 3' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Show less' } ) );
		expect( onToggle ).toHaveBeenCalledWith( false );
	} );

	it( 'keeps the toggle click from reaching the row', () => {
		const onRowClick = jest.fn();
		render(
			// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
			<div onClick={ onRowClick }>
				<CellList>{ items( 3 ) }</CellList>
			</div>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Show 1 more' } ) );
		expect( onRowClick ).not.toHaveBeenCalled();
	} );
} );
