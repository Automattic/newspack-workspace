/**
 * External dependencies.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';

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

	it( 'renders no stray 0 for an empty actions list with no toggle', () => {
		const { container } = render( <CardSortableList items={ [ { id: 1, title: 'Homepage', actions: [] } ] } /> );
		expect( container.querySelector( '.newspack-card--core--sortable-list__end' ) ).not.toBeInTheDocument();
		expect( container ).not.toHaveTextContent( '0' );
	} );

	describe( 'a second move during the first move\'s animation', () => {
		const threeItems = [
			{ id: 1, title: 'One' },
			{ id: 2, title: 'Two' },
			{ id: 3, title: 'Three' },
		];

		// Holds the order the way a real parent does, so its re-render can undo a move.
		const StatefulList = ( { onDragCallback } ) => {
			const [ listItems, setListItems ] = useState( threeItems );
			return (
				<CardSortableList
					items={ listItems }
					onDragCallback={ ( from, to ) => {
						onDragCallback( from, to );
						setListItems( current => {
							const reordered = [ ...current ];
							const [ moved ] = reordered.splice( from, 1 );
							reordered.splice( to, 0, moved );
							return reordered;
						} );
					} }
				/>
			);
		};

		const titles = () => [ ...document.querySelectorAll( '.newspack-card--core--sortable-list__title' ) ].map( el => el.textContent );
		const moveDown = index => fireEvent.click( screen.getAllByRole( 'button', { name: 'Move one position down' } )[ index ] );

		beforeEach( () => jest.useFakeTimers() );
		afterEach( () => jest.useRealTimers() );

		it( 'reports both moves, in order, and keeps the second on screen', () => {
			const onDragCallback = jest.fn();
			render( <StatefulList onDragCallback={ onDragCallback } /> );

			moveDown( 0 );
			expect( onDragCallback ).not.toHaveBeenCalled();
			moveDown( 1 );
			expect( onDragCallback.mock.calls ).toEqual( [ [ 0, 1 ] ] );
			expect( titles() ).toEqual( [ 'Two', 'Three', 'One' ] );

			act( () => {
				jest.runAllTimers();
			} );
			expect( onDragCallback.mock.calls ).toEqual( [
				[ 0, 1 ],
				[ 1, 2 ],
			] );
			expect( titles() ).toEqual( [ 'Two', 'Three', 'One' ] );
		} );

		it( 'keeps focus on the moved row between the two moves', () => {
			render( <StatefulList onDragCallback={ jest.fn() } /> );
			moveDown( 0 );
			const rows = document.querySelectorAll( '.newspack-card--core--sortable-list__item' );
			expect( rows[ 1 ].contains( document.activeElement ) ).toBe( true );
			expect( rows[ 1 ] ).toHaveTextContent( 'One' );
		} );

		it( 'reports the pending move when a drag starts', () => {
			const onDragCallback = jest.fn();
			render( <StatefulList onDragCallback={ onDragCallback } /> );

			moveDown( 0 );
			fireEvent.dragStart( document.querySelectorAll( '.newspack-card--core--sortable-list__card' )[ 2 ], {
				dataTransfer: { setData: () => {}, setDragImage: () => {} },
			} );
			expect( onDragCallback.mock.calls ).toEqual( [ [ 0, 1 ] ] );

			act( () => {
				jest.runAllTimers();
			} );
			expect( onDragCallback ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
