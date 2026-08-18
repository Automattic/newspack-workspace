import { renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import useCollectionData from './use-collection-data';
import { notifyError, notifyInfo } from '../notices';
import { FETCH_ALL_MAX_ITEMS } from '../utils/per-page';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../notices', () => ( { notifyError: jest.fn(), notifyInfo: jest.fn() } ) );

const makeResponse = ( items, { total = items.length, totalPages = 1 } = {} ) => ( {
	headers: {
		get: name => ( name === 'X-WP-Total' ? String( total ) : String( totalPages ) ),
	},
	json: async () => items,
} );

describe( 'useCollectionData', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		notifyError.mockClear();
		notifyInfo.mockClear();
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
			apiFetch.mockImplementation( ( { path, parse } ) => {
				const page = Number( ( path.match( /[?&]page=(\d+)/ ) || [] )[ 1 ] || 1 );
				return Promise.resolve( parse === false ? makeResponse( pages[ page ], { total: 5, totalPages: 3 } ) : pages[ page ] );
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

		it( 'keeps already-loaded items and notifies once a page fails on both the initial attempt and the retry', async () => {
			let page2Attempts = 0;
			apiFetch.mockImplementation( ( { path } ) => {
				const page = Number( ( path.match( /[?&]page=(\d+)/ ) || [] )[ 1 ] || 1 );
				if ( page === 1 ) {
					return Promise.resolve( makeResponse( [ { id: 1 }, { id: 2 } ], { total: 3, totalPages: 2 } ) );
				}
				page2Attempts += 1;
				return Promise.reject( new Error( 'network error' ) );
			} );

			const { result } = renderHook( () =>
				useCollectionData( { path: '/wp/v2/test?per_page=2&page=1', fetchAll: true, errorNoticeId: 'test-id' } )
			);

			await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
			expect( result.current.data.map( item => item.id ) ).toEqual( [ 1, 2 ] );
			expect( page2Attempts ).toBe( 2 );
			expect( notifyError ).toHaveBeenCalledWith( 'Only some items could be loaded. Reload the page to try again.', {
				id: 'test-id',
			} );
			expect( result.current.paginationInfo ).toEqual( { totalItems: result.current.data.length, totalPages: 1 } );
		} );

		it( 'stops at the fetch-all cap and notifies the list was truncated', async () => {
			apiFetch.mockImplementation( ( { path, parse } ) => {
				const page = Number( ( path.match( /[?&]page=(\d+)/ ) || [] )[ 1 ] || 1 );
				const items = Array.from( { length: 100 }, ( unused, i ) => ( { id: page * 100 + i } ) );
				return Promise.resolve( parse === false ? makeResponse( items, { total: 50000, totalPages: 500 } ) : items );
			} );

			const { result } = renderHook( () => useCollectionData( { path: '/wp/v2/test?per_page=100&page=1', fetchAll: true } ) );

			await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
			expect( result.current.data ).toHaveLength( FETCH_ALL_MAX_ITEMS );
			expect( apiFetch ).toHaveBeenCalledTimes( FETCH_ALL_MAX_ITEMS / 100 );
			expect( notifyInfo ).toHaveBeenCalledWith(
				`Showing the first ${ FETCH_ALL_MAX_ITEMS.toLocaleString() } items. Use search or filters to narrow the list.`
			);
			expect( result.current.paginationInfo ).toEqual( { totalItems: result.current.data.length, totalPages: 1 } );
		} );

		it( 'stops quietly on a deterministic out-of-range page — no retry, no error notice', async () => {
			let page2Attempts = 0;
			apiFetch.mockImplementation( ( { path } ) => {
				const page = Number( ( path.match( /[?&]page=(\d+)/ ) || [] )[ 1 ] || 1 );
				if ( page === 1 ) {
					return Promise.resolve( makeResponse( [ { id: 1 }, { id: 2 } ], { total: 3, totalPages: 2 } ) );
				}
				page2Attempts += 1;
				return Promise.reject( { code: 'rest_post_invalid_page_number', data: { status: 400 } } );
			} );

			const { result } = renderHook( () => useCollectionData( { path: '/wp/v2/test?per_page=2&page=1', fetchAll: true } ) );

			await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
			expect( result.current.data.map( item => item.id ) ).toEqual( [ 1, 2 ] );
			expect( page2Attempts ).toBe( 1 );
			expect( notifyError ).not.toHaveBeenCalled();
			expect( notifyInfo ).not.toHaveBeenCalled();
			expect( result.current.paginationInfo ).toEqual( { totalItems: 2, totalPages: 1 } );
		} );

		it( 'keeps pages that succeeded alongside a failing sibling in the same batch', async () => {
			apiFetch.mockImplementation( ( { path, parse } ) => {
				const page = Number( ( path.match( /[?&]page=(\d+)/ ) || [] )[ 1 ] || 1 );
				if ( page === 4 ) {
					return Promise.reject( { code: 'rest_post_invalid_page_number', data: { status: 400 } } );
				}
				return Promise.resolve( parse === false ? makeResponse( [ { id: page } ], { total: 8, totalPages: 4 } ) : [ { id: page } ] );
			} );

			const { result } = renderHook( () => useCollectionData( { path: '/wp/v2/test?per_page=2&page=1', fetchAll: true } ) );

			await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
			expect( result.current.data.map( item => item.id ) ).toEqual( [ 1, 2, 3 ] );
			expect( result.current.paginationInfo ).toEqual( { totalItems: 3, totalPages: 1 } );
			expect( notifyError ).not.toHaveBeenCalled();
		} );

		it( 'retries and reports a 400 that is not an invalid-page error', async () => {
			let page2Attempts = 0;
			apiFetch.mockImplementation( ( { path } ) => {
				const page = Number( ( path.match( /[?&]page=(\d+)/ ) || [] )[ 1 ] || 1 );
				if ( page === 1 ) {
					return Promise.resolve( makeResponse( [ { id: 1 }, { id: 2 } ], { total: 3, totalPages: 2 } ) );
				}
				page2Attempts += 1;
				return Promise.reject( { code: 'rest_invalid_param', data: { status: 400 } } );
			} );

			const { result } = renderHook( () => useCollectionData( { path: '/wp/v2/test?per_page=2&page=1', fetchAll: true } ) );

			await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
			expect( page2Attempts ).toBe( 2 );
			expect( notifyError ).toHaveBeenCalled();
		} );
	} );
} );
