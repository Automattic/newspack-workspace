/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { menu as icon } from '@wordpress/icons';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Overlay Menu', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'menu', 'newspack-plugin' ),
		__( 'overlay', 'newspack-plugin' ),
		__( 'drawer', 'newspack-plugin' ),
		__( 'navigation', 'newspack-plugin' ),
		__( 'hamburger', 'newspack-plugin' ),
	],
	description: __( 'A trigger button that opens an overlay drawer panel with customizable content.', 'newspack-plugin' ),
	edit: Edit,
	save: () => (
		<div { ...useBlockProps.save() }>
			<InnerBlocks.Content />
		</div>
	),
};
