/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockEditProps } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import './editor.scss';
import { ListingEditor, type ListingEditorAttributes } from './edit';
import metadata from './block.json';
import parentData from '../curated-list/block.json';
import { getIcon } from '../../editor/utils';

const parentAttributes = parentData.attributes;
const { attributes, category } = metadata;
const { post_types } = window.newspack_listings_data;

// See the matching comment in `../curated-list/index.tsx` for why this
// wrapper exists (bridges the generic `Attributes` inferred from the merged
// `attributes` object below with `ListingEditor`'s specific
// `ListingEditorAttributes`). Declared once, outside the loop, since it
// doesn't depend on `listingType`.
const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = props => {
	const { attributes: editAttributes, clientId, setAttributes } = props;
	// The real `edit` component also receives the registered block `name` at
	// runtime (`ListingEditorComponent` reads it) even though the published
	// `BlockEditProps` type doesn't declare it.
	const { name } = props as BlockEditProps< Record< string, unknown > > & { name: string };

	return (
		<ListingEditor attributes={ editAttributes as ListingEditorAttributes } clientId={ clientId } name={ name } setAttributes={ setAttributes } />
	);
};

export const registerListingBlock = () => {
	for ( const listingType in post_types ) {
		if ( post_types.hasOwnProperty( listingType ) ) {
			// `post_types` is declared with its exact known keys (see
			// `src/types/globals.d.ts`), which doesn't give it a `string` index
			// signature for the `for...in` key - narrow at this `window.*` global
			// boundary rather than widening the declared shape.
			const listingTypeKey = listingType as keyof typeof post_types;

			registerBlockType( `newspack-listings/${ listingType }`, {
				apiVersion: 3,
				title: listingType.charAt( 0 ).toUpperCase() + listingType.slice( 1 ),
				icon: {
					src: getIcon( listingType ),
					foreground: '#003da5',
				},
				category,
				parent: [ 'newspack-listings/list-container' ],
				keywords: [ __( 'lists', 'newspack-listings' ), __( 'listings', 'newspack-listings' ), __( 'latest', 'newspack-listings' ) ],

				// Combine attributes with parent attributes, so parent can pass data to InnerBlocks without relying on contexts.
				attributes: Object.assign( attributes, parentAttributes ),

				// Hide from Block Inserter if there are no published posts of this type.
				supports: {
					inserter: post_types[ listingTypeKey ].show_in_inserter || false,
				},

				edit,
				save: () => null, // uses view.php
			} );
		}
	}
};
