/**
 * Insights wizard — module-level cache.
 *
 * Slot key = tab|start|end|compare_start|compare_end. Slots are created
 * lazily on first read and survive tab unmounts; React subscribers attach
 * via subscribe() and read via getSlot(). ensureFetched() populates a slot
 * from a fetcher, deduping concurrent calls. refresh() re-runs a fetch
 * unconditionally; cooldown state is carried in the response envelope's
 * `cache.cooldown_until` field. setCooldown() writes the timestamp
 * directly (e.g. after a manual-refresh response that signals throttling).
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

declare global {
	interface Window {
		__newspackInsightsCache?: CacheInternals;
	}
}

// Webpack code-splits each tab into its own lazy chunk and each chunk gets its
// own copy of this module. Anchor the slot/listener state on `window` so every
// copy reads and writes the same Maps — without this, a refresh fired from one
// tab's chunk doesn't notify CooldownNotice or LastUpdated subscribers living
// in the main chunk.
const internals: CacheInternals = ( () => {
	if ( typeof window === 'undefined' ) {
		return { slots: new Map(), listeners: new Map() };
	}
	if ( ! window.__newspackInsightsCache ) {
		window.__newspackInsightsCache = { slots: new Map(), listeners: new Map() };
	}
	return window.__newspackInsightsCache;
} )();

const notify = ( key: string ) => {
	internals.listeners.get( key )?.forEach( listener => listener() );
};

const setSlot = ( key: string, patch: Partial< CacheSlot > ) => {
	const current = internals.slots.get( key ) ?? { ...IDLE_SLOT };
	const next = { ...current, ...patch };
	internals.slots.set( key, next );
	notify( key );
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

	ensureFetched< T >( key: string, fetcher: () => Promise< CachedEnvelope< T > > ): void {
		const slot = this.getSlot< T >( key );
		if ( slot.status === 'success' || slot.inFlight ) {
			return;
		}

		// Mark the slot as in-flight synchronously so a second call entering
		// on the same microtask sees the guard and bails out.
		let settle: () => void = () => {};
		const inFlight = new Promise< void >( resolve => {
			settle = resolve;
		} );
		setSlot( key, { status: 'loading', error: null, inFlight } );

		( async () => {
			try {
				const envelope = await fetcher();
				setSlot( key, {
					status: 'success',
					data: envelope.data,
					error: null,
					computedAt: envelope.cache.computed_at,
					source: envelope.cache.source,
					cooldownUntil: envelope.cache.cooldown_until,
					inFlight: null,
				} );
			} catch ( e: unknown ) {
				const message = e instanceof Error ? e.message : String( e );
				setSlot( key, { status: 'error', error: message, inFlight: null } );
			} finally {
				settle();
			}
		} )();
	},

	refresh< T >( key: string, refresher: () => Promise< CachedEnvelope< T > > ): void {
		let settle: () => void = () => {};
		const inFlight = new Promise< void >( resolve => {
			settle = resolve;
		} );
		setSlot( key, { status: 'loading', error: null, inFlight } );

		( async () => {
			try {
				const envelope = await refresher();
				const incoming = envelope.data as unknown;
				const prior = this.getSlot( key );
				const incomingIsEmpty =
					incoming === null ||
					incoming === undefined ||
					( Array.isArray( incoming ) && incoming.length === 0 ) ||
					( typeof incoming === 'object' && Object.keys( incoming as object ).length === 0 );

				if ( incomingIsEmpty && prior.data !== null ) {
					// Refresh returned no usable payload (BQ unreachable, server
					// fell back, etc.). Preserve the prior data / computedAt so
					// the tab keeps rendering the last known good view; still
					// update the cache metadata in case the throttle changed.
					setSlot( key, {
						status: 'success',
						error: null,
						source: envelope.cache.source,
						cooldownUntil: envelope.cache.cooldown_until,
						inFlight: null,
					} );
				} else {
					setSlot( key, {
						status: 'success',
						data: envelope.data,
						error: null,
						computedAt: envelope.cache.computed_at,
						source: envelope.cache.source,
						cooldownUntil: envelope.cache.cooldown_until,
						inFlight: null,
					} );
				}
			} catch ( e: unknown ) {
				const message = e instanceof Error ? e.message : String( e );
				setSlot( key, { status: 'error', error: message, inFlight: null } );
			} finally {
				settle();
			}
		} )();
	},

	setCooldown( key: string, until: string | null ): void {
		setSlot( key, { cooldownUntil: until } );
	},

	/** Internal testing helper. Not part of the public API. */
	reset(): void {
		internals.slots.clear();
		internals.listeners.clear();
	},
};
