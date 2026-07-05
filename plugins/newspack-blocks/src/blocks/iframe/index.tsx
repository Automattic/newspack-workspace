/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';
import { iframe as icon } from 'newspack-icons';

/**
 * WordPress dependencies
 */
import { ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockAlignment, BlockEditProps } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import IframeEdit, { type IframeAttributes } from './edit';
import metadata from './block.json';
const { name, attributes, apiVersion, category } = metadata;

/**
 * Style dependencies - will load in editor
 */
import './editor.scss';

export const title = __( 'Iframe', 'newspack-blocks' );

// Name must be exported separately.
export { name };

// `align` is a literal array of `BlockAlignment` members; annotate it explicitly
// so it isn't widened to `string[]` (which `registerBlockType` won't accept).
const supports: { html: boolean; align: BlockAlignment[] } = {
	html: false,
	align: [ 'wide', 'full' ],
};

// `registerBlockType`'s generic `Attributes` gets inferred from this `edit`
// component's own prop type, but the block.json-derived `attributes` below
// types each field as `unknown` (JSON imports lose their literal `"type"`
// strings, so `@wordpress/blocks` types can't map them to real TS types) -
// wrap `IframeEdit` so the boundary-facing component matches that, narrowing
// back to `IframeAttributes` only where `IframeEdit` needs it.
const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = ( { attributes: editAttributes, setAttributes } ) => (
	<IframeEdit attributes={ editAttributes as IframeAttributes } setAttributes={ setAttributes } />
);

export const settings = {
	apiVersion,
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	category,
	keywords: [ __( 'iframe', 'newspack-blocks' ), __( 'project iframe', 'newspack-blocks' ) ],
	description: (
		<>
			<p>{ __( 'Embed an iframe.', 'newspack-blocks' ) }</p>
			<ExternalLink href="https://help.newspack.com/publishing-and-appearance/blocks/iframe-block/">
				{ __( 'Support reference', 'newspack-blocks' ) }
			</ExternalLink>
		</>
	),
	attributes,
	supports,
	edit,
	save: () => null, // to use view.php
};
