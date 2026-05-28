/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { currencyDollar } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { PriceEditor } from './edit';
import metadata from './block.json';
const { attributes, category, name } = metadata;

export const registerPriceBlock = () => {
	registerBlockType( name, {
		apiVersion: 3,
		title: __( 'Price', 'newspack-listings' ),
		icon: {
			src: currencyDollar,
			foreground: '#003da5',
		},
		category,
		keywords: [
			__( 'curated', 'newspack-listings' ),
			__( 'list', 'newspack-listings' ),
			__( 'lists', 'newspack-listings' ),
			__( 'listings', 'newspack-listings' ),
			__( 'latest', 'newspack-listings' ),
			__( 'price', 'newspack-listings' ),
		],

		attributes,

		edit: PriceEditor,
		save: () => null, // uses view.php
	} );
};
