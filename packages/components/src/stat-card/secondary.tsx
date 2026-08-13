/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardSecondaryProps } from './types';

const Secondary = ( { className, children }: StatCardSecondaryProps ) => {
	useStatCardContext();

	return <div className={ classnames( 'newspack-stat-card__secondary', className ) }>{ children }</div>;
};

export default Secondary;
