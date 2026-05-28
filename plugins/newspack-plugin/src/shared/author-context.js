/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * Window global key used by newspack-blocks to expose the shared AuthorContext.
 *
 * @type {string}
 */
export const AUTHOR_CONTEXT_KEY = 'NewspackAuthorContext';

/**
 * Fallback context that always returns null.
 * Used when the shared AuthorContext from newspack-blocks is not available.
 */
const FallbackAuthorContext = createContext( null );

/**
 * Get the shared AuthorContext from newspack-blocks if available, otherwise use fallback.
 * Resolved at render time (not module load) so it works regardless of script load order.
 *
 * @return {Object} React context object.
 */
export const getSharedAuthorContext = () =>
	typeof window !== 'undefined' && window[ AUTHOR_CONTEXT_KEY ] ? window[ AUTHOR_CONTEXT_KEY ] : FallbackAuthorContext;
