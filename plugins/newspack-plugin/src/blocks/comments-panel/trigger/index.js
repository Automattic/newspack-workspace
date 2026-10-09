/**
 * WordPress dependencies
 */
import { button as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../../packages/colors/colors.module.scss';

const { name } = metadata;

export { metadata, name };

export const settings = {
	title: metadata.title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	edit: Edit,
	save: () => null,
};
