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
 * Enable a list by appending it, or disable it by removing it.
 *
 * @param {string[]} lists  IDs of the enabled lists, in display order.
 * @param {string}   listId ID of the list to toggle.
 * @return {string[]} The new enabled lists.
 */
export const toggleList = ( lists, listId ) => ( lists.includes( listId ) ? lists.filter( id => id !== listId ) : [ ...lists, listId ] );

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
