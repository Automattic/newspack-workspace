/* globals newspack_popups_view */

/**
 * Get all prompts on the page.
 *
 * @return {Array} Array of prompt elements.
 */
export const getPrompts = () => {
	return [ ...document.querySelectorAll( '.newspack-popup-container' ) ];
};

/**
 * Get raw prompt ID number from element ID name.
 *
 * @param {string} id Element ID of the prompt.
 *
 * @return {number} Raw ID number from the element ID.
 */
export const getRawId = id => {
	const parts = id.split( '_' );
	return parseInt( parts[ parts.length - 1 ] );
};

/**
 * Total time an element's current transitions take to finish, from the computed
 * transition-duration and transition-delay lists.
 *
 * @param {Element} element Element whose transitions to measure.
 * @return {number} Longest duration plus delay, in milliseconds.
 */
const getTransitionTime = element => {
	const style = window.getComputedStyle( element );
	const toMs = value => {
		const time = parseFloat( value ) || 0;
		return value.trim().endsWith( 'ms' ) ? time : time * 1000;
	};
	const durations = ( style.transitionDuration || '0s' ).split( ',' ).map( toMs );
	const delays = ( style.transitionDelay || '0s' ).split( ',' ).map( toMs );
	const count = ( style.transitionProperty || '' ).split( ',' ).length;
	return Math.max( 0, ...Array.from( { length: count }, ( _, index ) => durations[ index % durations.length ] + delays[ index % delays.length ] ) );
};

/**
 * Close an overlay when its close button is clicked.
 *
 * @param {Event} event Dispatched click event.
 */
export const closeOverlay = event => {
	const parent = event.currentTarget.closest( '.newspack-lightbox' );

	if ( parent?.classList.contains( 'hidden' ) ) {
		event.preventDefault();
		return;
	}

	if ( parent && parent.contains( event.currentTarget ) ) {
		parent.classList.add( 'hidden' );
		setTimeout( () => {
			parent.style.display = 'none';
		}, getTransitionTime( parent ) );
	}

	// Remove the overlay from RAS.
	if ( parent.overlayId && window.newspackReaderActivation?.overlays ) {
		window.newspackReaderActivation.overlays.remove( parent.overlayId );
	}

	event.preventDefault();
};

/**
 * Log debugging data if WP_DEBUG is set.
 *
 * @param {string} key  Key name for debug data.
 * @param {any}    data Data to log.
 */
export const debug = ( key, data ) => {
	if ( ! newspack_popups_view.debug ) {
		return;
	}

	window.newspack_popups_debug = window.newspack_popups_debug || {};
	window.newspack_popups_debug[ key ] = data;
};
