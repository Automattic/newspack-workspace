/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType, registerBlockStyle } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import { target as icon } from '../../../packages/icons';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Contribution Meter', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'donations', 'newspack-plugin' ),
		__( 'fundraising', 'newspack-plugin' ),
		__( 'revenue', 'newspack-plugin' ),
		__( 'progress', 'newspack-plugin' ),
		__( 'goal', 'newspack-plugin' ),
		__( 'campaign', 'newspack-plugin' ),
		__( 'contribution', 'newspack-plugin' ),
		__( 'meter', 'newspack-plugin' ),
		__( 'newspack', 'newspack-plugin' ),
	],
	description: __( 'Display progress toward your goal. Works seamlessly with the Donate block.', 'newspack-plugin' ),
	edit: Edit,
	save: () => null, // Server-side rendered block.
};

registerBlockType( { name, ...metadata }, settings );

registerBlockStyle( name, {
	name: 'linear',
	label: __( 'Linear', 'newspack-plugin' ),
	isDefault: true,
} );

registerBlockStyle( name, {
	name: 'circular',
	label: __( 'Circular', 'newspack-plugin' ),
	example: {
		attributes: {
			className: 'is-style-circular',
		},
	},
} );
