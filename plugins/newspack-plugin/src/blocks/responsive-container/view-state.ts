/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';

/**
 * Ephemeral, editor-only store for which view (desktop / mobile) is being edited
 * in each Responsive Container instance.
 *
 * The value is kept in module memory rather than a block attribute so toggling
 * views never dirties the post — the same approach the Overlay Menu block uses
 * for its preview state. State is keyed by the container's clientId and shared
 * between the container and its breakpoint children.
 */
const views = new Map();
const listeners = new Map();

const DEFAULT_VIEW = 'desktop';

/**
 * Returns the current view for a container, defaulting to desktop.
 *
 * @param {string} clientId Container clientId.
 * @return {string} 'desktop' | 'mobile'
 */
export function getView( clientId ) {
	return views.get( clientId ) || DEFAULT_VIEW;
}

/**
 * Sets the view for a container and notifies subscribers.
 *
 * @param {string} clientId Container clientId.
 * @param {string} view     'desktop' | 'mobile'
 */
function setView( clientId, view ) {
	views.set( clientId, view );
	listeners.get( clientId )?.forEach( callback => callback( view ) );
}

/**
 * Subscribe to the view of a container instance and read/update it.
 *
 * @param {string} clientId Container clientId (the container's own, or a
 *                          breakpoint's parent clientId).
 * @return {Array} `[ view, setViewForContainer ]`.
 */
export function useView( clientId ) {
	const [ view, setLocal ] = useState( () => getView( clientId ) );

	useEffect( () => {
		// Re-sync in case the value changed between render and subscribe.
		setLocal( getView( clientId ) );
		let subscribers = listeners.get( clientId );
		if ( ! subscribers ) {
			subscribers = new Set();
			listeners.set( clientId, subscribers );
		}
		subscribers.add( setLocal );
		return () => {
			subscribers.delete( setLocal );
			if ( subscribers.size === 0 ) {
				listeners.delete( clientId );
			}
		};
	}, [ clientId ] );

	return [ view, newView => setView( clientId, newView ) ];
}
