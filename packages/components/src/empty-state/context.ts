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

export const useEmptyStateContext = (): EmptyStateContextValue => {
	const context = useContext( EmptyStateContext );
	if ( ! context ) {
		throw new Error( 'EmptyState subcomponents must be rendered inside EmptyState.Root.' );
	}
	return context;
};

/**
 * Assert placement inside a `Root` without depending on the value.
 *
 * For subcomponents that read nothing from context. Development throws so the
 * mistake surfaces while it is cheap; production returns, because a stray
 * subcomponent should not blank an admin screen over a layout hint.
 */
export const useEmptyStateInvariant = (): void => {
	const context = useContext( EmptyStateContext );
	if ( ! context && process.env.NODE_ENV !== 'production' ) {
		throw new Error( 'EmptyState subcomponents must be rendered inside EmptyState.Root.' );
	}
};
