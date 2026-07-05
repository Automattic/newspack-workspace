/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockEditProps } from '@wordpress/blocks';
import { pencil } from '@wordpress/icons';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { SelfServeListingsEditor, type SelfServeListingsAttributes } from './edit';
import metadata from './block.json';
const { attributes, category, name } = metadata;

export const registerSelfServeListingsBlock = () => {
	// See the matching comment in `../price/index.tsx` for why this wrapper
	// exists (bridges the generic default `Attributes` with
	// `SelfServeListingsEditor`'s specific `SelfServeListingsAttributes`).
	const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = ( { attributes: editAttributes, clientId, setAttributes } ) => (
		<SelfServeListingsEditor attributes={ editAttributes as SelfServeListingsAttributes } clientId={ clientId } setAttributes={ setAttributes } />
	);

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

		edit,
		save: () => null, // uses view.php
	} );
};
