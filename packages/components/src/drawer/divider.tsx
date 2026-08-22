/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import type { DrawerDividerProps } from './types';

const Divider = ( { className }: DrawerDividerProps ) => <hr className={ classnames( 'newspack-drawer__divider', className ) } />;

export default Divider;
