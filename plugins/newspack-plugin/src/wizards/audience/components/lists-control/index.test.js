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
			Promise.resolve( 'saved-lookup?include=7' === path ? [ { id: 7, title: 'Legacy Plan [invalid status: draft]' } ] : [] )
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

		await waitFor( () => expect( screen.getByText( 'Legacy Plan [invalid status: draft]' ) ).toBeInTheDocument() );
		expect( screen.queryByText( 'Deleted subscription' ) ).not.toBeInTheDocument();
	} );
} );
