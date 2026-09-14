/**
 * WordPress dependencies.
 */
import { createContext, useContext, useEffect } from '@wordpress/element';
import { _x } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import type { StatCardHeadingLevel, StatCardLabels } from './types';

type StatCardContextValue = {
	heading: StatCardHeadingLevel;
	labels: StatCardLabels;
};

const ORPHAN_MESSAGE = 'StatCard subcomponents must be rendered inside StatCard.Root.';

// Resolved per call rather than once at module load, so a locale switched after
// the bundle evaluates still reaches the defaults. A blank override is a missing
// one: the glyph and the arrow must never be left announcing nothing.
export const resolveStatCardLabels = ( labels?: Partial< StatCardLabels > ): StatCardLabels => ( {
	notApplicable: labels?.notApplicable?.trim() || _x( 'Not applicable', 'a statistic with no number to show', 'newspack-plugin' ),
	up: labels?.up?.trim() || _x( 'Up', 'a statistic that has increased', 'newspack-plugin' ),
	down: labels?.down?.trim() || _x( 'Down', 'a statistic that has decreased', 'newspack-plugin' ),
} );

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
		return { heading: 3, labels: resolveStatCardLabels() };
	}

	return context;
};
