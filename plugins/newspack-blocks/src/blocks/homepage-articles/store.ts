/**
 * External dependencies
 */
import { createStore, applyMiddleware } from 'redux';
import { call, put, takeLatest, delay } from 'redux-saga/effects';
import createSagaMiddleware from 'redux-saga';
import { set } from 'lodash';

/**
 * WordPress dependencies
 */
import { register, select } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import { getBlockQueries, sanitizePostList, recursivelyGetBlocks } from './utils';

const { name } = metadata;
export const STORE_NAMESPACE = `newspack-blocks/${ name }`;

// A block-and-query pair, as returned by utils.getBlockQueries().
type QueryBlock = { postsQuery: PostsQuery; clientId: Block[ 'clientId' ] };

// The REST endpoint (Newspack_Blocks_API::posts_endpoint) also accepts an
// `exclude` list for deduplication; the shared global `PostsQuery` type doesn't
// declare it, so it's added locally here.
type PostsQueryWithExclude = PostsQuery & { exclude?: PostId[] };

type StoreState = {
	// Map of returned posts to block clientIds.
	postsByBlock: Record< string, Post[] >;
	errorsByBlock: Record< string, string >;
	isUIDisabled?: boolean;
};

type StoreAction = {
	type: 'DISABLE_UI' | 'ENABLE_UI' | 'UPDATE_BLOCK_POSTS' | 'UPDATE_BLOCK_ERROR' | 'REFLOW';
	clientId?: Block[ 'clientId' ];
	posts?: Post[];
	error?: string;
};

const initialState: StoreState = {
	// Map of returned posts to block clientIds.
	postsByBlock: {},
	errorsByBlock: {},
};

// Generic redux action creators, not @wordpress/data actions.
const actions = {
	reflow: () => {
		reduxStore.dispatch( {
			type: 'REFLOW',
		} );
	},
};

// Generic redux selectors, not @wordpress/data selectors.
const selectors = {
	getPosts( { clientId }: { clientId: Block[ 'clientId' ] } ) {
		return reduxStore.getState().postsByBlock[ clientId ];
	},
	getError( { clientId }: { clientId: Block[ 'clientId' ] } ) {
		return reduxStore.getState().errorsByBlock[ clientId ];
	},
	isUIDisabled() {
		return reduxStore.getState().isUIDisabled;
	},
};

const reducer = ( state: StoreState = initialState, action: StoreAction ): StoreState => {
	switch ( action.type ) {
		case 'DISABLE_UI':
			return set( state, 'isUIDisabled', true );
		case 'ENABLE_UI':
			return set( state, 'isUIDisabled', false );
		case 'UPDATE_BLOCK_POSTS':
			return set( state, [ 'postsByBlock', action.clientId as string ], action.posts );
		case 'UPDATE_BLOCK_ERROR':
			return set( state, [ 'errorsByBlock', action.clientId as string ], action.error );
	}
	return state;
};

// create the saga middleware
const sagaMiddleware = createSagaMiddleware();
// mount it on the Store
const reduxStore = createStore( reducer, applyMiddleware( sagaMiddleware ) );

const genericStore = {
	getSelectors() {
		return selectors;
	},
	getActions() {
		return actions;
	},
	...reduxStore,
};

/**
 * A cache for posts queries.
 */
const POSTS_QUERIES_CACHE: Record< string, Post[] > = {};
const createCacheKey = JSON.stringify;

/**
 * Get posts for a single block.
 *
 * @yield
 * @param {Object} block an object with a postsQuery and a clientId
 */
function* getPostsForBlock( block: QueryBlock ) {
	const cacheKey = createCacheKey( block.postsQuery );
	// `posts_rest_url` isn't declared on the shared `window.newspack_blocks_data`
	// global type; re-typed here at this boundary (see also components/query-controls.tsx).
	const restUrl = ( window.newspack_blocks_data as { posts_rest_url: string } ).posts_rest_url;
	let posts = POSTS_QUERIES_CACHE[ cacheKey ];
	if ( posts === undefined ) {
		const url = addQueryArgs( restUrl, {
			...block.postsQuery,
			// `context=edit` is needed, so that custom REST fields are returned.
			context: 'edit',
		} );
		posts = ( yield call( apiFetch, { url } ) ) as Post[];
		POSTS_QUERIES_CACHE[ cacheKey ] = posts;
	}

	const postsIds = posts.map( post => post.id );
	yield put( { type: 'UPDATE_BLOCK_POSTS', clientId: block.clientId, posts } );
	return postsIds;
}

/**
 * Whether a block uses deduplication.
 *
 * @param {string} clientId
 *
 * @return {boolean} whether the block uses deduplication
 */
function shouldDeduplicate( clientId: Block[ 'clientId' ] ) {
	const { getBlock } = select( 'core/block-editor' );
	const block = getBlock( clientId );
	return block?.attributes?.deduplicate;
}

const createFetchPostsSaga = ( blockNames: Block[ 'name' ][] ) => {
	/**
	 * "worker" Saga: will be fired on REFLOW actions
	 *
	 * @yield
	 */
	function* fetchPosts() {
		// debounce by 300ms
		yield delay( 300 );

		const { getBlocks } = select( 'core/block-editor' );
		const { getCurrentPostId } = select( 'core/editor' );

		yield put( { type: 'DISABLE_UI' } );

		const blocks = recursivelyGetBlocks( getBlocks );

		const blockQueries = getBlockQueries( blocks, blockNames );

		// Use requested specific posts ids as the starting state of exclusion list.
		const specificPostsId = blockQueries.reduce< PostId[] >( ( acc, { clientId, postsQuery } ) => {
			if ( shouldDeduplicate( clientId ) && postsQuery.include ) {
				acc = [ ...acc, ...postsQuery.include ];
			}
			return acc;
		}, [] );

		let exclude = sanitizePostList( [ ...specificPostsId, getCurrentPostId() ] );
		while ( blockQueries.length ) {
			// blockQueries.length guards this loop, so shift() always returns an
			// element here; `exclude` is also cast onto PostsQuery at this single
			// boundary (see PostsQueryWithExclude above).
			const nextBlock = blockQueries.shift() as { postsQuery: PostsQueryWithExclude; clientId: Block[ 'clientId' ] };
			const deduplicate = shouldDeduplicate( nextBlock.clientId );
			if ( deduplicate ) {
				nextBlock.postsQuery.exclude = exclude;
			}
			let fetchedPostIds: PostId[] = [];
			try {
				fetchedPostIds = ( yield call( getPostsForBlock, nextBlock ) ) as PostId[];
			} catch ( e ) {
				yield put( { type: 'UPDATE_BLOCK_ERROR', clientId: nextBlock.clientId, error: ( e as Error ).message } );
			}
			if ( deduplicate ) {
				exclude = [ ...exclude, ...fetchedPostIds ];
			}
		}

		yield put( { type: 'ENABLE_UI' } );
	}

	/**
	 * Starts fetchPosts on each dispatched `REFLOW` action.
	 *
	 * fetchPosts will wait 300ms before fetching. Thanks to takeLatest,
	 * if new reflow happens during this time, the reflow from before
	 * will be cancelled.
	 *
	 * @yield
	 */
	return function* fetchPostsSaga() {
		yield takeLatest( 'REFLOW', fetchPosts );
	};
};

export const registerQueryStore = ( blockNames: Block[ 'name' ][] ) => {
	register( { name: STORE_NAMESPACE, instantiate: () => genericStore } );

	// Run the saga ✨
	sagaMiddleware.run( createFetchPostsSaga( blockNames ) );
};
