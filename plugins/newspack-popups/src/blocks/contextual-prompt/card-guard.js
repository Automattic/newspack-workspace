/**
 * What holds a Contextual Prompt to its shape once it is in the post.
 *
 * Two things the pattern cannot enforce on its own:
 *
 * - One prompt per story. The pattern is kept out of the inserter, but Duplicate
 *   and paste still put a second card in the post, so everything after the first
 *   is removed as it lands.
 * - A card detached from the pattern keeps the pattern's structure. Detaching
 *   copies the card's markup into the post, locks and all: the group's own lock
 *   is stripped so the publisher can move and delete their prompt, while its
 *   children stay fixed — core's Unlock modal writes the `lock` attribute, and
 *   re-asserting it makes unlocking ineffective without touching any of the
 *   styling controls.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import domReady from '@wordpress/dom-ready';
import { select, dispatch, subscribe } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Internal dependencies.
 */
import { findPromptCards, isDetachedPromptCard, PATTERN_ID } from './instance';
import { isEditingPattern, resolveEditedEntity } from './editor-locks';

const NOTICE_ID = 'newspack-contextual-prompt-single';

// Mirrors BLOCK_LOCK in class-newspack-popups-contextual-prompt-pattern.php.
const CHILD_LOCK = { move: true, remove: true };

/**
 * What has to change for the post to carry one prompt in the pattern's shape.
 *
 * Every list is empty when nothing is wrong, which is the common case — a pass
 * that finds nothing must dispatch nothing, or the attribute writes would tick
 * the store into another pass.
 *
 * @param {Object[]} blocks Block tree.
 * @return {{remove: string[], stripGroupLock: string[], pinTemplateLock: string[], lockChildren: string[]}} The corrections.
 */
export const planPromptCorrections = blocks => {
	const cards = findPromptCards( blocks );
	const plan = {
		remove: cards.slice( 1 ).map( card => card.clientId ),
		stripGroupLock: [],
		pinTemplateLock: [],
		lockChildren: [],
	};

	// Only the card the post keeps: the rest are on their way out, and an
	// instance carries its structure in the pattern rather than the post.
	const [ card ] = cards;
	if ( ! card || ! isDetachedPromptCard( card.name, card.attributes ) ) {
		return plan;
	}

	// Detaching copies the pattern's own lock down with the markup, which would
	// leave the publisher unable to move or delete the prompt they own.
	if ( undefined !== card.attributes?.lock ) {
		plan.stripGroupLock.push( card.clientId );
	}

	if ( 'insert' !== card.attributes?.templateLock ) {
		plan.pinTemplateLock.push( card.clientId );
	}

	for ( const child of card.innerBlocks || [] ) {
		const lock = child.attributes?.lock;
		if ( true !== lock?.move || true !== lock?.remove ) {
			plan.lockChildren.push( child.clientId );
		}
	}

	return plan;
};

/**
 * A reconciler applying those corrections as the content changes.
 *
 * The block tree is compared by reference — the store ticks on every keystroke,
 * and only a new tree can carry a new card. Corrections are dispatched behind a
 * latch: they re-enter this reconciler synchronously, and a second pass would
 * read the tree it is halfway through changing.
 *
 * @param {Object}   args           Store access.
 * @param {Function} args.getBlocks Reads the block tree.
 * @param {Function} args.isPattern Whether the pattern itself is what is open.
 * @param {Function} args.apply     Applies a plan.
 * @return {Function} The reconciler.
 */
export const createPromptCardHold = ( { getBlocks, isPattern, apply } ) => {
	let lastBlocks;
	let applying = false;

	return () => {
		if ( applying ) {
			return;
		}

		const blocks = getBlocks();
		if ( blocks === lastBlocks ) {
			return;
		}
		lastBlocks = blocks;

		// The pattern's card is not a copy of itself: its locks are the ones every
		// instance inherits, and stripping them there would strip them everywhere.
		if ( isPattern() ) {
			return;
		}

		const plan = planPromptCorrections( blocks );
		if ( ! Object.values( plan ).some( list => list.length ) ) {
			return;
		}

		applying = true;
		try {
			apply( plan );
		} finally {
			applying = false;
		}
	};
};

export const registerContextualPromptCardGuard = () => {
	// Without the pattern id an instance is indistinguishable from any other
	// synced pattern, and the marker-classed Group it carries would read as a
	// second card — removing the content of the one prompt the post has.
	if ( ! PATTERN_ID ) {
		return;
	}

	domReady( () => {
		if ( ! select( blockEditorStore ) ) {
			return;
		}

		const { updateBlockAttributes, removeBlocks } = dispatch( blockEditorStore );

		const reconcile = createPromptCardHold( {
			getBlocks: () => select( blockEditorStore ).getBlocks(),
			isPattern: () =>
				isEditingPattern( {
					...resolveEditedEntity( {
						siteEditor: select( 'core/edit-site' ),
						editor: select( 'core/editor' ),
					} ),
					patternId: PATTERN_ID,
				} ),
			apply: plan => {
				if ( plan.stripGroupLock.length ) {
					updateBlockAttributes( plan.stripGroupLock, { lock: undefined } );
				}
				if ( plan.pinTemplateLock.length ) {
					updateBlockAttributes( plan.pinTemplateLock, { templateLock: 'insert' } );
				}
				if ( plan.lockChildren.length ) {
					updateBlockAttributes( plan.lockChildren, { lock: { ...CHILD_LOCK } } );
				}
				if ( plan.remove.length ) {
					// selectPrevious would move the caret off whatever the publisher was
					// doing when the copy landed.
					removeBlocks( plan.remove, false );
					dispatch( 'core/notices' ).createNotice( 'info', __( 'Only one Contextual Prompt can be added per post.', 'newspack-popups' ), {
						type: 'snackbar',
						id: NOTICE_ID,
					} );
				}
			},
		} );

		reconcile();
		subscribe( reconcile );
	} );
};
