/**
 * WordPress dependencies.
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import type { StatCardHeadingLevel } from './types';

type StatCardContextValue = {
	heading: StatCardHeadingLevel;
};

export const StatCardContext = createContext< StatCardContextValue | null >( null );

export const useStatCardContext = (): StatCardContextValue => {
	const context = useContext( StatCardContext );
	if ( ! context ) {
		throw new Error( 'StatCard subcomponents must be rendered inside StatCard.Root.' );
	}
	return context;
};
