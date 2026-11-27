/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { button as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../../packages/colors/colors.module.scss';

export const title = __( 'Overlay Button', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	edit: Edit,
	save: () => null,
};
