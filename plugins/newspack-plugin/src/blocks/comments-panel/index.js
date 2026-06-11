/**
 * WordPress dependencies
 */
import { comment as icon } from '@wordpress/icons';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

const { name } = metadata;

export { metadata, name };

export const settings = {
	title: metadata.title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	description: metadata.description,
	edit: Edit,
	save: () => (
		<div { ...useBlockProps.save() }>
			<InnerBlocks.Content />
		</div>
	),
};
