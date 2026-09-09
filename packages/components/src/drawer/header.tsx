/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import type { DrawerHeaderProps } from './types';

const Header = ( { className, children }: DrawerHeaderProps ) => (
	<div className={ classnames( 'newspack-drawer__header', className ) }>{ children }</div>
);

export default Header;
