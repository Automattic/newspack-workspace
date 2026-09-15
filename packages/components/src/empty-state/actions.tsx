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
import { useEmptyStateInvariant } from './context';
import type { EmptyStateActionsProps } from './types';

const Actions = ( { orientation = 'row', gap = 'sm', className, children }: EmptyStateActionsProps ) => {
	useEmptyStateInvariant();

	const isColumn = orientation === 'column';

	// Rows wrap: the empty state only gets half the grid above 1054px.
	return (
		<Stack
			direction={ isColumn ? 'column' : 'row' }
			align="center"
			justify="center"
			gap={ gap }
			wrap={ isColumn ? undefined : 'wrap' }
			className={ classnames( 'newspack-empty-state__actions', className ) }
		>
			{ children }
		</Stack>
	);
};

export default Actions;
