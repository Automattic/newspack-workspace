/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import Grid from '../grid';
import type { SplitSectionRootProps } from './types';

export default function Root( { className, children, ...props }: SplitSectionRootProps ) {
	return (
		<Grid columns={ 2 } gutter={ 32 } noMargin className={ classnames( 'newspack-split-section', className ) } { ...props }>
			{ children }
		</Grid>
	);
}
