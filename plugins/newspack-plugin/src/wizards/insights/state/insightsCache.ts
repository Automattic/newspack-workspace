/**
 * Insights wizard — module-level cache.
 *
 * Slot key = tab|start|end|compare_start|compare_end. Slots are created
 * lazily on first read and survive tab unmounts; React subscribers attach
 * via subscribe() and read via getSlot(). The ensureFetched / refresh /
 * setCooldown / CooldownError APIs land in subsequent tasks.
 */

type Source = 'bigquery' | 'external' | 'local';
type Status = 'idle' | 'loading' | 'success' | 'error';

export interface CachedEnvelope< T > {
	cache: { source: Source; computed_at: string; cooldown_until: string | null };
	data: T;
}

export interface CacheSlot< T = unknown > {
	status: Status;
	data: T | null;
	error: string | null;
	computedAt: string | null;
	source: Source | null;
	cooldownUntil: string | null;
	inFlight: Promise< void > | null;
}

const IDLE_SLOT: CacheSlot = Object.freeze( {
	status: 'idle',
	data: null,
	error: null,
	computedAt: null,
	source: null,
	cooldownUntil: null,
	inFlight: null,
} );

export const makeSlotKey = ( tab: string, range: { start: string; end: string }, previousRange: { start: string; end: string } | null ): string =>
	`${ tab }|${ range.start }|${ range.end }|${ previousRange?.start ?? '' }|${ previousRange?.end ?? '' }`;

interface CacheInternals {
	slots: Map< string, CacheSlot >;
	listeners: Map< string, Set< () => void > >;
}

const internals: CacheInternals = {
	slots: new Map(),
	listeners: new Map(),
};

export const insightsCache = {
	getSlot< T >( key: string ): CacheSlot< T > {
		if ( ! internals.slots.has( key ) ) {
			internals.slots.set( key, { ...IDLE_SLOT } );
		}
		return internals.slots.get( key ) as CacheSlot< T >;
	},

	subscribe( key: string, listener: () => void ): () => void {
		const set = internals.listeners.get( key ) ?? new Set();
		set.add( listener );
		internals.listeners.set( key, set );
		return () => set.delete( listener );
	},

	/** Internal testing helper. Not part of the public API. */
	reset(): void {
		internals.slots.clear();
		internals.listeners.clear();
	},
};
