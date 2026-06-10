/**
 * Tests for the insightsCache module.
 */

import { insightsCache, makeSlotKey } from './insightsCache';

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
