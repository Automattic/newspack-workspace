/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardLabelProps } from './types';

const Label = ( { suffix, heading, className, children }: StatCardLabelProps ) => {
	const context = useStatCardContext();
	const Heading = `h${ heading ?? context.heading }` as 'h2';

	return (
		<div className={ classnames( 'newspack-stat-card__label', className ) }>
			<Heading className="newspack-stat-card__label-text">{ children }</Heading>
			{ suffix }
		</div>
	);
};

export default Label;
