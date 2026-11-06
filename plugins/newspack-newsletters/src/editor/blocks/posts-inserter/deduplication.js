/**
 * External dependencies
 */
import { uniq, pick, flatten, values, flatMap, slice, without, omit } from 'lodash';

/**
 * WordPress dependencies
 */
import { createRegistrySelector, registerStore } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { POSTS_INSERTER_BLOCK_NAME, POSTS_INSERTER_STORE_NAME } from './consts';

const DEFAULT_STATE = {
	postIdsByBlocks: {},
	existingBlockIdsInOrder: [],
	insertedPosts: [],
};

const actions = {
	setHandledPostsIds( ids, props ) {
		return {
			type: 'SET_HANDLED_POST_IDS',
			handledPostIds: ids,
			props,
		};
	},
	/**
	 * After insertion, save the inserted post ids with the blocks that hold them.
	 *
	 * @param {Array} insertedPostIds post ids
	 * @param {Array} clientIds       client IDs of the inserted blocks
	 */
	setInsertedPostsIds( insertedPostIds, clientIds ) {
		return {
			type: 'SET_INSERTED_POST_IDS',
			insertedPostIds,
			clientIds,
		};
	},
	removeBlock( clientId ) {
		return {
			type: 'REMOVE_BLOCK',
			clientId,
		};
	},
};

/**
 * Post IDs from the inserts whose blocks are still in the editor.
 *
 * Inserted posts are reserved only while at least one of their blocks remains, so undoing
 * an insert, or deleting the inserted blocks, makes those posts available again.
 *
 * @param {Array}    insertedPosts  Inserts, each with `postIds` and `clientIds`.
 * @param {Function} isBlockPresent Whether a block with the given client ID is in the editor.
 * @return {Array} Reserved post IDs.
 */
export const getReservedInsertedPostIds = ( insertedPosts, isBlockPresent ) =>
	uniq(
		flatMap(
			insertedPosts.filter( ( { clientIds } ) => clientIds.some( isBlockPresent ) ),
			'postIds'
		)
	);

const getAllPostsInserterBlocksIds = blocks =>
	flatMap( blocks, block => [
		...( block.name === POSTS_INSERTER_BLOCK_NAME ? [ block.clientId ] : [] ),
		...getAllPostsInserterBlocksIds( block.innerBlocks ),
	] );

registerStore( POSTS_INSERTER_STORE_NAME, {
	reducer( state = DEFAULT_STATE, action ) {
		switch ( action.type ) {
			case 'SET_HANDLED_POST_IDS':
				const { clientId, existingBlocks } = action.props;
				const existingBlockIdsInOrder = getAllPostsInserterBlocksIds( existingBlocks );
				return {
					...state,
					existingBlockIdsInOrder,
					postIdsByBlocks: pick(
						{
							...state.postIdsByBlocks,
							[ clientId ]: action.handledPostIds,
						},
						existingBlockIdsInOrder
					),
				};
			case 'SET_INSERTED_POST_IDS':
				return {
					...state,
					insertedPosts: [ ...state.insertedPosts, { postIds: action.insertedPostIds, clientIds: action.clientIds } ],
				};
			case 'REMOVE_BLOCK':
				return {
					...state,
					existingBlockIdsInOrder: without( state.existingBlockIdsInOrder, action.clientId ),
					postIdsByBlocks: omit( state.postIdsByBlocks, [ action.clientId ] ),
				};
		}

		return state;
	},

	actions,

	selectors: {
		getHandledPostIds: createRegistrySelector( select => ( { postIdsByBlocks, existingBlockIdsInOrder, insertedPosts }, blockClientId ) => {
			const { getBlock } = select( 'core/block-editor' );
			const blockIndex = existingBlockIdsInOrder.indexOf( blockClientId );
			const blocksBeforeIds = slice( existingBlockIdsInOrder, 0, blockIndex );
			return [
				/**
				 * Ids of posts handled by the existing blocks.
				 */
				...uniq( flatten( values( pick( postIdsByBlocks, blocksBeforeIds ) ) ) ),
				/**
				 * Ids of posts that were inserted.
				 */
				...getReservedInsertedPostIds( insertedPosts, clientId => Boolean( getBlock( clientId ) ) ),
			];
		} ),
	},
} );
