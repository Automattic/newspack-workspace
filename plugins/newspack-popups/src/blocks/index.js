/**
 * Internal dependencies.
 */
import { registerCustomPlacementBlock } from './custom-placement';
import { registerSinglePromptBlock } from './single-prompt';
import { registerContextualPromptInstance } from './contextual-prompt/instance';
import { registerContextualPromptEditorLocks } from './contextual-prompt/editor-locks';
import { registerContextualPromptCardGuard } from './contextual-prompt/card-guard';
import './contextual-prompt/editor.scss';
import './prompt-editor-canvas.scss';

// Register the Custom Placement block.
registerCustomPlacementBlock();
registerSinglePromptBlock();
// The Contextual Prompt inspector only registers when the feature flag is on
// (wp_localize_script stringifies the boolean to '1'/'').
if ( Boolean( window.newspack_popups_blocks_data?.contextual_prompts_enabled ) ) {
	registerContextualPromptInstance();
	registerContextualPromptEditorLocks();
	registerContextualPromptCardGuard();
}
