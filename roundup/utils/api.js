/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Fetch posts for selection in the roundup block.
 *
 * @param {Object} params         Query parameters.
 * @param {string} params.search  Search query.
 * @param {number} params.page    Page number.
 * @param {number} params.perPage Posts per page.
 * @return {Promise} Promise resolving to posts data.
 */
export async function fetchPosts( { search = '', page = 1, perPage = 20 } = {} ) {
	return apiFetch( {
		path: '/wp/v2/newspack-roundup/posts',
		method: 'POST',
		data: {
			search,
			page,
			per_page: perPage,
		},
	} );
}

/**
 * Generate roundup content from selected posts.
 *
 * @param {Object} params          Generation parameters.
 * @param {Array}  params.postIds  Array of post IDs.
 * @param {Array}  params.imageIds Array of image IDs.
 * @param {Object} params.options  Generation options (tone, length, etc.).
 * @return {Promise} Promise resolving to generated content.
 */
export async function generateRoundup( { postIds, imageIds = [], options = {} } = {} ) {
	return apiFetch( {
		path: '/wp/v2/newspack-roundup/generate',
		method: 'POST',
		data: {
			post_ids: postIds,
			image_ids: imageIds,
			options,
		},
	} );
}

/**
 * Regenerate roundup content with new parameters.
 *
 * @param {Object} params          Regeneration parameters.
 * @param {Array}  params.postIds  Array of post IDs.
 * @param {Array}  params.imageIds Array of image IDs.
 * @param {Object} params.options  Generation options (tone, length, etc.).
 * @return {Promise} Promise resolving to regenerated content.
 */
export async function regenerateRoundup( { postIds, imageIds = [], options = {} } = {} ) {
	return apiFetch( {
		path: '/wp/v2/newspack-roundup/regenerate',
		method: 'POST',
		data: {
			post_ids: postIds,
			image_ids: imageIds,
			options,
		},
	} );
}

/**
 * Fetch available tones for roundup generation.
 *
 * @return {Promise} Promise resolving to array of tone options.
 */
export async function fetchTones() {
	return apiFetch( {
		path: '/wp/v2/newspack-roundup/tones',
		method: 'GET',
	} );
}
