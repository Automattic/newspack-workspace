/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import { collections as icon } from '../../../packages/icons';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Collections', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'collections', 'newspack-plugin' ),
		__( 'issues', 'newspack-plugin' ),
		__( 'magazine', 'newspack-plugin' ),
		__( 'publications', 'newspack-plugin' ),
		__( 'content', 'newspack-plugin' ),
		__( 'loop', 'newspack-plugin' ),
		__( 'query', 'newspack-plugin' ),
		__( 'latest', 'newspack-plugin' ),
		__( 'newspack', 'newspack-plugin' ),
	],
	description: __(
		'An advanced block that allows displaying collections based on different parameters and visual configurations.',
		'newspack-plugin'
	),
	edit: Edit,
	save: () => null, // Server-side rendered block.
};
