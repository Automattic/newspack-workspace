/**
 * WordPress dependencies
 */
import { search as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

const { name } = metadata;

export { metadata, name };

export const settings = {
	title: metadata.title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	description: metadata.description,
	edit,
	save: () => null,
};
