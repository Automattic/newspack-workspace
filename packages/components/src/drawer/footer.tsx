/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import type { DrawerFooterProps } from './types';

const Footer = ( { className, children }: DrawerFooterProps ) => (
	<div className={ classnames( 'newspack-drawer__footer', className ) }>{ children }</div>
);

export default Footer;
