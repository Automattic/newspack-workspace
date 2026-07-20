/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import type { User } from '@wordpress/core-data';
import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useCoAuthors } from '../../shared/hooks/use-coauthors';
import type { CoAuthor } from '../../shared/hooks/use-coauthors';
import { useCustomByline, extractAuthorIdsFromByline } from '../../shared/hooks/use-custom-byline';

/**
 * Avatar data resolved for display: image source, alt text, and the size
 * bounds derived from the available avatar sizes.
 */
export type AvatarData = {
	src?: string;
	alt?: string;
	minSize?: number | string;
	maxSize?: number;
};

/**
 * User data resolved from the core store for an author.
 */
type AuthorUserData = Pick< User< 'edit' >, 'name' | 'avatar_urls' > | null;

/**
 * Avatar URLs keyed by size: either the core REST shape (WP users) or the
 * CoAuthors Plus REST shape (guest authors).
 */
type AuthorAvatarUrls = User< 'edit' >[ 'avatar_urls' ] | NonNullable< CoAuthor[ 'avatar_urls' ] >;

/**
 * A post author with resolved avatar data, as returned by usePostAuthors().
 */
export type PostAuthorWithAvatar = {
	id?: number | string;
	name?: string;
	display_name?: string;
	user_nicename?: string;
	author_link?: string;
	avatar_urls?: AuthorAvatarUrls | null;
	avatarSrc?: string;
};

/**
 * Compute min and max avatar sizes from available size keys.
 *
 * @param sizes Array of available size strings.
 * @return Object with minSize and maxSize properties.
 */
function getAvatarSizes( sizes: string[] | null ) {
	const minSize = sizes ? sizes[ 0 ] : 24;
	const maxSize = sizes ? sizes[ sizes.length - 1 ] : 128;
	const maxSizeBuffer = Math.floor( Number( maxSize ) * 2.5 );
	return {
		minSize,
		maxSize: maxSizeBuffer,
	};
}

/**
 * Hook to get the site's default avatar URL from block editor settings.
 *
 * @return Default avatar URL.
 */
export function useDefaultAvatar(): string | undefined {
	const { avatarURL: defaultAvatarUrl } = useSelect( select => {
		// The block-editor store is untyped; assert the selector shape at the boundary.
		const { getSettings } = select( 'core/block-editor' ) as {
			getSettings: () => { __experimentalDiscussionSettings: { avatarURL?: string } };
		};
		const { __experimentalDiscussionSettings } = getSettings();
		return __experimentalDiscussionSettings;
	} );
	return defaultAvatarUrl;
}

/**
 * Hook to get the avatar data for the post's primary author.
 *
 * @param props          Hook props.
 * @param props.userId   User ID (accepted from callers, currently unused).
 * @param props.postId   Post ID.
 * @param props.postType Post type.
 * @return Avatar object with src, alt, minSize, and maxSize.
 */
export function useUserAvatar( { postId, postType }: { userId?: number; postId?: number; postType?: string } ): AvatarData {
	const { authorDetails } = useSelect(
		select => {
			const { getEditedEntityRecord, getUser } = select( coreStore );
			// Outside a post context there is no record to read; the entity record's
			// `author` field is asserted at the store boundary.
			const postRecord = postId && postType ? ( getEditedEntityRecord( 'postType', postType, postId ) as { author?: number } | null ) : null;
			const _authorId = postRecord?.author;
			return {
				authorDetails: _authorId ? getUser( _authorId ) : null,
			};
		},
		[ postType, postId ]
	);
	const avatarUrls = authorDetails?.avatar_urls ? Object.values( authorDetails.avatar_urls ) : null;
	const sizes = authorDetails?.avatar_urls ? Object.keys( authorDetails.avatar_urls ) : null;
	const { minSize, maxSize } = getAvatarSizes( sizes );
	const defaultAvatar = useDefaultAvatar();
	return {
		src: avatarUrls ? avatarUrls[ avatarUrls.length - 1 ] : defaultAvatar,
		minSize,
		maxSize,
		alt: authorDetails
			? // translators: %s: Author name.
			  sprintf( __( '%s Avatar', 'newspack-plugin' ), authorDetails?.name )
			: __( 'Default Avatar', 'newspack-plugin' ),
	};
}

/**
 * Hook to get post authors with avatar data.
 *
 * Resolution order mirrors the PHP get_avatar_authors() method:
 * 1. Custom byline authors (if active and has [Author] shortcodes).
 *    If the byline is active but text-only (no shortcodes), returns empty — no avatars.
 * 2. CoAuthors Plus authors.
 * 3. Empty array (caller falls back to useUserAvatar for single-author display).
 *
 * Each returned author includes an `avatarSrc` field with a resolved URL,
 * falling back to the site's default avatar when user-specific data is unavailable
 * (e.g. CAP guest authors without a linked WordPress account).
 *
 * @param props          Hook props.
 * @param props.postId   Post ID to get authors for.
 * @param props.postType Post type (default: 'post').
 * @return Authors array with avatar data.
 */
export function usePostAuthors( { postId, postType = 'post' }: { postId?: number; postType?: string } ): PostAuthorWithAvatar[] {
	const { bylineActive, bylineContent } = useCustomByline( postId, postType );
	const { authors: coAuthors } = useCoAuthors( postId, postType, bylineActive );
	const defaultAvatarUrl = useDefaultAvatar();

	// Extract author IDs from custom byline content.
	const bylineAuthorIds: number[] = useMemo(
		() => ( bylineActive && bylineContent ? extractAuthorIdsFromByline( bylineContent ) : [] ),
		[ bylineActive, bylineContent ]
	);

	const hasCustomBylineAuthors = bylineAuthorIds.length > 0;

	// Custom byline is active but contains no [Author] shortcodes — text-only byline.
	const hasActiveTextOnlyByline = bylineActive && ! hasCustomBylineAuthors;

	// Resolve user data from the core store. Return raw getUser() references
	// (stable store objects) to avoid useSelect memoization warnings from
	// creating new objects via .map() inside the selector.
	const userDataMap: Record< number | string, AuthorUserData > | null = useSelect(
		select => {
			if ( hasActiveTextOnlyByline ) {
				return null;
			}

			const { getUser } = select( coreStore );

			if ( hasCustomBylineAuthors ) {
				const map: Record< number | string, AuthorUserData > = {};
				bylineAuthorIds.forEach( authorId => {
					map[ authorId ] = getUser( authorId ) || null;
				} );
				return map;
			}

			if ( coAuthors && coAuthors.length > 0 ) {
				const map: Record< number | string, AuthorUserData > = {};
				coAuthors.forEach( author => {
					if ( author.id && ! author.isGuest ) {
						map[ author.id ] = getUser( author.id ) || null;
					}
				} );
				return map;
			}

			return null;
		},
		[ hasActiveTextOnlyByline, hasCustomBylineAuthors, bylineAuthorIds, coAuthors ]
	);

	// Build the authors array outside useSelect so .map() doesn't trigger
	// memoization warnings.
	const authorsWithAvatars: PostAuthorWithAvatar[] = useMemo( () => {
		if ( hasActiveTextOnlyByline || ! userDataMap ) {
			return [];
		}

		if ( hasCustomBylineAuthors ) {
			return bylineAuthorIds
				.map( authorId => {
					const userData = userDataMap[ authorId ];
					if ( ! userData ) {
						return null;
					}
					return {
						id: authorId,
						name: userData.name || '',
						display_name: userData.name || '',
						avatar_urls: userData.avatar_urls || null,
					};
				} )
				.filter( ( author ): author is NonNullable< typeof author > => Boolean( author ) );
		}

		if ( coAuthors && coAuthors.length > 0 ) {
			return coAuthors.map( author => {
				const userData = author.id && ! author.isGuest ? userDataMap[ author.id ] : null;
				return {
					id: author.id,
					name: author.display_name,
					display_name: author.display_name,
					user_nicename: author.user_nicename,
					author_link: author.author_link,
					avatar_urls: userData?.avatar_urls || author.avatar_urls || null,
				};
			} );
		}

		return [];
	}, [ hasActiveTextOnlyByline, hasCustomBylineAuthors, bylineAuthorIds, coAuthors, userDataMap ] );

	// Resolve a concrete avatarSrc for each author so the render layer doesn't need
	// to know about fallback logic.
	return useMemo(
		() =>
			authorsWithAvatars.map( author => ( {
				...author,
				avatarSrc: author.avatar_urls?.[ 96 ] || defaultAvatarUrl,
			} ) ),
		[ authorsWithAvatars, defaultAvatarUrl ]
	);
}
