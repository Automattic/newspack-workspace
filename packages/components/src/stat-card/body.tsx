/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardBodyProps } from './types';

const Body = ( { className, children }: StatCardBodyProps ) => {
	useStatCardContext();

	return <div className={ classnames( 'newspack-stat-card__body', className ) }>{ children }</div>;
};

export default Body;
