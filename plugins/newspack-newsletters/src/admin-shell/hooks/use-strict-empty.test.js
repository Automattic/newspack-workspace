/**
 * Internal dependencies
 */
import useStrictEmpty from './use-strict-empty';

const baseArgs = {
	hasLoadedOnce: true,
	isLoading: false,
	paginationInfo: { totalItems: 0 },
	trashCount: 0,
	view: { search: '', filters: [] },
};

describe( 'useStrictEmpty', () => {
	it( 'is true for an empty, unfiltered collection', () => {
		expect( useStrictEmpty( baseArgs ) ).toBe( true );
	} );

	it( 'is false before the first load resolves', () => {
		expect( useStrictEmpty( { ...baseArgs, hasLoadedOnce: false } ) ).toBe( false );
	} );

	it( 'is false while loading', () => {
		expect( useStrictEmpty( { ...baseArgs, isLoading: true } ) ).toBe( false );
	} );

	it( 'is false when the collection has items', () => {
		expect( useStrictEmpty( { ...baseArgs, paginationInfo: { totalItems: 3 } } ) ).toBe( false );
	} );

	it( 'is false when items are only in the trash', () => {
		expect( useStrictEmpty( { ...baseArgs, trashCount: 2 } ) ).toBe( false );
	} );

	// Taxonomy screens (advertisers) never fetch a trash count, so it stays null.
	// Treating that as "trash is non-empty" would hide their empty state forever.
	it( 'is true when the collection has no trash concept at all', () => {
		expect( useStrictEmpty( { ...baseArgs, trashCount: null } ) ).toBe( true );
		expect( useStrictEmpty( { ...baseArgs, trashCount: undefined } ) ).toBe( true );
	} );

	// A search or filter matching nothing keeps the DataViews "no results" treatment.
	it( 'is false for a search', () => {
		expect( useStrictEmpty( { ...baseArgs, view: { search: 'x', filters: [] } } ) ).toBe( false );
	} );

	it( 'is false for an active filter', () => {
		expect( useStrictEmpty( { ...baseArgs, view: { search: '', filters: [ { field: 'status' } ] } } ) ).toBe( false );
	} );

	it( 'is true when view.filters is absent', () => {
		expect( useStrictEmpty( { ...baseArgs, view: { search: '' } } ) ).toBe( true );
	} );
} );
