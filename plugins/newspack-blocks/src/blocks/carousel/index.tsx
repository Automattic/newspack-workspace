/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';
import { contentCarousel as icon } from 'newspack-icons';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import type { BlockAlignment, BlockEditProps } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import CarouselEdit from './edit';

/**
 * Style dependencies - will load in editor
 */
import './view.scss';
import './editor.scss';
import metadata from './block.json';
const { name, attributes, apiVersion, category } = metadata;

// Name must be exported separately.
export { name };

export const title = __( 'Content Carousel', 'newspack-blocks' );

// `align` is a literal array of `BlockAlignment` members; annotate it explicitly
// so it isn't widened to `string[]` (which `registerBlockType` won't accept).
const supports: { html: boolean; align: BlockAlignment[] } = {
	html: false,
	align: [ 'center', 'wide', 'full' ],
};

// `registerBlockType`'s generic `Attributes` gets inferred from this `edit`
// component's own prop type, but the block.json-derived attributes below
// type each field as `unknown` (JSON imports lose their literal `"type"`
// strings, so `@wordpress/blocks` types can't map them to real TS types) -
// wrap `CarouselEdit` (itself already loosely typed at its own export
// boundary, see edit.tsx) so the boundary-facing component matches that.
const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = props => <CarouselEdit { ...props } />;

export const settings = {
	apiVersion,
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	attributes,
	category,
	keywords: [
		__( 'posts', 'newspack-blocks' ),
		__( 'articles', 'newspack-blocks' ),
		__( 'latest', 'newspack-blocks' ),
		__( 'query', 'newspack-blocks' ),
	],
	description: __(
		'An advanced block that displays content in a carousel format with customizable parameters and visual configurations.',
		'newspack-blocks'
	),
	supports,
	edit,
	save: () => null, // to use view.php
};
