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
	// Read for the invariant alone: the body is what pins the footer to the bottom.
	useStatCardContext();

	return <div className={ classnames( 'newspack-stat-card__body', className ) }>{ children }</div>;
};

export default Body;
