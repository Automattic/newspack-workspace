/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { Icon, customLink } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import edit from './edit';
import save from './save';
import metadata from './block.json';

const { name, title } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: <Icon icon={ customLink } />,
	edit,
	save,
};

export default () => {
	registerBlockType( { name, ...metadata }, settings );
};
