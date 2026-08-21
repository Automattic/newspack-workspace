/**
 * WordPress dependencies.
 */
import { createContext, useContext, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import type { StatCardHeadingLevel } from './types';

type StatCardContextValue = {
	heading: StatCardHeadingLevel;
};

const ORPHAN_MESSAGE = 'StatCard subcomponents must be rendered inside StatCard.Root.';

const ORPHAN_FALLBACK: StatCardContextValue = { heading: 3 };

export const StatCardContext = createContext< StatCardContextValue | null >( null );

export const useStatCardContext = (): StatCardContextValue => {
	const context = useContext( StatCardContext );
	const isOrphan = ! context;

	useEffect( () => {
		if ( ! isOrphan ) {
			return;
		}
		// eslint-disable-next-line no-console
		console.warn( ORPHAN_MESSAGE );
	}, [ isOrphan ] );

	if ( ! context ) {
		// A loose slot sizes its figure against the wrong container, which is
		// cosmetic. Nothing above these cards catches an error, so throwing in
		// production would blank an admin screen over it.
		if ( 'production' !== process.env.NODE_ENV ) {
			throw new Error( ORPHAN_MESSAGE );
		}
		return ORPHAN_FALLBACK;
	}

	return context;
};
