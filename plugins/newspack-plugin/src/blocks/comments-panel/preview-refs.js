/**
 * Module-level Maps for ephemeral editor-only preview state.
 *
 * Keyed by the PARENT comments-panel block's clientId so the parent and trigger
 * can look up entries using their own clientId or a single getBlockRootClientId()
 * call, avoiding innerBlocks traversal that can return empty mid-transition.
 *
 * panelToggles: the content block registers a toggle function here so the parent
 * and trigger toolbar buttons can open/close the preview without a shared store.
 *
 * subscribers: any block mirroring the panel's open state registers a React state
 * setter here. Multiple blocks can subscribe to the same panel.
 */

/** @type {Map<string, function(): void>} */
export const panelToggles = new Map();

/** @type {Map<string, Set<function(boolean): void>>} */
const subscribers = new Map();

/**
 * Subscribe a React state setter to open-state changes for a panel.
 *
 * @param {string}                  parentClientId Parent comments-panel block clientId.
 * @param {function(boolean): void} setter         React state setter.
 * @return {function(): void} Unsubscribe function.
 */
export function subscribeToPanel( parentClientId, setter ) {
	if ( ! subscribers.has( parentClientId ) ) {
		subscribers.set( parentClientId, new Set() );
	}
	subscribers.get( parentClientId ).add( setter );
	return () => {
		const set = subscribers.get( parentClientId );
		if ( ! set ) {
			return;
		}
		set.delete( setter );
		if ( ! set.size ) {
			subscribers.delete( parentClientId );
		}
	};
}

/**
 * Notify all subscribers of a new open state.
 *
 * @param {string}  parentClientId Parent comments-panel block clientId.
 * @param {boolean} isOpen         New open state.
 */
export function notifySubscribers( parentClientId, isOpen ) {
	subscribers.get( parentClientId )?.forEach( fn => fn( isOpen ) );
}
