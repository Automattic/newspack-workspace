import { EVENTS, emit } from './events';
import { generateID } from './utils';

const overlays = [];

/**
 * Get all overlays.
 *
 * @return {Array} Overlays.
 */
function get() {
	return overlays || [];
}

/**
 * Add an overlay.
 *
 * @param {string} overlayId Overlay ID.
 *
 * @return {string} Overlay ID.
 */
function add( overlayId = '' ) {
	if ( ! overlayId ) {
		overlayId = generateID();
	}
	overlays.push( overlayId );
	emit( EVENTS.overlay, { overlays, added: overlayId } );
	return overlayId;
}

/**
 * Remove an overlay.
 *
 * @param {string} overlayId Overlay ID.
 *
 * @return {Array} Overlays.
 */
function remove( overlayId ) {
	if ( ! overlayId ) {
		return overlays;
	}
	const index = overlays.indexOf( overlayId );
	if ( index > -1 ) {
		overlays.splice( index, 1 );
	}
	emit( EVENTS.overlay, { overlays, removed: overlayId } );
	return overlays;
}

export default {
	get,
	add,
	remove,
};
