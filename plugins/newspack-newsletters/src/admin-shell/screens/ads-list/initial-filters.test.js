import { getInitialFilters, getInitialView } from './initial-filters';

describe( 'ads getInitialFilters', () => {
	it( 'returns no filters when post_status is absent', () => {
		expect( getInitialFilters( '' ) ).toEqual( [] );
		expect( getInitialFilters( '?foo=bar' ) ).toEqual( [] );
	} );

	it( 'maps post_status=trash to a trash kind filter', () => {
		expect( getInitialFilters( '?post_status=trash' ) ).toEqual( [ { field: 'status', operator: 'isAny', value: [ 'trash' ] } ] );
	} );

	it( 'maps post_status=draft, pending, and auto-draft to a draft kind filter', () => {
		const expected = [ { field: 'status', operator: 'isAny', value: [ 'draft' ] } ];
		expect( getInitialFilters( '?post_status=draft' ) ).toEqual( expected );
		expect( getInitialFilters( '?post_status=pending' ) ).toEqual( expected );
		expect( getInitialFilters( '?post_status=auto-draft' ) ).toEqual( expected );
	} );

	it( 'maps post_status=future to a scheduled kind filter so WP-scheduled ads stay visible on deep links', () => {
		expect( getInitialFilters( '?post_status=future' ) ).toEqual( [ { field: 'status', operator: 'isAny', value: [ 'scheduled' ] } ] );
	} );

	it( 'ignores unknown post_status values (publish/private split across kinds)', () => {
		expect( getInitialFilters( '?post_status=publish' ) ).toEqual( [] );
		expect( getInitialFilters( '?post_status=anything' ) ).toEqual( [] );
	} );
} );

describe( 'ads getInitialView', () => {
	it( 'returns an empty patch when no recognised args are present', () => {
		expect( getInitialView( '' ) ).toEqual( {} );
	} );

	it( 'forwards a draft kind filter from post_status', () => {
		const patch = getInitialView( '?post_status=draft' );
		expect( patch.filters ).toEqual( [ { field: 'status', operator: 'isAny', value: [ 'draft' ] } ] );
	} );

	it( 'forwards the search term', () => {
		expect( getInitialView( '?s=spring%20sale' ).search ).toBe( 'spring sale' );
	} );

	it( 'maps legacy orderby values onto DataView sort fields', () => {
		expect( getInitialView( '?orderby=price&order=asc' ).sort ).toEqual( {
			field: 'price',
			direction: 'asc',
		} );
		expect( getInitialView( '?orderby=expiry_date&order=desc' ).sort ).toEqual( {
			field: 'expiry_date',
			direction: 'desc',
		} );
		expect( getInitialView( '?orderby=impressions' ).sort ).toEqual( {
			field: 'impressions',
			direction: 'desc',
		} );
	} );

	it( 'omits sort for unknown orderby values', () => {
		expect( getInitialView( '?orderby=wat&order=asc' ).sort ).toBeUndefined();
	} );
} );
