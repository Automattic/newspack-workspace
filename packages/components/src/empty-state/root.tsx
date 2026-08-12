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

// `start` and `end` are attributes the Grid's own stylesheet matches on, not React
// props. VStack forwards them to the DOM but does not type them.
const gridColumn = { start: 2, end: 4 } as React.ComponentProps< 'div' >;

const Root = ( { size = 'default', className, children }: EmptyStateRootProps ) => {
	const context = useMemo( () => ( { size } ), [ size ] );

	return (
		<EmptyStateContext.Provider value={ context }>
			<Grid className={ classnames( 'newspack-empty-state', className ) } columns={ 4 } noMargin>
				<VStack { ...gridColumn } spacing={ 8 }>
					{ children }
				</VStack>
			</Grid>
		</EmptyStateContext.Provider>
	);
};

export default Root;
