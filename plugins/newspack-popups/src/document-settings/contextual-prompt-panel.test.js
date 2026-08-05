/**
 * The document-settings panel's copy-application decision: an override may only
 * be keyed by the name the pattern actually binds, so a record that resolved
 * without one — the fetch failed, or the binding was removed — offers nothing to
 * generate or apply. A card detached from the pattern is keyed by nothing: its
 * copy is written straight to its own paragraph.
 *
 * The editor's ESM chain is not transformable, so every editor module the panel
 * pulls in is stubbed down to what it actually uses.
 */

/**
 * WordPress dependencies.
 */
import { act, render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

// The pattern record's markup, as the panel's bound-name lookup parses it.
let mockParsed = [];

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
	select: () => ( { getEditedPostContent: () => '' } ),
	dispatch: () => ( { createSuccessNotice: jest.fn() } ),
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

jest.mock( '@wordpress/components', () => {
	const { forwardRef } = require( 'react' );
	return {
		Notice: ( { children } ) => <div>{ children }</div>,
		Button: ( { children, onClick, disabled } ) => (
			<button onClick={ onClick } disabled={ disabled }>
				{ children }
			</button>
		),
		// A suggestion card is the radio the publisher picks before applying.
		Card: forwardRef( ( props, ref ) => (
			<div
				ref={ ref }
				role={ props.role }
				aria-checked={ props[ 'aria-checked' ] }
				tabIndex={ props.tabIndex }
				onClick={ props.onClick }
				onKeyDown={ props.onKeyDown }
			>
				{ props.children }
			</div>
		) ),
		CardBody: ( { children } ) => <div>{ children }</div>,
		__experimentalVStack: ( { children } ) => <div>{ children }</div>,
	};
} );

const PATTERN_ID = 12;
const NO_COPY_FIELD = 'The Contextual Prompt pattern has no editable copy field, so generated copy cannot be applied.';
const HAS_PROMPT = 'This post has a Contextual Prompt. Edit its copy directly in the post.';

// No prompt in the post: the panel offers to insert one.
const NO_PROMPT = {
	postId: 7,
	postType: 'post',
	blockCount: 3,
	promptClientId: null,
	promptDetached: false,
	promptCopyClientId: null,
	promptFraming: null,
	patternContent: '<!-- wp:group /-->',
	patternResolved: true,
	patternExists: true,
};

// A card the publisher detached from the pattern, copy paragraph and all.
const DETACHED = { ...NO_PROMPT, promptClientId: 'card', promptDetached: true, promptCopyClientId: 'copy', promptFraming: 'top' };

const bound = {
	name: 'core/paragraph',
	attributes: { metadata: { name: 'Prompt Copy', bindings: { __default: { source: 'core/pattern-overrides' } } } },
	innerBlocks: [],
};
const unbound = { name: 'core/paragraph', attributes: { metadata: { name: 'Prompt Copy' } }, innerBlocks: [] };

let ContextualPromptPanel;
const dispatchers = {};

// A suggestion is picked from the list, then applied with the one Apply
// button. The application lands only after the busy hold plays out.
const pickAndApply = async () => {
	fireEvent.click( await screen.findByRole( 'radio' ) );
	jest.useFakeTimers();
	fireEvent.click( screen.getByText( 'Apply' ) );
	await act( async () => {
		jest.advanceTimersByTime( 900 );
	} );
	jest.useRealTimers();
};

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
	useSelect.mockReturnValue( NO_PROMPT );
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

	// A pattern that has gone — the site opted out while this editor was open —
	// is not a pattern missing a copy field: the panel says nothing rather than
	// blaming the design, and the wizard is where the feature's state is told.
	it( 'renders nothing when the pattern is gone', () => {
		useSelect.mockReturnValue( { ...NO_PROMPT, patternContent: '', patternExists: false } );

		const { container } = render( <ContextualPromptPanel /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'inserts an instance keyed by the name the pattern binds', async () => {
		render( <ContextualPromptPanel /> );

		fireEvent.click( screen.getByText( 'Generate Suggestions' ) );
		expect( await screen.findByText( 'Apply' ) ).toBeDisabled();
		await pickAndApply();

		expect( dispatchers.insertBlock ).toHaveBeenCalledWith(
			{ name: 'core/block', attributes: { ref: PATTERN_ID, content: { 'Prompt Copy': { content: 'Support us.' } } } },
			0
		);
	} );

	// Nothing is applied until the record has resolved: the candidates are held
	// back rather than written under a name that may not be the pattern's.
	it( 'lists no candidates while the pattern record is unresolved', async () => {
		useSelect.mockReturnValue( { ...NO_PROMPT, patternContent: '', patternResolved: false } );

		render( <ContextualPromptPanel /> );

		fireEvent.click( screen.getByText( 'Generate Suggestions' ) );
		await screen.findByText( 'Regenerate Suggestions' );

		expect( screen.queryByRole( 'radio' ) ).toBeNull();
		expect( screen.queryByText( 'Apply' ) ).toBeNull();
		expect( dispatchers.insertBlock ).not.toHaveBeenCalled();
	} );

	// Detaching a prompt breaks its reference to the pattern, not its place in
	// the post: it is still the one prompt the post has.
	it( "reports a detached card as the post's prompt", () => {
		useSelect.mockReturnValue( DETACHED );

		render( <ContextualPromptPanel /> );

		expect( screen.getByText( HAS_PROMPT ) ).toBeTruthy();
		expect( screen.getByText( 'Regenerate Suggestions' ) ).toBeTruthy();
	} );

	it( "rewrites a detached card's copy rather than inserting a second prompt", async () => {
		useSelect.mockReturnValue( DETACHED );

		render( <ContextualPromptPanel /> );
		fireEvent.click( screen.getByText( 'Regenerate Suggestions' ) );
		await pickAndApply();

		expect( dispatchers.updateBlockAttributes ).toHaveBeenCalledWith( 'copy', { content: 'Support us.' } );
		expect( dispatchers.selectBlock ).toHaveBeenCalledWith( 'card' );
		expect( dispatchers.insertBlock ).not.toHaveBeenCalled();
	} );

	// The pattern's binding keys an override; a detached card has none to key,
	// so what the pattern does or does not bind cannot gate it.
	it( 'applies to a detached card whatever the pattern binds', async () => {
		mockParsed = [ unbound ];
		useSelect.mockReturnValue( DETACHED );

		render( <ContextualPromptPanel /> );
		fireEvent.click( screen.getByText( 'Regenerate Suggestions' ) );
		await pickAndApply();

		expect( screen.queryByText( NO_COPY_FIELD ) ).toBeNull();
		expect( dispatchers.updateBlockAttributes ).toHaveBeenCalledWith( 'copy', { content: 'Support us.' } );
	} );

	// A card whose copy paragraph was deleted has nowhere to put the result.
	it( 'applies nothing to a detached card with no copy paragraph', async () => {
		useSelect.mockReturnValue( { ...DETACHED, promptCopyClientId: null } );

		render( <ContextualPromptPanel /> );
		fireEvent.click( screen.getByText( 'Regenerate Suggestions' ) );
		await screen.findByText( 'Regenerate Suggestions' );

		expect( screen.queryByText( 'Apply' ) ).toBeNull();
		expect( dispatchers.updateBlockAttributes ).not.toHaveBeenCalled();
	} );
} );
