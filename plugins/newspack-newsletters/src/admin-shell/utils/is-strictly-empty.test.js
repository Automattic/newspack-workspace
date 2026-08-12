/**
 * Internal dependencies
 */
import isStrictlyEmpty from './is-strictly-empty';

const baseArgs = {
	hasLoadedOnce: true,
	isLoading: false,
	paginationInfo: { totalItems: 0 },
	trashCount: 0,
	view: { search: '', filters: [] },
};

describe( 'isStrictlyEmpty', () => {
	it( 'is true for an empty, unfiltered collection', () => {
		expect( isStrictlyEmpty( baseArgs ) ).toBe( true );
	} );

	it( 'is false before the first load resolves', () => {
		expect( isStrictlyEmpty( { ...baseArgs, hasLoadedOnce: false } ) ).toBe( false );
	} );

	it( 'is false while loading', () => {
		expect( isStrictlyEmpty( { ...baseArgs, isLoading: true } ) ).toBe( false );
	} );

	it( 'is false when the collection has items', () => {
		expect( isStrictlyEmpty( { ...baseArgs, paginationInfo: { totalItems: 3 } } ) ).toBe( false );
	} );

	it( 'is false when items are only in the trash', () => {
		expect( isStrictlyEmpty( { ...baseArgs, trashCount: 2 } ) ).toBe( false );
	} );

	// Taxonomy screens (advertisers) never fetch a trash count, so they omit the
	// key. Treating that as "trash is non-empty" would hide their empty state forever.
	it( 'is true when the collection has no trash concept at all', () => {
		const noTrash = { ...baseArgs };
		delete noTrash.trashCount;
		expect( isStrictlyEmpty( noTrash ) ).toBe( true );
		expect( isStrictlyEmpty( { ...baseArgs, trashCount: undefined } ) ).toBe( true );
	} );

	// On screens that DO fetch a count, null means "unknown", not "no trash":
	// useCollectionData resets it on every refetch, and a failed sub-fetch leaves it
	// null for good. Collapsing that to 0 makes a freshly trashed last item flash
	// the empty state.
	it( 'is false when the trash count is unknown', () => {
		expect( isStrictlyEmpty( { ...baseArgs, trashCount: null } ) ).toBe( false );
	} );

	// A search or filter matching nothing keeps the DataViews "no results" treatment.
	it( 'is false for a search', () => {
		expect( isStrictlyEmpty( { ...baseArgs, view: { search: 'x', filters: [] } } ) ).toBe( false );
	} );

	it( 'is false for an active filter', () => {
		expect( isStrictlyEmpty( { ...baseArgs, view: { search: '', filters: [ { field: 'status' } ] } } ) ).toBe( false );
	} );

	it( 'is true when view.filters is absent', () => {
		expect( isStrictlyEmpty( { ...baseArgs, view: { search: '' } } ) ).toBe( true );
	} );
} );
