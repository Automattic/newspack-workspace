/**
 * WordPress dependencies
 */
import { registerBlockType, type BlockConfiguration } from '@wordpress/blocks';
import { Icon, customLink } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import edit, { type ShareBlockEditAttributes } from './edit';
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
	registerBlockType< ShareBlockEditAttributes >( { ...metadata, name } as BlockConfiguration< ShareBlockEditAttributes >, settings );
};
