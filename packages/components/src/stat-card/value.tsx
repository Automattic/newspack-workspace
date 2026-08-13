/**
 * WordPress dependencies.
 */
import { _x } from '@wordpress/i18n';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { NULL_GLYPH } from './constants';
import { useStatCardContext } from './context';
import type { StatCardValueProps } from './types';

const Value = ( { value, valueLabel, variant = 'figure', className }: StatCardValueProps ) => {
	// The hero scale is a container query on the root, so a value rendered outside
	// one would size against whatever container it happened to land in.
	useStatCardContext();

	const isNull = null === value || undefined === value;
	const shown = isNull ? NULL_GLYPH : value;
	const spoken = valueLabel ?? ( isNull ? _x( 'Not applicable', 'a statistic with no number to show', 'newspack-plugin' ) : undefined );

	return (
		<span className={ classnames( 'newspack-stat-card__value', 'text' === variant && 'newspack-stat-card__value--text', className ) }>
			{ spoken ? (
				<>
					{ /* Hidden rather than labelled: ARIA prohibits naming a generic element, and `role="img"` makes screen readers announce a graphic. */ }
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
