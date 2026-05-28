/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';

/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { postAuthor } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import edit from './edit';
import variations from './variations';

/**
 * Style dependencies - will load in editor
 */
import './editor.scss';
import './view.scss';
import metadata from './block.json';
const { name, attributes, apiVersion, category } = metadata;

// Name must be exported separately.
export { name };

export const title = __( 'Author Profile', 'newspack-blocks' );

// Add Newspack author custom fields to the block attributes.
const authorCustomFields = window.newspack_blocks_data?.author_custom_fields || [];
authorCustomFields.forEach( field => {
	attributes[ `show${ field.name }` ] = {
		type: 'boolean',
		default: true,
	};
} );

export const settings = {
	apiVersion,
	title,
	icon: {
		src: postAuthor,
		foreground: colors[ 'primary-400' ],
	},
	attributes,
	category,
	keywords: [ __( 'author', 'newspack-blocks' ), __( 'profile', 'newspack-blocks' ) ],
	description: __( 'Display an author profile card.', 'newspack-blocks' ),
	supports: {
		html: false,
		default: '',
	},
	edit,
	variations,
	// Save inner blocks for nested mode (layoutVersion 2).
	// For flat mode (layoutVersion 1), return null to use server-side rendering only.
	save: props => {
		if ( props.attributes.layoutVersion === 2 ) {
			return <InnerBlocks.Content />;
		}
		return null;
	},
};
