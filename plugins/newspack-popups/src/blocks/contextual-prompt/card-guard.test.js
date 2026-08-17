/**
 * The corrections a post's prompt needs — surplus cards to remove, and the locks
 * a detached card has to be held to — and the reconciler that applies them.
 *
 * The editor's ESM chain is not transformable, and none of it is needed here.
 */
jest.mock( '@wordpress/block-editor', () => ( {} ) );
jest.mock( '@wordpress/dom-ready', () => jest.fn() );

const PATTERN_ID = 12;

// The pattern id is read once at import, so the module is loaded against its
// localized global.
const loadGuard = () => {
	jest.resetModules();
	window.newspack_popups_blocks_data = { contextual_prompts_pattern_id: String( PATTERN_ID ) };
	return require( './card-guard' );
};

afterEach( () => {
	delete window.newspack_popups_blocks_data;
} );

const CHILD_LOCK = { move: true, remove: true };

const instance = ( clientId, innerBlocks = [], attributes = {} ) => ( {
	clientId,
	name: 'core/block',
	attributes: { ref: PATTERN_ID, ...attributes },
	innerBlocks,
} );
const foreignPattern = ( clientId, innerBlocks = [] ) => ( { clientId, name: 'core/block', attributes: { ref: PATTERN_ID + 1 }, innerBlocks } );
const detached = ( clientId, innerBlocks = [], attributes = {} ) => ( {
	clientId,
	name: 'core/group',
	attributes: { className: 'wp-block-group newspack-contextual-prompt has-background', templateLock: 'insert', ...attributes },
	innerBlocks,
} );
const group = ( clientId, innerBlocks = [] ) => ( { clientId, name: 'core/group', attributes: { className: 'wp-block-group' }, innerBlocks } );
const paragraph = ( clientId, attributes = { lock: CHILD_LOCK } ) => ( { clientId, name: 'core/paragraph', attributes, innerBlocks: [] } );

// A detached card as the guard has already reconciled it: nothing left to do.
const settled = clientId => detached( clientId, [ paragraph( `${ clientId }-copy` ), paragraph( `${ clientId }-cta` ) ] );

describe( 'planPromptCorrections: surplus cards', () => {
	it.each( [
		[ 'nothing to remove in a post with no prompt', [ paragraph( 'p1' ), group( 'g1', [ paragraph( 'p2' ) ] ) ], [] ],
		[ 'nothing to remove for a single instance', [ paragraph( 'p1' ), instance( 'card' ) ], [] ],
		[ 'nothing to remove for a single detached card', [ paragraph( 'p1' ), settled( 'card' ) ], [] ],
		[ 'the second of two instances', [ instance( 'first' ), paragraph( 'p1' ), instance( 'second' ) ], [ 'second' ] ],
		[ 'the detached copy of an instance', [ instance( 'first' ), settled( 'second' ) ], [ 'second' ] ],
		[ 'the instance pasted after a detached card', [ settled( 'first' ), instance( 'second' ) ], [ 'second' ] ],
		[ 'every card after the first', [ instance( 'first' ), instance( 'second' ), settled( 'third' ) ], [ 'second', 'third' ] ],
		[ 'a card nested inside another block', [ instance( 'first' ), group( 'g1', [ group( 'g2', [ settled( 'nested' ) ] ) ] ) ], [ 'nested' ] ],
		[ 'a card nested under the only top-level block', [ group( 'g1', [ instance( 'first' ), settled( 'nested' ) ] ) ], [ 'nested' ] ],
	] )( 'removes %s', ( label, blocks, expected ) => {
		const { planPromptCorrections } = loadGuard();
		expect( planPromptCorrections( blocks ).remove ).toEqual( expected );
	} );

	// An instance renders the pattern's own marker-classed Group as an inner
	// block: the same card, not a second one.
	it( 'does not count the pattern content an instance carries', () => {
		const { planPromptCorrections } = loadGuard();
		expect( planPromptCorrections( [ instance( 'card', [ settled( 'pattern-content' ) ] ) ] ).remove ).toEqual( [] );
	} );

	// Another synced pattern's content is not the post's to correct, and a
	// marker-classed Group inside one is that pattern's business.
	it( 'never descends into a foreign synced pattern', () => {
		const { planPromptCorrections } = loadGuard();
		const blocks = [ instance( 'card' ), foreignPattern( 'other', [ settled( 'lookalike' ) ] ) ];

		expect( planPromptCorrections( blocks ).remove ).toEqual( [] );
	} );

	// A card copied out of the pattern editor carries the pattern's own lock, and
	// the store honours it: removal would silently do nothing.
	it.each( [
		[ 'a locked detached copy', [ instance( 'first' ), detached( 'second', [ paragraph( 'copy' ) ], { lock: CHILD_LOCK } ) ] ],
		[ 'a locked instance', [ instance( 'first' ), instance( 'second', [], { lock: CHILD_LOCK } ) ] ],
	] )( 'unlocks %s before removing it', ( label, blocks ) => {
		const { planPromptCorrections } = loadGuard();
		const plan = planPromptCorrections( blocks );

		expect( plan.remove ).toEqual( [ 'second' ] );
		expect( plan.unlockRemovals ).toEqual( [ 'second' ] );
	} );

	// A copy pasted above the prompt the post already carries is still the copy:
	// removing by document order would delete the card the publisher wrote.
	it.each( [
		[ 'above', [ instance( 'new' ), instance( 'old' ) ] ],
		[ 'below', [ instance( 'old' ), instance( 'new' ) ] ],
	] )( 'removes a newcomer that lands %s the card the post already carried', ( label, blocks ) => {
		const { planPromptCorrections } = loadGuard();
		const plan = planPromptCorrections( blocks, [ 'old' ] );

		expect( plan.remove ).toEqual( [ 'new' ] );
		expect( plan.keep ).toEqual( [ 'old' ] );
	} );

	// A post saved before this guard existed, or reopened with fresh client ids:
	// nothing is known, so document order is all there is to go on.
	it( 'keeps the first of cards it has never seen', () => {
		const { planPromptCorrections } = loadGuard();
		const plan = planPromptCorrections( [ instance( 'first' ), instance( 'second' ) ], [ 'gone' ] );

		expect( plan.remove ).toEqual( [ 'second' ] );
		expect( plan.keep ).toEqual( [ 'first' ] );
	} );

	it( 'unlocks nothing a removal does not need', () => {
		const { planPromptCorrections } = loadGuard();
		const blocks = [ instance( 'first' ), settled( 'second' ), instance( 'third', [], { lock: { move: true, remove: false } } ) ];

		expect( planPromptCorrections( blocks ).unlockRemovals ).toEqual( [] );
	} );
} );

describe( 'planPromptCorrections: detached card locks', () => {
	it( 'asks for nothing when a detached card is already in shape', () => {
		const { planPromptCorrections } = loadGuard();

		expect( planPromptCorrections( [ settled( 'card' ) ] ) ).toEqual( {
			keep: [ 'card' ],
			remove: [],
			unlockRemovals: [],
			stripGroupLock: [],
			pinTemplateLock: [],
			lockChildren: [],
		} );
	} );

	// The detach copies the pattern's own lock down with the markup, which would
	// leave the publisher unable to move or delete the prompt they own.
	it( 'strips the lock the detach copied onto the card', () => {
		const { planPromptCorrections } = loadGuard();
		const card = detached( 'card', [ paragraph( 'copy' ) ], { lock: CHILD_LOCK } );

		expect( planPromptCorrections( [ card ] ).stripGroupLock ).toEqual( [ 'card' ] );
	} );

	it.each( [
		[ 'lifted', { templateLock: undefined } ],
		[ 'changed', { templateLock: 'all' } ],
		[ 'false', { templateLock: false } ],
	] )( 're-pins templateLock when it is %s', ( label, attributes ) => {
		const { planPromptCorrections } = loadGuard();
		const card = detached( 'card', [ paragraph( 'copy' ) ], attributes );

		expect( planPromptCorrections( [ card ] ).pinTemplateLock ).toEqual( [ 'card' ] );
	} );

	// Core's Unlock modal writes the child's own lock attribute, so re-asserting
	// it is what makes unlocking ineffective.
	it.each( [
		[ 'unlocked outright', {} ],
		[ 'lifted by the unlock modal', { lock: { move: false, remove: false } } ],
		[ 'only half locked', { lock: { move: true, remove: false } } ],
	] )( 're-locks a child %s', ( label, attributes ) => {
		const { planPromptCorrections } = loadGuard();
		const card = detached( 'card', [ paragraph( 'copy', attributes ), paragraph( 'cta' ) ] );

		expect( planPromptCorrections( [ card ] ).lockChildren ).toEqual( [ 'copy' ] );
	} );

	it( 're-locks every child that needs it', () => {
		const { planPromptCorrections } = loadGuard();
		const card = detached( 'card', [ paragraph( 'copy', {} ), paragraph( 'cta', {} ) ] );

		expect( planPromptCorrections( [ card ] ).lockChildren ).toEqual( [ 'copy', 'cta' ] );
	} );

	// Only the card's own children are held: a locked child cannot be
	// restructured, so what sits under one is the publisher's to arrange.
	it( 'leaves blocks below the card alone', () => {
		const { planPromptCorrections } = loadGuard();
		const card = detached( 'card', [ paragraph( 'copy' ), { ...paragraph( 'cta' ), innerBlocks: [ paragraph( 'nested', {} ) ] } ] );

		expect( planPromptCorrections( [ card ] ).lockChildren ).toEqual( [] );
	} );

	// An instance's structure lives in the pattern, not the post.
	it( 'holds nothing on an instance', () => {
		const { planPromptCorrections } = loadGuard();
		const card = instance( 'card', [ detached( 'pattern-content', [ paragraph( 'copy', {} ) ], { lock: CHILD_LOCK } ) ] );

		expect( planPromptCorrections( [ card ] ) ).toEqual( {
			keep: [ 'card' ],
			remove: [],
			unlockRemovals: [],
			stripGroupLock: [],
			pinTemplateLock: [],
			lockChildren: [],
		} );
	} );

	// The surplus is on its way out; correcting it would write attributes onto
	// blocks the same pass removes.
	it( 'holds nothing on a card it is removing', () => {
		const { planPromptCorrections } = loadGuard();
		const plan = planPromptCorrections( [ settled( 'first' ), detached( 'second', [ paragraph( 'copy', {} ) ], { lock: CHILD_LOCK } ) ] );

		expect( plan.remove ).toEqual( [ 'second' ] );
		expect( plan.stripGroupLock ).toEqual( [] );
		expect( plan.lockChildren ).toEqual( [] );
	} );
} );

describe( 'createPromptCorrectionApplier', () => {
	const emptyPlan = {
		remove: [],
		unlockRemovals: [],
		stripGroupLock: [],
		pinTemplateLock: [],
		lockChildren: [],
	};

	const setUp = ( { canRemoveBlocks = () => true, removed = true } = {} ) => {
		const { createPromptCorrectionApplier } = loadGuard();
		const deps = {
			updateBlockAttributes: jest.fn(),
			removeBlocks: jest.fn(),
			markNextChangeAsNotPersistent: jest.fn(),
			canRemoveBlocks: jest.fn( canRemoveBlocks ),
			getBlock: jest.fn( () => ( removed ? undefined : { clientId: 'second' } ) ),
			createNotice: jest.fn(),
		};
		return { apply: createPromptCorrectionApplier( deps ), ...deps };
	};

	it( 'removes the surplus card and says so', () => {
		const guard = setUp();

		guard.apply( { ...emptyPlan, remove: [ 'second' ] } );

		expect( guard.removeBlocks ).toHaveBeenCalledWith( [ 'second' ], false );
		expect( guard.markNextChangeAsNotPersistent ).toHaveBeenCalledTimes( 1 );
		expect( guard.createNotice ).toHaveBeenCalledWith( 'info', expect.stringContaining( 'Only one Contextual Prompt' ), expect.any( Object ) );
	} );

	// The mark arms exactly one change, so arming it for a removal the store
	// refuses would demote whatever the publisher does next out of the undo stack.
	it( 'arms nothing when the store would refuse the removal', () => {
		const guard = setUp( { canRemoveBlocks: () => false } );

		guard.apply( { ...emptyPlan, remove: [ 'second' ] } );

		expect( guard.markNextChangeAsNotPersistent ).not.toHaveBeenCalled();
		expect( guard.removeBlocks ).not.toHaveBeenCalled();
		expect( guard.createNotice ).not.toHaveBeenCalled();
	} );

	// The unlock is a correction of its own and still landed, so its mark stands.
	it( 'still marks the unlock it dispatched before the refusal', () => {
		const guard = setUp( { canRemoveBlocks: () => false } );

		guard.apply( { ...emptyPlan, remove: [ 'second' ], unlockRemovals: [ 'second' ] } );

		expect( guard.markNextChangeAsNotPersistent ).toHaveBeenCalledTimes( 1 );
		expect( guard.updateBlockAttributes ).toHaveBeenCalledWith( [ 'second' ], { lock: undefined } );
		expect( guard.removeBlocks ).not.toHaveBeenCalled();
	} );

	// A notice about a card the post visibly still carries would be a lie.
	it( 'raises no notice when the removal was silently refused', () => {
		const guard = setUp( { removed: false } );

		guard.apply( { ...emptyPlan, remove: [ 'second' ] } );

		expect( guard.removeBlocks ).toHaveBeenCalled();
		expect( guard.createNotice ).not.toHaveBeenCalled();
	} );

	it( 'never asks the store about a removal it is not making', () => {
		const guard = setUp();

		guard.apply( { ...emptyPlan, lockChildren: [ 'copy' ] } );

		expect( guard.updateBlockAttributes ).toHaveBeenCalledWith( [ 'copy' ], { lock: CHILD_LOCK } );
		expect( guard.canRemoveBlocks ).not.toHaveBeenCalled();
	} );
} );

describe( 'createPromptCardHold', () => {
	const setUp = ( trees, { isPattern = () => false } = {} ) => {
		const { createPromptCardHold } = loadGuard();
		const apply = jest.fn();
		let index = 0;
		const reconcile = createPromptCardHold( {
			getBlocks: () => trees[ Math.min( index++, trees.length - 1 ) ],
			isPattern,
			apply,
		} );
		return { reconcile, apply };
	};

	it( 'applies the corrections a tree needs', () => {
		const { reconcile, apply } = setUp( [ [ instance( 'first' ), instance( 'second' ) ] ] );

		reconcile();

		expect( apply ).toHaveBeenCalledWith( expect.objectContaining( { remove: [ 'second' ] } ) );
	} );

	// The store ticks on every keystroke; only a new tree can carry a new card.
	it( 'reads nothing further while the tree is unchanged', () => {
		const { reconcile, apply } = setUp( [ [ instance( 'first' ), instance( 'second' ) ] ] );

		reconcile();
		reconcile();
		reconcile();

		expect( apply ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'applies nothing to a tree already in shape', () => {
		const { reconcile, apply } = setUp( [ [ settled( 'card' ) ] ] );

		reconcile();

		expect( apply ).not.toHaveBeenCalled();
	} );

	// The pattern's own card is not a detached copy: its locks are the ones every
	// instance inherits.
	it( 'leaves the pattern itself alone', () => {
		const pattern = [ detached( 'card', [ paragraph( 'copy', {} ) ], { lock: CHILD_LOCK } ) ];
		const { reconcile, apply } = setUp( [ pattern ], { isPattern: () => true } );

		reconcile();

		expect( apply ).not.toHaveBeenCalled();
	} );

	// The corrections re-enter the reconciler synchronously, on a tree they are
	// halfway through changing.
	it( 'ignores the tick its own corrections raise', () => {
		const { createPromptCardHold } = loadGuard();
		const tree = [ instance( 'first' ), instance( 'second' ) ];
		const hold = {};
		const apply = jest.fn( () => hold.reconcile() );
		hold.reconcile = createPromptCardHold( { getBlocks: () => tree, isPattern: () => false, apply } );

		hold.reconcile();

		expect( apply ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'corrects a card that lands after a clean pass', () => {
		const clean = [ instance( 'first' ) ];
		const duplicated = [ instance( 'first' ), instance( 'second' ) ];
		const { reconcile, apply } = setUp( [ clean, duplicated ] );

		reconcile();
		reconcile();

		expect( apply ).toHaveBeenCalledWith( expect.objectContaining( { remove: [ 'second' ] } ) );
	} );

	// The publisher's own prompt may be detached and hand-tailored, and pasting
	// above it makes the copy first in document order.
	it.each( [
		[ 'above', [ instance( 'new' ), settled( 'old' ) ] ],
		[ 'below', [ settled( 'old' ), instance( 'new' ) ] ],
	] )( 'removes the copy pasted %s the prompt the post already carried', ( label, pasted ) => {
		const { reconcile, apply } = setUp( [ [ settled( 'old' ) ], pasted ] );

		reconcile();
		reconcile();

		expect( apply ).toHaveBeenCalledWith( expect.objectContaining( { remove: [ 'new' ] } ) );
	} );

	// Saved before this guard existed: neither card is the one it kept last pass.
	it( 'keeps the first when the post opens carrying two', () => {
		const { reconcile, apply } = setUp( [ [ instance( 'first' ), instance( 'second' ) ] ] );

		reconcile();

		expect( apply ).toHaveBeenCalledWith( expect.objectContaining( { remove: [ 'second' ] } ) );
	} );

	// Client ids do not survive a reload, so a tree in which none of them is known
	// is a fresh open rather than a paste.
	it( 'falls back to document order when no id is known any more', () => {
		const { reconcile, apply } = setUp( [ [ instance( 'old' ) ], [ instance( 'a' ), instance( 'b' ) ] ] );

		reconcile();
		reconcile();

		expect( apply ).toHaveBeenCalledWith( expect.objectContaining( { remove: [ 'b' ] } ) );
	} );
} );
