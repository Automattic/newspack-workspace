/**
 * Posts for a block's automatic query, and whether that query is still loading.
 *
 * The resolution is looked up with the same arguments as the records, so the two stay
 * keyed on one request. A failed request also has no records, but it has finished, so it
 * reads as empty rather than loading.
 *
 * @param {Function} select   Data registry `select`.
 * @param {string}   postType Post type slug.
 * @param {Object}   query    `getEntityRecords` query args.
 * @return {{posts: Object[], isLoading: boolean}} Posts, empty while loading.
 */
export const selectAutoPosts = ( select, postType, query ) => {
	const { getEntityRecords, hasFinishedResolution } = select( 'core' );
	const records = getEntityRecords( 'postType', postType, query );

	return {
		posts: records || [],
		isLoading: ! records && ! hasFinishedResolution( 'getEntityRecords', [ 'postType', postType, query ] ),
	};
};
