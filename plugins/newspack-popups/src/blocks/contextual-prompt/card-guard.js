/**
 * What holds a Contextual Prompt to its shape once it is in the post.
 *
 * Two things the pattern cannot enforce on its own:
 *
 * - One prompt per story. The pattern is kept out of the inserter, but Duplicate
 *   and paste still put a second card in the post, so the newcomer is removed as
 *   it lands — whichever position it lands in.
 * - A card detached from the pattern is the publisher's to reshape, but only so
 *   far. Detaching copies the card's markup into the post, locks and all; those
 *   locks are lifted — the group's own lock and its `templateLock`, and any lock
 *   on the call to action — so the publisher can move the card and swap the CTA
 *   for blocks of their own. The generated copy is held in place: it is the one
 *   child the pattern names, and it is what keeps the card a prompt. Core's
 *   Unlock modal writes the `lock` attribute, so holding the copy re-asserts it,
 *   and freeing the rest strips it, whichever way the modal was used.
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

// The plan's correction lists, as opposed to the cards it hands the next pass.
const CORRECTIONS = [ 'remove', 'unlockRemovals', 'stripGroupLock', 'stripTemplateLock', 'lockChildren', 'unlockChildren' ];

/**
 * What has to change for the post to carry one prompt in the pattern's shape.
 *
 * Every correction list is empty when nothing is wrong, which is the common case
 * — a pass that finds nothing must dispatch nothing, or the attribute writes
 * would tick the store into another pass.
 *
 * The card the post keeps is the one the previous pass already saw: a copy
 * pasted above the publisher's own prompt is still the copy, and removing by
 * document order would delete the card they wrote. Only a pass with nothing
 * known — the post opened carrying two, saved before this guard existed, or
 * reopened with fresh client ids — falls back to document order.
 *
 * @param {Object[]} blocks Block tree.
 * @param {string[]} known  Client ids of the cards the previous pass kept.
 * @return {{keep: string[], remove: string[], unlockRemovals: string[], stripGroupLock: string[], pinTemplateLock: string[], lockChildren: string[]}} The corrections, and the cards to know next pass.
 */
export const planPromptCorrections = ( blocks, known = [] ) => {
	const cards = findPromptCards( blocks );
	const knownIds = new Set( known );
	const survivors = cards.filter( card => knownIds.has( card.clientId ) );
	const kept = survivors.length ? survivors : cards.slice( 0, 1 );
	const surplus = cards.filter( card => ! kept.includes( card ) );
	const plan = {
		keep: kept.map( card => card.clientId ),
		remove: surplus.map( card => card.clientId ),
		// A card pasted out of the pattern editor carries the pattern's own lock,
		// which the store honours: removal would silently do nothing.
		unlockRemovals: surplus.filter( card => card.attributes?.lock?.remove ).map( card => card.clientId ),
		stripGroupLock: [],
		stripTemplateLock: [],
		lockChildren: [],
		unlockChildren: [],
	};

	// Only the card the post keeps: the rest are on their way out, and an
	// instance carries its structure in the pattern rather than the post.
	const [ card ] = kept;
	if ( ! card || ! isDetachedPromptCard( card.name, card.attributes ) ) {
		return plan;
	}

	// Detaching copies the pattern's own lock and its templateLock onto the card.
	// Strip both: the group lock so the publisher can move or delete the prompt,
	// the templateLock so they can replace its call to action with their own
	// blocks.
	if ( undefined !== card.attributes?.lock ) {
		plan.stripGroupLock.push( card.clientId );
	}

	if ( undefined !== card.attributes?.templateLock ) {
		plan.stripTemplateLock.push( card.clientId );
	}

	// The generated copy is the one child held in place: it is what makes the
	// card a prompt. The pattern names only that child, so a `metadata` name
	// tells it from the CTA and anything the publisher adds — those are theirs to
	// arrange, and any lock the detach left on one is lifted.
	for ( const child of card.innerBlocks || [] ) {
		const lock = child.attributes?.lock;
		if ( child.attributes?.metadata?.name ) {
			if ( true !== lock?.move || true !== lock?.remove ) {
				plan.lockChildren.push( child.clientId );
			}
		} else if ( undefined !== lock ) {
			plan.unlockChildren.push( child.clientId );
		}
	}

	return plan;
};

/**
 * What applies a plan to the store.
 *
 * Nothing the guard does is the publisher's edit, so each dispatch is marked
 * not-persistent: it stays out of the undo stack and off a freshly opened post's
 * dirty flag, so undoing a paste pops the paste itself. The mark arms exactly
 * one change, which is why a removal the store would refuse must not arm it —
 * the flag would sit there and demote whatever the publisher does next.
 *
 * @param {Object}   deps                               Store access.
 * @param {Function} deps.updateBlockAttributes         Writes block attributes.
 * @param {Function} deps.removeBlocks                  Removes blocks.
 * @param {Function} deps.markNextChangeAsNotPersistent Arms the not-persistent mark.
 * @param {Function} deps.canRemoveBlocks               Whether the store would remove them.
 * @param {Function} deps.getBlock                      Reads a block by client id.
 * @param {Function} deps.createNotice                  Raises a notice.
 * @return {Function} The applier.
 */
export const createPromptCorrectionApplier =
	( { updateBlockAttributes, removeBlocks, markNextChangeAsNotPersistent, canRemoveBlocks, getBlock, createNotice } ) =>
	plan => {
		if ( plan.stripGroupLock.length ) {
			markNextChangeAsNotPersistent();
			updateBlockAttributes( plan.stripGroupLock, { lock: undefined } );
		}
		if ( plan.stripTemplateLock.length ) {
			markNextChangeAsNotPersistent();
			updateBlockAttributes( plan.stripTemplateLock, { templateLock: undefined } );
		}
		if ( plan.lockChildren.length ) {
			markNextChangeAsNotPersistent();
			updateBlockAttributes( plan.lockChildren, { lock: { ...CHILD_LOCK } } );
		}
		if ( plan.unlockChildren.length ) {
			markNextChangeAsNotPersistent();
			updateBlockAttributes( plan.unlockChildren, { lock: undefined } );
		}
		if ( ! plan.remove.length ) {
			return;
		}
		if ( plan.unlockRemovals.length ) {
			markNextChangeAsNotPersistent();
			updateBlockAttributes( plan.unlockRemovals, { lock: undefined } );
		}
		// A locked ancestor or a template can still refuse the removal outright.
		if ( ! canRemoveBlocks( plan.remove ) ) {
			return;
		}
		// selectPrevious would move the caret off whatever the publisher was doing
		// when the copy landed.
		markNextChangeAsNotPersistent();
		removeBlocks( plan.remove, false );
		// The store can refuse it silently too, and a notice about a card the post
		// visibly still carries would be a lie.
		if ( plan.remove.some( clientId => ! getBlock( clientId ) ) ) {
			createNotice( 'info', __( 'Only one Contextual Prompt can be added per post.', 'newspack-popups' ), {
				type: 'snackbar',
				id: NOTICE_ID,
			} );
		}
	};

/**
 * A reconciler applying those corrections as the content changes.
 *
 * The block tree is compared by reference — the store ticks on every keystroke,
 * and only a new tree can carry a new card. Corrections are dispatched behind a
 * latch: they re-enter this reconciler synchronously, and a second pass would
 * read the tree it is halfway through changing.
 *
 * The cards each pass keeps are carried to the next, which is what tells the
 * post's own prompt from one that just landed.
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
	let known = [];

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

		const plan = planPromptCorrections( blocks, known );
		known = plan.keep;
		if ( ! CORRECTIONS.some( key => plan[ key ].length ) ) {
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

		const {
			updateBlockAttributes,
			removeBlocks,
			__unstableMarkNextChangeAsNotPersistent: markNextChangeAsNotPersistent,
		} = dispatch( blockEditorStore );

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
			apply: createPromptCorrectionApplier( {
				updateBlockAttributes,
				removeBlocks,
				markNextChangeAsNotPersistent,
				canRemoveBlocks: clientIds => select( blockEditorStore ).canRemoveBlocks( clientIds ),
				getBlock: clientId => select( blockEditorStore ).getBlock( clientId ),
				createNotice: ( ...args ) => dispatch( 'core/notices' ).createNotice( ...args ),
			} ),
		} );

		reconcile();
		subscribe( reconcile );
	} );
};
