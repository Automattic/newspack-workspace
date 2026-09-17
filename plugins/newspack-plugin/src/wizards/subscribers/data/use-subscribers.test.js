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
import { useSubscribers, viewToParams } from './use-subscribers';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const baseView = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'memberSince', direction: 'desc' },
	search: '',
	filters: [],
};

describe( 'viewToParams', () => {
	it( 'maps page, perPage and the default sort', () => {
		expect( viewToParams( baseView ) ).toEqual( {
			page: 1,
			per_page: 20,
			orderby: 'memberSince',
			order: 'desc',
		} );
	} );

	it( 'passes through the name sort with ascending order', () => {
		const params = viewToParams( { ...baseView, sort: { field: 'name', direction: 'asc' } } );
		expect( params.orderby ).toBe( 'name' );
		expect( params.order ).toBe( 'asc' );
	} );

	it( 'falls back to memberSince for a sort field the endpoint cannot honor', () => {
		// lastPayment is a display-only column this slice; sorting on it must not
		// produce an invalid orderby (the endpoint enums to name / memberSince).
		const params = viewToParams( { ...baseView, sort: { field: 'lastPayment', direction: 'asc' } } );
		expect( params.orderby ).toBe( 'memberSince' );
	} );

	it( 'trims a non-empty search and omits an empty one', () => {
		expect( viewToParams( { ...baseView, search: '  alice ' } ).search ).toBe( 'alice' );
		expect( viewToParams( { ...baseView, search: '   ' } ) ).not.toHaveProperty( 'search' );
	} );

	it( 'extracts the status filter, ignoring unsupported filters', () => {
		const params = viewToParams( {
			...baseView,
			filters: [
				{ field: 'status', operator: 'isAny', value: [ 'active', 'on-hold' ] },
				{ field: 'groupRole', operator: 'isAny', value: [ 'owner' ] },
			],
		} );
		expect( params.status ).toEqual( [ 'active', 'on-hold' ] );
		expect( params ).not.toHaveProperty( 'groupRole' );
	} );

	it( 'omits the status param when the filter is present but empty', () => {
		const params = viewToParams( {
			...baseView,
			filters: [ { field: 'status', operator: 'isAny', value: [] } ],
		} );
		expect( params ).not.toHaveProperty( 'status' );
	} );
} );

describe( 'useSubscribers', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'requests the endpoint with the view params and returns the envelope', async () => {
		apiFetch.mockResolvedValue( { items: [ { id: 7 } ], total: 42, pages: 3 } );

		const { result } = renderHook( () => useSubscribers( baseView ) );

		expect( result.current.loading ).toBe( true );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const { path } = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( path ).toContain( '/newspack/v1/wizard/newspack-subscribers/subscribers' );
		expect( path ).toContain( 'orderby=memberSince' );
		expect( path ).toContain( 'per_page=20' );

		expect( result.current.items ).toEqual( [ { id: 7 } ] );
		expect( result.current.total ).toBe( 42 );
		expect( result.current.pages ).toBe( 3 );
	} );

	it( 'surfaces the failure instead of passing an empty page off as the answer', async () => {
		// An empty envelope alone would render as "this site has no subscribers".
		// The error is what lets the screen say the read failed, so it is the
		// contract here — not just the empty page beside it.
		apiFetch.mockRejectedValue( new Error( 'boom' ) );

		const { result } = renderHook( () => useSubscribers( baseView ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.error ).toBeTruthy();
		expect( result.current.items ).toEqual( [] );
		expect( result.current.total ).toBe( 0 );
		expect( result.current.pages ).toBe( 0 );
	} );

	it( 'clears the error and refetches on reload', async () => {
		// The retry button in the error notice calls reload(); a retry that left
		// the error set would keep showing the notice over good data.
		apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

		const { result } = renderHook( () => useSubscribers( baseView ) );

		await waitFor( () => expect( result.current.error ).toBeTruthy() );

		apiFetch.mockResolvedValue( { items: [ { id: 7 } ], total: 1, pages: 1 } );
		act( () => result.current.reload() );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( result.current.error ).toBeFalsy();
		expect( result.current.items ).toEqual( [ { id: 7 } ] );
	} );
} );
