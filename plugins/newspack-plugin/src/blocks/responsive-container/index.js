/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { resizeCornerNE as icon } from '@wordpress/icons';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Responsive Container', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'responsive', 'newspack-plugin' ),
		__( 'adaptive', 'newspack-plugin' ),
		__( 'header', 'newspack-plugin' ),
		__( 'footer', 'newspack-plugin' ),
		__( 'mobile', 'newspack-plugin' ),
		__( 'desktop', 'newspack-plugin' ),
	],
	description: __(
		'A container that shows one set of blocks on desktop and another on mobile, swapping automatically at a breakpoint. Ideal for responsive headers and footers.',
		'newspack-plugin'
	),
	edit: Edit,
	save: () => (
		<div { ...useBlockProps.save( { className: 'newspack-responsive-container' } ) }>
			<InnerBlocks.Content />
		</div>
	),
};
