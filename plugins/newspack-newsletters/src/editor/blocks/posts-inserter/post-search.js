/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import { SELECTABLE_STATUSES } from './consts';

/**
 * REST path for the "Add posts" search.
 *
 * Core's `/wp/v2/search` handler is hardcoded to published posts, so the search runs
 * against the post type's own collection endpoint instead, which accepts `status`.
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
		...( includeStatuses ? { status: SELECTABLE_STATUSES } : {} ),
	} );

/**
 * Suggestions for the "Add posts" search, unpublished statuses included.
 *
 * @param {string} restBase REST base of the post type being searched.
 * @param {string} search   Search term.
 * @return {Promise<Object[]>} Posts with `id`, `title` and `status`.
 */
export const fetchPostSuggestions = ( restBase, search ) =>
	apiFetch( { path: getPostSearchPath( restBase, search ) } )
		.catch( error => {
			// Core rejects the whole request when the user can't edit this post type. Fall back
			// to published posts rather than leaving the search empty. Any other failure — no
			// network, a broken endpoint — is not worth a second attempt.
			if ( 'rest_forbidden_status' !== error?.code ) {
				throw error;
			}
			return apiFetch( { path: getPostSearchPath( restBase, search, false ) } );
		} )
		.then( posts =>
			posts.map( post => ( {
				id: post.id,
				title: decodeEntities( post.title?.rendered ) || __( '(no title)', 'newspack-newsletters' ),
				status: post.status,
			} ) )
		)
		.catch( () => [] );

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
