import { getReservedInsertedPostIds } from './deduplication';

// An insert keeps its posts out of later inserters only while its blocks are in the
// editor, so undoing the insert gives the restored inserter its original posts back.
describe( 'getReservedInsertedPostIds', () => {
	const insertedPosts = [
		{ postIds: [ 1, 2 ], clientIds: [ 'a', 'b' ] },
		{ postIds: [ 2, 3 ], clientIds: [ 'c' ] },
	];

	it( 'reserves the posts of every insert whose blocks are present', () => {
		expect( getReservedInsertedPostIds( insertedPosts, () => true ) ).toEqual( [ 1, 2, 3 ] );
	} );

	it( 'releases the posts of an insert whose blocks are all gone', () => {
		expect( getReservedInsertedPostIds( insertedPosts, clientId => 'c' === clientId ) ).toEqual( [ 2, 3 ] );
	} );

	it( 'keeps an insert reserved while any one of its blocks remains', () => {
		expect( getReservedInsertedPostIds( insertedPosts, clientId => 'b' === clientId ) ).toEqual( [ 1, 2 ] );
	} );
} );
