/**
 * WordPress dependencies.
 */
import { useEffect } from '@wordpress/element';
import { _x } from '@wordpress/i18n';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardDeltaDirection, StatCardDeltaProps, StatCardDeltaTone } from './types';

const glyphs: Record< StatCardDeltaDirection, string > = {
	up: '↑',
	down: '↓',
};

const tones: StatCardDeltaTone[] = [ 'positive', 'negative', 'neutral' ];

const Delta = ( { direction, tone = 'neutral', directionLabel, className, children }: StatCardDeltaProps ) => {
	useStatCardContext();

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV ) {
			return;
		}
		if ( ! glyphs[ direction ] ) {
			// eslint-disable-next-line no-console
			console.warn( `StatCard.Delta: unknown direction "${ direction }". Use one of ${ Object.keys( glyphs ).join( ', ' ) }.` );
		}
		if ( ! tones.includes( tone ) ) {
			// eslint-disable-next-line no-console
			console.warn( `StatCard.Delta: unknown tone "${ tone }", falling back to neutral. Use one of ${ tones.join( ', ' ) }.` );
		}
	}, [ direction, tone ] );

	const directions: Record< StatCardDeltaDirection, string > = {
		up: _x( 'Up', 'a statistic that has increased', 'newspack-plugin' ),
		down: _x( 'Down', 'a statistic that has decreased', 'newspack-plugin' ),
	};

	const glyph = glyphs[ direction ];
	// Keyed on the same direction as the arrow, so a direction with no arrow is
	// left unspoken rather than announced as the opposite one. `||` not `??`:
	// an empty label is a missing one.
	const spoken = directionLabel || directions[ direction ];

	return (
		<span
			className={ classnames(
				'newspack-stat-card__delta',
				'neutral' !== tone && tones.includes( tone ) && `newspack-stat-card__delta--${ tone }`,
				className
			) }
		>
			{ /* The arrow is hidden and its meaning given as text, since a bare glyph announces inconsistently. */ }
			{ glyph && <span aria-hidden="true">{ glyph }</span> }
			{ spoken && <span className="screen-reader-text">{ spoken }</span> }
			{ children }
		</span>
	);
};

export default Delta;
