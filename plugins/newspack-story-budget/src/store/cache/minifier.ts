/**
 * This module provides functionality to minify large collections of objects by
 * replacing repeated property names with shorter keys. It reduces the size of
 * data stored in cache by compressing object property paths.
 *
 * The data processed here is whatever happens to be cached under a given
 * `STORAGE_KEYS` slice (see `cache/index.ts`) -- arbitrarily-shaped, recursively
 * nested plain objects/arrays with no single static shape, so this module
 * works in `unknown` throughout and narrows structurally rather than typing
 * against any one caller's data shape.
 */

type PlainObject = Record< string, unknown >;

/**
 * Flatten an object.
 *
 * @param obj      The object to flatten.
 * @param prefix   The prefix to use for the flattened object.
 * @param callback The callback to use for each flattened entry.
 */
const flatten = ( obj: PlainObject, prefix: string, callback: ( path: string, value: unknown ) => void ): void => {
	for ( const [ key, value ] of Object.entries( obj ) ) {
		const path = prefix ? `${ prefix }.${ key }` : key;
		if ( value && typeof value === 'object' && ! Array.isArray( value ) ) {
			// Recursing into a nested plain object -- `typeof value === 'object'` plus the
			// array/null exclusions above are exactly `PlainObject`'s runtime contract.
			flatten( value as PlainObject, path, callback );
		} else {
			callback( path, value );
		}
	}
};

/**
 * Set a nested value in an object.
 *
 * @param obj   The object to set the nested value in.
 * @param path  The path to set the nested value in.
 * @param value The value to set.
 */
const setNested = ( obj: PlainObject, path: string, value: unknown ): void => {
	const keys = path.split( '.' );
	let current: PlainObject = obj;
	while ( keys.length > 1 ) {
		const key = keys.shift() as string; // `keys.length > 1` guarantees a defined element.
		if ( ! ( key in current ) ) {
			current[ key ] = {};
		}
		// Only ever populated with plain objects, by this same function, above.
		current = current[ key ] as PlainObject;
	}
	current[ keys[ 0 ] ] = value;
};

type Minifiable = PlainObject | unknown[];

/**
 * Check if the data is minifiable.
 *
 * Arrays or objects containing only objects are minifiable.
 *
 * @param data The array or object to check.
 *
 * @return Whether the data is minifiable.
 */
const isMinifiable = ( data: unknown ): data is Minifiable => {
	if ( ! data || typeof data !== 'object' ) {
		return false;
	}
	return Object.values( data ).every( item => typeof item === 'object' );
};

interface MinifyResult {
	data: unknown;
	keyMap?: Record< string, string >;
}

/**
 * Minify an array or object.
 *
 * @param data The array or object to minify.
 *
 * @return An object containing the minified data and key map.
 */
const minify = ( data: unknown ): MinifyResult => {
	if ( ! isMinifiable( data ) ) {
		return { data };
	}

	const keyMap = new Map< string, string >();

	let keyCounter = 0;
	const getOrAddShortKey = ( fullPath: string ): string => {
		if ( ! keyMap.has( fullPath ) ) {
			const shortKey = keyCounter.toString( 36 );
			keyMap.set( fullPath, shortKey );
			keyCounter++;
		}
		return keyMap.get( fullPath ) as string; // Just set above, if it was missing.
	};

	const minifyEntry = ( entry: unknown ): unknown => {
		if ( Array.isArray( entry ) ) {
			return entry;
		}
		const flat: PlainObject = {};
		// `isMinifiable()` guarantees every entry of `data` is an object (see above).
		flatten( entry as PlainObject, '', ( path, value ) => {
			const shortKey = getOrAddShortKey( path );
			flat[ shortKey ] = value;
		} );
		return flat;
	};

	const minified = Array.isArray( data )
		? data.map( entry => minifyEntry( entry ) )
		: Object.fromEntries( Object.entries( data ).map( ( [ id, entry ] ) => [ id, minifyEntry( entry ) ] ) );

	return {
		data: minified,
		keyMap: Object.fromEntries( keyMap ),
	};
};

/**
 * Restore a minified array or object.
 *
 * @param minified The minified data to restore.
 * @param keyMap   The key map to use for the restored data.
 *
 * @return The restored data.
 */
const restore = ( minified: unknown, keyMap?: Record< string, string > ): unknown => {
	if ( ! keyMap || Object.keys( keyMap ).length === 0 ) {
		return minified;
	}

	const reverseMap = new Map( Object.entries( keyMap ).map( ( [ full, short ] ) => [ short, full ] ) );

	const restoreEntry = ( minifiedEntry: unknown ): unknown => {
		if ( Array.isArray( minifiedEntry ) ) {
			return minifiedEntry;
		}
		const entry: PlainObject = {};
		// Minified entries are always plain objects (see `minifyEntry()` above).
		for ( const [ shortKey, value ] of Object.entries( minifiedEntry as PlainObject ) ) {
			const path = reverseMap.get( shortKey );
			if ( path ) {
				setNested( entry, path, value );
			}
		}
		return entry;
	};

	return Array.isArray( minified )
		? minified.map( minifiedEntry => restoreEntry( minifiedEntry ) )
		: Object.fromEntries( Object.entries( minified as PlainObject ).map( ( [ id, minifiedEntry ] ) => [ id, restoreEntry( minifiedEntry ) ] ) );
};

export { isMinifiable, minify, restore };
