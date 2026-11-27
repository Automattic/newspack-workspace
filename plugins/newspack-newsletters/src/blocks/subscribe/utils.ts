/**
 * Bring the Edit Lists modal's order in step with the block.
 *
 * The modal shows every list. The block only stores the lists that are on,
 * in form order, so where a list that is off sits is only known to the editor,
 * for the session. This keeps that order while it still agrees with the block,
 * and rebuilds it (lists that are on first, in form order, then the rest) when
 * it doesn't: on first load, after an undo, or when a list is added or removed.
 *
 * @param order  Every list ID in modal order, as last known.
 * @param lists  IDs of the lists that are on, in form order.
 * @param allIds IDs of every list that exists.
 * @return The modal order. The same array when nothing changed.
 */
export const syncListOrder = ( order: string[], lists: string[], allIds: string[] ): string[] => {
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
 * The config holds every active list, since the editor asks for the edit
 * context, and is empty when the request fails. A saved list it lacks is
 * dropped, unless that would leave the block with none: a form with no lists
 * renders for no reader, so the saved ones stay.
 *
 * @param saved  List IDs saved on the block.
 * @param config Lists config keyed by list ID. An empty array when there are no lists.
 * @return The list IDs to keep.
 */
export const listsToKeep = ( saved: string[], config: Record< string, unknown > | unknown[] ): string[] => {
	const available = saved.filter( id => Object.prototype.hasOwnProperty.call( config || {}, id ) );
	return available.length ? available : saved;
};

/**
 * Whether an access gate restricts a list.
 *
 * @param listGates Gates keyed by list ID. May be missing.
 * @param listId    ID of the list.
 * @return True when at least one gate restricts the list.
 */
export const isListGated = ( listGates: Record< string, ListGate[] > | undefined, listId: string ): boolean =>
	Array.isArray( listGates?.[ listId ] ) && listGates![ listId ].length > 0;

/**
 * Whether the site has any gated list.
 *
 * @param listGates Gates keyed by list ID. May be missing.
 * @return True when any list is gated.
 */
export const hasGatedLists = ( listGates: Record< string, ListGate[] > | undefined ): boolean =>
	Object.keys( listGates || {} ).some( listId => isListGated( listGates, listId ) );

/**
 * Whether the button should render as the site's own button.
 *
 * Mirrors the front end: on a block theme, a button left at both default colors
 * takes its fill and text from the theme. A cleared color counts as its default. If either color was set, both come from the block.
 *
 * @param backgroundColor The block's background color.
 * @param textColor       The block's text color.
 * @param isBlockTheme    Whether the site runs a block theme.
 * @return Whether the colors are left to the theme.
 */
export const usesSiteButtonStyle = (
	backgroundColor: string | undefined,
	textColor: string | undefined,
	isBlockTheme: boolean = !! window.newspackIsBlockTheme
): boolean => isBlockTheme && ( backgroundColor ?? '#dd3333' ) === '#dd3333' && ( textColor ?? '#ffffff' ) === '#ffffff';
