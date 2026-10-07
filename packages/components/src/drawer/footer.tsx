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
import type { DrawerFooterProps } from './types';

const Footer = ( { className, children }: DrawerFooterProps ) => (
	<Stack direction="row" justify="flex-end" wrap="wrap" gap="sm" className={ classnames( 'newspack-drawer__footer', className ) }>
		{ children }
	</Stack>
);

export default Footer;
