/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import { corrections as icon } from '../../../packages/icons';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Corrections', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [ __( 'clarifications', 'newspack-plugin' ), __( 'updates', 'newspack-plugin' ) ],
	description: __( 'Display all corrections and clarifications made to a post.', 'newspack-plugin' ),
	usesContext: [ 'postId' ],
	edit: Edit,
};
