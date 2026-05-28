/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { share } from '@wordpress/icons';

import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import './style.scss';
import './editor.scss';
import edit from './edit';
import metadata from './block.json';
import colors from '../../../packages/colors/colors.module.scss';

const { name } = metadata;

export { name };

export const settings = {
	...metadata,
	title: __( 'Author Social Links', 'newspack-plugin' ),
	icon: {
		src: share,
		foreground: colors[ 'primary-400' ],
	},
	edit,
	save: () => <InnerBlocks.Content />,
};
