import { buildQueryParams, toQueryString } from './build-query';
import { FETCH_ALL_CHUNK_SIZE, PER_PAGE_ALL } from '../../utils/per-page';

describe( 'buildQueryParams', () => {
	it( 'sets page and per_page from the view, defaulting to 1 and 20', () => {
		expect( buildQueryParams( {} ) ).toMatchObject( { page: 1, per_page: 20 } );
		expect( buildQueryParams( { page: 3, perPage: 50 } ) ).toMatchObject( {
			page: 3,
			per_page: 50,
		} );
	} );

	it( 'maps the All sentinel to max-size chunks starting at page 1', () => {
		expect( buildQueryParams( { perPage: PER_PAGE_ALL, page: 7 } ) ).toMatchObject( { page: 1, per_page: FETCH_ALL_CHUNK_SIZE } );
	} );

	it( 'restricts fields so content/excerpt are never rendered server-side', () => {
		const { _fields } = buildQueryParams( {} );
		expect( _fields.split( ',' ) ).toEqual(
			expect.arrayContaining( [ 'id', 'status', 'title', 'date', 'link', 'meta', 'newspack_newsletters_status' ] )
		);
		expect( _fields ).not.toContain( 'content' );
		expect( _fields ).not.toContain( 'excerpt' );
		expect( _fields.split( ',' ) ).toEqual( expect.arrayContaining( [ 'categories', 'tags' ] ) );
	} );

	it( 'requests context=edit so meta and private fields are returned', () => {
		expect( buildQueryParams( {} ).context ).toBe( 'edit' );
	} );

	it( 'never asks for _links or embeds', () => {
		for ( const view of [ {}, { fields: [ 'categories', 'tags' ] }, { fields: [ 'status' ] } ] ) {
			const params = buildQueryParams( view );
			expect( params ).not.toHaveProperty( '_embed' );
			expect( params._fields.split( ',' ) ).not.toContain( '_links' );
		}
	} );

	it( 'reads author display data from a dedicated field rather than an embed', () => {
		expect( buildQueryParams( {} )._fields.split( ',' ) ).toContain( 'newspack_newsletters_author' );
	} );

	it( 'requests term names only while a taxonomy column is visible', () => {
		const hasTerms = view => buildQueryParams( view )._fields.split( ',' ).includes( 'newspack_newsletters_terms' );
		// Unknown visibility — assume the columns are on.
		expect( hasTerms( {} ) ).toBe( true );
		expect( hasTerms( { fields: [ 'status', 'categories' ] } ) ).toBe( true );
		expect( hasTerms( { fields: [ 'tags' ] } ) ).toBe( true );
		expect( hasTerms( { fields: [ 'status', 'date', 'author' ] } ) ).toBe( false );
	} );

	it( 'defaults status to all common writable statuses (no trash) when no filter is set', () => {
		const { status } = buildQueryParams( {} );
		expect( status.split( ',' ) ).toEqual( expect.arrayContaining( [ 'publish', 'private', 'future', 'draft', 'pending' ] ) );
		expect( status.split( ',' ) ).not.toContain( 'trash' );
	} );

	it( 'includes auto-draft so a post-new + back row stays visible', () => {
		const { status } = buildQueryParams( {} );
		expect( status.split( ',' ) ).toContain( 'auto-draft' );
	} );

	it( 'replaces the default status set when the user filters by status', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'status', operator: 'isAny', value: [ 'trash' ] } ],
		} );
		expect( params.status ).toBe( 'trash' );
	} );

	it( 'joins multi-value status filters with commas', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'status', operator: 'isAny', value: [ 'publish', 'draft' ] } ],
		} );
		expect( params.status.split( ',' ) ).toEqual( [ 'publish', 'draft' ] );
	} );

	it( 'maps author filter to the REST author param', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'author', value: 42 } ],
		} );
		expect( params.author ).toBe( '42' );
	} );

	it( 'joins multi-author selections with commas', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'author', value: [ 42, 7 ] } ],
		} );
		expect( params.author ).toBe( '42,7' );
	} );

	it( 'maps categories / tags filters to the native term REST params', () => {
		const cats = buildQueryParams( {
			filters: [ { field: 'categories', value: [ 12, 34 ] } ],
		} );
		expect( cats.categories ).toBe( '12,34' );

		const tags = buildQueryParams( {
			filters: [ { field: 'tags', value: [ 5 ] } ],
		} );
		expect( tags.tags ).toBe( '5' );
	} );

	it( 'maps send_list filter to the custom REST param', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'send_list', value: [ 'list-a', 'list-b' ] } ],
		} );
		expect( params.newspack_newsletters_send_list_id ).toBe( 'list-a,list-b' );
	} );

	it( 'maps public_page filter to the custom is_public REST query param', () => {
		const yesParams = buildQueryParams( {
			filters: [ { field: 'public_page', value: '1' } ],
		} );
		expect( yesParams.newspack_newsletters_is_public ).toBe( '1' );

		const noParams = buildQueryParams( {
			filters: [ { field: 'public_page', value: '0' } ],
		} );
		expect( noParams.newspack_newsletters_is_public ).toBe( '0' );
	} );

	it( 'ignores unknown filter fields silently rather than passing them through', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'wat', value: 'nope' } ],
		} );
		expect( params ).not.toHaveProperty( 'wat' );
	} );

	it( 'maps view.sort.field=title to orderby=title', () => {
		const params = buildQueryParams( { sort: { field: 'title', direction: 'asc' } } );
		expect( params ).toMatchObject( { orderby: 'title', order: 'asc' } );
	} );

	it( 'maps view.sort.field=send_date to orderby=date (server-side sort by post_date)', () => {
		const params = buildQueryParams( { sort: { field: 'send_date', direction: 'desc' } } );
		expect( params ).toMatchObject( { orderby: 'date', order: 'desc' } );
	} );

	it( 'omits orderby for unknown sort fields (graceful degradation)', () => {
		const params = buildQueryParams( { sort: { field: 'wat', direction: 'asc' } } );
		expect( params ).not.toHaveProperty( 'orderby' );
	} );

	it( 'forwards the search term as the REST search param', () => {
		const params = buildQueryParams( { search: 'weekly digest' } );
		expect( params.search ).toBe( 'weekly digest' );
	} );

	it( 'omits the search param when the search string is empty', () => {
		expect( buildQueryParams( { search: '' } ) ).not.toHaveProperty( 'search' );
	} );
} );

describe( 'toQueryString', () => {
	it( 'serialises params into a leading-? query string', () => {
		const qs = toQueryString( { page: 2, status: 'publish,draft' } );
		expect( qs ).toMatch( /^\?/ );
		expect( qs ).toContain( 'page=2' );
		expect( qs ).toContain( 'status=publish%2Cdraft' );
	} );

	it( 'skips empty/undefined values', () => {
		const qs = toQueryString( { page: 1, search: '', author: undefined } );
		expect( qs ).not.toContain( 'search' );
		expect( qs ).not.toContain( 'author' );
	} );
} );
