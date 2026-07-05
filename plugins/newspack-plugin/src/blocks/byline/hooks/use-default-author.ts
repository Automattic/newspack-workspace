/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Details of the default WordPress author, as resolved from the core store.
 * `undefined` when the user record has not been fetched yet.
 */
type DefaultAuthorDetails =
	| {
			id?: number;
			name?: string;
			slug?: string;
	  }
	| null
	| undefined;

/**
 * Hook to get default WordPress author.
 *
 * @param postId   Post ID.
 * @param postType Post type.
 * @return Author details and loading state.
 */
export function useDefaultAuthor( postId: number | undefined, postType: string | undefined ) {
	const { authorDetails, isLoading }: { authorDetails: DefaultAuthorDetails; isLoading: boolean } = useSelect(
		select => {
			const { getEditedEntityRecord, getUser, hasFinishedResolution } = select( coreStore );
			// Outside a post context there is no record to read; the entity record's
			// `author` field is asserted at the store boundary.
			const postRecord = postId && postType ? ( getEditedEntityRecord( 'postType', postType, postId ) as { author?: number } | null ) : null;
			const authorId = postRecord?.author;

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
