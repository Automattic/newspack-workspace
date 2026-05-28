/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Hook to get default WordPress author.
 *
 * @param {number} postId   Post ID.
 * @param {string} postType Post type.
 * @return {Object} Author details and loading state.
 */
export function useDefaultAuthor( postId, postType ) {
	const { authorDetails, isLoading } = useSelect(
		select => {
			const { getEditedEntityRecord, getUser, hasFinishedResolution } = select( coreStore );
			const authorId = getEditedEntityRecord( 'postType', postType, postId )?.author;

			if ( ! authorId ) {
				return { authorDetails: null, isLoading: false };
			}

			const user = getUser( authorId );
			const hasResolved = hasFinishedResolution( 'getUser', [ authorId ] );

			return {
				authorDetails: user,
				isLoading: ! hasResolved,
			};
		},
		[ postType, postId ]
	);

	return { authorDetails, isLoading };
}
