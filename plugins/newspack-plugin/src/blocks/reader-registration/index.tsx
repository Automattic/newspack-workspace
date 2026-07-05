/**
 * WordPress dependencies
 */
import { useInnerBlocksProps, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';
import { readerRegistration as icon } from '../../../packages/icons';
import colors from '../../../packages/colors/colors.module.scss';

const { name } = metadata;

export { metadata, name };

export const settings = {
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	edit,
	save: () => <div { ...useInnerBlocksProps.save( useBlockProps.save() ) } />,
};
