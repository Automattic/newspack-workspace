// @wordpress/block-editor pulls in an untransformed ES module (parsel-js).
jest.mock( '@wordpress/block-editor', () => ( {} ) );

// newspack-colors resolves to a Sass module, which jest cannot parse.
jest.mock( 'newspack-colors', () => ( {} ) );

jest.mock( '@wordpress/blocks' );

// The editor payload is read at import time, so each case reloads the module.
const registerWith = data => {
	jest.resetModules();
	window.newspack_popups_blocks_data = data;
	const { registerBlockType } = require( '@wordpress/blocks' );
	require( './index' ).registerContextualPromptBlock();
	return registerBlockType.mock.calls;
};

const supportsWith = data => registerWith( { contextual_prompts_insertable: true, ...data } )[ 0 ][ 1 ].supports;

describe( 'registerContextualPromptBlock', () => {
	it( 'defaults a button CTA to the flex column core hangs its orientation toggle off', () => {
		const { layout } = supportsWith( { donations_native: false } );

		expect( layout.default ).toEqual( { type: 'flex', orientation: 'vertical', justifyContent: 'stretch' } );
		expect( layout.allowOrientation ).toBe( true );
		expect( layout.allowVerticalAlignment ).toBe( true );
		expect( layout.allowSwitching ).toBe( false );
		expect( layout.allowJustification ).toBe( true );
	} );

	it( 'keeps flow layout for the donate form, which has nothing to flow alongside', () => {
		const { layout } = supportsWith( { donations_native: true } );

		expect( layout.default ).toEqual( { type: 'default' } );
		expect( layout.allowOrientation ).toBe( false );
		expect( layout.allowSwitching ).toBe( false );
		expect( layout.allowJustification ).toBe( false );
	} );

	it( 'treats a missing flag as donations-native', () => {
		expect( supportsWith( {} ).layout.default ).toEqual( { type: 'default' } );
	} );

	it( 'registers nothing inside a prompt', () => {
		expect( registerWith( { is_prompt: true } ) ).toHaveLength( 0 );
	} );
} );
