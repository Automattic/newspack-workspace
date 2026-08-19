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
import { useAvatars } from './use-avatars';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// The endpoint's cap, which the wizard localizes onto window as `avatarBatchCap`.
// With no config present (as in these tests) the hook falls back to the same 200.
const BATCH_SIZE = 200;

const emailsFor = count => Array.from( { length: count }, ( _, i ) => `reader${ i }@test.com` );

// One response shaped like the endpoint's, resolving every email it was sent.
const avatarsResponseFor = emails => ( {
	show: true,
	avatars: Object.fromEntries( emails.map( email => [ email, `https://avatar.test/${ email }` ] ) ),
} );

describe( 'useAvatars', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'resolves a single batch and keys the result by email', async () => {
		apiFetch.mockImplementation( ( { data } ) => Promise.resolve( avatarsResponseFor( data.emails ) ) );

		const { result } = renderHook( () => useAvatars( [ 'a@test.com', 'b@test.com' ] ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( result.current.avatars ).toEqual( {
			'a@test.com': 'https://avatar.test/a@test.com',
			'b@test.com': 'https://avatar.test/b@test.com',
		} );
	} );

	it( 'batches past the endpoint cap and merges every batch', async () => {
		// The reason the hook exists: a set larger than one batch must resolve in
		// full rather than silently dropping the overflow.
		const emails = emailsFor( BATCH_SIZE + 50 );
		apiFetch.mockImplementation( ( { data } ) => Promise.resolve( avatarsResponseFor( data.emails ) ) );

		const { result } = renderHook( () => useAvatars( emails ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.emails ).toHaveLength( BATCH_SIZE );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data.emails ).toHaveLength( 50 );
		// Merged, not last-batch-wins.
		expect( Object.keys( result.current.avatars ) ).toHaveLength( BATCH_SIZE + 50 );
		expect( result.current.avatars[ emails[ 0 ] ] ).toBe( `https://avatar.test/${ emails[ 0 ] }` );
		expect( result.current.avatars[ emails[ emails.length - 1 ] ] ).toBe( `https://avatar.test/${ emails[ emails.length - 1 ] }` );
	} );

	it( 'batches to the cap the server localized, not a client-side copy of it', async () => {
		// The endpoint silently truncates anything past its own cap, so a client
		// batching to a larger number loses the overflow with no error anywhere.
		// A hook holding its own hard-coded 200 would send one batch of 12 here.
		const serverCap = 5;
		window.newspackSubscribers = { avatarBatchCap: serverCap };
		const emails = emailsFor( 12 );
		apiFetch.mockImplementation( ( { data } ) => Promise.resolve( avatarsResponseFor( data.emails ) ) );

		const { result } = renderHook( () => useAvatars( emails ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.emails ).toHaveLength( serverCap );
		expect( apiFetch.mock.calls[ 2 ][ 0 ].data.emails ).toHaveLength( 2 );
		expect( Object.keys( result.current.avatars ) ).toHaveLength( 12 );

		delete window.newspackSubscribers;
	} );

	it( 'honors the server saying avatars are off, even for one batch of many', async () => {
		// The Discussion setting can be toggled between page load (which sets the
		// client SHOW_AVATARS flag) and this fetch; the server is the authority.
		const emails = emailsFor( BATCH_SIZE + 1 );
		apiFetch.mockResolvedValueOnce( avatarsResponseFor( emails.slice( 0, BATCH_SIZE ) ) ).mockResolvedValueOnce( { show: false } );

		const { result } = renderHook( () => useAvatars( emails ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.avatars ).toEqual( {} );
	} );

	it( 'keeps the batches that resolved when one request fails', async () => {
		const emails = emailsFor( BATCH_SIZE + 1 );
		apiFetch.mockRejectedValueOnce( new Error( 'boom' ) ).mockResolvedValueOnce( avatarsResponseFor( emails.slice( BATCH_SIZE ) ) );

		const { result } = renderHook( () => useAvatars( emails ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.avatars ).toEqual( {
			[ emails[ BATCH_SIZE ] ]: `https://avatar.test/${ emails[ BATCH_SIZE ] }`,
		} );
	} );

	it( 'skips the request entirely for an empty set', async () => {
		const { result } = renderHook( () => useAvatars( [ undefined, '' ] ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( result.current.avatars ).toEqual( {} );
	} );

	it( 'passes an explicit size through to the endpoint', async () => {
		apiFetch.mockImplementation( ( { data } ) => Promise.resolve( avatarsResponseFor( data.emails ) ) );

		const { result } = renderHook( () => useAvatars( [ 'a@test.com' ], { size: 128 } ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { emails: [ 'a@test.com' ], size: 128 } );
	} );
} );
