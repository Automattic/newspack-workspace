/**
 * WordPress dependencies.
 */
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useEmptyStateContext } from './context';
import type { EmptyStateActionsProps } from './types';

const Actions = ( { orientation = 'row', spacing = 2, className, children }: EmptyStateActionsProps ) => {
	// Read for the invariant alone: actions outside a Root would lose the spine.
	useEmptyStateContext();

	const isColumn = orientation === 'column';
	const Stack = isColumn ? VStack : HStack;

	// A row wraps rather than overflowing: the empty state sits in half the grid above
	// 1054px, which is the narrow case for two actions side by side.
	return (
		<Stack alignment="center" spacing={ spacing } wrap={ ! isColumn } className={ classnames( 'newspack-empty-state__actions', className ) }>
			{ children }
		</Stack>
	);
};

export default Actions;
