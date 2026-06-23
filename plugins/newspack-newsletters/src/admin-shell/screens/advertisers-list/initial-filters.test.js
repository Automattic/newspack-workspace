import { getInitialView } from './initial-filters';

describe( 'advertisers-list getInitialView', () => {
	it( 'returns an empty patch when the URL has no recognised args', () => {
		expect( getInitialView( '' ) ).toEqual( {} );
		expect( getInitialView( '?foo=bar' ) ).toEqual( {} );
	} );

	it( 'forwards the legacy `s` arg as the search term', () => {
		expect( getInitialView( '?s=acme' ) ).toEqual( { search: 'acme' } );
	} );

	it( 'forwards `orderby=name` (alphabetical, ascending by default)', () => {
		expect( getInitialView( '?orderby=name' ) ).toEqual( {
			sort: { field: 'name', direction: 'asc' },
		} );
	} );

	it( 'forwards `orderby=slug` and respects an explicit `order=desc`', () => {
		expect( getInitialView( '?orderby=slug&order=desc' ) ).toEqual( {
			sort: { field: 'slug', direction: 'desc' },
		} );
	} );

	it( 'forwards `orderby=count` for the term-usage column', () => {
		expect( getInitialView( '?orderby=count&order=asc' ) ).toEqual( {
			sort: { field: 'count', direction: 'asc' },
		} );
	} );

	it( 'ignores REST `orderby` values that have no matching DataView field', () => {
		// `id`, `include`, `term_group`, `description` are valid REST
		// orderbys but not exposed in this list — skip them rather than
		// producing an invalid sort state.
		expect( getInitialView( '?orderby=id' ) ).toEqual( {} );
		expect( getInitialView( '?orderby=description' ) ).toEqual( {} );
	} );

	it( 'combines `s` and `orderby` into a single patch', () => {
		expect( getInitialView( '?s=acme&orderby=slug&order=asc' ) ).toEqual( {
			search: 'acme',
			sort: { field: 'slug', direction: 'asc' },
		} );
	} );
} );
