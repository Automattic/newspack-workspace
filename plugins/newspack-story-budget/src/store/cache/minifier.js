/**
 * This module provides functionality to minify large collections of objects by
 * replacing repeated property names with shorter keys. It reduces the size of
 * data stored in cache by compressing object property paths.
 */

/**
 * Flatten an object.
 *
 * @param {Object}   obj      The object to flatten.
 * @param {string}   prefix   The prefix to use for the flattened object.
 * @param {Function} callback The callback to use for each flattened entry.
 */
const flatten = ( obj, prefix, callback ) => {
	for ( const [ key, value ] of Object.entries( obj ) ) {
		const path = prefix ? `${ prefix }.${ key }` : key;
		if ( value && typeof value === 'object' && ! Array.isArray( value ) ) {
			flatten( value, path, callback );
		} else {
			callback( path, value );
		}
	}
};

/**
 * Set a nested value in an object.
 *
 * @param {Object} obj   The object to set the nested value in.
 * @param {string} path  The path to set the nested value in.
 * @param {any}    value The value to set.
 */
const setNested = ( obj, path, value ) => {
	const keys = path.split( '.' );
	let current = obj;
	while ( keys.length > 1 ) {
		const key = keys.shift();
		if ( ! ( key in current ) ) {
			current[ key ] = {};
		}
		current = current[ key ];
	}
	current[ keys[ 0 ] ] = value;
};

/**
 * Check if the data is minifiable.
 *
 * Arrays or objects containing only objects are minifiable.
 *
 * @param {Array|Object} data The array or object to check.
 *
 * @return {boolean} Whether the data is minifiable.
 */
const isMinifiable = data => {
	if ( ! data || typeof data !== 'object' ) {
		return false;
	}
	return Object.values( data ).every( item => typeof item === 'object' );
};

/**
 * Minify an array or object.
 *
 * @param {Array|Object} data The array or object to minify.
 *
 * @return {Object} An object containing the minified data and key map.
 */
const minify = data => {
	if ( ! isMinifiable( data ) ) {
		return { data };
	}

	const keyMap = new Map();

	let keyCounter = 0;
	const getOrAddShortKey = fullPath => {
		if ( ! keyMap.has( fullPath ) ) {
			const shortKey = keyCounter.toString( 36 );
			keyMap.set( fullPath, shortKey );
			keyCounter++;
		}
		return keyMap.get( fullPath );
	};

	const minifyEntry = entry => {
		if ( Array.isArray( entry ) ) {
			return entry;
		}
		const flat = {};
		flatten( entry, '', ( path, value ) => {
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
 * @param {Array|Object} minified The minified data to restore.
 * @param {Object}       keyMap   The key map to use for the restored data.
 *
 * @return {Array|Object} The restored data.
 */
const restore = ( minified, keyMap ) => {
	if ( ! keyMap || Object.keys( keyMap ).length === 0 ) {
		return minified;
	}

	const reverseMap = new Map( Object.entries( keyMap ).map( ( [ full, short ] ) => [ short, full ] ) );

	const restoreEntry = minifiedEntry => {
		if ( Array.isArray( minifiedEntry ) ) {
			return minifiedEntry;
		}
		const entry = {};
		for ( const [ shortKey, value ] of Object.entries( minifiedEntry ) ) {
			const path = reverseMap.get( shortKey );
			if ( path ) {
				setNested( entry, path, value );
			}
		}
		return entry;
	};

	return Array.isArray( minified )
		? minified.map( minifiedEntry => restoreEntry( minifiedEntry ) )
		: Object.fromEntries( Object.entries( minified ).map( ( [ id, minifiedEntry ] ) => [ id, restoreEntry( minifiedEntry ) ] ) );
};

export { isMinifiable, minify, restore };
