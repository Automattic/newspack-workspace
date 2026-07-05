/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';

/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import { listView } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import edit from './edit';

/**
 * Style dependencies - will load in editor
 */
import './editor.scss';
import './view.scss';
import metadata from './block.json';
const { name, attributes, apiVersion, category } = metadata;

// Name must be exported separately.
export { name };

export const title = __( 'Author List', 'newspack-blocks' );

// Add Newspack author custom fields to the block attributes.
const authorCustomFields: { name: string }[] =
	( window.newspack_blocks_data as { author_custom_fields?: { name: string }[] } )?.author_custom_fields || [];
authorCustomFields.forEach( field => {
	( attributes as Record< string, unknown > )[ `show${ field.name }` ] = {
		type: 'boolean',
		default: true,
	};
} );

export const settings = {
	apiVersion,
	title,
	icon: {
		src: listView,
		foreground: colors[ 'primary-400' ],
	},
	attributes,
	category,
	keywords: [ __( 'author', 'newspack-blocks' ), __( 'profile', 'newspack-blocks' ) ],
	description: __( 'Display a list of author profile cards.', 'newspack-blocks' ),
	styles: [
		{ name: 'default', label: _x( 'Default', 'block style', 'newspack-blocks' ), isDefault: true },
		{ name: 'center', label: _x( 'Centered', 'block style', 'newspack-blocks' ) },
	],
	supports: {
		html: false,
		default: '',
	},
	edit,
	save: () => null, // to use view.php
};
