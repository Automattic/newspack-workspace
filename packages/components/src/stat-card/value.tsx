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
import { STAT_CARD_NULL_GLYPH } from './constants';
import { useStatCardContext } from './context';
import type { StatCardValueProps, StatCardValueVariant } from './types';

const variants: StatCardValueVariant[] = [ 'figure', 'text' ];

const Value = ( { value, valueLabel, variant = 'figure', className }: StatCardValueProps ) => {
	// The hero scale is a container query on the root, so a value rendered loose
	// would size against whichever container it landed in.
	useStatCardContext();

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV || variants.includes( variant ) ) {
			return;
		}
		// eslint-disable-next-line no-console
		console.warn( `StatCard.Value: unknown variant "${ variant }", falling back to figure. Use one of ${ variants.join( ', ' ) }.` );
	}, [ variant ] );

	const isNull = null === value || undefined === value;
	const shown = isNull ? STAT_CARD_NULL_GLYPH : value;
	// `||` not `??`: an empty label is a missing one, and the glyph must never be left unnamed.
	const spoken = valueLabel || ( isNull ? _x( 'Not applicable', 'a statistic with no number to show', 'newspack-plugin' ) : undefined );

	return (
		<span className={ classnames( 'newspack-stat-card__value', 'text' === variant && 'newspack-stat-card__value--text', className ) }>
			{ spoken ? (
				<>
					{ /* Hidden, not labelled: ARIA forbids naming a generic element, and `role="img"` announces a graphic. */ }
					<span aria-hidden="true">{ shown }</span>
					<span className="screen-reader-text">{ spoken }</span>
				</>
			) : (
				shown
			) }
		</span>
	);
};

export default Value;
