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
