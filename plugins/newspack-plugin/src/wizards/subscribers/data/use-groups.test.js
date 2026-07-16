// @jest-environment jsdom

/**
 * External dependencies
 */
import { renderHook, waitFor } from '@testing-library/react';

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

	it( 'degrades to an empty list when the request fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'boom' ) );

		const { result } = renderHook( () => useGroups() );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.groups ).toEqual( [] );
	} );
} );
