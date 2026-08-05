/**
 * The inspector component itself, which the helper tests next door cannot reach:
 * they replace the whole block-editor module with an empty object. Here it is a
 * prop-recording stub instead, so the slot group the fill mounts into is
 * assertable — a selected pattern instance is a section block, and the inspector
 * mounts only the content and list groups for one.
 *
 * The block editor's ESM chain is still not transformable, so every editor
 * module the component pulls in is stubbed down to what it actually uses.
 */

/**
 * WordPress dependencies.
 */
import { render } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';

const mockInspectorProps = [];

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: props => {
		mockInspectorProps.push( props );
		return props.children;
	},
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
	select: jest.fn(),
} ) );

// Only `parse` is used, and only to look for the pattern's bound paragraph.
jest.mock( '@wordpress/blocks', () => ( { parse: () => [] } ) );

jest.mock( '@wordpress/api-fetch' );

jest.mock( '@wordpress/components', () => ( {
	Notice: ( { children } ) => <div>{ children }</div>,
	PanelBody: ( { children } ) => <section>{ children }</section>,
	Button: ( { children, onClick } ) => <button onClick={ onClick }>{ children }</button>,
	__experimentalVStack: ( { children } ) => <div>{ children }</div>,
} ) );

const PATTERN_ID = 12;

let withPromptInstanceInspector;

beforeAll( () => {
	window.newspack_popups_blocks_data = { contextual_prompts_pattern_id: String( PATTERN_ID ) };
	( { withPromptInstanceInspector } = require( './instance' ) );
} );

afterAll( () => {
	delete window.newspack_popups_blocks_data;
} );

beforeEach( () => {
	mockInspectorProps.length = 0;
	useSelect.mockReturnValue( { postId: 7, framing: 'top', patternContent: '', patternResolved: true, patternExists: true } );
	useDispatch.mockReturnValue( { updateBlockAttributes: jest.fn() } );
} );

const BlockEdit = jest.fn( () => <div>Block edit</div> );

// An instance already carrying copy, so nothing auto-generates while it renders.
const instanceProps = {
	name: 'core/block',
	clientId: 'prompt-1',
	attributes: { ref: PATTERN_ID, content: { 'Prompt Copy': { content: 'Support us.' } } },
};

describe( 'withPromptInstanceInspector', () => {
	it( 'mounts its fill in the content slot group for a prompt instance', () => {
		const Wrapped = withPromptInstanceInspector( BlockEdit );

		render( <Wrapped { ...instanceProps } /> );

		expect( mockInspectorProps.length ).toBeGreaterThan( 0 );
		expect( mockInspectorProps.map( props => props.group ) ).toEqual( mockInspectorProps.map( () => 'content' ) );
	} );

	it( 'renders the original block edit untouched for anything else', () => {
		const Wrapped = withPromptInstanceInspector( BlockEdit );

		const { container } = render( <Wrapped { ...instanceProps } name="core/paragraph" attributes={ {} } /> );

		expect( mockInspectorProps ).toHaveLength( 0 );
		expect( container.textContent ).toBe( 'Block edit' );
	} );

	// The widgets editor has no post to generate copy from, so the panel has
	// nothing to offer there.
	it( 'mounts nothing where there is no post to generate from', () => {
		useSelect.mockReturnValue( { postId: undefined, framing: null, patternContent: '', patternResolved: true, patternExists: true } );
		const Wrapped = withPromptInstanceInspector( BlockEdit );

		const { container } = render( <Wrapped { ...instanceProps } /> );

		expect( mockInspectorProps ).toHaveLength( 0 );
		expect( container.textContent ).toBe( 'Block edit' );
	} );
} );
