/**
 * Tests for the insightsCache module.
 */

import { CachedEnvelope, insightsCache, makeSlotKey } from './insightsCache';

describe( 'makeSlotKey', () => {
	it( 'composes a stable key from tab + window components', () => {
		const range = { start: '2026-01-01', end: '2026-01-31' };
		const compare = { start: '2025-12-01', end: '2025-12-31' };

		expect( makeSlotKey( 'engagement', range, null ) ).toBe( 'engagement|2026-01-01|2026-01-31||' );
		expect( makeSlotKey( 'engagement', range, compare ) ).toBe( 'engagement|2026-01-01|2026-01-31|2025-12-01|2025-12-31' );
	} );
} );

describe( 'insightsCache.getSlot', () => {
	afterEach( () => {
		( insightsCache as unknown as { reset: () => void } ).reset();
	} );

	it( 'returns an idle slot for an unknown key', () => {
		const slot = insightsCache.getSlot( 'audience|2026-01-01|2026-01-31||' );
		expect( slot.status ).toBe( 'idle' );
		expect( slot.data ).toBeNull();
		expect( slot.error ).toBeNull();
		expect( slot.computedAt ).toBeNull();
		expect( slot.cooldownUntil ).toBeNull();
	} );

	it( 'returns the same object instance for the same key', () => {
		const a = insightsCache.getSlot( 'k' );
		const b = insightsCache.getSlot( 'k' );
		expect( a ).toBe( b );
	} );
} );

describe( 'insightsCache.ensureFetched', () => {
	afterEach( () => {
		( insightsCache as unknown as { reset: () => void } ).reset();
	} );

	const envelope: CachedEnvelope< { v: number } > = {
		cache: { source: 'external', computed_at: '2026-06-10T00:00:00Z', cooldown_until: null },
		data: { v: 1 },
	};

	it( 'populates the slot from a successful fetcher', async () => {
		const fetcher = jest.fn().mockResolvedValue( envelope );

		insightsCache.ensureFetched( 'k', fetcher );
		await Promise.resolve();
		await Promise.resolve();

		const slot = insightsCache.getSlot< { v: number } >( 'k' );
		expect( slot.status ).toBe( 'success' );
		expect( slot.data ).toEqual( { v: 1 } );
		expect( slot.computedAt ).toBe( '2026-06-10T00:00:00Z' );
		expect( slot.source ).toBe( 'external' );
	} );

	it( 'records errors and exposes the message', async () => {
		const fetcher = jest.fn().mockRejectedValue( new Error( 'kaboom' ) );

		insightsCache.ensureFetched( 'k', fetcher );
		await Promise.resolve();
		await Promise.resolve();

		expect( insightsCache.getSlot( 'k' ).status ).toBe( 'error' );
		expect( insightsCache.getSlot( 'k' ).error ).toBe( 'kaboom' );
	} );

	it( 'is a no-op when the slot is already in success state', async () => {
		const fetcher = jest.fn().mockResolvedValue( envelope );
		insightsCache.ensureFetched( 'k', fetcher );
		await Promise.resolve();
		await Promise.resolve();

		insightsCache.ensureFetched( 'k', fetcher );
		await Promise.resolve();

		expect( fetcher ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'dedupes concurrent in-flight calls', async () => {
		let resolveIt: ( v: typeof envelope ) => void = () => {};
		const slow = new Promise< typeof envelope >( resolve => {
			resolveIt = resolve;
		} );
		const fetcher = jest.fn().mockReturnValue( slow );

		insightsCache.ensureFetched( 'k', fetcher );
		insightsCache.ensureFetched( 'k', fetcher );
		resolveIt( envelope );
		await Promise.resolve();
		await Promise.resolve();

		expect( fetcher ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'notifies subscribers on state transitions', async () => {
		const listener = jest.fn();
		insightsCache.subscribe( 'k', listener );

		insightsCache.ensureFetched( 'k', () => Promise.resolve( envelope ) );
		await Promise.resolve();
		await Promise.resolve();

		expect( listener.mock.calls.length ).toBeGreaterThanOrEqual( 2 ); // loading + success
	} );
} );

describe( 'insightsCache.refresh', () => {
	afterEach( () => {
		( insightsCache as unknown as { reset: () => void } ).reset();
	} );

	const fresh: CachedEnvelope< { v: number } > = {
		cache: { source: 'bigquery', computed_at: '2026-06-10T00:05:00Z', cooldown_until: null },
		data: { v: 99 },
	};

	it( 'replaces the slot with the refreshed envelope', async () => {
		insightsCache.refresh( 'k', () => Promise.resolve( fresh ) );
		await Promise.resolve();
		await Promise.resolve();

		const slot = insightsCache.getSlot< { v: number } >( 'k' );
		expect( slot.status ).toBe( 'success' );
		expect( slot.data ).toEqual( { v: 99 } );
		expect( slot.computedAt ).toBe( '2026-06-10T00:05:00Z' );
		expect( slot.cooldownUntil ).toBeNull();
	} );

	it( 'reads cooldown_until from the refreshed envelope', async () => {
		const throttled: CachedEnvelope< { v: number } > = {
			cache: { source: 'bigquery', computed_at: '2026-06-09T00:00:00Z', cooldown_until: '2026-06-10T00:10:00Z' },
			data: { v: 1 },
		};

		insightsCache.refresh( 'k', () => Promise.resolve( throttled ) );
		await Promise.resolve();
		await Promise.resolve();

		const slot = insightsCache.getSlot< { v: number } >( 'k' );
		expect( slot.cooldownUntil ).toBe( '2026-06-10T00:10:00Z' );
		expect( slot.data ).toEqual( { v: 1 } );
		expect( slot.computedAt ).toBe( '2026-06-09T00:00:00Z' );
	} );

	it( 'setCooldown can clear the cooldown', () => {
		insightsCache.setCooldown( 'k', '2026-06-10T00:10:00Z' );
		expect( insightsCache.getSlot( 'k' ).cooldownUntil ).toBe( '2026-06-10T00:10:00Z' );
		insightsCache.setCooldown( 'k', null );
		expect( insightsCache.getSlot( 'k' ).cooldownUntil ).toBeNull();
	} );
} );
