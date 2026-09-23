import apiFetch from '@wordpress/api-fetch';

import { fetchPostSuggestions, formatPostLabel, getPostSearchPath } from './post-search';

jest.mock( '@wordpress/api-fetch' );

describe( 'getPostSearchPath', () => {
	it( 'searches the given post type collection endpoint', () => {
		expect( getPostSearchPath( 'newspack_nl_cpt', 'election' ) ).toContain( '/wp/v2/newspack_nl_cpt?' );
	} );

	it( 'asks for scheduled, draft and pending posts alongside published ones', () => {
		const path = decodeURIComponent( getPostSearchPath( 'posts', 'election' ) );

		expect( path ).toContain( 'status[0]=publish' );
		expect( path ).toContain( 'status[1]=future' );
		expect( path ).toContain( 'status[2]=draft' );
		expect( path ).toContain( 'status[3]=pending' );
	} );

	it( 'never asks for private posts', () => {
		expect( decodeURIComponent( getPostSearchPath( 'posts', 'election' ) ) ).not.toContain( 'private' );
	} );

	it( 'requests the status field so suggestions can be labelled', () => {
		expect( decodeURIComponent( getPostSearchPath( 'posts', 'election' ) ) ).toContain( '_fields=id,title,status' );
	} );

	it( 'drops the status filter when asked to fall back', () => {
		const path = decodeURIComponent( getPostSearchPath( 'posts', 'election', false ) );

		expect( path ).not.toContain( 'status[' );
		expect( path ).toContain( 'search=election' );
	} );
} );

describe( 'formatPostLabel', () => {
	it( 'leaves published posts unlabelled', () => {
		expect( formatPostLabel( 'City budget passes', 'publish' ) ).toBe( 'City budget passes' );
	} );

	it( 'labels scheduled posts', () => {
		expect( formatPostLabel( 'Election night live blog', 'future' ) ).toBe( 'Election night live blog — Scheduled' );
	} );

	it( 'labels drafts', () => {
		expect( formatPostLabel( 'Mayor race preview', 'draft' ) ).toBe( 'Mayor race preview — Draft' );
	} );

	it( 'labels posts awaiting review', () => {
		expect( formatPostLabel( 'Election guide 2026', 'pending' ) ).toBe( 'Election guide 2026 — Pending' );
	} );

	it( 'leaves the title alone when the status is not yet known', () => {
		expect( formatPostLabel( 'City budget passes', undefined ) ).toBe( 'City budget passes' );
	} );
} );

describe( 'fetchPostSuggestions', () => {
	afterEach( () => {
		jest.resetAllMocks();
	} );

	it( 'retries once for published posts only when core forbids the status query', async () => {
		apiFetch
			.mockRejectedValueOnce( { code: 'rest_forbidden_status' } )
			.mockResolvedValueOnce( [ { id: 7, title: { rendered: 'City budget' }, status: 'publish' } ] );

		const posts = await fetchPostSuggestions( 'posts', 'budget' );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		// The first request asks for unpublished statuses; the retry drops them.
		expect( decodeURIComponent( apiFetch.mock.calls[ 0 ][ 0 ].path ) ).toContain( 'status[0]=publish' );
		expect( decodeURIComponent( apiFetch.mock.calls[ 1 ][ 0 ].path ) ).not.toContain( 'status[' );
		expect( posts ).toEqual( [ { id: 7, title: 'City budget', status: 'publish' } ] );
	} );

	it( 'resolves to an empty list without retrying on any other error', async () => {
		apiFetch.mockRejectedValueOnce( { code: 'rest_no_route' } );

		const posts = await fetchPostSuggestions( 'posts', 'budget' );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( posts ).toEqual( [] );
	} );
} );
