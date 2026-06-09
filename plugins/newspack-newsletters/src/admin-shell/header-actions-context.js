/**
 * Header actions context.
 *
 * Owner-keyed registry so overlapping mounts don't clobber each
 * other's actions. Each `useHeaderActions` caller has its own slot;
 * the visible set is the most recently registered owner's.
 *
 * Action shape: `{ type: 'primary' | 'secondary', label, icon?, href?, onClick? }`
 */

import { createContext, useCallback, useContext, useEffect, useId, useMemo, useState } from '@wordpress/element';

// Split contexts so registrations don't re-render readers and vice versa:
// `useHeaderActions` callers (screens) consume the stable API; `<PageHeader>` consumes the value.
const HeaderActionsAPIContext = createContext( null );
const HeaderActionsValueContext = createContext( [] );

export function HeaderActionsProvider( { children } ) {
	const [ registry, setRegistry ] = useState( () => ( {
		ownerOrder: [],
		actionsByOwner: {},
	} ) );

	const upsert = useCallback(
		( ownerId, actions ) =>
			setRegistry( prev => {
				const ownerOrder = prev.ownerOrder.includes( ownerId ) ? prev.ownerOrder : [ ...prev.ownerOrder, ownerId ];
				return {
					ownerOrder,
					actionsByOwner: { ...prev.actionsByOwner, [ ownerId ]: actions },
				};
			} ),
		[]
	);

	const remove = useCallback(
		ownerId =>
			setRegistry( prev => {
				if ( ! prev.ownerOrder.includes( ownerId ) ) {
					return prev;
				}
				const nextActionsByOwner = { ...prev.actionsByOwner };
				delete nextActionsByOwner[ ownerId ];
				return {
					ownerOrder: prev.ownerOrder.filter( id => id !== ownerId ),
					actionsByOwner: nextActionsByOwner,
				};
			} ),
		[]
	);

	const api = useMemo( () => ( { upsert, remove } ), [ upsert, remove ] );

	const visibleActions = useMemo( () => {
		const { ownerOrder, actionsByOwner } = registry;
		if ( ownerOrder.length === 0 ) {
			return [];
		}
		return actionsByOwner[ ownerOrder[ ownerOrder.length - 1 ] ] || [];
	}, [ registry ] );

	return (
		<HeaderActionsAPIContext.Provider value={ api }>
			<HeaderActionsValueContext.Provider value={ visibleActions }>{ children }</HeaderActionsValueContext.Provider>
		</HeaderActionsAPIContext.Provider>
	);
}

/**
 * Read the currently-registered header actions. Used by the chassis
 * `<PageHeader />` component to render the action row.
 */
export function useHeaderActionsValue() {
	return useContext( HeaderActionsValueContext );
}

/**
 * Register header actions for the lifetime of the calling component.
 *
 * The `actions` array MUST be a stable reference (wrap in `useMemo`)
 * — passing a fresh literal every render would loop. Outside a
 * provider this is a no-op so isolated mounts (Jest, Storybook) work.
 *
 * @param {Array} actions Memoised array of action descriptors.
 */
export function useHeaderActions( actions ) {
	const api = useContext( HeaderActionsAPIContext );
	const ownerId = useId();

	useEffect( () => {
		if ( ! api ) {
			return undefined;
		}
		api.upsert( ownerId, Array.isArray( actions ) ? actions : [] );
		return () => api.remove( ownerId );
	}, [ api, ownerId, actions ] );
}
