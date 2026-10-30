/**
 * WordPress dependencies.
 */
import { close } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import Button from '../button';
import { useDrawerContext } from './context';
import type { DrawerCloseIconProps } from './types';

const CloseIcon = ( { label, icon = close, className }: DrawerCloseIconProps ) => {
	const { requestClose, title } = useDrawerContext();
	const defaultLabel = title?.text
		? // translators: %s: the drawer's title.
		  sprintf( __( 'Close %s', 'newspack-plugin' ), title.text )
		: __( 'Close', 'newspack-plugin' );

	return (
		<Button
			className={ classnames( 'newspack-drawer__dismiss', className ) }
			icon={ icon }
			size="small"
			label={ label || defaultLabel }
			onClick={ requestClose }
		/>
	);
};

export default CloseIcon;
