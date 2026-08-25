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
import { useSubscriber } from './use-subscriber';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'useSubscriber', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'reads one subscriber by id', async () => {
		apiFetch.mockResolvedValue( { id: 42, name: 'Ada', groups: [], subscriptions: [] } );

		const { result } = renderHook( () => useSubscriber( 42 ) );

		expect( result.current.loading ).toBe( true );
		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe( '/newspack/v1/wizard/newspack-subscribers/subscribers/42' );
		expect( result.current.subscriber.name ).toBe( 'Ada' );
		expect( result.current.notFound ).toBe( false );
	} );

	it( 'distinguishes a missing subscriber from a failed read', async () => {
		// The screen shows a different message for each: "no such subscriber" is a
		// dead end, a failed read is worth retrying. Collapsing both into `error`
		// would offer a Retry button that can never succeed.
		apiFetch.mockRejectedValue( { code: 'newspack_subscriber_not_found', message: 'Subscriber not found.' } );

		const { result } = renderHook( () => useSubscriber( 999 ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.notFound ).toBe( true );
		expect( result.current.subscriber ).toBeNull();
	} );

	it( 'treats a non-numeric id (rest_no_route) as not-found, not a retryable error', async () => {
		// #/subscribers/abc never matches the `(?P<id>\d+)` route, so the REST API
		// answers rest_no_route. That can never succeed on retry, so it is a dead
		// end like a deleted user — not the network failure a Retry button implies.
		apiFetch.mockRejectedValue( { code: 'rest_no_route', message: 'No route was found matching the URL and request method.' } );

		const { result } = renderHook( () => useSubscriber( 'abc' ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.notFound ).toBe( true );
		expect( result.current.subscriber ).toBeNull();
	} );

	it( 'reports a transport failure as retryable, not as a missing subscriber', async () => {
		apiFetch.mockRejectedValue( new Error( 'boom' ) );

		const { result } = renderHook( () => useSubscriber( 42 ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( result.current.error ).toBeTruthy();
		expect( result.current.notFound ).toBe( false );
	} );

	it( 'clears the error and refetches on reload', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

		const { result } = renderHook( () => useSubscriber( 42 ) );

		await waitFor( () => expect( result.current.error ).toBeTruthy() );

		apiFetch.mockResolvedValue( { id: 42, name: 'Ada' } );
		act( () => result.current.reload() );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( result.current.error ).toBeFalsy();
		expect( result.current.subscriber.name ).toBe( 'Ada' );
	} );

	it( 'never shows the previous person: the id change clears the subscriber before the new fetch resolves', async () => {
		apiFetch.mockResolvedValue( { id: 1, name: 'First' } );

		const { result, rerender } = renderHook( ( { id } ) => useSubscriber( id ), { initialProps: { id: 1 } } );
		await waitFor( () => expect( result.current.subscriber?.name ).toBe( 'First' ) );

		// Hold the second fetch open so the transition window is observable.
		let resolveSecond;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				resolveSecond = resolve;
			} )
		);
		rerender( { id: 2 } );

		// Mid-transition: the first person is already gone, not lingering under id 2.
		await waitFor( () => expect( result.current.loading ).toBe( true ) );
		expect( result.current.subscriber ).toBeNull();

		resolveSecond( { id: 2, name: 'Second' } );
		await waitFor( () => expect( result.current.subscriber?.name ).toBe( 'Second' ) );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toBe( '/newspack/v1/wizard/newspack-subscribers/subscribers/2' );
	} );
} );
