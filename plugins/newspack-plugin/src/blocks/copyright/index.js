/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { postDate as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';

export const title = __( 'Copyright', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'copyright', 'newspack-plugin' ),
		__( 'date', 'newspack-plugin' ),
		__( 'year', 'newspack-plugin' ),
		__( 'newspack', 'newspack-plugin' ),
	],
	description: __( 'Display a copyright line with the current year and, optionally, the site title.', 'newspack-plugin' ),
	edit: Edit,
};
