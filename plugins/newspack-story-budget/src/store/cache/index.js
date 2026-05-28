import { minify, restore } from './minifier';
import { isRemoteSite } from '../../utils/sites';
import { isBudgetStories } from '../../utils/budgets';

/**
 * Helper functions for caching data in session storage.
 */
const STORAGE_KEY_BASE = 'newspack-story-budget-';

// Cache configuration.
export const STORAGE_KEYS = {
	fields: {
		actions: [ 'fetchFields' ],
		ttl: 1000 * 60 * 60 * 24, // 24 hours
	},
	stories: {
		actions: [ 'refreshStories' ],
		ttl: 1000 * 30, // 30 seconds
	},
	view: {}, // No expiration.
	meta: {}, // No expiration.
};

/**
 * Encode object to be stored.
 *
 * @param {Object} object Object to encode.
 *
 * @return {string} Encoded object.
 */
export function encode( object ) {
	return JSON.stringify( object );
}

/**
 * Decode object to be read.
 *
 * @param {string} str String to decode.
 *
 * @return {Object} Decoded string.
 */
export function decode( str ) {
	if ( ! str || 'string' !== typeof str ) {
		return str;
	}
	return JSON.parse( str );
}

/**
 * Get a cached object.
 *
 * @param {string} key Cache.
 *
 * @return {Object} Cached data.
 */
export function getCache( key ) {
	if ( ! STORAGE_KEYS.hasOwnProperty( key ) ) {
		return null;
	}
	try {
		const cache = decode( sessionStorage.getItem( STORAGE_KEY_BASE + key ) );
		if ( ! cache?.data ) {
			return null;
		}
		cache.data = restore( cache.data, cache.keyMap );

		return cache;
	} catch ( error ) {
		console.warn( 'Unable to get cache for key:', key, error ); // eslint-disable-line no-console
		return null;
	}
}

/**
 * Set a cached object.
 *
 * @param {string} key  Cache.
 * @param {Object} data Data to set.
 */
export function setCache( key, data ) {
	if ( ! STORAGE_KEYS.hasOwnProperty( key ) ) {
		return;
	}

	const minified = minify( data );

	try {
		sessionStorage.removeItem( STORAGE_KEY_BASE + key );
		sessionStorage.setItem(
			STORAGE_KEY_BASE + key,
			encode( {
				timestamp: Date.now(),
				keyMap: minified.keyMap,
				data: minified.data,
			} )
		);
	} catch ( error ) {
		console.warn( 'Unable to set cache for key:', key, error ); // eslint-disable-line no-console
	}
}

/**
 * Delete a cached object.
 *
 * @param {string} key Cache.
 */
export function deleteCache( key ) {
	if ( ! STORAGE_KEYS.hasOwnProperty( key ) ) {
		return;
	}
	try {
		sessionStorage.removeItem( STORAGE_KEY_BASE + key );
	} catch ( error ) {
		console.warn( 'Unable to delete cache for key:', key, error ); // eslint-disable-line no-console
	}
}

/**
 * Check if cache can be used.
 *
 * @return {boolean} True if cache can be used, false otherwise.
 */
export function canUseCache() {
	// Don't use cache if we're on a remote site.
	if ( isRemoteSite() ) {
		return false;
	}

	// Don't use cache if we're in the post editor.
	if ( window.location?.pathname?.indexOf( '/post.php' ) !== -1 || window.location?.pathname?.indexOf( '/post-new.php' ) !== -1 ) {
		return false;
	}

	// Don't use cache if sessionStorage is not available.
	if ( 'undefined' === typeof sessionStorage ) {
		return false;
	}

	// Don't use cache if the user is on the budget stories page.
	if ( isBudgetStories() ) {
		return false;
	}

	return true;
}
