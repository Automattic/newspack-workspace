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
registerContextualPromptBlock();
