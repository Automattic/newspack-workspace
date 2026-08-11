/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SpecificPostsControl from './specific-posts-control';

const noop = () => {};
const noItems = () => Promise.resolve( [] );

const ITEMS = [
	{ value: 11, label: 'Alpha' },
	{ value: 22, label: 'Beta' },
];

const titles = () => Array.from( document.querySelectorAll( '.newspack-blocks-reorder-modal__title' ) ).map( el => el.textContent );

describe( 'SpecificPostsControl', () => {
	it( 'exposes the token field to assistive technology as "Content"', () => {
		render( <SpecificPostsControl postIds={ [] } onChange={ noop } fetchSuggestions={ noop } fetchSavedInfo={ noop } /> );
		expect( screen.getByRole( 'combobox', { name: 'Content' } ) ).toBeInTheDocument();
	} );

	it( 'keeps the reorder button focusable while too little content is selected', async () => {
		render( <SpecificPostsControl postIds={ [ 11 ] } onChange={ noop } fetchSuggestions={ noop } fetchSavedInfo={ noItems } /> );
		const reorder = await screen.findByRole( 'button', { name: /Reorder Content/ } );
		expect( reorder ).toHaveAttribute( 'aria-disabled', 'true' );
		reorder.focus();
		expect( document.activeElement ).toBe( reorder );
	} );

	it( 'says why the reorder button is unavailable', async () => {
		render( <SpecificPostsControl postIds={ [ 11 ] } onChange={ noop } fetchSuggestions={ noop } fetchSavedInfo={ noItems } /> );
		expect( await screen.findByRole( 'button', { name: 'Reorder Content: pick at least two items' } ) ).toBeInTheDocument();
	} );

	it( 'enables the reorder button once two items are selected', async () => {
		render(
			<SpecificPostsControl
				postIds={ [ 11, 22 ] }
				onChange={ noop }
				fetchSuggestions={ noop }
				fetchSavedInfo={ () => Promise.resolve( ITEMS ) }
			/>
		);
		expect( await screen.findByRole( 'button', { name: 'Reorder Content' } ) ).not.toHaveAttribute( 'aria-disabled' );
	} );

	it( 'writes the reordered IDs back through onChange', async () => {
		const onChange = jest.fn();
		render(
			<SpecificPostsControl
				postIds={ [ 11, 22 ] }
				onChange={ onChange }
				fetchSuggestions={ noop }
				fetchSavedInfo={ () => Promise.resolve( ITEMS ) }
			/>
		);

		fireEvent.click( await screen.findByRole( 'button', { name: 'Reorder Content' } ) );
		await screen.findByLabelText( 'Move Up: Beta' );
		expect( titles() ).toEqual( [ 'Alpha', 'Beta' ] );

		fireEvent.click( screen.getByLabelText( 'Move Up: Beta' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( onChange ).toHaveBeenCalledWith( [ 22, 11 ] );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'closes the modal without changing anything when the reorder is cancelled', async () => {
		const onChange = jest.fn();
		render(
			<SpecificPostsControl
				postIds={ [ 11, 22 ] }
				onChange={ onChange }
				fetchSuggestions={ noop }
				fetchSavedInfo={ () => Promise.resolve( ITEMS ) }
			/>
		);

		fireEvent.click( await screen.findByRole( 'button', { name: 'Reorder Content' } ) );
		await screen.findByLabelText( 'Move Up: Beta' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

		expect( onChange ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'asks for the titles once and shares them with the modal', async () => {
		const fetchSavedInfo = jest.fn( () => Promise.resolve( ITEMS ) );
		render( <SpecificPostsControl postIds={ [ 11, 22 ] } onChange={ noop } fetchSuggestions={ noop } fetchSavedInfo={ fetchSavedInfo } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Reorder Content' } ) );
		await screen.findByLabelText( 'Move Up: Beta' );

		expect( fetchSavedInfo ).toHaveBeenCalledTimes( 1 );
	} );
} );
