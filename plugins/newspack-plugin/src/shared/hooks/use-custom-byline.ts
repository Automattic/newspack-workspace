/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Hook to get custom byline data.
 *
 * @param {number} postId   Post ID.
 * @param {string} postType Post type.
 * @return {Object} Custom byline data.
 */
export function useCustomByline( postId, postType ) {
	const { bylineActive, bylineContent } = useSelect(
		select => {
			const { getEditedEntityRecord } = select( coreStore );
			const postRecord = getEditedEntityRecord( 'postType', postType, postId );
			return {
				bylineActive: postRecord?.meta?._newspack_byline_active || false,
				bylineContent: postRecord?.meta?._newspack_byline || '',
			};
		},
		[ postId, postType ]
	);

	return { bylineActive, bylineContent };
}

/**
 * Extract author IDs from custom byline shortcode content.
 *
 * @param {string} bylineContent Byline content with [Author id=X]...[/Author] shortcodes.
 * @return {Array} Array of author IDs (integers).
 */
export function extractAuthorIdsFromByline( bylineContent ) {
	if ( ! bylineContent ) {
		return [];
	}
	const regex = /\[Author\s+id\s*=\s*(\d+)\]/gi;
	const ids = [];
	let match;
	while ( ( match = regex.exec( bylineContent ) ) !== null ) {
		ids.push( parseInt( match[ 1 ], 10 ) );
	}
	return ids;
}
