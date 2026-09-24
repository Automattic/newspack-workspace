/**
 * WordPress dependencies.
 */
import { forwardRef, useEffect } from '@wordpress/element';
import { Stack, VisuallyHidden } from '@wordpress/ui';

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

const Value = forwardRef< HTMLSpanElement, StatCardValueProps >( function Value(
	{ value, valueLabel, variant = 'figure', suffix, className, ...props },
	ref
) {
	const { labels } = useStatCardContext();

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV || variants.includes( variant ) ) {
			return;
		}
		// eslint-disable-next-line no-console
		console.warn( `StatCard.Value: unknown variant "${ variant }", falling back to figure. Use one of ${ variants.join( ', ' ) }.` );
	}, [ variant ] );

	// A blank string is a missing figure too, and an empty hero would read as one
	// that never loaded. A zero is a figure, so it stays out of this.
	const isNull = null === value || undefined === value || ( 'string' === typeof value && '' === value.trim() );
	const shown = isNull ? STAT_CARD_NULL_GLYPH : value;
	// Trimmed, and `||` not `??`: a blank label is a missing one, and the glyph must never be left unnamed.
	const spoken = valueLabel?.trim() || ( isNull ? labels.notApplicable : undefined );

	const figure = (
		<span
			ref={ ref }
			className={ classnames( 'newspack-stat-card__value', 'text' === variant && 'newspack-stat-card__value--text', className ) }
			{ ...props }
		>
			{ spoken ? (
				<>
					{ /* Hidden, not labelled: ARIA forbids naming a generic element, and `role="img"` announces a graphic. */ }
					<span aria-hidden="true">{ shown }</span>
					<VisuallyHidden render={ <span /> }>{ spoken }</VisuallyHidden>
				</>
			) : (
				shown
			) }
		</span>
	);

	if ( ! suffix ) {
		return figure;
	}

	return (
		<Stack direction="row" align="baseline" gap="sm" className="newspack-stat-card__figure">
			{ figure }
			{ suffix }
		</Stack>
	);
} );

export default Value;
