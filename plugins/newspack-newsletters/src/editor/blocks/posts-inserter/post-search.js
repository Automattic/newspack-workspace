/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Post statuses the "Add posts" search offers.
 *
 * Core's `/wp/v2/search` handler is hardcoded to published posts, so the search runs
 * against the post type's own collection endpoint instead, which accepts `status`.
 * Private posts are deliberately left out.
 */
export const SEARCHABLE_STATUSES = [ 'publish', 'future', 'draft', 'pending' ];

/**
 * REST path for the "Add posts" search.
 *
 * @param {string}  restBase        REST base of the post type being searched.
 * @param {string}  search          Search term.
 * @param {boolean} includeStatuses Whether to ask for unpublished posts. Core rejects the
 *                                  whole request when the user can't edit the post type,
 *                                  so the caller retries with this off.
 * @return {string} REST path.
 */
export const getPostSearchPath = ( restBase, search, includeStatuses = true ) =>
	addQueryArgs( `/wp/v2/${ restBase }`, {
		search,
		per_page: 20,
		orderby: 'relevance',
		_fields: 'id,title,status',
		...( includeStatuses ? { status: SEARCHABLE_STATUSES } : {} ),
	} );

/**
 * REST path for looking up the current status of already-selected posts, so a post that
 * gets published stops being labelled as a draft.
 *
 * @param {string}   restBase REST base of the post type.
 * @param {number[]} ids      Post IDs to look up.
 * @return {string} REST path.
 */
export const getPostStatusPath = ( restBase, ids ) =>
	addQueryArgs( `/wp/v2/${ restBase }`, {
		include: ids,
		per_page: 100,
		_fields: 'id,status',
		status: SEARCHABLE_STATUSES,
	} );

/**
 * Fold a status lookup response into the known statuses.
 *
 * Requested posts absent from the response — trashed, private, or otherwise unreadable —
 * are recorded with an empty status. Without that they would count as unknown forever and
 * the lookup would run on every render.
 *
 * @param {Object}   known        Statuses keyed by post ID.
 * @param {number[]} requestedIds Post IDs the lookup asked for.
 * @param {Object[]} posts        Posts returned by the lookup.
 * @return {Object} Updated statuses keyed by post ID.
 */
export const mergePostStatuses = ( known, requestedIds, posts ) => ( {
	...known,
	...requestedIds.reduce( ( all, id ) => ( { ...all, [ id ]: '' } ), {} ),
	...posts.reduce( ( all, post ) => ( { ...all, [ post.id ]: post.status } ), {} ),
} );

/**
 * Human-readable name for an unpublished status. Published posts get none.
 *
 * @param {string} status Post status.
 * @return {string} Status name, or an empty string.
 */
const getStatusLabel = status => {
	switch ( status ) {
		case 'future':
			return __( 'Scheduled', 'newspack-newsletters' );
		case 'draft':
			return __( 'Draft', 'newspack-newsletters' );
		case 'pending':
			return __( 'Pending', 'newspack-newsletters' );
		default:
			return '';
	}
};

/**
 * Post title as shown in the "Add posts" field, with its status appended when the post
 * isn't published.
 *
 * @param {string} title  Post title.
 * @param {string} status Post status. May be undefined while it's still being looked up.
 * @return {string} Title for display.
 */
export const formatPostLabel = ( title, status ) => {
	const label = getStatusLabel( status );

	if ( ! label ) {
		return title;
	}

	return sprintf(
		/* translators: 1: post title. 2: post status, such as Draft or Scheduled. */
		_x( '%1$s — %2$s', 'post title with status', 'newspack-newsletters' ),
		title,
		label
	);
};
