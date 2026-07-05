/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockEditProps } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import './editor.scss';
import { CuratedListEditor, type CuratedListAttributes } from './edit';
import { List } from '../../svg';
import metadata from './block.json';
const { attributes, category, name } = metadata;

export const registerCuratedListBlock = () => {
	// `registerBlockType`'s generic `Attributes` gets inferred from the
	// `attributes` object below (each field typed per the real
	// `BlockAttribute` schema, not the runtime value shape), so `edit` must
	// match `BlockEditProps<that inferred shape>` - wrap `CuratedListEditor`
	// (itself `ComponentType<Record<string, unknown>>`, see its cast in
	// `./edit`) so the boundary-facing component matches it, narrowing back to
	// `CuratedListAttributes` only where `CuratedListEditor` needs it. Matches
	// the pattern used for `IframeEdit` in newspack-blocks' `blocks/iframe/index.tsx`.
	const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = ( { attributes: editAttributes, setAttributes, ...rest } ) => (
		<CuratedListEditor attributes={ editAttributes as CuratedListAttributes } setAttributes={ setAttributes } { ...rest } />
	);

	registerBlockType( name, {
		apiVersion: 3,
		title: __( 'Curated List', 'newspack-listings' ),
		icon: {
			src: <List />,
			foreground: '#003da5',
		},
		category,
		keywords: [
			__( 'curated', 'newspack-listings' ),
			__( 'list', 'newspack-listings' ),
			__( 'lists', 'newspack-listings' ),
			__( 'listings', 'newspack-listings' ),
			__( 'latest', 'newspack-listings' ),
		],

		attributes,

		edit,
		save: () => <InnerBlocks.Content />, // also uses view.php
	} );
};
