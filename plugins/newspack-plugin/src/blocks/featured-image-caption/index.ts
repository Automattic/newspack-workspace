/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { caption as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import { Edit } from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Featured Image Caption', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'caption', 'newspack-plugin' ),
		__( 'featured image', 'newspack-plugin' ),
		__( 'credit', 'newspack-plugin' ),
		__( 'newspack', 'newspack-plugin' ),
	],
	description: __( 'Display the featured image caption and credit.', 'newspack-plugin' ),
	edit: Edit,
};
