import { formatPostLabel, getPostSearchPath, getPostStatusPath, mergePostStatuses } from './post-search';

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

describe( 'getPostStatusPath', () => {
	it( 'looks up only the id and status of the given posts', () => {
		const path = decodeURIComponent( getPostStatusPath( 'posts', [ 12, 34 ] ) );

		expect( path ).toContain( '/wp/v2/posts?' );
		expect( path ).toContain( 'include[0]=12' );
		expect( path ).toContain( 'include[1]=34' );
		expect( path ).toContain( '_fields=id,status' );
	} );

	it( 'covers the same statuses the search offers', () => {
		const path = decodeURIComponent( getPostStatusPath( 'posts', [ 12 ] ) );

		expect( path ).toContain( 'status[0]=publish' );
		expect( path ).toContain( 'status[3]=pending' );
		expect( path ).not.toContain( 'private' );
	} );
} );

describe( 'mergePostStatuses', () => {
	it( 'records the status of every post in the response', () => {
		const merged = mergePostStatuses(
			{},
			[ 12, 34 ],
			[
				{ id: 12, status: 'draft' },
				{ id: 34, status: 'publish' },
			]
		);

		expect( merged ).toEqual( { 12: 'draft', 34: 'publish' } );
	} );

	it( 'keeps statuses it already knew about', () => {
		const merged = mergePostStatuses( { 9: 'future' }, [ 12 ], [ { id: 12, status: 'draft' } ] );

		expect( merged[ 9 ] ).toBe( 'future' );
	} );

	it( 'marks requested posts missing from the response as looked up, so they are not requested forever', () => {
		const merged = mergePostStatuses( {}, [ 12, 99 ], [ { id: 12, status: 'draft' } ] );

		expect( merged ).toHaveProperty( '99' );
		expect( formatPostLabel( 'Deleted post', merged[ 99 ] ) ).toBe( 'Deleted post' );
	} );

	it( 'lets a fresh response correct a status it already knew', () => {
		const merged = mergePostStatuses( { 12: 'draft' }, [ 12 ], [ { id: 12, status: 'publish' } ] );

		expect( merged[ 12 ] ).toBe( 'publish' );
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
