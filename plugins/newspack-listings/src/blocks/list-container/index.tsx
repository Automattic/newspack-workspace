/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockEditProps } from '@wordpress/blocks';
import { group } from '@wordpress/icons';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import './editor.scss';
import { ListContainerEditor } from './edit';
import type { CuratedListAttributes } from '../curated-list/edit';
import parentData from '../curated-list/block.json';

const parentAttributes = parentData.attributes;

export const registerListContainerBlock = () => {
	// See the matching comment in `../curated-list/index.tsx` for why this
	// wrapper exists (bridges the generic `Attributes` inferred from
	// `attributes: parentAttributes` with `ListContainerEditor`'s specific
	// `CuratedListAttributes`).
	const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = ( { attributes: editAttributes, setAttributes, ...rest } ) => (
		<ListContainerEditor attributes={ editAttributes as CuratedListAttributes } setAttributes={ setAttributes } { ...rest } />
	);

	registerBlockType( 'newspack-listings/list-container', {
		apiVersion: 3,
		title: __( 'Container', 'newspack-listings' ),
		icon: {
			src: group,
			foreground: '#003da5',
		},
		category: 'newspack',
		parent: [ 'newspack-listings/curated-list' ],
		keywords: [
			__( 'curated', 'newspack-listings' ),
			__( 'list', 'newspack-listings' ),
			__( 'lists', 'newspack-listings' ),
			__( 'listings', 'newspack-listings' ),
			__( 'latest', 'newspack-listings' ),
		],

		attributes: parentAttributes,

		// Hide from block inserter menus.
		supports: {
			inserter: false,
		},

		edit,
		save: () => <InnerBlocks.Content />, // also uses view.php
	} );
};
