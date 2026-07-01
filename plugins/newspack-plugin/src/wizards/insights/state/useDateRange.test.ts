/**
 * Tests for useDateRange state module.
 */

import { computeRangeForPreset } from './useDateRange';

describe( 'computeRangeForPreset — basic', () => {
	it( 'returns null for custom preset', () => {
		expect( computeRangeForPreset( 'custom' ) ).toBeNull();
	} );

	it( 'returns a 7-day range for last-7', () => {
		const today = new Date( 2026, 5, 15 ); // 2026-06-15 local
		const range = computeRangeForPreset( 'last-7', today );
		expect( range ).toEqual( { start: '2026-06-09', end: '2026-06-15' } );
	} );

	it( 'returns a 30-day range for last-30', () => {
		const today = new Date( 2026, 5, 15 ); // 2026-06-15 local
		const range = computeRangeForPreset( 'last-30', today );
		expect( range ).toEqual( { start: '2026-05-17', end: '2026-06-15' } );
	} );

	it( 'returns a 90-day range for last-90', () => {
		const today = new Date( 2026, 5, 15 ); // 2026-06-15 local
		const range = computeRangeForPreset( 'last-90', today );
		expect( range ).toEqual( { start: '2026-03-18', end: '2026-06-15' } );
	} );

	it( 'returns this-month range', () => {
		const today = new Date( 2026, 5, 15 ); // 2026-06-15
		const range = computeRangeForPreset( 'this-month', today );
		expect( range ).toEqual( { start: '2026-06-01', end: '2026-06-15' } );
	} );

	it( 'returns last-month range', () => {
		const today = new Date( 2026, 5, 15 ); // 2026-06-15
		const range = computeRangeForPreset( 'last-month', today );
		expect( range ).toEqual( { start: '2026-05-01', end: '2026-05-31' } );
	} );
} );

describe( 'computeRangeForPreset — site timezone anchoring', () => {
	afterEach( () => {
		jest.useRealTimers();
		// Clean up window.newspackInsights
		if ( typeof window !== 'undefined' ) {
			delete ( window as any ).newspackInsights;
		}
	} );

	it( 'anchors last-30 end to site-TZ date when browser UTC is a day ahead', () => {
		// UTC 2026-06-30T02:00:00Z → LA still on 2026-06-29
		jest.useFakeTimers().setSystemTime( new Date( '2026-06-30T02:00:00Z' ) );
		( window as any ).newspackInsights = { timezone: 'America/Los_Angeles' };
		const range = computeRangeForPreset( 'last-30' );
		expect( range!.end ).toBe( '2026-06-29' );
	} );

	it( 'anchors last-7 end to site-TZ date when browser UTC is a day ahead', () => {
		jest.useFakeTimers().setSystemTime( new Date( '2026-06-30T02:00:00Z' ) );
		( window as any ).newspackInsights = { timezone: 'America/Los_Angeles' };
		const range = computeRangeForPreset( 'last-7' );
		expect( range!.end ).toBe( '2026-06-29' );
	} );

	it( 'falls back to browser-local date when no timezone is configured', () => {
		jest.useFakeTimers().setSystemTime( new Date( '2026-06-30T02:00:00Z' ) );
		// No window.newspackInsights set
		expect( () => computeRangeForPreset( 'last-7' ) ).not.toThrow();
		const range = computeRangeForPreset( 'last-7' );
		expect( range!.end ).toBeTruthy(); // valid ISO date string
	} );
} );
