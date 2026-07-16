/**
 * WordPress dependencies
 */
import { getCategories } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import type { BlockAlignment, BlockEditProps } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { ad as icon } from '../utils/icons';
import Edit, { type AdUnitAttributes } from './edit';

/**
 * Style dependencies - will load in editor
 */
import './editor.scss';

export const name = 'ad-unit';
export const title = __( 'Ad Unit', 'newspack-ads' );

// `align` is a literal array of `BlockAlignment` members; annotate it explicitly
// so it isn't widened to `string[]` (which `registerBlockType` won't accept).
const supports: {
	html: boolean;
	align: BlockAlignment[];
	color: { text: boolean; background: boolean };
	visibility: boolean;
	position: { sticky: boolean };
} = {
	html: false,
	align: [ 'left', 'center', 'right', 'wide', 'full' ],
	color: {
		text: false,
		background: true,
	},
	visibility: false,
	position: {
		sticky: true,
	},
};

// `registerBlockType`'s generic `Attributes` gets inferred from this `edit`
// component's own prop type, but the `attributes` config below types each
// field as `unknown` (matching how already-migrated blocks handle
// block.json-derived attributes, see e.g. blocks/iframe/index.tsx in
// newspack-blocks) - wrap `Edit` so the boundary-facing component matches
// that, narrowing back to `AdUnitAttributes` only where `Edit` needs it.
const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = ( { attributes: editAttributes, setAttributes } ) => (
	<Edit attributes={ editAttributes as AdUnitAttributes } setAttributes={ setAttributes } />
);

export const settings = {
	apiVersion: 3,
	title,
	icon: {
		src: icon,
		foreground: '#406ebc',
	},
	category: getCategories().some( ( { slug } ) => slug === 'newspack' ) ? 'newspack' : 'common',
	keywords: [ __( 'ad', 'newspack-ads' ), __( 'advert', 'newspack-ads' ), __( 'ads', 'newspack-ads' ) ],
	description: __( 'Render an ad unit from your inventory.', 'newspack-ads' ),
	attributes: {
		provider: {
			type: 'string',
		},
		ad_unit: {
			type: 'string',
		},
		bidders_ids: {
			type: 'object',
			default: {},
		},
		// Legacy attribute.
		activeAd: {
			type: 'string',
		},
	},
	supports,
	edit,
	save: () => null, // to use Newspack_Ads_Blocks::render_block()
};
