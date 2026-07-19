/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { button as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata, {
	edit: Edit,
	icon,
	save: () => null,
} );
