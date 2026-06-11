/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */

import { renderHook, act } from '@testing-library/react';
import useCountdown from './useCountdown';

describe( 'useCountdown', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2026-06-10T00:00:00Z' ) );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'returns the mm:ss string until the deadline', () => {
		const { result } = renderHook( () => useCountdown( '2026-06-10T00:02:30Z' ) );

		expect( result.current ).toBe( '02:30' );

		act( () => {
			jest.advanceTimersByTime( 30 * 1000 );
		} );

		expect( result.current ).toBe( '02:00' );
	} );

	it( 'returns null after the deadline', () => {
		const { result } = renderHook( () => useCountdown( '2026-06-10T00:00:10Z' ) );

		act( () => {
			jest.advanceTimersByTime( 11 * 1000 );
		} );

		expect( result.current ).toBeNull();
	} );

	it( 'returns null when the input is null', () => {
		const { result } = renderHook( () => useCountdown( null ) );
		expect( result.current ).toBeNull();
	} );
} );
