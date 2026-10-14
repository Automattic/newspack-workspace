/**
 * WordPress dependencies
 */
import { postComments as icon } from '@wordpress/icons';
import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../../packages/colors/colors.module.scss';

const { name } = metadata;

export { metadata, name };

export const settings = {
	title: metadata.title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	edit: Edit,
	// Dynamic block — PHP renders the wrapper, so save only the inner blocks.
	save: () => <InnerBlocks.Content />,
};
