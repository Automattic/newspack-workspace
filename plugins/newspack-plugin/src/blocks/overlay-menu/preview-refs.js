/**
 * Module-level Maps for ephemeral editor-only preview state.
 *
 * Both Maps are keyed by the PARENT overlay-menu block's clientId, not the
 * panel's clientId. This lets the parent and trigger look up entries using
 * their own clientId or a single getBlockRootClientId() call, avoiding the
 * innerBlocks traversal that can return empty during a template-part switch.
 *
 * panelToggles: the panel registers a toggle function here so the parent and
 * trigger toolbar buttons can open/close it without a shared reactive store.
 *
 * subscribers: any block that needs to mirror the panel's open state registers
 * a React state setter here. Multiple blocks can subscribe to the same panel.
 */

/** @type {Map<string, function(): void>} */
export const panelToggles = new Map();

/** @type {Map<string, Set<function(boolean): void>>} */
const subscribers = new Map();

/**
 * Subscribe a React state setter to open-state changes for a panel.
 * Returns an unsubscribe function suitable for useEffect cleanup.
 *
 * @param {string}                  parentClientId Parent overlay-menu block clientId.
 * @param {function(boolean): void} setter         React state setter.
 * @return {function(): void} Unsubscribe function.
 */
export function subscribeToPanel( parentClientId, setter ) {
	if ( ! subscribers.has( parentClientId ) ) {
		subscribers.set( parentClientId, new Set() );
	}
	subscribers.get( parentClientId ).add( setter );
	return () => subscribers.get( parentClientId )?.delete( setter );
}

/**
 * Notify all subscribers of a new open state.
 *
 * @param {string}  parentClientId Parent overlay-menu block clientId.
 * @param {boolean} isOpen         New open state.
 */
export function notifySubscribers( parentClientId, isOpen ) {
	subscribers.get( parentClientId )?.forEach( fn => fn( isOpen ) );
}
