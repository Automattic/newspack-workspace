/**
 * Shared state between the Contextual Prompt panel and the in-editor placement
 * indicator.
 *
 * The panel (a PluginDocumentSettingPanel) and the indicator (an
 * editor.BlockListBlock filter) render in separate React trees, so a small
 * data store is the bridge: the panel publishes what the prompt currently looks
 * like and where it will land, and every block in the list reads it to decide
 * whether to render the ghost preview above or below itself.
 */

/**
 * WordPress dependencies
 */
import { createReduxStore, register, select as globalSelect } from '@wordpress/data';

export const STORE_NAME = 'newspack-popups/contextual-prompt';

const DEFAULT_STATE = {
	// Whether a prompt exists, is shown on this story, and should therefore be
	// previewed in the editor.
	active: false,
	// Block index the prompt is inserted before, matching the front-end
	// `blocks_count` rule.
	position: 0,
	body: '',
	buttonLabel: '',
	// True when the site uses Newspack donations, so the prompt renders the
	// native donate block instead of a plain button.
	donationsNative: false,
	// True while a site-wide override is replacing every prompt's copy.
	overrideActive: false,
};

const store = createReduxStore( STORE_NAME, {
	reducer( state = DEFAULT_STATE, action ) {
		if ( 'SET_PREVIEW' === action.type ) {
			return { ...state, ...action.preview };
		}
		return state;
	},
	actions: {
		setPreview( preview ) {
			return { type: 'SET_PREVIEW', preview };
		},
	},
	selectors: {
		getPreview( state ) {
			return state;
		},
	},
} );

register( store );

export const getPreview = () => globalSelect( STORE_NAME ).getPreview();
