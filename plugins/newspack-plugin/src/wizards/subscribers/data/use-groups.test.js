// @jest-environment jsdom

/**
 * External dependencies
 */
import { act, renderHook, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { useGroups, viewToParams } from './use-groups';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const baseView = { page: 1, perPage: 20, sort: { field: 'createdAt', direction: 'desc' }, search: '', filters: [] };

describe( 'viewToParams', () => {
	it( 'maps search, sort, the status and Subscription filters and the page', () => {
		expect(
			viewToParams( {
				page: 3,
				perPage: 50,
				sort: { field: 'owner', direction: 'asc' },
				search: '  harbor ',
				filters: [
					{ field: 'status', operator: 'isAny', value: [ 'active' ] },
					{ field: 'product', operator: 'isAny', value: [ 'Team Annual' ] },
				],
			} )
		).toEqual( {
			page: 3,
			per_page: 50,
			orderby: 'owner',
			order: 'asc',
			search: 'harbor',
			status: [ 'active' ],
			product: [ 'Team Annual' ],
		} );
	} );

	it( 'falls back to the created date for a column the server cannot sort', () => {
		expect( viewToParams( { ...baseView, sort: { field: 'members', direction: 'asc' } } ) ).toMatchObject( {
			orderby: 'createdAt',
			order: 'asc',
		} );
	} );
} );

describe( 'useGroups', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'fetches one page and returns it with the product options', async () => {
		apiFetch.mockResolvedValue( { items: [ { id: 1 }, { id: 2 } ], total: 40, pages: 2, products: [ 'Team Annual' ] } );

		const { result } = renderHook( () => useGroups( baseView ) );

		expect( result.current.loading ).toBe( true );
		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain( '/newspack/v1/wizard/newspack-subscribers/groups?' );
		expect( result.current.groups ).toEqual( [ { id: 1 }, { id: 2 } ] );
		expect( result.current.total ).toBe( 40 );
		expect( result.current.pages ).toBe( 2 );
		expect( result.current.products ).toEqual( [ 'Team Annual' ] );
	} );

	it( 'surfaces the failure instead of passing an empty list off as the answer', async () => {
		// An empty list alone would render as "this site has no groups". The error
		// is what lets the screen say the read failed.
		apiFetch.mockRejectedValue( new Error( 'boom' ) );

		const { result } = renderHook( () => useGroups( baseView ) );
		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.error ).toBeTruthy();
		expect( result.current.groups ).toEqual( [] );
	} );

	it( 'clears the error and refetches on reload', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

		const { result } = renderHook( () => useGroups( baseView ) );
		await waitFor( () => expect( result.current.error ).toBeTruthy() );

		apiFetch.mockResolvedValue( { items: [ { id: 1 } ], total: 1, pages: 1 } );
		act( () => result.current.reload() );
		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( result.current.error ).toBeFalsy();
		expect( result.current.groups ).toEqual( [ { id: 1 } ] );
	} );
} );
