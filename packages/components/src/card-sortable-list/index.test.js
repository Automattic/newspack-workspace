/**
 * External dependencies.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import CardSortableList from './';

const items = [
	{ id: 1, title: 'Homepage', badge: { label: 'Active', intent: 'stable' } },
	{ id: 2, title: 'About', badge: { label: 'Draft', intent: 'draft' } },
];

describe( 'CardSortableList', () => {
	it( 'renders a badge for each item that has badge text', () => {
		render( <CardSortableList items={ items } /> );
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Draft' ) ).toBeInTheDocument();
	} );

	it( 'renders no badge for an item without badge text', () => {
		const { container } = render( <CardSortableList items={ [ { id: 1, title: 'Homepage' } ] } /> );
		expect( screen.getByText( 'Homepage' ) ).toBeInTheDocument();
		// An unguarded Badge would still emit an empty span, so assert on element children.
		expect( container.querySelector( 'h3' ).children ).toHaveLength( 0 );
	} );

	it( 'names each toggle after its item', () => {
		render(
			<CardSortableList
				items={ [
					{ id: 1, title: 'Homepage', toggleChecked: true, onToggleChange: () => {} },
					{ id: 2, title: 'About', toggleChecked: false, onToggleChange: () => {} },
				] }
			/>
		);
		expect( screen.getByRole( 'checkbox', { name: 'Homepage' } ) ).toBeChecked();
		expect( screen.getByRole( 'checkbox', { name: 'About' } ) ).not.toBeChecked();
	} );

	it( 'disables the first up and last down move buttons', () => {
		render( <CardSortableList items={ items } /> );
		const up = screen.getAllByRole( 'button', { name: 'Move one position up' } );
		const down = screen.getAllByRole( 'button', { name: 'Move one position down' } );
		expect( up[ 0 ] ).toBeDisabled();
		expect( up[ 1 ] ).toBeEnabled();
		expect( down[ 0 ] ).toBeEnabled();
		expect( down[ 1 ] ).toBeDisabled();
	} );

	it( 'keeps focus on a move button after a keyboard move', () => {
		jest.useFakeTimers();
		const onDragCallback = jest.fn();
		render( <CardSortableList items={ items } onDragCallback={ onDragCallback } /> );
		fireEvent.click( screen.getAllByRole( 'button', { name: 'Move one position down' } )[ 0 ] );
		act( () => {
			jest.runAllTimers();
		} );
		expect( onDragCallback ).toHaveBeenCalledWith( 0, 1 );
		const rows = document.querySelectorAll( '.newspack-card--core--sortable-list__item' );
		expect( rows[ 1 ].contains( document.activeElement ) ).toBe( true );
		expect( document.activeElement ).toHaveAccessibleName( 'Move one position up' );
		jest.useRealTimers();
	} );

	it( 'omits the toggle and menu when an item has neither', () => {
		render( <CardSortableList items={ [ { id: 1, title: 'Homepage' } ] } /> );
		expect( screen.queryByRole( 'checkbox' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'More actions' } ) ).not.toBeInTheDocument();
	} );
} );
