/**
 * WordPress dependencies
 */
import { pullquote as icon } from '@wordpress/icons';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';

const { name } = metadata;

export { metadata, name };

export const settings = {
	icon,
	edit,
};

export default () => {
	registerBlockType( { name, ...metadata }, settings );
};
