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
import type { DrawerHeaderProps } from './types';

const Header = ( { className, children }: DrawerHeaderProps ) => (
	<Stack direction="row" align="center" gap="sm" className={ classnames( 'newspack-drawer__header', className ) }>
		{ children }
	</Stack>
);

export default Header;
