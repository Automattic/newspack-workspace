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
import { useGroups } from './use-groups';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'useGroups', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'fetches the full group set once and returns it', async () => {
		apiFetch.mockResolvedValue( { items: [ { id: 1 }, { id: 2 } ], total: 2, pages: 1 } );

		const { result } = renderHook( () => useGroups() );

		expect( result.current.loading ).toBe( true );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe( '/newspack/v1/wizard/newspack-subscribers/groups' );
		expect( result.current.groups ).toEqual( [ { id: 1 }, { id: 2 } ] );
	} );

	it( 'surfaces the failure instead of passing an empty list off as the answer', async () => {
		// An empty list alone would render as "this site has no groups". The error
		// is what lets the screen say the read failed, so it is the contract here —
		// not just the empty list beside it.
		apiFetch.mockRejectedValue( new Error( 'boom' ) );

		const { result } = renderHook( () => useGroups() );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.error ).toBeTruthy();
		expect( result.current.groups ).toEqual( [] );
	} );

	it( 'clears the error and refetches on reload', async () => {
		// The retry button in the error notice calls reload(); a retry that left
		// the error set would keep showing the notice over good data.
		apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

		const { result } = renderHook( () => useGroups() );

		await waitFor( () => expect( result.current.error ).toBeTruthy() );

		apiFetch.mockResolvedValue( { items: [ { id: 1 } ], total: 1, pages: 1 } );
		act( () => result.current.reload() );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( result.current.error ).toBeFalsy();
		expect( result.current.groups ).toEqual( [ { id: 1 } ] );
	} );
} );
