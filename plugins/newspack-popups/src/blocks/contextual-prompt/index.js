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
import { ContextualPromptEditor, DONATIONS_NATIVE } from './edit';

// A flex column for a plain-button CTA, so core offers its own orientation
// toggle, flow layout for the full-width donate form. Must stay identical to
// layout_support() in class-newspack-popups-contextual-prompt-block.php;
// block.json carries no layout supports, since both of these override it.
const LAYOUT_SUPPORT = DONATIONS_NATIVE
	? {
			default: { type: 'default' },
			allowSwitching: false,
			allowJustification: false,
			allowOrientation: false,
			// Without this core still renders an empty "Layout" section.
			allowEditing: false,
	  }
	: {
			// justifyContent: stretch, since core's flex layout zeroes child margins
			// and the copy and button would otherwise shrink-wrap.
			default: { type: 'flex', orientation: 'vertical', justifyContent: 'stretch' },
			allowSwitching: false,
			// Paired with orientation: the toggle rewrites the whole layout
			// attribute, remapping stretch to flex-start, and only a justification
			// control can put it back.
			allowJustification: true,
			allowOrientation: true,
			allowVerticalAlignment: true,
	  };

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
		supports: { ...metadata.supports, inserter: isInsertable, layout: LAYOUT_SUPPORT },
		// Registered here rather than in block.json, whose i18n schema does not
		// translate example content. Feeds the inserter preview and the one the
		// Site Editor shows above Styles > Blocks. The template swaps this CTA for
		// the donate block on a donations-native site, which previews the real
		// thing; the copy is deliberately non-empty so no generation is attempted.
		example: {
			attributes: {},
			innerBlocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: __(
							'Reporting like this takes time and costs money. If you value it, consider supporting our newsroom.',
							'newspack-popups'
						),
					},
				},
				{
					name: 'core/buttons',
					innerBlocks: [ { name: 'core/button', attributes: { text: __( 'Donate', 'newspack-popups' ) } } ],
				},
			],
			viewportWidth: 800,
		},
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
