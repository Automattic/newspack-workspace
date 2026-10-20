/**
 * Get (or create) the top-level wrapper that hosts snackbars and the live regions.
 *
 * Any other `.newspack-ui` element may sit inside a modal or prompt that is hidden, which would
 * hide the snackbars and silence the live regions with it.
 *
 * @return {Element} The wrapper element.
 */
export function getWrapper() {
	let wrapper = document.getElementById( 'newspack-ui__notices' );
	if ( ! wrapper ) {
		wrapper = document.createElement( 'div' );
		wrapper.id = 'newspack-ui__notices';
		wrapper.classList.add( 'newspack-ui' );
		document.body.appendChild( wrapper );
	}
	return wrapper;
}

/**
 * Get (or create) a visually-hidden ARIA live region.
 *
 * @param {boolean} assertive - Whether the region announces assertively (errors) instead of politely.
 * @return {Element} The live region element.
 */
export function getLiveRegion( assertive ) {
	const id = assertive ? 'newspack-ui__sr-live-assertive' : 'newspack-ui__sr-live-polite';
	let region = document.getElementById( id );
	if ( ! region ) {
		region = document.createElement( 'div' );
		region.id = id;
		region.classList.add( 'screen-reader-text' );
		region.setAttribute( 'role', assertive ? 'alert' : 'status' );
		region.setAttribute( 'aria-live', assertive ? 'assertive' : 'polite' );
		getWrapper().appendChild( region );
	}
	return region;
}

/**
 * Announce a message to screen readers via the appropriate live region.
 *
 * @param {string}  message   - The message to announce.
 * @param {boolean} assertive - Whether to announce assertively (errors) instead of politely.
 */
export function announce( message, assertive ) {
	if ( ! message ) {
		return;
	}
	const region = getLiveRegion( assertive );
	const line = document.createElement( 'div' );
	line.textContent = message;
	// Two frames later, so a region moved by a modal opening or closing in the same task
	// reaches assistive tech before its new line does.
	requestAnimationFrame( () =>
		requestAnimationFrame( () => {
			region.appendChild( line );
			// Remove once announced so repeated messages re-announce and the region stays tidy.
			setTimeout( () => line.remove(), 1000 );
		} )
	);
}
