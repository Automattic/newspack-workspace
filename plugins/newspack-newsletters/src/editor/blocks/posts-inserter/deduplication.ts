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

interface EditorBlock {
	name: string;
	clientId: string;
	innerBlocks: EditorBlock[];
}

/** A single insert: the post IDs it placed and the blocks that hold them. */
interface InsertedPostsEntry {
	postIds: number[];
	clientIds: string[];
}

interface DeduplicationState {
	postIdsByBlocks: Record< string, number[] >;
	existingBlockIdsInOrder: string[];
	insertedPosts: InsertedPostsEntry[];
}

type DeduplicationAction =
	| {
			type: 'SET_HANDLED_POST_IDS';
			handledPostIds: number[];
			props: { clientId: string; existingBlocks: EditorBlock[] };
	  }
	| { type: 'SET_INSERTED_POST_IDS'; insertedPostIds: number[]; clientIds: string[] }
	| { type: 'REMOVE_BLOCK'; clientId: string };

const DEFAULT_STATE: DeduplicationState = {
	postIdsByBlocks: {},
	existingBlockIdsInOrder: [],
	insertedPosts: [],
};

const actions = {
	setHandledPostsIds( ids: number[], props: { clientId: string; existingBlocks: EditorBlock[] } ) {
		return {
			type: 'SET_HANDLED_POST_IDS',
			handledPostIds: ids,
			props,
		};
	},
	/**
	 * After insertion, save the inserted post ids with the blocks that hold them.
	 *
	 * @param insertedPostIds post ids
	 * @param clientIds       client IDs of the inserted blocks
	 */
	setInsertedPostsIds( insertedPostIds: number[], clientIds: string[] ) {
		return {
			type: 'SET_INSERTED_POST_IDS',
			insertedPostIds,
			clientIds,
		};
	},
	removeBlock( clientId: string ) {
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
export const getReservedInsertedPostIds = ( insertedPosts: InsertedPostsEntry[], isBlockPresent: ( clientId: string ) => boolean ): number[] =>
	uniq(
		flatMap(
			insertedPosts.filter( ( { clientIds } ) => clientIds.some( isBlockPresent ) ),
			'postIds'
		)
	);

const getAllPostsInserterBlocksIds = ( blocks: EditorBlock[] ): string[] =>
	flatMap( blocks, block => [
		...( block.name === POSTS_INSERTER_BLOCK_NAME ? [ block.clientId ] : [] ),
		...getAllPostsInserterBlocksIds( block.innerBlocks ),
	] );

registerStore( POSTS_INSERTER_STORE_NAME, {
	reducer( state: DeduplicationState = DEFAULT_STATE, action: DeduplicationAction ) {
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
		getHandledPostIds: createRegistrySelector(
			select =>
				( { postIdsByBlocks, existingBlockIdsInOrder, insertedPosts }: DeduplicationState, blockClientId: string ) => {
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
				}
		),
	},
} );
