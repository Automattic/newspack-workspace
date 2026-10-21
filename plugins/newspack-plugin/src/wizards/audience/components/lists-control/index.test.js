/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import ListsControl from '.';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'ListsControl', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'names saved items through savedInfoPath, so an item missing from the selectable list keeps its name', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve( 'saved-lookup?include=7' === path ? [ { id: 7, title: 'Legacy Plan [invalid status: Draft]' } ] : [] )
		);

		render(
			<ListsControl
				label="Products"
				value={ [ 7 ] }
				onChange={ () => {} }
				path="selectable-list"
				savedInfoPath={ ids => `saved-lookup?include=${ ids.join( ',' ) }` }
				deletedItemLabel="Deleted subscription"
			/>
		);

		await waitFor( () => expect( screen.getByText( 'Legacy Plan [invalid status: Draft]' ) ).toBeInTheDocument() );
		expect( screen.queryByText( 'Deleted subscription' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps same-named items apart with labelWithId, so removing one leaves the other saved', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 96, title: 'Duo Plan' },
			{ id: 97, title: 'Duo Plan' },
		] );
		const onChange = jest.fn();

		render(
			<ListsControl
				label="Products"
				value={ [ 96, 97 ] }
				onChange={ onChange }
				path="list"
				labelWithId
				deletedItemLabel="Deleted subscription"
			/>
		);
		await waitFor( () => expect( screen.getByText( 'Duo Plan (#96)' ) ).toBeInTheDocument() );

		fireEvent.click( screen.getAllByRole( 'button', { name: /remove/i } )[ 0 ] );

		expect( onChange ).toHaveBeenLastCalledWith( [ 97 ] );
	} );

	it( 'drops typed text that matches no item, so it is never saved as an empty value', async () => {
		apiFetch.mockResolvedValue( [ { id: 96, title: 'Duo Plan' } ] );
		const onChange = jest.fn();

		render( <ListsControl label="Products" value={ [ 96 ] } onChange={ onChange } path="list" labelWithId /> );
		await waitFor( () => expect( screen.getByText( 'Duo Plan (#96)' ) ).toBeInTheDocument() );

		const input = screen.getByRole( 'combobox' );
		fireEvent.change( input, { target: { value: 'No such plan' } } );
		fireEvent.keyDown( input, { key: 'Enter' } );

		// Strict: a loose match would count `[ 96, undefined ]` as `[ 96 ]`.
		expect( onChange.mock.lastCall[ 0 ] ).toStrictEqual( [ 96 ] );
	} );
} );
