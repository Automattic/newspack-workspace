/* globals newspack_popups_view */

/**
 * Get all prompts on the page.
 *
 * @return {Array} Array of prompt elements.
 */
export const getPrompts = (): PromptElement[] => {
	return [ ...document.querySelectorAll< PromptElement >( '.newspack-popup-container' ) ];
};

/**
 * Get raw prompt ID number from element ID name.
 *
 * @param {string} id Element ID of the prompt.
 *
 * @return {number} Raw ID number from the element ID.
 */
export const getRawId = ( id: string ): number => {
	const parts = id.split( '_' );
	return parseInt( parts[ parts.length - 1 ] );
};

/**
 * Close an overlay when its close button is clicked.
 *
 * @param {Event} event Dispatched click event.
 */
export const closeOverlay = ( event: Event ): void => {
	const currentTarget = event.currentTarget as PromptElement;
	const parent = currentTarget.closest< PromptElement >( '.newspack-lightbox' );

	if ( parent && parent.contains( currentTarget ) ) {
		parent.style.display = 'none';
	}

	// Remove the overlay from RAS.
	// NOTE: pre-existing behavior, not introduced by this migration: unlike the
	// `style.display` write above, this reads `parent.overlayId` without a
	// `parent &&` guard, so it throws if `.closest()` found no match.
	if ( parent!.overlayId && window.newspackReaderActivation?.overlays ) {
		window.newspackReaderActivation.overlays.remove( parent!.overlayId );
	}

	event.preventDefault();
};

/**
 * Log debugging data if WP_DEBUG is set.
 *
 * @param {string} key  Key name for debug data.
 * @param {any}    data Data to log.
 */
export const debug = ( key: string | null, data: unknown ): void => {
	if ( ! newspack_popups_view.debug ) {
		return;
	}

	window.newspack_popups_debug = window.newspack_popups_debug || {};
	// `String( key )` mirrors the implicit key-to-string coercion JS already
	// performs when indexing an object with a non-string value.
	window.newspack_popups_debug[ String( key ) ] = data;
};
