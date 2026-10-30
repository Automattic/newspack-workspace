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
import type { DrawerContentProps } from './types';

const Content = ( { padding = 6, gap = 'lg', className, children }: DrawerContentProps ) => (
	<Stack
		direction="column"
		gap={ gap }
		className={ classnames( 'newspack-drawer__content', className ) }
		// A custom property, not inline padding, so the stylesheet's seam rules win.
		style={ { '--newspack-drawer-content-padding': `${ padding * 4 }px` } as React.CSSProperties }
	>
		{ children }
	</Stack>
);

export default Content;
