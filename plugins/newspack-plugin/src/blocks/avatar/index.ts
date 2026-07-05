/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import { postAvatar as icon } from '../../../packages/icons';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Avatar', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'author', 'newspack-plugin' ),
		__( 'user', 'newspack-plugin' ),
		__( 'profile', 'newspack-plugin' ),
		__( 'newspack', 'newspack-plugin' ),
	],
	description: __( 'Display post author avatar.', 'newspack-plugin' ),
	usesContext: [ 'postId', 'postType' ],
	edit: Edit,
};
