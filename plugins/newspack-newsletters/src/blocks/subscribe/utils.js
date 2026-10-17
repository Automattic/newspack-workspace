/**
 * Bring the Edit Lists modal's order in step with the block.
 *
 * The modal shows every list. The block only stores the lists that are on,
 * in form order, so where a list that is off sits is only known to the editor,
 * for the session. This keeps that order while it still agrees with the block,
 * and rebuilds it (lists that are on first, in form order, then the rest) when
 * it doesn't: on first load, after an undo, or when a list is added or removed.
 *
 * @param {string[]} order  Every list ID in modal order, as last known.
 * @param {string[]} lists  IDs of the lists that are on, in form order.
 * @param {string[]} allIds IDs of every list that exists.
 * @return {string[]} The modal order. The same array when nothing changed.
 */
export const syncListOrder = ( order, lists, allIds ) => {
	const enabled = lists.filter( id => allIds.includes( id ) );
	const sameMembers = order.length === allIds.length && allIds.every( id => order.includes( id ) );
	const enabledNow = order.filter( id => enabled.includes( id ) );
	const agrees = sameMembers && enabledNow.length === enabled.length && enabledNow.every( ( id, index ) => id === enabled[ index ] );
	if ( agrees ) {
		return order;
	}
	return [ ...enabled, ...allIds.filter( id => ! enabled.includes( id ) ) ];
};

/**
 * The saved lists a block keeps once the lists config has loaded.
 *
 * The config holds only the lists the current user can join, and is empty when
 * the request fails. A saved list it lacks is dropped, unless that would leave
 * the block with none: a form with no lists renders for no reader, so the saved
 * ones stay.
 *
 * @param {string[]}     saved  List IDs saved on the block.
 * @param {Object|Array} config Lists config keyed by list ID. An empty array when there are no lists.
 * @return {string[]} The list IDs to keep.
 */
export const listsToKeep = ( saved, config ) => {
	const available = saved.filter( id => Object.prototype.hasOwnProperty.call( config || {}, id ) );
	return available.length ? available : saved;
};

/**
 * Whether an access gate restricts a list.
 *
 * @param {Object|undefined} listGates Gates keyed by list ID. May be missing.
 * @param {string}           listId    ID of the list.
 * @return {boolean} True when at least one gate restricts the list.
 */
export const isListGated = ( listGates, listId ) => Array.isArray( listGates?.[ listId ] ) && listGates[ listId ].length > 0;

/**
 * Whether the site has any gated list.
 *
 * @param {Object|undefined} listGates Gates keyed by list ID. May be missing.
 * @return {boolean} True when any list is gated.
 */
export const hasGatedLists = listGates => Object.keys( listGates || {} ).some( listId => isListGated( listGates, listId ) );
