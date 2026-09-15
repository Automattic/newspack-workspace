/**
 * WordPress dependencies.
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import type { EmptyStateSize } from './types';

type EmptyStateContextValue = {
	size: EmptyStateSize;
};

export const EmptyStateContext = createContext< EmptyStateContextValue | null >( null );

const FALLBACK_CONTEXT: EmptyStateContextValue = { size: 'default' };

export const useEmptyStateContext = (): EmptyStateContextValue => {
	const context = useContext( EmptyStateContext );
	if ( ! context && process.env.NODE_ENV !== 'production' ) {
		throw new Error( 'EmptyState subcomponents must be rendered inside EmptyState.Root.' );
	}
	return context ?? FALLBACK_CONTEXT;
};

/**
 * Assert placement inside a `Root` without depending on the value.
 *
 * For subcomponents that read nothing from context. Both hooks throw in
 * development so the mistake surfaces while it is cheap, and neither throws in
 * production, because a stray subcomponent should not blank an admin screen
 * over a layout hint.
 */
export const useEmptyStateInvariant = (): void => {
	const context = useContext( EmptyStateContext );
	if ( ! context && process.env.NODE_ENV !== 'production' ) {
		throw new Error( 'EmptyState subcomponents must be rendered inside EmptyState.Root.' );
	}
};
