/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';

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

	it( 'keeps same-named items apart with labelWithId, so each token maps back to its own ID', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 96, title: 'Duo Plan' },
			{ id: 97, title: 'Duo Plan' },
		] );

		render(
			<ListsControl
				label="Products"
				value={ [ 96, 97 ] }
				onChange={ () => {} }
				path="list"
				labelWithId
				deletedItemLabel="Deleted subscription"
			/>
		);

		await waitFor( () => expect( screen.getByText( 'Duo Plan (#96)' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'Duo Plan (#97)' ) ).toBeInTheDocument();
	} );
} );
