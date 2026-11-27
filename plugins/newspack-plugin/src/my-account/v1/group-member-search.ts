/**
 * Search fields on the My Account group page: narrow the members and invitations
 * tables to rows whose name or email contains the query.
 *
 * The page renders every member and invitation, so filtering the rendered rows is
 * enough. Rows are hidden rather than removed, which keeps their row actions (remove,
 * set manager, resend, cancel) working on a filtered list.
 */

// Wait for a pause in typing before announcing the count, so a screen reader reads
// one result instead of one per keystroke. The rows themselves filter immediately.
export const ANNOUNCE_DELAY_MS = 400;

/** One filterable table row: its element and the normalized text to match against. */
interface SearchRow {
	element: HTMLElement;
	searchText: string;
}

// Case- and accent-insensitive, so "jose" finds "José". Lowercasing ignores the
// browser locale: a Turkish locale would turn "I" into a dotless "ı".
const normalize = ( text?: string ) =>
	( text || '' )
		.normalize( 'NFD' )
		.replace( /\p{Diacritic}/gu, '' )
		.toLowerCase()
		.trim();

const filterRows = ( rows: SearchRow[], query: string ) => {
	let visibleCount = 0;
	rows.forEach( row => {
		const isMatch = ! query || row.searchText.includes( query );
		row.element.hidden = ! isMatch;
		if ( isMatch ) {
			visibleCount++;
		}
	} );
	return visibleCount;
};

const initSearchField = ( wrapper: HTMLElement ) => {
	const input = wrapper.querySelector< HTMLInputElement >( 'input[type="search"]' );
	const table = input && document.getElementById( input.getAttribute( 'aria-controls' ) as string );
	if ( ! table ) {
		return;
	}
	const rows: SearchRow[] = [ ...table.querySelectorAll< HTMLElement >( 'tbody tr' ) ].map( element => ( {
		element,
		searchText: normalize( element.dataset.searchText ),
	} ) );
	const status = wrapper.querySelector< HTMLElement >( '[data-search-status]' );
	const empty = table.parentElement!.querySelector< HTMLElement >( '[data-search-empty]' );
	const statusTemplate = wrapper.dataset.statusTemplate || '';
	let announceTimer: ReturnType< typeof setTimeout > | undefined;

	// `table` exists only when `input` did, so `input` is present here.
	input!.addEventListener( 'input', () => {
		const query = normalize( input!.value );
		const visibleCount = filterRows( rows, query );
		const hasNoResults = !! query && 0 === visibleCount;
		table.hidden = hasNoResults;
		if ( empty ) {
			empty.hidden = ! hasNoResults;
		}
		if ( ! status ) {
			return;
		}
		clearTimeout( announceTimer );
		if ( ! query ) {
			status.textContent = '';
			return;
		}
		announceTimer = setTimeout( () => {
			// The no-results copy already says this on screen; keep the count for screen readers only.
			status.classList.toggle( 'screen-reader-text', hasNoResults );
			// Tolerate a translation that turned a %d placeholder into %s.
			status.textContent = statusTemplate.replace( /%1\$[ds]/, String( visibleCount ) ).replace( /%2\$[ds]/, String( rows.length ) );
		}, ANNOUNCE_DELAY_MS );
	} );
};

export const initGroupMemberSearch = ( root: Element ) => {
	root.querySelectorAll< HTMLElement >( '.newspack-my-account__group_subscription__search' ).forEach( initSearchField );
};
