/**
 * The guard reaches the block editor through injected accessors, so the store
 * itself is never touched here; the module chain still pulls it in, and jest
 * does not transform its ESM.
 */
jest.mock( '@wordpress/block-editor', () => ( {} ) );

const { isEditingPattern, resolveEditedEntity, createLockHold } = require( './editor-locks' );

describe( 'isEditingPattern', () => {
	// getEditedPostId() returns the id as a string; the localized pattern id is a
	// number by the time it gets here.
	it.each( [
		[ 'the pattern, id as a string', 'wp_block', '232', 232, true ],
		[ 'the pattern, id as a number', 'wp_block', 232, 232, true ],
		[ 'another pattern', 'wp_block', '233', 232, false ],
		[ 'a template', 'wp_template', 'twentytwentyfour//home', 232, false ],
		[ 'a page carrying the same id', 'page', '232', 232, false ],
		[ 'nothing yet', undefined, undefined, 232, false ],
	] )( 'is %s → %s', ( label, postType, postId, patternId, expected ) => {
		expect( isEditingPattern( { postType, postId, patternId } ) ).toBe( expected );
	} );

	// An unseeded site localizes 0, which Number() would match against a missing
	// id.
	it.each( [
		[ 'zero', 0 ],
		[ 'undefined', undefined ],
	] )( 'never matches when the pattern id is %s', ( label, patternId ) => {
		expect( isEditingPattern( { postType: 'wp_block', postId: 0, patternId } ) ).toBe( false );
		expect( isEditingPattern( { postType: 'wp_block', postId: undefined, patternId } ) ).toBe( false );
	} );
} );

describe( 'resolveEditedEntity', () => {
	const siteEditorOn = ( postType, postId ) => ( {
		getEditedPostType: () => postType,
		getEditedPostId: () => postId,
	} );
	const editorOn = ( postType, postId ) => ( {
		getCurrentPostType: () => postType,
		getCurrentPostId: () => postId,
	} );

	it( 'reads the Site Editor route when there is one', () => {
		expect( resolveEditedEntity( { siteEditor: siteEditorOn( 'wp_block', '232' ), editor: editorOn( 'page', 12 ) } ) ).toEqual( {
			postType: 'wp_block',
			postId: '232',
		} );
	} );

	// Pattern focus mode swaps the post editor's own entity for the pattern.
	it( 'reads the post editor when the Site Editor is not loaded', () => {
		expect( resolveEditedEntity( { siteEditor: undefined, editor: editorOn( 'wp_block', 232 ) } ) ).toEqual( {
			postType: 'wp_block',
			postId: 232,
		} );
	} );

	it( 'reads the post the post editor started on', () => {
		expect( resolveEditedEntity( { siteEditor: undefined, editor: editorOn( 'post', 29 ) } ) ).toEqual( {
			postType: 'post',
			postId: 29,
		} );
	} );

	// A Site Editor store that answers neither selector must not contribute half
	// an answer for the post editor's id to pair with.
	it.each( [
		[ 'answers with nothing', {} ],
		[ 'has dropped the selectors', { getEditedPostType: undefined } ],
		[ 'is not loaded', undefined ],
	] )( 'falls through whole when the Site Editor %s', ( label, siteEditor ) => {
		expect( resolveEditedEntity( { siteEditor, editor: editorOn( 'post', 29 ) } ) ).toEqual( {
			postType: 'post',
			postId: 29,
		} );
	} );

	it( 'resolves to nothing when no editor answers', () => {
		expect( resolveEditedEntity( {} ) ).toEqual( { postType: undefined, postId: undefined } );
	} );
} );

describe( 'createLockHold', () => {
	const setup = ( { canLockBlocks = true, editing = false } = {} ) => {
		const state = { canLockBlocks, editing };
		const setCanLockBlocks = jest.fn( value => {
			state.canLockBlocks = value;
		} );
		const reconcile = createLockHold( {
			isEditingPattern: () => state.editing,
			getCanLockBlocks: () => state.canLockBlocks,
			setCanLockBlocks,
		} );
		return { state, setCanLockBlocks, reconcile };
	};

	it( 'holds locking off while the pattern is open', () => {
		const { state, setCanLockBlocks, reconcile } = setup( { editing: true } );
		reconcile();
		expect( setCanLockBlocks ).toHaveBeenCalledWith( false );
		expect( state.canLockBlocks ).toBe( false );
	} );

	it( 'leaves other entities alone', () => {
		const { state, setCanLockBlocks, reconcile } = setup();
		reconcile();
		expect( setCanLockBlocks ).not.toHaveBeenCalled();
		expect( state.canLockBlocks ).toBe( true );
	} );

	// The subscription runs on every store change; only a change of route may
	// write.
	it( 'does not rewrite the setting it already holds', () => {
		const { setCanLockBlocks, reconcile } = setup( { editing: true } );
		reconcile();
		reconcile();
		reconcile();
		expect( setCanLockBlocks ).toHaveBeenCalledTimes( 1 );
	} );

	// The Site Editor re-pushes its own settings as it renders, which would hand
	// the publisher an Unlock button mid-session.
	it( 'reasserts the hold when something restores the setting', () => {
		const { state, setCanLockBlocks, reconcile } = setup( { editing: true } );
		reconcile();
		state.canLockBlocks = true;
		reconcile();
		expect( setCanLockBlocks ).toHaveBeenCalledTimes( 2 );
		expect( state.canLockBlocks ).toBe( false );
	} );

	// Navigating on to another pattern in the same session must not inherit the
	// lockdown.
	it( 'restores the setting on the way out', () => {
		const { state, setCanLockBlocks, reconcile } = setup( { editing: true } );
		reconcile();
		state.editing = false;
		reconcile();
		expect( setCanLockBlocks ).toHaveBeenLastCalledWith( true );
		expect( state.canLockBlocks ).toBe( true );
	} );

	it( 'restores it again after navigating back and forth', () => {
		const { state, setCanLockBlocks, reconcile } = setup( { editing: true } );
		reconcile();
		state.editing = false;
		reconcile();
		state.editing = true;
		reconcile();
		expect( state.canLockBlocks ).toBe( false );
		state.editing = false;
		reconcile();
		expect( state.canLockBlocks ).toBe( true );
		expect( setCanLockBlocks ).toHaveBeenCalledTimes( 4 );
	} );

	// Restoring means as found, not true: another route may have turned locking
	// off for reasons of its own.
	it( 'restores what it found rather than assuming', () => {
		const { state, setCanLockBlocks, reconcile } = setup( { canLockBlocks: false, editing: true } );
		reconcile();
		expect( setCanLockBlocks ).not.toHaveBeenCalled();
		state.editing = false;
		reconcile();
		expect( setCanLockBlocks ).toHaveBeenCalledWith( false );
	} );

	it( 'stays quiet when it never held anything', () => {
		const { setCanLockBlocks, reconcile } = setup();
		reconcile();
		reconcile();
		expect( setCanLockBlocks ).not.toHaveBeenCalled();
	} );
} );
