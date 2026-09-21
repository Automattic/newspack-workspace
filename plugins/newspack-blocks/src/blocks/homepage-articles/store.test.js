/**
 * External dependencies
 */
import { runSaga } from 'redux-saga';

/**
 * WordPress dependencies
 */
import { getQueryArgs } from '@wordpress/url';

jest.mock( '@wordpress/api-fetch' );

const SINGLE_URL = 'https://example.test/wp-json/newspack-blocks/v1/newspack-blocks-posts';
const BATCH_URL = 'https://example.test/wp-json/newspack-blocks/v1/newspack-blocks-posts-batch';

// Every query draws from posts 1–20, newest first, skipping the posts in its exclusion list.
const findPosts = ( postsToShow, exclude ) => {
	const ids = [];
	for ( let id = 1; id <= 20 && ids.length < postsToShow; id++ ) {
		if ( ! exclude.includes( id ) ) {
			ids.push( id );
		}
	}
	return ids;
};

// Stands in for both endpoints. The batch endpoint carries the exclusion list from each
// deduplicating query to the next; the single-block endpoint takes its list from the URL.
const fakeEndpoints = ( { url, data } ) => {
	if ( ! data ) {
		const { postsToShow, exclude = [] } = getQueryArgs( url );
		return Promise.resolve( findPosts( Number( postsToShow ), exclude.map( Number ) ).map( id => ( { id } ) ) );
	}
	let exclude = [ ...data.exclude ];
	return Promise.resolve(
		data.queries.map( ( { clientId, postsQuery, deduplicate } ) => {
			if ( postsQuery.fail ) {
				return { clientId, error: 'Invalid parameter(s): postsToShow' };
			}
			const ids = findPosts( postsQuery.postsToShow, deduplicate ? exclude : [] );
			if ( deduplicate ) {
				exclude = [ ...exclude, ...ids ];
			}
			return { clientId, posts: ids.map( id => ( { id } ) ) };
		} )
	);
};

const block = ( clientId, postsToShow, extra = {} ) => ( {
	clientId,
	postsQuery: { postsToShow, ...extra },
	deduplicate: true,
} );

describe( 'fetchPostsForBlocks', () => {
	// The query cache lives at module scope, so each test loads a fresh copy of the store,
	// along with the api-fetch mock that copy calls.
	let fetchPostsForBlocks, apiFetch;

	const run = async ( blocks, exclude = [] ) => {
		const dispatched = [];
		await runSaga( { dispatch: action => dispatched.push( action ) }, fetchPostsForBlocks, blocks, exclude ).toPromise();
		return {
			postsByBlock: Object.fromEntries(
				dispatched
					.filter( action => action.type === 'UPDATE_BLOCK_POSTS' )
					.map( action => [ action.clientId, action.posts.map( post => post.id ) ] )
			),
			errorsByBlock: Object.fromEntries(
				dispatched.filter( action => action.type === 'UPDATE_BLOCK_ERROR' ).map( action => [ action.clientId, action.error ] )
			),
		};
	};

	beforeEach( () => {
		window.newspack_blocks_data = { posts_rest_url: SINGLE_URL, posts_batch_rest_url: BATCH_URL, posts_batch_max_queries: 50 };
		jest.isolateModules( () => {
			apiFetch = require( '@wordpress/api-fetch' ).default;
			( { fetchPostsForBlocks } = require( './store' ) );
		} );
		apiFetch.mockImplementation( fakeEndpoints );
	} );

	it( 'loads every block on the page with one request', async () => {
		const { postsByBlock } = await run( [ block( 'a', 2 ), block( 'b', 2 ), block( 'c', 1 ) ], [ 20 ] );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( { url: BATCH_URL, method: 'POST', data: { exclude: [ 20 ] } } );
		expect( postsByBlock ).toEqual( { a: [ 1, 2 ], b: [ 3, 4 ], c: [ 5 ] } );
	} );

	it( 'asks for custom REST fields', async () => {
		await run( [ block( 'a', 1 ), block( 'b', 1 ) ] );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.queries[ 0 ].postsQuery.context ).toBe( 'edit' );
	} );

	it( 'uses the single-block request when only one block needs posts', async () => {
		const { postsByBlock } = await run( [ block( 'a', 2 ) ], [ 1 ] );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const [ { url, data, method } ] = apiFetch.mock.calls[ 0 ];
		expect( url.startsWith( SINGLE_URL + '?' ) ).toBe( true );
		expect( getQueryArgs( url ) ).toMatchObject( { context: 'edit', exclude: [ '1' ] } );
		expect( data ).toBeUndefined();
		expect( method ).toBeUndefined();
		expect( postsByBlock ).toEqual( { a: [ 2, 3 ] } );
	} );

	it( 'fetches only the last block, on its own, when it is the one that changed', async () => {
		await run( [ block( 'a', 2 ), block( 'b', 2 ) ] );
		const { postsByBlock } = await run( [ block( 'a', 2 ), block( 'b', 3 ) ] );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		const [ { url, data } ] = apiFetch.mock.calls[ 1 ];
		expect( data ).toBeUndefined();
		expect( getQueryArgs( url ).exclude ).toEqual( [ '1', '2' ] );
		expect( postsByBlock ).toEqual( { a: [ 1, 2 ], b: [ 3, 4, 5 ] } );
	} );

	it( 'reports a failed single-block request on that block only', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'Service unavailable' ) );

		const { postsByBlock, errorsByBlock } = await run( [ block( 'a', 1 ) ] );

		expect( errorsByBlock ).toEqual( { a: 'Service unavailable' } );
		expect( postsByBlock ).toEqual( {} );
	} );

	it( 'reuses cached posts when nothing on the page changed', async () => {
		const blocks = [ block( 'a', 2 ), block( 'b', 2 ) ];
		await run( blocks );
		const { postsByBlock } = await run( blocks );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( postsByBlock ).toEqual( { a: [ 1, 2 ], b: [ 3, 4 ] } );
	} );

	// Blocks above an edited block keep their cached posts. The edited block and everything
	// below it are re-fetched, starting from the posts the blocks above show.
	it( 'only re-fetches from the first block that changed', async () => {
		await run( [ block( 'a', 2 ), block( 'b', 2 ), block( 'c', 2 ) ] );
		const { postsByBlock } = await run( [ block( 'a', 2 ), block( 'b', 3 ), block( 'c', 2 ) ] );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		const { data } = apiFetch.mock.calls[ 1 ][ 0 ];
		expect( data.queries.map( query => query.clientId ) ).toEqual( [ 'b', 'c' ] );
		expect( data.exclude ).toEqual( [ 1, 2 ] );
		expect( postsByBlock ).toEqual( { a: [ 1, 2 ], b: [ 3, 4, 5 ], c: [ 6, 7 ] } );
	} );

	it( 'keeps the posts of a block without deduplication available to the blocks below it', async () => {
		const { postsByBlock } = await run( [ block( 'a', 2 ), { ...block( 'b', 2 ), deduplicate: false }, block( 'c', 2 ) ] );

		expect( postsByBlock ).toEqual( { a: [ 1, 2 ], b: [ 1, 2 ], c: [ 3, 4 ] } );
	} );

	it( 'reports a failed query on its own block and still loads the others', async () => {
		const { postsByBlock, errorsByBlock } = await run( [ block( 'a', 1 ), block( 'b', 1, { fail: true } ), block( 'c', 1 ) ] );

		expect( errorsByBlock ).toEqual( { b: 'Invalid parameter(s): postsToShow' } );
		expect( postsByBlock ).toEqual( { a: [ 1 ], c: [ 2 ] } );
	} );

	// Later blocks' exclusion lists depend on the failed batch, so they can't be loaded either.
	it( 'reports an error on every remaining block when a batch request fails', async () => {
		window.newspack_blocks_data.posts_batch_max_queries = 2;
		apiFetch.mockImplementationOnce( fakeEndpoints ).mockRejectedValueOnce( new Error( 'Service unavailable' ) );

		const { postsByBlock, errorsByBlock } = await run( [ block( 'a', 1 ), block( 'b', 1 ), block( 'c', 1 ), block( 'd', 1 ), block( 'e', 1 ) ] );

		expect( postsByBlock ).toEqual( { a: [ 1 ], b: [ 2 ] } );
		expect( errorsByBlock ).toEqual( { c: 'Service unavailable', d: 'Service unavailable', e: 'Service unavailable' } );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'splits a page larger than the batch limit and carries deduplication across batches', async () => {
		window.newspack_blocks_data.posts_batch_max_queries = 2;

		const { postsByBlock } = await run( [ block( 'a', 1 ), block( 'b', 1 ), block( 'c', 1 ), block( 'd', 1 ), block( 'e', 1 ) ] );

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data.exclude ).toEqual( [ 1, 2 ] );
		// The last block is left over on its own, so it uses the single-block request.
		expect( getQueryArgs( apiFetch.mock.calls[ 2 ][ 0 ].url ).exclude ).toEqual( [ '1', '2', '3', '4' ] );
		expect( postsByBlock ).toEqual( { a: [ 1 ], b: [ 2 ], c: [ 3 ], d: [ 4 ], e: [ 5 ] } );
	} );
} );
