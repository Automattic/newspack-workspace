/**
 * Get a cookie value given its name.
 *
 * @param {string} name Cookie name.
 *
 * @return {string} Cookie value or empty string if not found.
 */
export function getCookie( name ) {
	if ( ! name ) {
		return '';
	}
	const value = `; ${ document.cookie }`;
	const parts = value.split( `; ${ name }=` );
	if ( parts.length === 2 ) {
		return decodeURIComponent( parts.pop().split( ';' ).shift() );
	}

	return '';
}

/**
 * Set a cookie.
 *
 * @param {string} name           Cookie name.
 * @param {string} value          Cookie value.
 * @param {number} expirationDays Expiration in days from now.
 */
export function setCookie( name, value, expirationDays = 365 ) {
	const date = new Date();
	date.setTime( date.getTime() + expirationDays * 24 * 60 * 60 * 1000 );
	document.cookie = `${ name }=${ value }; expires=${ date.toUTCString() }; path=/`;
}

/**
 * Generate a random ID with the given length.
 *
 * If entropy is an issue, https://www.npmjs.com/package/nanoid can be used.
 *
 * @param {number} length Length of the ID. Defaults to 9.
 *
 * @return {string} Random ID.
 */
export function generateID( length = 9 ) {
	let randomString = '';
	const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	for ( let i = 0; i < length; i++ ) {
		const randomIndex = Math.floor( Math.random() * chars.length );
		randomString += chars.charAt( randomIndex );
	}
	return randomString;
}

/**
 * Debug logging function that only logs when localStorage flag is set.
 *
 * @param {string} level Log level ('log' or 'error').
 * @param {...any} args  Arguments to pass to console.
 */
// eslint-disable-next-line no-console
export function debugLog( level = 'log', ...args ) {
	if ( localStorage.getItem( 'newspack-reader-activation-debug' ) === 'true' ) {
		const method = level === 'error' ? 'error' : 'log';
		// eslint-disable-next-line no-console
		console[ method ]( ...args );
	}
}

/**
 * Execute a callback after all overlays are closed.
 *
 * This function is overlay-aware and will:
 * 1. Check if there are any overlays currently open
 * 2. If overlays exist, wait for them to close before executing the callback
 * 3. If no overlays, execute the callback immediately
 *
 * @param {Function} callback The function to execute after overlays close.
 */
export function onOverlaysClose( callback ) {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( ras => {
		setTimeout( () => {
			if ( ras.overlays.get().length ) {
				// Wait for overlays to close before executing callback.
				const handleOverlayClose = ( { detail: { overlays } } ) => {
					setTimeout( () => {
						if ( ! overlays.length ) {
							callback();
							window.newspackReaderActivation.off( 'overlay', handleOverlayClose );
						}
					}, 50 );
				};
				ras.on( 'overlay', handleOverlayClose );
				return;
			}
			callback();
		}, 50 );
	} );
}

/**
 * Queue a page reload, waiting for any open overlays to close first.
 *
 * This is a convenience wrapper around `onOverlaysClose` that reloads the page.
 */
export function queuePageReload() {
	onOverlaysClose( () => window.location.reload() );
}
