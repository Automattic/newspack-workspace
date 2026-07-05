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

export const panelToggles = new Map< string, () => void >();

const subscribers = new Map< string, Set< ( isOpen: boolean ) => void > >();

/**
 * Subscribe a React state setter to open-state changes for a panel.
 * Returns an unsubscribe function suitable for useEffect cleanup.
 *
 * @param parentClientId Parent overlay-menu block clientId.
 * @param setter         React state setter.
 * @return Unsubscribe function.
 */
export function subscribeToPanel( parentClientId: string, setter: ( isOpen: boolean ) => void ): () => void {
	let setters = subscribers.get( parentClientId );
	if ( ! setters ) {
		setters = new Set();
		subscribers.set( parentClientId, setters );
	}
	setters.add( setter );
	return () => subscribers.get( parentClientId )?.delete( setter );
}

/**
 * Notify all subscribers of a new open state.
 *
 * @param parentClientId Parent overlay-menu block clientId.
 * @param isOpen         New open state.
 */
export function notifySubscribers( parentClientId: string, isOpen: boolean ) {
	subscribers.get( parentClientId )?.forEach( fn => fn( isOpen ) );
}
