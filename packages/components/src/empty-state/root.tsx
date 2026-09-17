/**
 * WordPress dependencies.
 */
import { useMemo } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

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
import './style.scss';

// Positions the stack in columns 2 to 4. `data-` attributes because that is the form
// grid/style.scss standardised on, after prop filtering was found to drop a bare `end`
// before it reached the DOM. That file carries the full note.
const gridColumn = { 'data-start': '2', 'data-end': '4' };

const Root = ( { size = 'default', className, children }: EmptyStateRootProps ) => {
	const context = useMemo( () => ( { size } ), [ size ] );

	return (
		<EmptyStateContext.Provider value={ context }>
			<Grid className={ classnames( 'newspack-empty-state', className ) } columns={ 4 } noMargin>
				<Stack className="newspack-empty-state__stack" direction="column" gap="2xl" { ...gridColumn }>
					{ children }
				</Stack>
			</Grid>
		</EmptyStateContext.Provider>
	);
};

export default Root;
