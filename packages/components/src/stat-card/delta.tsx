/**
 * WordPress dependencies.
 */
import { useEffect } from '@wordpress/element';
import { _x } from '@wordpress/i18n';
import { VisuallyHidden } from '@wordpress/ui';

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

const Delta = ( { direction, tone = 'neutral', directionLabel, label, className, children }: StatCardDeltaProps ) => {
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
	// Trimmed, and `||` not `??`: a blank label is a missing one. The fallback
	// is keyed on the arrow's own direction, so an unrecognised one goes
	// unspoken rather than announced as its opposite; words the caller wrote
	// are honoured either way.
	const named = label?.trim();
	const spoken = directionLabel?.trim() || directions[ direction ];

	const classes = classnames(
		'newspack-stat-card__delta',
		'neutral' !== tone && tones.includes( tone ) && `newspack-stat-card__delta--${ tone }`,
		className
	);

	// `label` names the whole delta, so the change it restates is hidden with the arrow.
	if ( named ) {
		return (
			<span className={ classes }>
				<span aria-hidden="true">
					{ glyph }
					{ children }
				</span>
				<VisuallyHidden render={ <span /> }>{ named }</VisuallyHidden>
			</span>
		);
	}

	return (
		<span className={ classes }>
			{ /* The arrow is hidden and its meaning given as text, since a bare glyph announces inconsistently. */ }
			{ glyph && <span aria-hidden="true">{ glyph }</span> }
			{ spoken && <VisuallyHidden render={ <span /> }>{ spoken }</VisuallyHidden> }
			{ children }
		</span>
	);
};

export default Delta;
