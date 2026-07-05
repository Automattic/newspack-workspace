/**
 * External dependencies
 */
import { uniq, pick, flatten, values, flatMap, slice, without, omit } from 'lodash';

/**
 * WordPress dependencies
 */
import { registerStore } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { POSTS_INSERTER_BLOCK_NAME, POSTS_INSERTER_STORE_NAME } from './consts';

interface EditorBlock {
	name: string;
	clientId: string;
	innerBlocks: EditorBlock[];
}

interface DeduplicationState {
	postIdsByBlocks: Record< string, number[] >;
	existingBlockIdsInOrder: string[];
	insertedPostIds: number[];
}

type DeduplicationAction =
	| {
			type: 'SET_HANDLED_POST_IDS';
			handledPostIds: number[];
			props: { clientId: string; existingBlocks: EditorBlock[] };
	  }
	| { type: 'SET_INSERTED_POST_IDS'; insertedPostIds: number[] }
	| { type: 'REMOVE_BLOCK'; clientId: string };

const DEFAULT_STATE: DeduplicationState = {
	postIdsByBlocks: {},
	existingBlockIdsInOrder: [],
	insertedPostIds: [],
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
	 * After insertion, save the inserted post ids.
	 *
	 * @param insertedPostIds post ids
	 */
	setInsertedPostsIds( insertedPostIds: number[] ) {
		return {
			type: 'SET_INSERTED_POST_IDS',
			insertedPostIds,
		};
	},
	removeBlock( clientId: string ) {
		return {
			type: 'REMOVE_BLOCK',
			clientId,
		};
	},
};

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
					insertedPostIds: uniq( [ ...state.insertedPostIds, ...action.insertedPostIds ] ),
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
		getHandledPostIds( { postIdsByBlocks, existingBlockIdsInOrder, insertedPostIds }: DeduplicationState, blockClientId: string ) {
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
				...insertedPostIds,
			];
		},
	},
} );
