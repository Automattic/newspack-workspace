/**
 * WordPress dependencies
 */
import { comment as icon } from '@wordpress/icons';
import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

const { name } = metadata;

const CHILD_LOCK = { move: true, remove: true };

export { metadata, name };

export const settings = {
	title: metadata.title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	description: metadata.description,
	template: [
		[ 'newspack/comments-panel-trigger', { lock: CHILD_LOCK } ],
		[ 'newspack/comments-panel-content', { lock: CHILD_LOCK } ],
	],
	edit: Edit,
	save: () => <InnerBlocks.Content />,
};
