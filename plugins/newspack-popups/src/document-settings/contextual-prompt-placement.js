/**
 * In-editor placement indicator for Contextual Prompts.
 *
 * Shows a ghost preview of the prompt exactly where readers will see it, so an
 * editor doesn't have to publish and view the story to discover that the
 * prompt landed in the middle of a photo or an embed.
 *
 * Implemented as an `editor.BlockListBlock` decoration rather than by inserting
 * a real block: the prompt is injected at render time from a separate prompt
 * post, so nothing about it belongs in this post's content.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import ContextualPromptPreview from './contextual-prompt-preview';
import { STORE_NAME } from './contextual-prompt-store';

// `wp-block` is what the editor and themes use to constrain an element to the
// content column. The indicator stands in for a block, so it takes the same
// class and lines up with the surrounding copy instead of bleeding full width.
const Indicator = ( { preview } ) => (
	<div className="wp-block newspack-contextual-prompt-indicator" contentEditable={ false }>
		<span className="newspack-contextual-prompt-indicator__label">
			{ preview.overrideActive
				? __( 'Contextual Prompt — site-wide override, unstyled preview', 'newspack-popups' )
				: __( 'Contextual Prompt — unstyled preview', 'newspack-popups' ) }
		</span>
		<ContextualPromptPreview body={ preview.body } buttonLabel={ preview.buttonLabel } donationsNative={ preview.donationsNative } />
	</div>
);

const withPlacementIndicator = createHigherOrderComponent(
	BlockListBlock => props => {
		const { preview, placement } = useSelect(
			select => {
				const current = select( STORE_NAME ).getPreview();
				if ( ! current.active ) {
					return { preview: current, placement: null };
				}

				// Only top-level blocks receive the indicator: the front end
				// inserts between top-level blocks, so nested blocks (columns,
				// groups) must not claim it.
				const { getBlockRootClientId, getBlockOrder, getBlockIndex } = select( 'core/block-editor' );
				if ( getBlockRootClientId( props.clientId ) ) {
					return { preview: current, placement: null };
				}

				const total = getBlockOrder().length;
				if ( ! total ) {
					return { preview: current, placement: null };
				}
				const index = getBlockIndex( props.clientId );

				// Mirrors the front end: the prompt is inserted before the first
				// top-level block whose index is >= position, and falls after the
				// last block when position runs past the end of the story.
				const target = Math.max( 0, Math.min( current.position, total ) );
				if ( target === index ) {
					return { preview: current, placement: 'before' };
				}
				if ( target >= total && index === total - 1 ) {
					return { preview: current, placement: 'after' };
				}
				return { preview: current, placement: null };
			},
			[ props.clientId ]
		);

		if ( ! placement ) {
			return <BlockListBlock { ...props } />;
		}

		return (
			<>
				{ 'before' === placement && <Indicator preview={ preview } /> }
				<BlockListBlock { ...props } />
				{ 'after' === placement && <Indicator preview={ preview } /> }
			</>
		);
	},
	'withContextualPromptPlacementIndicator'
);

export default () => {
	addFilter( 'editor.BlockListBlock', 'newspack-popups/contextual-prompt-placement', withPlacementIndicator );
};
