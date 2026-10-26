/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * External dependencies
 */
import { aiText } from 'newspack-icons';
import colors from 'newspack-colors';

/**
 * Internal dependencies
 */
import edit from './edit';
import save from './save';
import metadata from './block.json';
import './editor.scss';

/**
 * Register the Roundup block.
 */
registerBlockType( metadata.name, {
	...metadata,
	edit,
	save,
	icon: {
		src: aiText,
		foreground: colors[ 'primary-400' ],
	},
} );
