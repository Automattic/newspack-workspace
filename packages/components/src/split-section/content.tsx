/**
 * WordPress dependencies
 */
import { Stack } from '@wordpress/ui';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import type { SplitSectionContentProps } from './types';

export default function Content( { className, children, ...props }: SplitSectionContentProps ) {
	return (
		<Stack { ...props } direction="column" gap="xl" className={ classnames( 'newspack-split-section__content', className ) }>
			{ children }
		</Stack>
	);
}
