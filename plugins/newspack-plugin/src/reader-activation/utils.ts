/**
 * Get a cookie value given its name.
 *
 * @param name Cookie name.
 *
 * @return Cookie value or empty string if not found.
 */
export function getCookie( name: string ): string {
	if ( ! name ) {
		return '';
	}
	const value = `; ${ document.cookie }`;
	const parts = value.split( `; ${ name }=` );
	const lastPart = parts.length === 2 ? parts.pop() : undefined;
	if ( lastPart !== undefined ) {
		return decodeURIComponent( lastPart.split( ';' )[ 0 ] );
	}

	return '';
}

/**
 * Set a cookie.
 *
 * @param name           Cookie name.
 * @param value          Cookie value.
 * @param expirationDays Expiration in days from now.
 */
export function setCookie( name: string, value: string, expirationDays = 365 ): void {
	const date = new Date();
	date.setTime( date.getTime() + expirationDays * 24 * 60 * 60 * 1000 );
	document.cookie = `${ name }=${ value }; expires=${ date.toUTCString() }; path=/`;
}

/**
 * Generate a random ID with the given length.
 *
 * If entropy is an issue, https://www.npmjs.com/package/nanoid can be used.
 *
 * @param length Length of the ID. Defaults to 9.
 *
 * @return Random ID.
 */
export function generateID( length = 9 ): string {
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
 * @param level Log level ('log' or 'error').
 * @param args  Arguments to pass to console.
 */
// eslint-disable-next-line no-console
export function debugLog( level = 'log', ...args: unknown[] ): void {
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
 * @param callback The function to execute after overlays close.
 */
export function onOverlaysClose( callback: () => void ): void {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( ras => {
		setTimeout( () => {
			if ( ras.overlays.get().length ) {
				// Wait for overlays to close before executing callback.
				const handleOverlayClose = ( { detail: { overlays } }: CustomEvent< NewspackReaderActivationEventMap[ 'overlay' ] > ) => {
					setTimeout( () => {
						if ( ! overlays.length ) {
							callback();
							ras.off( 'overlay', handleOverlayClose );
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
export function queuePageReload(): void {
	onOverlaysClose( () => window.location.reload() );
}
