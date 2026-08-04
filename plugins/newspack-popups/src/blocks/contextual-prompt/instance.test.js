/**
 * The inspector pulls in the block editor's ESM chain, which jest does not
 * transform; the helpers under test need none of it.
 */
jest.mock( '@wordpress/block-editor', () => ( {} ) );

// The pattern markup is parsed with the block registry, so the two block types
// it uses have to exist — with the `metadata` attribute the block editor's own
// hook adds to every block type at registration, and a `save` reproducing the
// markup below, so parsing raises no validation warnings. Both modules are the
// freshly required ones: the serializer identifies `RawHTML` and passes inner
// blocks through module state, neither of which survives a registry reset.
const registerStubBlocks = ( { registerBlockType, __unstableGetInnerBlocksProps: getInnerBlocksProps }, { createElement } ) => {
	registerBlockType( 'core/paragraph', {
		apiVersion: 3,
		title: 'Paragraph',
		category: 'text',
		attributes: { content: { type: 'string', source: 'html', selector: 'p', default: '' }, metadata: { type: 'object' } },
		save: ( { attributes } ) => createElement( 'p', null, attributes.content ),
	} );
	registerBlockType( 'core/group', {
		apiVersion: 3,
		title: 'Group',
		category: 'design',
		attributes: { metadata: { type: 'object' } },
		save: () => createElement( 'div', getInnerBlocksProps( { className: 'wp-block-group' } ) ),
	} );
};

// The pattern id is read once at import, so each case loads the module afresh
// against its own localized globals.
const loadInstance = ( { blocksData, panelData } = {} ) => {
	jest.resetModules();
	window.newspack_popups_blocks_data = blocksData;
	window.newspackPopupsContextualPrompt = panelData;
	registerStubBlocks( require( '@wordpress/blocks' ), require( '@wordpress/element' ) );
	return require( './instance' );
};

const paragraph = ( attrs, content = '' ) => `<!-- wp:paragraph ${ JSON.stringify( attrs ) } -->\n<p>${ content }</p>\n<!-- /wp:paragraph -->`;

const BOUND_ATTRS = { metadata: { name: 'Prompt copy', bindings: { __default: { source: 'core/pattern-overrides' } } } };

const group = inner =>
	`<!-- wp:group {"metadata":{"name":"Contextual Prompt"}} -->\n<div class="wp-block-group">${ inner }</div>\n<!-- /wp:group -->`;

afterEach( () => {
	delete window.newspack_popups_blocks_data;
	delete window.newspackPopupsContextualPrompt;
} );

describe( 'isPromptInstance', () => {
	// wp_localize_script stringifies scalars, so the localized id arrives as a
	// string while the block attribute is a number.
	it.each( [
		[ 'a reference to the pattern', 'core/block', { ref: 12 }, true ],
		[ 'a reference stored as a string', 'core/block', { ref: '12' }, true ],
		[ 'another block carrying the same ref', 'core/paragraph', { ref: 12 }, false ],
		[ 'a reference to another pattern', 'core/block', { ref: 13 }, false ],
		[ 'a reference to nothing', 'core/block', { ref: 0 }, false ],
		[ 'no ref at all', 'core/block', {}, false ],
		[ 'undefined attributes', 'core/block', undefined, false ],
	] )( 'is %s → %s', ( label, name, attributes, expected ) => {
		const { isPromptInstance } = loadInstance( { blocksData: { contextual_prompts_pattern_id: '12' } } );
		expect( isPromptInstance( name, attributes ) ).toBe( expected );
	} );

	it( 'reads the id from the document-settings global', () => {
		const { isPromptInstance } = loadInstance( { panelData: { patternId: '12' } } );
		expect( isPromptInstance( 'core/block', { ref: 12 } ) ).toBe( true );
	} );

	// An unseeded site localizes 0; no block may match it.
	it.each( [
		[ 'the id is zero', { blocksData: { contextual_prompts_pattern_id: '0' } } ],
		[ 'nothing is localized', undefined ],
	] )( 'never matches when %s', ( label, globals ) => {
		const { isPromptInstance } = loadInstance( globals );
		expect( isPromptInstance( 'core/block', { ref: 0 } ) ).toBe( false );
		expect( isPromptInstance( 'core/block', { ref: 12 } ) ).toBe( false );
	} );
} );

describe( 'findBoundName', () => {
	it( 'finds the bound paragraph name', () => {
		const { findBoundName } = loadInstance();
		expect( findBoundName( group( paragraph( BOUND_ATTRS ) ) ) ).toBe( 'Prompt copy' );
	} );

	// The name is the publisher's to change in the pattern, so it is read from
	// the record rather than assumed. Repair pins it back server-side, but a
	// record read before that has to be taken at its word.
	it( 'finds a renamed bound paragraph', () => {
		const { findBoundName } = loadInstance();
		const attrs = { metadata: { ...BOUND_ATTRS.metadata, name: 'Story copy' } };
		expect( findBoundName( group( paragraph( attrs ) ) ) ).toBe( 'Story copy' );
	} );

	it( 'skips paragraphs that are not bound', () => {
		const { findBoundName } = loadInstance();
		const unbound = paragraph( { metadata: { name: 'Heading copy' } }, 'Static.' );
		expect( findBoundName( group( unbound + paragraph( BOUND_ATTRS ) ) ) ).toBe( 'Prompt copy' );
	} );

	// Null is what the callers gate on: there is nowhere to put copy, so a name
	// guessed here would key an override nothing reads.
	it.each( [
		[ 'an empty string', '' ],
		[ 'undefined', undefined ],
		[ 'null', null ],
		[ 'markup with no blocks', '<p>Just some HTML.</p>' ],
		[ 'a pattern with no bound paragraph', group( paragraph( { metadata: { name: 'Heading copy' } }, 'Static.' ) ) ],
		[
			'a binding to another source',
			group( paragraph( { metadata: { name: 'Meta copy', bindings: { __default: { source: 'core/post-meta' } } } } ) ),
		],
		[ 'a bound paragraph with no name', group( paragraph( { metadata: { bindings: { __default: { source: 'core/pattern-overrides' } } } } ) ) ],
	] )( 'returns null for %s', ( label, content ) => {
		const { findBoundName } = loadInstance();
		expect( findBoundName( content ) ).toBeNull();
	} );
} );

describe( 'buildOverrideAttrs', () => {
	it( 'keys the override by the bound name', () => {
		const { buildOverrideAttrs } = loadInstance();
		expect( buildOverrideAttrs( 'Story copy', 'Support us.' ) ).toEqual( { content: { 'Story copy': { content: 'Support us.' } } } );
	} );

	// The override is stored in a RichText attribute, which serializes strings as
	// raw HTML.
	it( 'encodes markup in the body', () => {
		const { buildOverrideAttrs } = loadInstance();
		const attrs = buildOverrideAttrs( 'Prompt copy', '<script>alert("xss")</script>' );
		expect( attrs.content[ 'Prompt copy' ].content ).toBe( '&lt;script>alert("xss")&lt;/script>' );
		expect( attrs.content[ 'Prompt copy' ].content ).not.toMatch( /</ );
	} );
} );

describe( 'shouldAutoGenerate', () => {
	// An empty instance in a post that can be generated from, whose pattern has
	// resolved with somewhere to put the copy, in a framing not yet tried.
	const ready = {
		canGenerate: true,
		overrideIsEmpty: true,
		patternResolved: true,
		boundName: 'Prompt copy',
		attempted: false,
	};

	it( 'generates for an empty instance with a resolved bound pattern', () => {
		const { shouldAutoGenerate } = loadInstance();
		expect( shouldAutoGenerate( ready ) ).toBe( true );
	} );

	// The pattern gates it twice over: an unresolved record would apply the
	// fallback name, and a pattern with no binding has nowhere to put the copy —
	// generating either way spends a request on an override nothing reads.
	it.each( [
		[ 'the editor has no post to generate from', { canGenerate: false } ],
		[ 'the instance already carries copy', { overrideIsEmpty: false } ],
		[ 'the pattern record has not resolved', { patternResolved: false } ],
		[ 'the pattern has no bound paragraph', { boundName: null } ],
		[ 'the bound name is empty', { boundName: '' } ],
		[ 'this framing was already tried', { attempted: true } ],
	] )( 'does not generate when %s', ( label, override ) => {
		const { shouldAutoGenerate } = loadInstance();
		expect( shouldAutoGenerate( { ...ready, ...override } ) ).toBe( false );
	} );
} );
