/**
 * Internal dependencies
 */
import { SEARCHABLE_STATUSES } from './consts';

/**
 * `getEntityRecords` query for the posts hand-picked on a block.
 *
 * @param {number[]} ids Post IDs.
 * @return {Object} Query args.
 */
export const getSpecificPostsQuery = ids => ( { include: ids, status: SEARCHABLE_STATUSES } );

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
 * @param {Function} select   Data registry `select`.
 * @param {string}   postType Post type slug.
 * @param {number[]} ids      Post IDs.
 * @return {?Object[]} Posts, or null/undefined while the lookup is in flight.
 */
export const selectSpecificPosts = ( select, postType, ids ) => {
	// An empty `include` is no filter at all, so core would answer with the whole
	// collection. Nothing is picked yet, so nothing is what we want.
	if ( 0 === ids.length ) {
		return [];
	}

	const { getEntityRecords, hasResolutionFailed } = select( 'core' );
	const query = getSpecificPostsQuery( ids );

	if ( hasResolutionFailed( 'getEntityRecords', [ 'postType', postType, query ] ) ) {
		return getEntityRecords( 'postType', postType, { include: ids } );
	}

	return getEntityRecords( 'postType', postType, query );
};
