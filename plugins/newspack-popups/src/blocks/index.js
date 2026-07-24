/**
 * Internal dependencies.
 */
import { registerCustomPlacementBlock } from './custom-placement';
import { registerSinglePromptBlock } from './single-prompt';
import { registerContextualPromptBlock } from './contextual-prompt';
import './prompt-editor-canvas.scss';

// Register the Custom Placement block.
registerCustomPlacementBlock();
registerSinglePromptBlock();
// The Contextual Prompt block only registers when the feature flag is on
// (wp_localize_script stringifies the boolean to '1'/'').
if ( Boolean( window.newspack_popups_blocks_data?.contextual_prompts_enabled ) ) {
	registerContextualPromptBlock();
}
