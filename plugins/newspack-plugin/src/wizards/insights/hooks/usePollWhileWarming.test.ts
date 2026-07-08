/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */

/**
 * External dependencies
 */
import { renderHook, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import usePollWhileWarming, { POLL_INTERVAL_MS, MAX_POLL_ATTEMPTS } from './usePollWhileWarming';

type Payload = { data_status?: 'complete' | 'warming' | 'incomplete' };

describe( 'usePollWhileWarming', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'stops after exactly one extra fetch once the payload turns complete', () => {
		const refetch = jest.fn();
		const { result, rerender } = renderHook(
			( { key, data }: { key: string; data: Payload | null } ) => usePollWhileWarming( key, data, refetch ),
			{ initialProps: { key: 'k', data: { data_status: 'warming' } as Payload } }
		);

		act( () => {
			jest.advanceTimersByTime( POLL_INTERVAL_MS );
		} );

		expect( refetch ).toHaveBeenCalledTimes( 1 );

		// Store now returns a complete payload.
		rerender( { key: 'k', data: { data_status: 'complete' } } );

		act( () => {
			jest.advanceTimersByTime( POLL_INTERVAL_MS );
		} );

		expect( refetch ).toHaveBeenCalledTimes( 1 );
		expect( result.current?.data_status ).toBe( 'complete' );
	} );

	it( 'escalates to incomplete and stops polling after the cap', () => {
		const refetch = jest.fn();
		const { result } = renderHook( () => usePollWhileWarming( 'k', { data_status: 'warming' } as Payload, refetch ) );

		for ( let i = 0; i < MAX_POLL_ATTEMPTS; i++ ) {
			act( () => {
				jest.advanceTimersByTime( POLL_INTERVAL_MS );
			} );
		}

		expect( refetch ).toHaveBeenCalledTimes( MAX_POLL_ATTEMPTS );
		expect( result.current?.data_status ).toBe( 'incomplete' );

		// No runaway: further time does not fire more fetches.
		act( () => {
			jest.advanceTimersByTime( POLL_INTERVAL_MS * 5 );
		} );

		expect( refetch ).toHaveBeenCalledTimes( MAX_POLL_ATTEMPTS );
	} );

	it( 'clears the timer on unmount', () => {
		const refetch = jest.fn();
		const { unmount } = renderHook( () => usePollWhileWarming( 'k', { data_status: 'warming' } as Payload, refetch ) );

		unmount();

		act( () => {
			jest.advanceTimersByTime( POLL_INTERVAL_MS * 3 );
		} );

		expect( refetch ).not.toHaveBeenCalled();
	} );

	it( 'resets and resumes polling when the key changes', () => {
		const refetch = jest.fn();
		const { result, rerender } = renderHook(
			( { key, data }: { key: string; data: Payload | null } ) => usePollWhileWarming( key, data, refetch ),
			{ initialProps: { key: 'k1', data: { data_status: 'warming' } as Payload } }
		);

		// Escalate the first key.
		for ( let i = 0; i < MAX_POLL_ATTEMPTS; i++ ) {
			act( () => {
				jest.advanceTimersByTime( POLL_INTERVAL_MS );
			} );
		}
		expect( refetch ).toHaveBeenCalledTimes( MAX_POLL_ATTEMPTS );
		expect( result.current?.data_status ).toBe( 'incomplete' );

		// A new window/date range: fresh key, still warming.
		rerender( { key: 'k2', data: { data_status: 'warming' } } );

		// Not stuck escalated.
		expect( result.current?.data_status ).toBe( 'warming' );

		act( () => {
			jest.advanceTimersByTime( POLL_INTERVAL_MS );
		} );

		expect( refetch ).toHaveBeenCalledTimes( MAX_POLL_ATTEMPTS + 1 );
	} );
} );
