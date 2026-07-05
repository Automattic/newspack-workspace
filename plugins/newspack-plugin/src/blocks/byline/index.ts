/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { postAuthor as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Byline', 'newspack-plugin' );

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
		__( 'byline', 'newspack-plugin' ),
		__( 'writer', 'newspack-plugin' ),
		__( 'newspack', 'newspack-plugin' ),
	],
	description: __( 'Display post author(s) with support for custom bylines and CoAuthors Plus.', 'newspack-plugin' ),
	usesContext: [ 'postId', 'postType' ],
	edit: Edit,
};
