/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import { countdown as icon } from '../../../../packages/icons';
import colors from '../../../../packages/colors/colors.module.scss';

export const title = __( 'Article Counter', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'countdown', 'newspack-plugin' ),
		__( 'content gate', 'newspack-plugin' ),
		__( 'metered', 'newspack-plugin' ),
		__( 'paywall', 'newspack-plugin' ),
		__( 'tracking', 'newspack-plugin' ),
	],
	description: __( 'Displays the current free article count.', 'newspack-plugin' ),
	edit: Edit,
	save: () => null,
};
