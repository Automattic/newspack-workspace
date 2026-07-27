/**
 * Newspack dependencies.
 */
import colors from 'newspack-colors';

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import { Icon, megaphone } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import './editor.scss';
import metadata from './block.json';
import { ContextualPromptEditor } from './edit';

const Save = () => {
	const blockProps = useBlockProps.save();
	const innerBlocksProps = useInnerBlocksProps.save( blockProps );
	return <div { ...innerBlocksProps } />;
};

export const registerContextualPromptBlock = () => {
	// No prompts inside prompts.
	if ( window.newspack_popups_blocks_data?.is_prompt ) {
		return null;
	}

	const postTypeLabel = window.newspack_popups_blocks_data?.post_type_label || __( 'post', 'newspack-popups' );

	// Registration is wider than insertion: the Site Editor registers the block
	// so it appears under Styles > Blocks, but cannot author one.
	const isInsertable = Boolean( window.newspack_popups_blocks_data?.contextual_prompts_insertable );

	registerBlockType( metadata.name, {
		...metadata,
		supports: { ...metadata.supports, inserter: isInsertable },
		title: __( 'Campaigns: Contextual Prompt', 'newspack-popups' ),
		description: sprintf(
			/* translators: %1$s: the edited content's post type label, e.g. "post", "page". */
			__(
				'A %1$s-specific donation ask. Copy is generated from the %1$s and editable; the call to action follows your donation settings.',
				'newspack-popups'
			),
			postTypeLabel
		),
		icon: {
			src: <Icon icon={ megaphone } />,
			foreground: colors[ 'primary-400' ],
		},
		keywords: [
			__( 'newspack', 'newspack-popups' ),
			__( 'contextual', 'newspack-popups' ),
			__( 'prompt', 'newspack-popups' ),
			__( 'donation', 'newspack-popups' ),
		],
		edit: ContextualPromptEditor,
		save: Save,
	} );
};
