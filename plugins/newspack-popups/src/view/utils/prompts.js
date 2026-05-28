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
 * Close an overlay when its close button is clicked.
 *
 * @param {Event} event Dispatched click event.
 */
export const closeOverlay = event => {
	const parent = event.currentTarget.closest( '.newspack-lightbox' );

	if ( parent && parent.contains( event.currentTarget ) ) {
		parent.style.display = 'none';
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
