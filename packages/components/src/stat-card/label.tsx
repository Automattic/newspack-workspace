/**
 * WordPress dependencies.
 */
import { useEffect } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardHeadingLevel, StatCardLabelProps } from './types';

const headings = {
	2: 'h2',
	3: 'h3',
	4: 'h4',
	5: 'h5',
	6: 'h6',
} as const;

const Label = ( { suffix, heading, className, children }: StatCardLabelProps ) => {
	const context = useStatCardContext();
	const level = ( heading ?? context.heading ) as StatCardHeadingLevel;
	// Consumers are largely untyped JS, where an out-of-range level would
	// otherwise render an <h7>, which carries no heading role at all.
	const Heading = headings[ level ] || headings[ 3 ];

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV || headings[ level ] ) {
			return;
		}
		// eslint-disable-next-line no-console
		console.warn(
			`StatCard: unknown heading level "${ level }", falling back to 3. Set \`heading\` on StatCard.Root or StatCard.Label to one of ${ Object.keys(
				headings
			).join( ', ' ) }.`
		);
	}, [ level ] );

	return (
		<div className={ classnames( 'newspack-stat-card__label', className ) }>
			<Heading className="newspack-stat-card__label-text">{ children }</Heading>
			{ suffix }
		</div>
	);
};

export default Label;
