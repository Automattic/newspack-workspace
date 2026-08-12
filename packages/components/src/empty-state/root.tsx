/**
 * WordPress dependencies.
 */
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useMemo } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import Grid from '../grid';
import { EmptyStateContext } from './context';
import type { EmptyStateRootProps } from './types';

// `start` and `end` are DOM attributes the Grid stylesheet matches on, not React
// props: they put the stack in columns 2 and 3 above 1054px, which is what centres
// the empty state. Dropping them collapses it into the first column, mistyping either
// spans all four, and both fail silently.
const gridColumn: { start: number; end: number } = { start: 2, end: 4 };

const Root = ( { size = 'default', className, children }: EmptyStateRootProps ) => {
	const context = useMemo( () => ( { size } ), [ size ] );

	return (
		<EmptyStateContext.Provider value={ context }>
			<Grid className={ classnames( 'newspack-empty-state', className ) } columns={ 4 } noMargin>
				<VStack { ...( gridColumn as React.ComponentProps< 'div' > ) } spacing={ 8 }>
					{ children }
				</VStack>
			</Grid>
		</EmptyStateContext.Provider>
	);
};

export default Root;
