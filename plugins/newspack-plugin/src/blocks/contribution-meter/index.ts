/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType, registerBlockStyle } from '@wordpress/blocks';
import type { BlockConfiguration, BlockStyle } from '@wordpress/blocks';

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

// Widen to the generic module shape before asserting the WP types, mirroring
// the registration barrel (src/blocks/index.ts).
const blockMetadata: Record< string, unknown > = { ...metadata };
const blockSettings: Record< string, unknown > = settings;
registerBlockType( blockMetadata as BlockConfiguration, blockSettings as Partial< BlockConfiguration > );

registerBlockStyle( name, {
	name: 'linear',
	label: __( 'Linear', 'newspack-plugin' ),
	isDefault: true,
} );

// `example` is supported by the block styles API but missing from BlockStyle.
registerBlockStyle( name, {
	name: 'circular',
	label: __( 'Circular', 'newspack-plugin' ),
	example: {
		attributes: {
			className: 'is-style-circular',
		},
	},
} as BlockStyle );
