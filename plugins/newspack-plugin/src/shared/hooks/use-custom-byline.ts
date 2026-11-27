/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Hook to get custom byline data.
 *
 * @param postId   Post ID.
 * @param postType Post type.
 * @return Custom byline data.
 */
export function useCustomByline( postId: number | null | undefined, postType: string | undefined ) {
	const { bylineActive, bylineContent } = useSelect(
		( select ): { bylineActive: boolean; bylineContent: string } => {
			const { getEditedEntityRecord } = select( coreStore );
			// The original passes postType/postId through unconditionally; the core
			// selector tolerates undefined at runtime, so assert at this boundary.
			const postRecord = getEditedEntityRecord( 'postType', postType as string, postId as number ) as {
				meta?: { _newspack_byline_active?: boolean; _newspack_byline?: string };
			} | null;
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
 * @param bylineContent Byline content with [Author id=X]...[/Author] shortcodes.
 * @return Array of author IDs (integers).
 */
export function extractAuthorIdsFromByline( bylineContent?: string | null ): number[] {
	if ( ! bylineContent ) {
		return [];
	}
	const regex = /\[Author\s+id\s*=\s*(\d+)\]/gi;
	const ids: number[] = [];
	let match: RegExpExecArray | null;
	while ( ( match = regex.exec( bylineContent ) ) !== null ) {
		ids.push( parseInt( match[ 1 ], 10 ) );
	}
	return ids;
}
