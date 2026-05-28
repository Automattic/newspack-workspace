/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { pencil } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { SelfServeListingsEditor } from './edit';
import metadata from './block.json';
const { attributes, category, name } = metadata;

export const registerSelfServeListingsBlock = () => {
	registerBlockType( name, {
		apiVersion: 3,
		title: __( 'Listings: Self-Serve Form', 'newspack-listings' ),
		icon: {
			src: pencil,
			foreground: '#003da5',
		},
		category,
		keywords: [
			__( 'list', 'newspack-listings' ),
			__( 'listings', 'newspack-listings' ),
			__( 'self', 'newspack-listings' ),
			__( 'serve', 'newspack-listings' ),
		],

		attributes,

		edit: SelfServeListingsEditor,
		save: () => null, // uses view.php
	} );
};
