/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * React context for author data in the editor.
 *
 * This context is used to pass author data to inner blocks in nested mode.
 * It avoids serializing author data into post content by using React context
 * instead of block attributes/context.
 *
 * For PHP render, inner blocks use block context via `usesContext: ['newspack-blocks/author']`
 * which is injected at runtime via `new WP_Block()`.
 */
export const AuthorContext = createContext( null );

/**
 * Expose AuthorContext globally so that blocks from other packages (like newspack-plugin's
 * avatar block) can consume it when nested inside the Author Profile block.
 *
 * This creates a loose coupling without hard package dependencies.
 */
if ( typeof window !== 'undefined' ) {
	window.NewspackAuthorContext = AuthorContext;
}
