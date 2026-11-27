import { EVENTS, emit } from './events';
import { generateID } from './utils';

const overlays: string[] = [];

/**
 * Get all overlays.
 *
 * @return Overlays.
 */
function get(): string[] {
	return overlays || [];
}

/**
 * Add an overlay.
 *
 * @param overlayId Overlay ID.
 *
 * @return Overlay ID.
 */
function add( overlayId = '' ): string {
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
 * @param overlayId Overlay ID.
 *
 * @return Overlays.
 */
function remove( overlayId?: string ): string[] {
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
