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

const EditComponent = ( { context: { postType, postId } = {} } ) => {
	const blockProps = useBlockProps();
	const [ postMeta = {} ] = useEntityProp( 'postType', postType, 'meta', postId );
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
	...metadata,
};

registerBlockType( metadata.name, blockData );
