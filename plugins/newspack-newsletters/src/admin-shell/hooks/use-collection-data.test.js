import { renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import useCollectionData from './use-collection-data';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../notices', () => ( { notifyError: jest.fn() } ) );

const makeResponse = ( items, { total = items.length, totalPages = 1 } = {} ) => ( {
	headers: {
		get: name => ( name === 'X-WP-Total' ? String( total ) : String( totalPages ) ),
	},
	json: async () => items,
} );

describe( 'useCollectionData', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'fetches a single page and reports header-driven pagination', async () => {
		apiFetch.mockResolvedValue( makeResponse( [ { id: 1 }, { id: 2 } ], { total: 60, totalPages: 3 } ) );

		const { result } = renderHook( () => useCollectionData( { path: '/wp/v2/test?page=1' } ) );

		await waitFor( () => expect( result.current.hasResolved ).toBe( true ) );
		expect( result.current.data ).toHaveLength( 2 );
		expect( result.current.paginationInfo ).toEqual( { totalItems: 60, totalPages: 3 } );
		expect( result.current.progress ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	describe( 'fetchAll', () => {
		it( 'walks every page, concatenates in page order, and clamps totalPages to 1', async () => {
			const pages = {
				1: [ { id: 1 }, { id: 2 } ],
				2: [ { id: 3 }, { id: 4 } ],
				3: [ { id: 5 } ],
			};
			apiFetch.mockImplementation( ( { path } ) => {
				const page = Number( ( path.match( /[?&]page=(\d+)/ ) || [] )[ 1 ] || 1 );
				return Promise.resolve( makeResponse( pages[ page ], { total: 5, totalPages: 3 } ) );
			} );

			const { result } = renderHook( () => useCollectionData( { path: '/wp/v2/test?per_page=2&page=1', fetchAll: true } ) );

			await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
			expect( result.current.data.map( item => item.id ) ).toEqual( [ 1, 2, 3, 4, 5 ] );
			expect( result.current.paginationInfo ).toEqual( { totalItems: 5, totalPages: 1 } );
			// Walk finished — progress resets so the control label recovers.
			expect( result.current.progress ).toBeNull();
			expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		} );

		it( 'skips the walk when the collection fits in one chunk', async () => {
			apiFetch.mockResolvedValue( makeResponse( [ { id: 1 } ], { total: 1, totalPages: 1 } ) );

			const { result } = renderHook( () => useCollectionData( { path: '/wp/v2/test?page=1', fetchAll: true } ) );

			await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
			expect( result.current.data ).toHaveLength( 1 );
			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
