/**
 * Internal dependencies
 */
import { SELECTABLE_STATUSES } from './consts';
import type { InserterPost } from './utils';

interface SpecificPostsQuery {
	include: number[];
	per_page: number;
	status: string[];
}

/** The subset of `core`-store selectors this lookup uses. */
interface CoreDataSelectors {
	getEntityRecords: ( kind: string, name: string, query?: object ) => InserterPost[] | null | undefined;
	hasResolutionFailed: ( selectorName: string, args: unknown[] ) => boolean;
}

type DataSelect = ( storeName: string ) => CoreDataSelectors;

/**
 * `getEntityRecords` query for the posts hand-picked on a block.
 *
 * `per_page` overrides core's default of 10, which would otherwise silently drop the
 * overflow once more than ten posts are hand-picked.
 *
 * @param ids Post IDs.
 * @return Query args.
 */
export const getSpecificPostsQuery = ( ids: number[] ): SpecificPostsQuery => ( {
	include: ids,
	per_page: ids.length,
	status: SELECTABLE_STATUSES,
} );

/**
 * Posts hand-picked on a block, unpublished ones included.
 *
 * Core rejects the whole request — every status, not just the unpublished ones — when the
 * user can't edit the post type being queried, so a rejected lookup is retried with core's
 * default published-only filter. Posts picked by a more privileged user then drop out of
 * the list rather than blanking it, which is how the "Add posts" search degrades too.
 *
 * The block and its inspector both call this with the same arguments, so core-data serves
 * them from a single request.
 *
 * @param select   Data registry `select`.
 * @param postType Post type slug.
 * @param ids      Post IDs.
 * @return Posts, or null/undefined while the lookup is in flight.
 */
export const selectSpecificPosts = ( select: DataSelect, postType: string, ids: number[] ): InserterPost[] | null | undefined => {
	// An empty `include` is no filter at all, so core would answer with the whole
	// collection. Nothing is picked yet, so nothing is what we want.
	if ( 0 === ids.length ) {
		return [];
	}

	const { getEntityRecords, hasResolutionFailed } = select( 'core' );
	const query = getSpecificPostsQuery( ids );

	if ( hasResolutionFailed( 'getEntityRecords', [ 'postType', postType, query ] ) ) {
		// Omitting `status` is the intended closed fallback: core's REST default is `publish`,
		// so this yields published posts only. Do not add an explicit status here.
		return getEntityRecords( 'postType', postType, { include: ids } );
	}

	return getEntityRecords( 'postType', postType, query );
};
