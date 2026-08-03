import { buildQueryParams, toQueryString } from './build-query';

describe( 'ads buildQueryParams', () => {
	it( 'sets page and per_page from the view, defaulting to 1 and 25', () => {
		expect( buildQueryParams( {} ) ).toMatchObject( { page: 1, per_page: 25 } );
		expect( buildQueryParams( { page: 2, perPage: 50 } ) ).toMatchObject( {
			page: 2,
			per_page: 50,
		} );
	} );

	it( 'requests context=edit so meta and private fields are returned', () => {
		expect( buildQueryParams( {} ).context ).toBe( 'edit' );
	} );

	it( 'embeds wp:term so the advertiser and placement columns can read terms', () => {
		expect( buildQueryParams( {} )._embed ).toBe( 'wp:term' );
	} );

	it( 'defaults to writable statuses (no trash) when no kind filter is set', () => {
		const { status, newspack_newsletters_ad_status: kindParam } = buildQueryParams( {} );
		expect( status.split( ',' ) ).toEqual( expect.arrayContaining( [ 'publish', 'private', 'future', 'draft', 'pending' ] ) );
		expect( status.split( ',' ) ).not.toContain( 'trash' );
		// Server doesn't get the kind param when no filter is selected.
		expect( kindParam ).toBeUndefined();
	} );

	it( 'includes future in the default status set so WP-scheduled ads stay visible', () => {
		// `future` covers ads scheduled through WordPress's Publish UI;
		// they don't have a `start_date` meta and would otherwise drop
		// off the React list (the classic CPT list showed them).
		const { status } = buildQueryParams( {} );
		expect( status.split( ',' ) ).toContain( 'future' );
	} );

	it( 'includes auto-draft so a post-new + back row stays visible', () => {
		const { status } = buildQueryParams( {} );
		expect( status.split( ',' ) ).toContain( 'auto-draft' );
	} );

	it( 'maps a single kind filter to the kind-specific REST query param', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'status', operator: 'isAny', value: [ 'expired' ] } ],
		} );
		expect( params.newspack_newsletters_ad_status ).toBe( 'expired' );
		// The server translates kind→post_status; the JS doesn't double-handle it.
		expect( params.status ).toBeUndefined();
	} );

	it( 'joins multiple kinds with commas', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'status', operator: 'isAny', value: [ 'active', 'scheduled' ] } ],
		} );
		expect( params.newspack_newsletters_ad_status.split( ',' ) ).toEqual( [ 'active', 'scheduled' ] );
	} );

	it( 'maps advertiser filter to the REST taxonomy param', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'advertiser', value: [ 7, 12 ] } ],
		} );
		expect( params.newspack_nl_advertiser ).toBe( '7,12' );
	} );

	it( 'maps ad_placement filter to the taxonomy REST base, not the taxonomy slug', () => {
		// Ad placement is registered with `rest_base => 'ad_placement'`,
		// so the WP REST posts filter exposes it under that short form.
		// Using the taxonomy slug here would silently drop the filter.
		const params = buildQueryParams( {
			filters: [ { field: 'ad_placement', value: [ 3 ] } ],
		} );
		expect( params.ad_placement ).toBe( '3' );
		expect( params ).not.toHaveProperty( 'newspack_nl_ad_placement' );
	} );

	it( 'ignores unknown filter fields silently', () => {
		const params = buildQueryParams( {
			filters: [ { field: 'wat', value: 'nope' } ],
		} );
		expect( params ).not.toHaveProperty( 'wat' );
	} );

	it( 'maps view.sort.field=title to orderby=title without a meta_key', () => {
		const params = buildQueryParams( { sort: { field: 'title', direction: 'asc' } } );
		expect( params ).toMatchObject( { orderby: 'title', order: 'asc' } );
		expect( params.meta_key ).toBeUndefined();
	} );

	it( 'sends meta-backed columns as virtual orderby tokens, not raw meta_value/meta_key', () => {
		for ( const field of [ 'start_date', 'expiry_date', 'price', 'impressions', 'clicks' ] ) {
			const params = buildQueryParams( { sort: { field, direction: 'desc' } } );
			expect( params ).toMatchObject( { orderby: field, order: 'desc' } );
			expect( params.meta_key ).toBeUndefined();
		}
	} );

	it( 'omits orderby for unknown sort fields', () => {
		const params = buildQueryParams( { sort: { field: 'wat', direction: 'asc' } } );
		expect( params ).not.toHaveProperty( 'orderby' );
	} );

	it( 'forwards the search term as the REST search param', () => {
		const params = buildQueryParams( { search: 'big sale' } );
		expect( params.search ).toBe( 'big sale' );
	} );

	it( 'omits the search param when the search string is empty', () => {
		expect( buildQueryParams( { search: '' } ) ).not.toHaveProperty( 'search' );
	} );
} );

describe( 'ads toQueryString', () => {
	it( 'serialises params into a leading-? query string', () => {
		const qs = toQueryString( { page: 2, newspack_newsletters_ad_status: 'expired,scheduled' } );
		expect( qs ).toMatch( /^\?/ );
		expect( qs ).toContain( 'page=2' );
		expect( qs ).toContain( 'newspack_newsletters_ad_status=expired%2Cscheduled' );
	} );

	it( 'skips empty/undefined values', () => {
		const qs = toQueryString( { page: 1, search: '', extra: undefined } );
		expect( qs ).not.toContain( 'search' );
		expect( qs ).not.toContain( 'extra' );
	} );
} );
