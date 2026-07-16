/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockEditProps } from '@wordpress/blocks';
import { currencyDollar } from '@wordpress/icons';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { PriceEditor, type PriceAttributes } from './edit';
import metadata from './block.json';
const { attributes, category, name } = metadata;

export const registerPriceBlock = () => {
	// `registerBlockType`'s generic `Attributes` gets inferred from this `edit`
	// component's own prop type, but the block.json-derived `attributes` above
	// types each field as a generic `BlockAttribute` schema (not the real
	// runtime value shape) - wrap `PriceEditor` so the boundary-facing
	// component matches the default `Attributes` generic, narrowing back to
	// `PriceAttributes` only where `PriceEditor` needs it. Matches the pattern
	// used for `IframeEdit` in newspack-blocks' `blocks/iframe/index.tsx`.
	const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = ( { attributes: editAttributes, isSelected, setAttributes } ) => (
		<PriceEditor attributes={ editAttributes as PriceAttributes } isSelected={ isSelected } setAttributes={ setAttributes } />
	);

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

		edit,
		save: () => null, // uses view.php
	} );
};
