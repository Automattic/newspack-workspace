/**
 * Contextual Prompt pattern locks, in every editor that opens the pattern.
 *
 * The server hides block locking for the editor that loads the pattern as its
 * post, but two routes reach the same post through settings that filter never
 * sees: the Site Editor (`site-editor.php?p=/wp_block/<id>`), whose editor
 * context carries no post, and the post editor's pattern focus mode, whose
 * settings were bootstrapped for the post the session started on. Both leave the
 * locks liftable — and savable. Holding the setting client-side covers them, and
 * hands it back on the way out: leaving focus mode, or moving on to another
 * pattern, must not inherit our lockdown.
 */

/**
 * WordPress dependencies.
 */
import domReady from '@wordpress/dom-ready';
import { select, dispatch, subscribe } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Internal dependencies.
 */
import { PATTERN_ID } from './instance';

/**
 * Whether the editor is on the Contextual Prompt pattern.
 *
 * @param {Object}        args           Route inputs.
 * @param {string}        args.postType  The edited entity's post type.
 * @param {string|number} args.postId    The edited entity's id.
 * @param {number}        args.patternId The site's pattern id.
 * @return {boolean} Whether the edited entity is the pattern.
 */
export const isEditingPattern = ( { postType, postId, patternId } ) =>
	Boolean( patternId ) && 'wp_block' === postType && Number( postId ) === Number( patternId );

/**
 * What the editor is currently editing, whichever editor it is.
 *
 * The Site Editor knows its own route; the post editor knows only the post it
 * bootstrapped with, which pattern focus mode swaps for the pattern itself. Both
 * halves come from one store, so a type resolved from one editor can never be
 * paired with an id from the other.
 *
 * @param {Object} args              Store access.
 * @param {Object} [args.siteEditor] The `core/edit-site` store, when present.
 * @param {Object} [args.editor]     The `core/editor` store, when present.
 * @return {{postType: string|undefined, postId: string|number|undefined}} The edited entity.
 */
export const resolveEditedEntity = ( { siteEditor, editor } ) => {
	const siteEditorPostType = siteEditor?.getEditedPostType?.();
	if ( siteEditorPostType ) {
		return { postType: siteEditorPostType, postId: siteEditor.getEditedPostId?.() };
	}

	return { postType: editor?.getCurrentPostType?.(), postId: editor?.getCurrentPostId?.() };
};

/**
 * A reconciler holding `canLockBlocks` off while the pattern is open.
 *
 * Editors re-push their own settings as they navigate, so the setting is
 * re-asserted on every change rather than only on arrival. What it was on
 * arrival is what it is restored to on leaving.
 *
 * @param {Object}   args                  Store access.
 * @param {Function} args.isEditingPattern Whether the pattern is the edited entity.
 * @param {Function} args.getCanLockBlocks Reads the current setting.
 * @param {Function} args.setCanLockBlocks Writes the setting.
 * @return {Function} The reconciler.
 */
export const createLockHold = ( { isEditingPattern: editingPattern, getCanLockBlocks, setCanLockBlocks } ) => {
	let held = false;
	let restoreTo;

	return () => {
		if ( editingPattern() ) {
			if ( ! held ) {
				restoreTo = getCanLockBlocks();
				held = true;
			}
			if ( false !== getCanLockBlocks() ) {
				setCanLockBlocks( false );
			}
			return;
		}

		if ( held ) {
			held = false;
			setCanLockBlocks( restoreTo );
		}
	};
};

export const registerContextualPromptEditorLocks = () => {
	if ( ! PATTERN_ID ) {
		return;
	}

	domReady( () => {
		if ( ! select( blockEditorStore ) ) {
			return;
		}

		const reconcile = createLockHold( {
			isEditingPattern: () =>
				isEditingPattern( {
					...resolveEditedEntity( {
						siteEditor: select( 'core/edit-site' ),
						editor: select( 'core/editor' ),
					} ),
					patternId: PATTERN_ID,
				} ),
			getCanLockBlocks: () => select( blockEditorStore ).getSettings().canLockBlocks,
			setCanLockBlocks: canLockBlocks => dispatch( blockEditorStore ).updateSettings( { canLockBlocks } ),
		} );

		reconcile();
		subscribe( reconcile );
	} );
};
