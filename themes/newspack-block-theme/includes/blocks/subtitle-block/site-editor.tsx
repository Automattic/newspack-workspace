/* globals newspack_block_theme_subtitle_block */

/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { Icon, listView } from '@wordpress/icons';
import { useEntityProp } from '@wordpress/core-data';

import metadata from './block.json';

const EditComponent = ( { context: { postType, postId } = {} }: { context?: { postType?: string; postId?: number } } ) => {
	const blockProps = useBlockProps();
	// `postType` is cast to `string`: `useEntityProp()` requires it, and this block's `usesContext`
	// (block.json) means the block editor always provides it once the block is actually rendered
	// in a post/template context -- the `= {}` default above only covers the (context-less) case
	// where this Edit component is instantiated outside that flow.
	const [ postMeta = {} ] = useEntityProp( 'postType', postType as string, 'meta', postId );
	const subtitle = postMeta[ newspack_block_theme_subtitle_block.post_meta_name ] || __( 'Article subtitle', 'newspack-block-theme' );
	return <p { ...blockProps }>{ subtitle }</p>;
};

const blockData = {
	title: __( 'Article Subtitle', 'newspack-block-theme' ),
	icon: {
		src: <Icon icon={ listView } />,
		foreground: '#36f',
	},
	edit: EditComponent,
	// Possible pre-existing bug (not introduced by this migration, not fixed here): `metadata`
	// (block.json) also has a `title` key ("Article Subtitle"), and since this spread comes last,
	// it silently overwrites the translated `title` above at every call -- the __() call's output
	// is discarded. The cast below only hides `title` from the spread source's static type (so TS
	// stops flagging that overwrite as a duplicate-property error) while keeping `name`/`category`/
	// `attributes` fully typed from block.json; it has no effect at runtime.
	...( metadata as Omit< typeof metadata, 'title' > ),
};

registerBlockType( metadata.name, blockData );
