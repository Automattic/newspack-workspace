/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useMemo } from '@wordpress/element';
import { store as coreStore } from '@wordpress/core-data';
import apiFetch from '@wordpress/api-fetch';

// Detection key for the optional Redux store published by CAP <4.0. When present and exposing
// `getAuthors`, prefer the synchronous path; otherwise fall back to term-ID resolution via REST.
const CAP_STORE = 'cap/authors';
const COAUTHORS_ENDPOINT = '/coauthors/v1/coauthors';
const COAUTHORS_BY_TERM_IDS_ENDPOINT = '/coauthors/v1/authors-by-term-ids';

// Module-level cache for guest author avatar URLs, keyed by user_nicename.
// Prevents duplicate REST requests when components re-mount or when
// multiple avatar blocks in a Query Loop share the same guest authors.
const guestAvatarCache = {};

// In-flight request promises, keyed by user_nicename.
// Prevents duplicate concurrent requests when multiple block instances
// mount simultaneously (e.g. Query Loop) and all check the cache before
// any fetch has completed.
const inflightRequests = {};

// Term-ID to author object cache (new CAP). A cached `false` means the server omitted the ID.
const coauthorDetailsCache = {};

// In-flight term-ID fetches keyed by sorted CSV, so concurrent mounts share one REST call.
const inflightTermResolves = {};

// Stable empty-state reference so setState is a no-op when already empty.
const EMPTY_AUTHORS = Object.freeze( [] );

/**
 * Fetch coauthor details for an array of taxonomy term IDs (new CAP).
 * Deduplicates concurrent requests and caches results by term ID.
 *
 * @param {number[]} termIds Array of coauthor taxonomy term IDs.
 * @return {Promise} Resolves once results are in `coauthorDetailsCache`.
 */
function fetchCoauthorsByTermIds( termIds ) {
	if ( ! termIds || termIds.length === 0 ) {
		return Promise.resolve();
	}
	const uncachedIds = termIds.filter( id => ! ( id in coauthorDetailsCache ) );
	if ( uncachedIds.length === 0 ) {
		return Promise.resolve();
	}
	const key = [ ...uncachedIds ].sort( ( a, b ) => a - b ).join( ',' );
	if ( inflightTermResolves[ key ] ) {
		return inflightTermResolves[ key ];
	}
	inflightTermResolves[ key ] = apiFetch( {
		path: `${ COAUTHORS_BY_TERM_IDS_ENDPOINT }?ids=${ uncachedIds.join( ',' ) }`,
	} )
		.then( results => {
			if ( Array.isArray( results ) ) {
				results.forEach( author => {
					if ( author?.termId ) {
						coauthorDetailsCache[ author.termId ] = author;
					}
				} );
			}
			// Mark IDs that the server omitted as permanently unresolved (e.g. deleted author).
			// Only done on a successful response — transient errors leave cache untouched to allow retries.
			uncachedIds.forEach( id => {
				if ( ! ( id in coauthorDetailsCache ) ) {
					coauthorDetailsCache[ id ] = false;
				}
			} );
		} )
		.catch( () => {
			// Do not poison the cache on transient errors (network, 5xx) or permission errors (403).
			// Concurrent calls are already deduped via `inflightTermResolves`, and leaving the cache
			// untouched allows a later call to `fetchCoauthorsByTermIds` to retry after this
			// in-flight request clears. Failure is signalled to consumers via `hasCoauthorTermIds`
			// remaining true while `authors` stays empty.
		} )
		.finally( () => {
			delete inflightTermResolves[ key ];
		} );
	return inflightTermResolves[ key ];
}

/**
 * Fetch a single coauthor's avatar URLs, deduplicating concurrent requests.
 *
 * @param {string} nicename Author nicename (slug).
 * @return {Promise} Resolves when the fetch completes (result is stored in guestAvatarCache).
 */
function fetchCoauthorAvatar( nicename ) {
	if ( nicename in guestAvatarCache ) {
		return Promise.resolve();
	}
	if ( inflightRequests[ nicename ] ) {
		return inflightRequests[ nicename ];
	}
	inflightRequests[ nicename ] = apiFetch( {
		path: `${ COAUTHORS_ENDPOINT }/${ encodeURIComponent( nicename ) }`,
	} )
		.then( result => {
			guestAvatarCache[ nicename ] = result?.avatar_urls || false;
		} )
		.catch( () => {
			guestAvatarCache[ nicename ] = false;
		} )
		.finally( () => {
			delete inflightRequests[ nicename ];
		} );
	return inflightRequests[ nicename ];
}

/**
 * Reset the module-level guest avatar cache. Exposed for testing only.
 */
export function resetGuestAvatarCacheForTests() {
	Object.keys( guestAvatarCache ).forEach( key => delete guestAvatarCache[ key ] );
	Object.keys( inflightRequests ).forEach( key => delete inflightRequests[ key ] );
}

/**
 * Reset the module-level coauthor details cache. Exposed for testing only.
 */
export function resetCoauthorDetailsCacheForTests() {
	Object.keys( coauthorDetailsCache ).forEach( key => delete coauthorDetailsCache[ key ] );
	Object.keys( inflightTermResolves ).forEach( key => delete inflightTermResolves[ key ] );
}

/**
 * Extract user_nicename from an author archive URL.
 *
 * @param {string} link Author archive URL (e.g. /author/jane/).
 * @return {string|undefined} Extracted nicename, or undefined.
 */
function extractNicenameFromLink( link ) {
	if ( ! link ) {
		return undefined;
	}
	const cleaned = link.split( '?' )[ 0 ].split( '#' )[ 0 ].replace( /\/$/, '' );
	const segments = cleaned.split( '/' );
	const slug = segments.pop();
	// Require a parent path segment so root URLs and plain permalinks are rejected.
	const parent = segments[ segments.length - 1 ];
	if ( ! slug || ! parent ) {
		return undefined;
	}
	return slug;
}

/**
 * Hook to get CoAuthors Plus authors from the CAP store or REST API.
 *
 * For the currently-edited post, uses one of two sources depending on which CAP version is active:
 *   - Legacy CAP (Redux store published by CAP <4.0): reads from `cap/authors` for real-time updates.
 *   - New CAP (no Redux store, CAP 4.0+): reads taxonomy term IDs from
 *     `getEditedPostAttribute('coauthors')` and resolves them to rich author data via the
 *     `authors-by-term-ids` REST endpoint.
 * For Query Loop posts, it uses REST data from `newspack_author_info` (unaffected by CAP version).
 *
 * @param {number}  postId   Post ID to get authors for.
 * @param {string}  postType Post type (default: 'post').
 * @param {boolean} skip     Skip fetching (default: false).
 * @return {Object} `{ authors, isCapAvailable, isLoading, hasCoauthorTermIds }`.
 *                  `isLoading` is true during the new-CAP term-ID resolution window (including the
 *                  pre-flight render before the effect fires); legacy CAP is synchronous.
 *                  `hasCoauthorTermIds` is true when the new-CAP path is in use AND term IDs are
 *                  assigned — consumers should treat `authors.length === 0` while this flag is true
 *                  as "resolution unavailable" (e.g. permission-denied REST) rather than "no coauthors",
 *                  to avoid silently falling back to default state.
 */
export function useCoAuthors( postId, postType = 'post', skip = false ) {
	// Return raw store references from useSelect to avoid creating new objects
	// on every render (which triggers useSelect memoization warnings).
	// The .map() transformations happen in useMemo below.
	const { legacyCapAuthors, coauthorTermIdsKey, restAuthors, isCapAvailable } = useSelect(
		select => {
			if ( skip ) {
				return { legacyCapAuthors: null, coauthorTermIdsKey: null, restAuthors: null, isCapAvailable: false };
			}

			const capStore = select( CAP_STORE );
			const isLegacyCapStoreAvailable = Boolean( capStore && typeof capStore.getAuthors === 'function' );

			const editorStore = select( 'core/editor' );
			const currentPostId = editorStore?.getCurrentPostId?.();
			const isQueryLoopContext = postId && currentPostId && postId !== currentPostId;

			// Query Loop: read from newspack_author_info on the post entity.
			// Unaffected by CAP version.
			if ( isQueryLoopContext && postId ) {
				const { getEntityRecord } = select( coreStore );
				const post = getEntityRecord( 'postType', postType, postId );
				const restData = post?.newspack_author_info || null;
				return {
					legacyCapAuthors: null,
					coauthorTermIdsKey: null,
					restAuthors: restData,
					isCapAvailable: restData ? true : isLegacyCapStoreAvailable,
				};
			}

			// Legacy CAP: read from cap/authors store for real-time updates.
			if ( isLegacyCapStoreAvailable ) {
				const rawCapAuthors = postId ? capStore.getAuthors( postId ) : null;
				return { legacyCapAuthors: rawCapAuthors, coauthorTermIdsKey: null, restAuthors: null, isCapAvailable: true };
			}

			// New CAP: read taxonomy term IDs from the post entity. Serialize to a stable string
			// inside the selector so a fresh array reference from `getEditedPostAttribute` doesn't
			// trigger spurious re-renders. `null` = attribute not loaded; `''` = loaded but empty;
			// CSV (e.g. `'471,488'`) = term IDs assigned.
			const termIds = editorStore?.getEditedPostAttribute?.( 'coauthors' );
			if ( Array.isArray( termIds ) ) {
				const validIds = termIds.map( Number ).filter( Number.isInteger );
				return {
					legacyCapAuthors: null,
					coauthorTermIdsKey: validIds.length === 0 ? '' : validIds.join( ',' ),
					restAuthors: null,
					isCapAvailable: true,
				};
			}

			// No CAP detected (plugin inactive).
			return { legacyCapAuthors: null, coauthorTermIdsKey: null, restAuthors: null, isCapAvailable: false };
		},
		[ postId, postType, skip ]
	);

	// Resolve coauthor term IDs to author data via REST (new CAP path only).
	// Uses a module-level cache + in-flight dedup so concurrent mounts share a single fetch.
	const [ resolvedTermAuthors, setResolvedTermAuthors ] = useState( EMPTY_AUTHORS );
	const [ asyncIsLoading, setAsyncIsLoading ] = useState( false );
	// Tracks the last `coauthorTermIdsKey` the effect has processed. Used to derive a synchronous
	// pre-flight loading state during the render between key change and effect firing — without
	// it, consumers briefly see "term IDs assigned but isLoading false" and may pick wrong defaults.
	const [ processedKey, setProcessedKey ] = useState( null );

	const hasCoauthorTermIds = coauthorTermIdsKey !== null && coauthorTermIdsKey !== '';
	const isPreFlight = hasCoauthorTermIds && processedKey !== coauthorTermIdsKey;
	const isLoading = isPreFlight || asyncIsLoading;

	useEffect( () => {
		if ( skip || coauthorTermIdsKey === null ) {
			// Use the module-level frozen empty array so the identity is stable
			// and setState is a no-op when already empty.
			setResolvedTermAuthors( EMPTY_AUTHORS );
			setAsyncIsLoading( false );
			setProcessedKey( coauthorTermIdsKey );
			return;
		}
		const ids = coauthorTermIdsKey === '' ? [] : coauthorTermIdsKey.split( ',' ).map( Number ).filter( Number.isInteger );
		if ( ids.length === 0 ) {
			setResolvedTermAuthors( EMPTY_AUTHORS );
			setAsyncIsLoading( false );
			setProcessedKey( coauthorTermIdsKey );
			return;
		}

		// If everything is already cached, resolve synchronously without flipping loading state.
		const allCached = ids.every( id => id in coauthorDetailsCache );
		if ( allCached ) {
			const resolved = ids.map( id => coauthorDetailsCache[ id ] ).filter( author => author && typeof author === 'object' );
			setResolvedTermAuthors( resolved.length === 0 ? EMPTY_AUTHORS : resolved );
			setAsyncIsLoading( false );
			setProcessedKey( coauthorTermIdsKey );
			return;
		}

		let cancelled = false;
		setAsyncIsLoading( true );
		fetchCoauthorsByTermIds( ids ).then( () => {
			if ( cancelled ) {
				return;
			}
			const resolved = ids.map( id => coauthorDetailsCache[ id ] ).filter( author => author && typeof author === 'object' );
			setResolvedTermAuthors( resolved.length === 0 ? EMPTY_AUTHORS : resolved );
			setAsyncIsLoading( false );
			setProcessedKey( coauthorTermIdsKey );
		} );

		return () => {
			cancelled = true;
		};
	}, [ coauthorTermIdsKey, skip ] );

	// Map raw store data to our normalized author format.
	const authors = useMemo( () => {
		// Legacy CAP store authors: { id, label, display, value, userType }
		if ( legacyCapAuthors && legacyCapAuthors.length > 0 ) {
			return legacyCapAuthors.map( author => ( {
				id: author.id,
				display_name: author.display || author.value || author.label,
				user_nicename: author.value,
				isGuest: author.userType === 'guest-author',
			} ) );
		}

		// New CAP authors resolved from term IDs.
		// REST shape: { id (string), termId, displayName, userNicename, userType, ... }
		// Coerce `id` to Number at the source so consumers and the in-file dedupe (`other.id === author.id`)
		// see a consistent shape across legacy CAP (numeric id) and new CAP (string id).
		if ( resolvedTermAuthors && resolvedTermAuthors.length > 0 ) {
			return resolvedTermAuthors.map( author => ( {
				id: Number( author.id ),
				termId: author.termId,
				display_name: author.displayName,
				user_nicename: author.userNicename,
				isGuest: author.userType === 'guest-author',
			} ) );
		}

		// REST API authors from newspack_author_info (Query Loop).
		if ( restAuthors && Array.isArray( restAuthors ) && restAuthors.length > 0 ) {
			return restAuthors.map( author => ( {
				id: author.id,
				display_name: author.display_name,
				author_link: author.author_link,
				user_nicename: author.user_nicename || extractNicenameFromLink( author.author_link ),
				...( typeof author.is_guest === 'boolean' ? { isGuest: author.is_guest } : {} ),
				...( author.avatar_urls ? { avatar_urls: author.avatar_urls } : {} ),
			} ) );
		}

		return [];
	}, [ legacyCapAuthors, resolvedTermAuthors, restAuthors ] );

	// Fetch avatar URLs from the CAP REST API for authors that need it.
	// The CAP store strips avatar data via formatAuthorData(), so we need
	// to fetch the raw REST response to get the avatar URL (especially for
	// guest authors whose avatars come from featured images).
	//
	// Fetches per-author (by nicename) instead of per-post so that avatars
	// resolve immediately when a guest author is added — before the post is saved.
	//
	// For currently-edited post authors, isGuest is explicitly true/false.
	// For Query Loop authors, isGuest is undefined (we can't distinguish from
	// REST data alone), so we fetch for all of them and let the consumer
	// (hooks.js) prefer WP user data when available.
	const [ avatarMap, setAvatarMap ] = useState( {} );

	// Build a stable key from author nicenames to control effect re-runs.
	// isGuest !== false: includes guest authors (true) and Query Loop authors (undefined),
	// but excludes known WP users from the currently-edited post (false).
	const authorsNeedingAvatars = authors.filter( author => author.isGuest !== false && author.user_nicename && ! author.avatar_urls );
	const avatarNicenames = authorsNeedingAvatars.map( author => author.user_nicename ).join( ',' );

	useEffect( () => {
		if ( skip || ! isCapAvailable || ! avatarNicenames ) {
			return;
		}

		let cancelled = false;
		Promise.all( authorsNeedingAvatars.map( a => fetchCoauthorAvatar( a.user_nicename ) ) ).then( () => {
			if ( cancelled ) {
				return;
			}
			const map = {};
			authorsNeedingAvatars.forEach( a => {
				if ( guestAvatarCache[ a.user_nicename ] ) {
					map[ a.id ] = guestAvatarCache[ a.user_nicename ];
				}
			} );
			setAvatarMap( map );
		} );

		return () => {
			cancelled = true;
		};
	}, [ skip, isCapAvailable, avatarNicenames ] );

	// Merge avatar URLs into guest authors.
	// Check both the async avatarMap (populated by the effect) and the
	// synchronous guestAvatarCache (populated by previous fetches) so that
	// cached avatars render immediately without waiting for an effect cycle.
	const authorsWithAvatars = authors.map( author => {
		if ( author.isGuest === false || ! author.user_nicename ) {
			return author;
		}
		const urls = avatarMap[ author.id ] || guestAvatarCache[ author.user_nicename ];
		if ( ! urls ) {
			return author;
		}
		return { ...author, avatar_urls: urls };
	} );

	return { authors: authorsWithAvatars, isCapAvailable, isLoading, hasCoauthorTermIds };
}
