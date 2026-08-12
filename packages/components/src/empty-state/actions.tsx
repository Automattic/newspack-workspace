/**
 * WordPress dependencies.
 */
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useEmptyStateContext } from './context';
import type { EmptyStateActionsProps } from './types';

const Actions = ( { className, children }: EmptyStateActionsProps ) => {
	// Read for the invariant alone: actions outside a Root would lose the spine.
	useEmptyStateContext();

	return (
		<HStack alignment="center" className={ classnames( 'newspack-empty-state__actions', className ) }>
			{ children }
		</HStack>
	);
};

export default Actions;
