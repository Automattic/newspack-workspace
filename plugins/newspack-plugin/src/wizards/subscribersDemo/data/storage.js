// PROTOTYPE ONLY: demo mutations (notes, tags, newsletters, group and subscriber
// overrides) are persisted to the current admin's localStorage so they survive a
// refresh during a demo. Everything lives under a shared prefix so a single version
// check can invalidate it all at once.
export const STORAGE_PREFIX = 'newspack-subscribers-demo:';

// Bump this whenever the seeded mock data changes shape or content. On the next load
// a mismatch wipes every stored override so the new seed surfaces instead of being
// masked by stale localStorage from a previous build.
const DATA_VERSION = '18';
const VERSION_STORAGE_KEY = STORAGE_PREFIX + 'data-version';

export function readStore( key ) {
	try {
		return JSON.parse( window.localStorage.getItem( key ) ) || {};
	} catch ( e ) {
		return {};
	}
}

export function writeStore( key, store ) {
	try {
		window.localStorage.setItem( key, JSON.stringify( store ) );
	} catch ( e ) {
		// Storage quota or disabled — fail silently in the prototype.
	}
}

// Clears every stored demo override when the data version has changed. Called once at
// app init, before any screen reads from localStorage.
export function purgeStaleStorage() {
	try {
		if ( window.localStorage.getItem( VERSION_STORAGE_KEY ) === DATA_VERSION ) {
			return;
		}
		const keys = [];
		for ( let i = 0; i < window.localStorage.length; i++ ) {
			const key = window.localStorage.key( i );
			if ( key && key.startsWith( STORAGE_PREFIX ) ) {
				keys.push( key );
			}
		}
		keys.forEach( key => window.localStorage.removeItem( key ) );
		window.localStorage.setItem( VERSION_STORAGE_KEY, DATA_VERSION );
	} catch ( e ) {
		// Storage disabled — nothing to purge.
	}
}
