window.newspack_reader_data = window.newspack_reader_data || {};

import { EVENTS, emit, on } from './events';
import { getApiNonce } from './session';

/**
 * The public reader data store (readerActivation.store).
 */
export type ReaderActivationStore = NewspackReaderActivationStore;

/**
 * The internal, destructive clear() kept off the public store object.
 */
export type ReaderActivationStoreClear = () => void;

/**
 * A merge strategy used to reconcile server and client values on rehydration.
 */
type MergeStrategy = ( serverValue: unknown, clientValue: unknown ) => unknown;

/**
 * Store configuration: the store-key prefix, the backing Storage object, and
 * the collection caps applied by store.add() — the maximum number of items and
 * the maximum age of a collection item when a 'timestamp' is set.
 */
const config = {
	storePrefix: newspack_reader_data?.store_prefix || 'np_reader_',
	storage: newspack_reader_data?.is_temporary ? window.sessionStorage : window.localStorage,
	collections: {
		maxItems: 1000,
		maxAge: 1000 * 60 * 60 * 24 * 30, // 30 days.
	},
};

/**
 * Registry of merge strategies for rehydration.
 */
const mergeStrategies = new Map< string, MergeStrategy >();

/**
 * Key under which each store's internal clear() is stashed on the public store
 * object so the singleton guard in Store() can recover it. A global-registry
 * Symbol ( Symbol.for ) is shared across separately-bundled copies of this
 * module, so a fresh module instance that hits the singleton guard still finds
 * the live store's clear() — without exposing it on the enumerable public API
 * as readerActivation.store.clear(). See NPPM-2721.
 */
const INTERNAL_CLEAR = Symbol.for( 'newspack.reader_activation.store.internal_clear' );

/**
 * Rehydrate a single item from server data, using the registered merge
 * strategy if one exists. Falls back to a direct overwrite.
 *
 * @param key         Store key.
 * @param serverValue Decoded value from the server.
 */
function rehydrateItem( key: string, serverValue: unknown ): void {
	const merge = mergeStrategies.get( key );
	try {
		if ( merge ) {
			const clientValue = _get( key );
			_set( key, merge( serverValue, clientValue ) );
		} else {
			_set( key, serverValue );
		}
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.warn( `Unable to rehydrate ${ key }`, err );
		// Fall back to overwriting with the server value so a failing merge
		// strategy can't leave the store in an inconsistent/missing state.
		_set( key, serverValue );
	}
}

/**
 * Initialize sync interval.
 *
 * @param queue Store items keys to sync.
 */
function initializeSyncInterval( queue: string[] ): void {
	setInterval( () => {
		// Bail if there are no items to sync or if it's a temporary session.
		if ( ! queue.length || newspack_reader_data?.is_temporary ) {
			return;
		}
		const key = queue.shift();
		if ( key === undefined ) {
			return;
		}
		syncItem( key )
			.then( () => clearPendingSync( key ) )
			.catch( () => setPendingSync( key ) );
	}, 1000 );
}

/**
 * Get store item key
 *
 * @param internal Whether it's an internal (bookkeeping) prefix.
 *
 * @return Store prefix string.
 */
function getStorePrefix( internal = false ): string {
	const parts = [ config.storePrefix ];
	if ( internal ) {
		parts.push( '_' );
	}
	return parts.join( '' );
}

/**
 * Get store item key
 *
 * @param key      Key to get.
 * @param internal Whether it's an internal value.
 *
 * @return Store item key.
 */
export function getStoreItemKey( key: string, internal = false ): string {
	if ( ! key ) {
		throw new Error( 'Key is required.' );
	}
	return getStorePrefix( internal ) + key;
}

/**
 * Read the persisted unsynced-keys queue. The value is JSON round-tripped
 * through storage, so it is narrowed at this single boundary.
 */
function getUnsyncedKeys(): string[] {
	const value = _get( 'unsynced', true );
	return Array.isArray( value ) ? ( value as string[] ) : [];
}

/**
 * Set a key as pending sync.
 *
 * @param key Key to flag.
 */
function setPendingSync( key: string ): void {
	const unsynced = getUnsyncedKeys();
	if ( unsynced.includes( key ) ) {
		return;
	}
	unsynced.push( key );
	_set( 'unsynced', unsynced, true );
}

/**
 * Clear a key from pending sync.
 *
 * @param key Key to clear.
 */
function clearPendingSync( key: string ): void {
	const unsynced = getUnsyncedKeys();
	if ( ! unsynced.includes( key ) ) {
		return;
	}
	unsynced.splice( unsynced.indexOf( key ), 1 );
	_set( 'unsynced', unsynced, true );
}

/**
 * Send a data item to the server.
 *
 * @param key Data key.
 *
 * @return Promise that resolves when the request is complete.
 */
function syncItem( key: string ): Promise< XMLHttpRequest | void > {
	if ( ! key ) {
		return Promise.reject( 'Key is required.' );
	}
	const apiNonce = getApiNonce();
	if ( ! newspack_reader_data.api_url || ! apiNonce ) {
		return Promise.reject( 'API not available.' );
	}

	const value = _get( key );
	const payload: { key: string; value?: string } = { key };
	if ( value ) {
		payload.value = JSON.stringify( value );
	}

	// Bail if value matches server value.
	if ( newspack_reader_data?.items && newspack_reader_data.items[ key ] === payload.value ) {
		return Promise.resolve();
	}

	const req = new XMLHttpRequest();
	req.open( payload.value ? 'POST' : 'DELETE', newspack_reader_data.api_url, true );
	req.setRequestHeader( 'Content-Type', 'application/json' );
	req.setRequestHeader( 'X-WP-Nonce', apiNonce );

	// Send request.
	req.send( JSON.stringify( payload ) );

	return new Promise( ( resolve, reject ) => {
		req.onreadystatechange = () => {
			if ( 4 !== req.readyState ) {
				return;
			}
			if ( 200 !== req.status ) {
				return reject( req );
			}
			// Update the known server value. The items cache is asserted present —
			// it is seeded at module load and this mirrors the pre-TS behavior of
			// failing loudly if it were ever removed.
			( newspack_reader_data.items as Record< string, unknown > )[ key ] = payload.value;
			return resolve( req );
		};
	} );
}

/**
 * Encode object to be stored.
 *
 * @param object Object to encode.
 *
 * @return Encoded object.
 */
function encode( object: unknown ): string {
	return JSON.stringify( object );
}

/**
 * Decode object to be read.
 *
 * @param str String to decode.
 *
 * @return Decoded string.
 */
function decode( str: unknown ): unknown {
	if ( ! str || 'string' !== typeof str ) {
		return str;
	}
	return JSON.parse( str );
}

/**
 * Assert that a key is not read-only.
 *
 * @param  key Key to check.
 * @throws {Error} If the key is read-only.
 */
function assertNotReadOnly( key: string ): void {
	if ( ( newspack_reader_data?.read_only_keys || [] ).includes( key ) ) {
		throw new Error( `Key '${ key }' is read-only.` );
	}
}

/**
 * Internal get function to fetch data from storage.
 *
 * @param key      Key to get.
 * @param internal Whether it's an internal value.
 *
 * @return Value. Null if not set.
 */
function _get( key: string, internal = false ): unknown {
	if ( ! key ) {
		throw new Error( 'Key is required.' );
	}
	return decode( config.storage.getItem( getStoreItemKey( key, internal ) ) );
}

/**
 * Internal set function to set data in storage.
 *
 * @param key      Key to set.
 * @param value    Value to set.
 * @param internal Whether it's an internal value.
 */
function _set( key: string, value: unknown, internal = false ): void {
	if ( ! key ) {
		throw new Error( 'Key is required.' );
	}
	if ( value === undefined || value === null ) {
		throw new Error( 'Value cannot be undefined or null.' );
	}
	if ( '_' === key[ 0 ] ) {
		throw new Error( 'Key cannot start with an underscore.' );
	}
	config.storage.setItem( getStoreItemKey( key, internal ), encode( value ) );
	if ( ! internal ) {
		emit( EVENTS.data, { key, value } );
	}
}

/**
 * Store.
 *
 * Returns a two-element tuple: the public store object (assigned to
 * `readerActivation.store` and therefore reachable by third-party code) and an
 * internal `clear()` that is deliberately kept off the public object. `clear()`
 * wipes the whole reader namespace and bypasses read-only-key protections, so
 * only init()'s post-logout handler should call it. See NPPM-2721.
 *
 * @return `[ publicStore, internalClear ]`.
 */
export default function Store(): [ ReaderActivationStore, ReaderActivationStoreClear ] {
	/**
	 * There should only be one store instance.
	 */
	if ( window.newspackRASInitialized && window.newspackReaderActivation?.store ) {
		const existingStore = window.newspackReaderActivation.store;
		// The live store carries its internal clear() under the global-registry
		// Symbol (see INTERNAL_CLEAR); recover it through that opaque boundary.
		const existingClear = ( existingStore as ReaderActivationStore & Record< symbol, ReaderActivationStoreClear > )[ INTERNAL_CLEAR ];
		return [ existingStore, existingClear ];
	}

	/**
	 * Queue of keys to sync with the server every second.
	 */
	const syncQueue: string[] = [];
	initializeSyncInterval( syncQueue );

	// Push unsynced items to the sync queue, pruning existing read-only
	// keys in order to address the upgrade case.
	const readOnlyKeys = newspack_reader_data?.read_only_keys || [];
	const unsynced = getUnsyncedKeys().filter( key => ! readOnlyKeys.includes( key ) );
	_set( 'unsynced', unsynced, true );
	for ( const key of unsynced ) {
		if ( ! syncQueue.includes( key ) ) {
			syncQueue.push( key );
		}
	}

	// When session hydration provides a nonce, rehydrate server items
	// and re-queue any unsynced items.
	on( EVENTS.session, ( { detail } ) => {
		const items = detail?.reader_data_items || {};
		newspack_reader_data.items = items;
		rehydrate( items );
		// Re-queue unsynced items.
		const unsyncedKeys = getUnsyncedKeys();
		for ( const key of unsyncedKeys ) {
			if ( ! syncQueue.includes( key ) ) {
				syncQueue.push( key );
			}
		}
	} );

	/**
	 * Rehydrate items from server data. Must be called after all merge
	 * strategies have been registered via store.register().
	 *
	 * Merge strategies must be registered synchronously before this
	 * method runs — async registration is not supported.
	 *
	 * @param items Items to rehydrate. Defaults to newspack_reader_data.items.
	 */
	function rehydrate( items: Record< string, unknown > | undefined = newspack_reader_data?.items ): void {
		if ( ! items || newspack_reader_data?.is_temporary ) {
			return;
		}
		const unsyncedKeys = getUnsyncedKeys();
		for ( const key of Object.keys( items ) ) {
			// Skip unsynced items unless they have a merge strategy,
			// which is the authority on how to reconcile values.
			if ( unsyncedKeys.includes( key ) && ! mergeStrategies.has( key ) ) {
				continue;
			}
			rehydrateItem( key, decode( items[ key ] ) );
		}
	}

	/**
	 * Drain both the in-memory and persisted sync queues so no stale
	 * write fires on a subsequent sync tick. Module-internal — called by
	 * clear() but not exposed on the public store API.
	 */
	function drainSyncQueue(): void {
		syncQueue.length = 0;
		_set( 'unsynced', [], true );
	}

	/**
	 * Wipe the entire reader-data namespace from storage and reseed an
	 * anonymous reader stub. Kept off the public store object (returned
	 * separately from Store()) because it bypasses read-only-key protections
	 * and would silently destroy reader state if called by third-party code.
	 * Only init()'s post-logout divergence handler should call it (NPPM-2721).
	 */
	function clear(): void {
		const prefix = getStorePrefix( false );
		// Guard against an empty prefix: newspack_reader_data.store_prefix is
		// PHP-filterable, and an accidental '' would make startsWith('') match
		// every key in the storage backend — wiping unrelated app/3rd-party
		// localStorage. Bail rather than nuke the whole origin.
		if ( ! prefix ) {
			return;
		}
		const internalPrefix = getStorePrefix( true );
		const keysToRemove: string[] = [];
		for ( let i = 0; i < config.storage.length; i++ ) {
			const storageKey = config.storage.key( i );
			if ( storageKey && storageKey.startsWith( prefix ) ) {
				keysToRemove.push( storageKey );
			}
		}
		for ( const storageKey of keysToRemove ) {
			config.storage.removeItem( storageKey );
			// Emit a per-key data event for each wiped public key, mirroring
			// delete(), so consumers that invalidate caches keyed by detail.key
			// don't silently keep stale data. Skip internal bookkeeping keys and
			// the 'reader' key (reseeded below, which emits its own data event).
			if ( ! storageKey.startsWith( internalPrefix ) ) {
				const key = storageKey.slice( prefix.length );
				if ( key && 'reader' !== key ) {
					emit( EVENTS.data, { key, value: undefined } );
				}
			}
		}
		drainSyncQueue();
		// Reset the in-memory server-known-items cache that syncItem reads to
		// short-circuit no-op writes. window.newspack_reader_data is initialized
		// at module load (top of this file), so no presence guard is needed.
		//
		// Load-bearing: this REASSIGNS the property to a fresh object rather than
		// mutating it in place. init()'s account-switch restore (NPPM-2899) captures a
		// reference to the prior items object *before* calling clear() and replays it
		// after the wipe to rehydrate the switched-in reader's own server data. That
		// relies on the captured reference staying intact — switching to in-place key
		// deletion (e.g. `delete items[k]`) would silently empty it and break the restore.
		newspack_reader_data.items = {};
		// Reseed via _set (not public set) so the reseed itself doesn't enqueue a
		// server write — and so init()'s trailing equality check skips its own
		// store.set('reader', ...) call. _set emits EVENTS.data for 'reader';
		// init() emits EVENTS.reader after calling clear(), so the reader-state
		// change is signalled without clear() double-emitting it.
		_set( 'reader', { authenticated: false } );
	}

	const publicStore: ReaderActivationStore = {
		/**
		 * Get a value from the store.
		 *
		 * @param key Key to get.
		 *
		 * @return Value. Null if not set.
		 */
		get: ( key: string ): unknown => {
			if ( ! key ) {
				throw new Error( 'Key is required.' );
			}
			return _get( key );
		},
		/**
		 * Get all values from the store.
		 *
		 * Iterates over keys in storage, filtering by our
		 * store prefix to ensure only relevant items are included.
		 *
		 * @return Plain object with all key-value pairs.
		 */
		getAll: (): Record< string, unknown > => {
			const data: Record< string, unknown > = {};
			const prefix = getStorePrefix( false );
			const internalPrefix = getStorePrefix( true );
			for ( let i = 0; i < config.storage.length; i++ ) {
				const storageKey = config.storage.key( i );
				if ( ! storageKey ) {
					continue;
				}
				if ( storageKey.startsWith( prefix ) && ! storageKey.startsWith( internalPrefix ) ) {
					const key = storageKey.slice( prefix.length );
					data[ key ] = decode( config.storage.getItem( storageKey ) );
				}
			}
			return data;
		},
		/**
		 * Set a value in the store.
		 *
		 * @param key   Key to set.
		 * @param value Value to set.
		 * @param sync  Whether to sync the value with the server. Default true.
		 */
		set: ( key: string, value: unknown, sync = true ): void => {
			assertNotReadOnly( key );
			_set( key, value, false );
			if ( sync ) {
				setPendingSync( key );
				syncQueue.push( key );
			}
		},
		/**
		 * Delete a value from the store.
		 *
		 * @param key Key to delete.
		 */
		delete: ( key: string ): void => {
			if ( ! key ) {
				throw new Error( 'Key is required.' );
			}
			assertNotReadOnly( key );
			config.storage.removeItem( getStoreItemKey( key ) );
			emit( EVENTS.data, { key, value: undefined } );
			setPendingSync( key );
			syncQueue.push( key );
		},
		/**
		 * Add a value to a collection.
		 *
		 * @param key   Collection key to add to.
		 * @param value Value to add. An optional `timestamp` member is used for max age.
		 */
		add: ( key: string, value: unknown ): void => {
			if ( ! key ) {
				throw new Error( 'Key cannot be empty.' );
			}
			assertNotReadOnly( key );
			if ( ! value ) {
				throw new Error( 'Value cannot be empty.' );
			}
			const storedCollection = _get( key ) || [];
			if ( ! Array.isArray( storedCollection ) ) {
				throw new Error( `Store key '${ key }' is not an array.` );
			}
			let collection: unknown[] = storedCollection;

			// Remove items older than max age if `timestamp` is set.
			if ( config.collections.maxAge ) {
				const now = Date.now();
				collection = collection.filter( item => {
					const timestamp = ( item as { timestamp?: unknown } )?.timestamp;
					return ! timestamp || now - Number( timestamp ) < config.collections.maxAge;
				} );
			}

			collection.push( value );

			// Remove items if max items is reached.
			collection = collection.slice( -config.collections.maxItems );

			_set( key, collection );
		},
		/**
		 * Register a merge strategy for a store key. The merge function is
		 * called during rehydration to reconcile server and client values.
		 *
		 * @param key           Store key.
		 * @param options       Options containing the merge function.
		 * @param options.merge Merge callback: (serverValue, clientValue) => resolvedValue.
		 */
		register: ( key: string, { merge }: { merge?: MergeStrategy } = {} ): void => {
			if ( typeof merge !== 'function' ) {
				throw new Error( `Store key '${ key }' requires a merge function.` );
			}
			if ( mergeStrategies.has( key ) ) {
				// eslint-disable-next-line no-console
				console.warn( `Store key '${ key }' already has a merge strategy registered. Overwriting.` );
			}
			mergeStrategies.set( key, merge );
		},
		/**
		 * Rehydrate items from server data. Must be called after all merge
		 * strategies have been registered.
		 */
		rehydrate,
	};

	// Stash clear() on the store under a non-enumerable, global-registry Symbol so
	// the singleton guard above can recover it from the live store (even from a
	// separately-bundled module instance), without exposing it on the enumerable
	// public API as readerActivation.store.clear() (NPPM-2721). Callers get it as
	// the tuple's second element; third-party code iterating the store never sees it.
	Object.defineProperty( publicStore, INTERNAL_CLEAR, { value: clear } );
	return [ publicStore, clear ];
}
