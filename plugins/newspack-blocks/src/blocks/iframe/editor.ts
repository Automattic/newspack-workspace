/**
 * Internal dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import type { ReactNode } from 'react';
import { name, settings } from '.';

// `description` is declared as `string` in `@wordpress/blocks`' published
// types, but the block editor only ever renders it as `children` of a
// `<Text>` component (see `@wordpress/block-editor`'s `BlockCard`), which
// accepts any `ReactNode` - this block's `description` below is JSX (to embed
// a support link), which already renders correctly today. Re-type
// `registerBlockType` at this boundary to reflect that, rather than the
// stricter-than-reality published signature.
const registerBlock = registerBlockType as (
	name: string,
	blockSettings: Omit< Parameters< typeof registerBlockType >[ 1 ], 'description' > & { description?: ReactNode }
) => ReturnType< typeof registerBlockType >;

registerBlock( `newspack-blocks/${ name }`, settings );
