/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * Value carried by the shared AuthorContext: author profile data provided by a
 * parent Author Profile block, or null outside one.
 */
type SharedAuthorContextValue = Record< string, unknown > | null;

/**
 * Window global key used by newspack-blocks to expose the shared AuthorContext.
 */
export const AUTHOR_CONTEXT_KEY = 'NewspackAuthorContext';

/**
 * Fallback context that always returns null.
 * Used when the shared AuthorContext from newspack-blocks is not available.
 */
const FallbackAuthorContext = createContext< SharedAuthorContextValue >( null );

/**
 * Get the shared AuthorContext from newspack-blocks if available, otherwise use fallback.
 * Resolved at render time (not module load) so it works regardless of script load order.
 *
 * @return React context object.
 */
export const getSharedAuthorContext = () =>
	typeof window !== 'undefined' && window[ AUTHOR_CONTEXT_KEY ] ? window[ AUTHOR_CONTEXT_KEY ] : FallbackAuthorContext;
