/**
 * WordPress dependencies.
 */
import { forwardRef, useEffect } from '@wordpress/element';
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

const Delta = forwardRef< HTMLSpanElement, StatCardDeltaProps >( function Delta(
	{ direction, tone = 'neutral', directionLabel, label, className, children, ...props },
	ref
) {
	const { labels } = useStatCardContext();

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
		up: labels.up,
		down: labels.down,
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

	return (
		<span ref={ ref } className={ classes } { ...props }>
			{ named ? (
				<>
					{ /* `label` names the whole delta, so the change it restates is hidden with the arrow. */ }
					<span aria-hidden="true">
						{ glyph }
						{ children }
					</span>
					<VisuallyHidden render={ <span /> }>{ named }</VisuallyHidden>
				</>
			) : (
				<>
					{ /* The arrow is hidden and its meaning given as text, since a bare glyph announces inconsistently. */ }
					{ glyph && <span aria-hidden="true">{ glyph }</span> }
					{ /* The trailing space separates the direction from the change in the raw text, not only in the layout. */ }
					{ spoken && <VisuallyHidden render={ <span /> }>{ `${ spoken } ` }</VisuallyHidden> }
					{ children }
				</>
			) }
		</span>
	);
} );

export default Delta;
