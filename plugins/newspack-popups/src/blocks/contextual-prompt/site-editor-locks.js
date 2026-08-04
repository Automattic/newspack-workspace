/**
 * Contextual Prompt pattern locks, in the Site Editor.
 *
 * The server hides block locking for the editor that opens the pattern, but the
 * Site Editor route (`site-editor.php?p=/wp_block/<id>`) reaches the same post
 * through an editor context that carries no post, so the filter cannot see what
 * is being edited and the locks become liftable — and savable. Holding the
 * setting client-side covers that route, and hands it back on the way out: the
 * Site Editor is a single page, so the next pattern the session opens must not
 * inherit our lockdown.
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
 * A reconciler holding `canLockBlocks` off while the pattern is open.
 *
 * The Site Editor re-pushes its own settings as it navigates, so the setting is
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

export const registerContextualPromptSiteEditorLocks = () => {
	if ( ! PATTERN_ID ) {
		return;
	}

	domReady( () => {
		// Outside the Site Editor the server filter already covers the route.
		const siteEditor = select( 'core/edit-site' );
		if ( ! siteEditor ) {
			return;
		}

		const editor = () => select( 'core/editor' );
		const reconcile = createLockHold( {
			isEditingPattern: () =>
				isEditingPattern( {
					postType: siteEditor.getEditedPostType?.() ?? editor()?.getCurrentPostType?.(),
					postId: siteEditor.getEditedPostId?.() ?? editor()?.getCurrentPostId?.(),
					patternId: PATTERN_ID,
				} ),
			getCanLockBlocks: () => select( blockEditorStore ).getSettings().canLockBlocks,
			setCanLockBlocks: canLockBlocks => dispatch( blockEditorStore ).updateSettings( { canLockBlocks } ),
		} );

		reconcile();
		subscribe( reconcile );
	} );
};
