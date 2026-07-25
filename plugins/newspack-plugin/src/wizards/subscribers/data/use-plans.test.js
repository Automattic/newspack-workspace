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
import { usePlans } from './use-plans';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'usePlans', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'fetches the plan names once and returns them', async () => {
		apiFetch.mockResolvedValue( { items: [ 'Acme Team', 'Digital Monthly' ], total: 2, pages: 1 } );

		const { result } = renderHook( () => usePlans() );

		expect( result.current ).toEqual( [] );

		await waitFor( () => expect( result.current ).toEqual( [ 'Acme Team', 'Digital Monthly' ] ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe( '/newspack/v1/wizard/newspack-subscribers/plans' );
	} );

	it( 'degrades to no options when the read fails, rather than rejecting', async () => {
		// The plan list only populates a filter dropdown. A failure here must not
		// take the table down with it — the subscribers read surfaces its own error
		// notice for the same failure mode.
		apiFetch.mockRejectedValue( new Error( 'boom' ) );

		const { result } = renderHook( () => usePlans() );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		expect( result.current ).toEqual( [] );
	} );
} );
