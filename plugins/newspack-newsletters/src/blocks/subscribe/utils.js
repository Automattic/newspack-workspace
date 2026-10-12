/**
 * Move an item to a new position, returning a new array.
 *
 * `to` is the index in the array after the item has been taken out, which is
 * how CardSortableList reports a reorder.
 *
 * @param {string[]} items Items to reorder.
 * @param {number}   from  Index of the item to move.
 * @param {number}   to    Index to move it to.
 * @return {string[]} The reordered items, or the input when there is nothing to move.
 */
export const moveItem = ( items, from, to ) => {
	if ( from === to || from < 0 || to < 0 || from >= items.length || to >= items.length ) {
		return items;
	}
	const reordered = [ ...items ];
	const [ moved ] = reordered.splice( from, 1 );
	reordered.splice( to, 0, moved );
	return reordered;
};

/**
 * Bring the sidebar's list order in step with the block.
 *
 * The sidebar shows every list. The block only stores the lists that are on,
 * in form order, so where a list that is off sits is only known to the editor,
 * for the session. This keeps that order while it still agrees with the block,
 * and rebuilds it (lists that are on first, in form order, then the rest) when
 * it doesn't: on first load, after an undo, or when a list is added or removed.
 *
 * @param {string[]} order  Every list ID in sidebar order, as last known.
 * @param {string[]} lists  IDs of the lists that are on, in form order.
 * @param {string[]} allIds IDs of every list that exists.
 * @return {string[]} The sidebar order. The same array when nothing changed.
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
 * The lists that are on, in sidebar order: what the block stores.
 *
 * @param {string[]} order   Every list ID in sidebar order.
 * @param {string[]} enabled IDs of the lists that are on.
 * @return {string[]} The enabled IDs, ordered as the sidebar has them.
 */
export const enabledInOrder = ( order, enabled ) => order.filter( id => enabled.includes( id ) );

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
