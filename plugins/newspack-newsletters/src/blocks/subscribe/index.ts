/**
 * WordPress dependencies
 */
import { inbox as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';

const { name } = metadata;

export { metadata, name };

export const settings = {
	icon: {
		src: icon,
		foreground: '#406ebc',
	},
	edit,
};
