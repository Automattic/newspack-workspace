/**
 * WordPress dependencies.
 */
import { Stack } from '@wordpress/ui';

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

	return (
		<Stack direction="column" gap="xs" className={ classnames( 'newspack-stat-card__body', className ) }>
			{ children }
		</Stack>
	);
};

export default Body;
