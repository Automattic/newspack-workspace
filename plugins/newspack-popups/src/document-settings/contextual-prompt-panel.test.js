/**
 * The document-settings panel's copy-application decision: an override may only
 * be keyed by the name the pattern actually binds, so a record that resolved
 * without one — the fetch failed, or the binding was removed — offers nothing to
 * generate or apply.
 *
 * The editor's ESM chain is not transformable, so every editor module the panel
 * pulls in is stubbed down to what it actually uses.
 */

/**
 * WordPress dependencies.
 */
import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

// The pattern record's markup, as the panel's bound-name lookup parses it.
let mockParsed = [];

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
	select: () => ( { getEditedPostContent: () => '' } ),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	parse: () => mockParsed,
	createBlock: ( name, attributes ) => ( { name, attributes } ),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: () => null,
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/edit-post', () => ( {
	PluginDocumentSettingPanel: ( { children } ) => <section>{ children }</section>,
} ) );

jest.mock( '@wordpress/api-fetch' );

jest.mock( '@wordpress/components', () => ( {
	Notice: ( { children } ) => <div>{ children }</div>,
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
	__experimentalVStack: ( { children } ) => <div>{ children }</div>,
} ) );

const PATTERN_ID = 12;
const NO_COPY_FIELD = 'The Contextual Prompt pattern has no editable copy field, so generated copy cannot be applied.';

const bound = {
	name: 'core/paragraph',
	attributes: { metadata: { name: 'Prompt copy', bindings: { __default: { source: 'core/pattern-overrides' } } } },
	innerBlocks: [],
};
const unbound = { name: 'core/paragraph', attributes: { metadata: { name: 'Prompt copy' } }, innerBlocks: [] };

let ContextualPromptPanel;
const dispatchers = {};

beforeAll( () => {
	window.newspackPopupsContextualPrompt = { enabled: true, patternId: String( PATTERN_ID ) };
	ContextualPromptPanel = require( './contextual-prompt-panel' ).default;
} );

afterAll( () => {
	delete window.newspackPopupsContextualPrompt;
} );

beforeEach( () => {
	mockParsed = [ bound ];
	dispatchers.insertBlock = jest.fn();
	dispatchers.updateBlockAttributes = jest.fn();
	dispatchers.selectBlock = jest.fn();
	useDispatch.mockReturnValue( dispatchers );
	useSelect.mockReturnValue( {
		postId: 7,
		postType: 'post',
		blockCount: 3,
		instance: null,
		instanceFraming: null,
		patternContent: '<!-- wp:group /-->',
		patternResolved: true,
	} );
	apiFetch.mockResolvedValue( { candidates: [ { body: 'Support us.', framing: 'top' } ] } );
} );

describe( 'ContextualPromptPanel', () => {
	it( 'offers generation when the pattern binds a copy field', () => {
		render( <ContextualPromptPanel /> );

		expect( screen.getByText( 'Generate Suggestions' ) ).toBeTruthy();
		expect( screen.queryByText( NO_COPY_FIELD ) ).toBeNull();
	} );

	// hasFinishedResolution() is true for a failed fetch too, so "resolved" alone
	// is no licence to write an override.
	it.each( [
		[ 'the pattern binds nothing', [ unbound ] ],
		[ 'the record resolved to nothing', [] ],
	] )( 'warns and offers no generation when %s', ( label, parsed ) => {
		mockParsed = parsed;

		render( <ContextualPromptPanel /> );

		expect( screen.getByText( NO_COPY_FIELD ) ).toBeTruthy();
		expect( screen.queryByText( 'Generate Suggestions' ) ).toBeNull();
	} );

	it( 'inserts an instance keyed by the name the pattern binds', async () => {
		render( <ContextualPromptPanel /> );

		fireEvent.click( screen.getByText( 'Generate Suggestions' ) );
		fireEvent.click( await screen.findByText( 'Apply' ) );

		expect( dispatchers.insertBlock ).toHaveBeenCalledWith(
			{ name: 'core/block', attributes: { ref: PATTERN_ID, content: { 'Prompt copy': { content: 'Support us.' } } } },
			0
		);
	} );

	// Nothing is applied until the record has resolved: the candidates are held
	// back rather than written under a name that may not be the pattern's.
	it( 'lists no candidates while the pattern record is unresolved', async () => {
		useSelect.mockReturnValue( {
			postId: 7,
			postType: 'post',
			blockCount: 3,
			instance: null,
			instanceFraming: null,
			patternContent: '',
			patternResolved: false,
		} );

		render( <ContextualPromptPanel /> );

		fireEvent.click( screen.getByText( 'Generate Suggestions' ) );
		await screen.findByText( 'Regenerate Suggestions' );

		expect( screen.queryByText( 'Apply' ) ).toBeNull();
		expect( dispatchers.insertBlock ).not.toHaveBeenCalled();
	} );
} );
