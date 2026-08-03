// @wordpress/block-editor pulls in an untransformed ES module (parsel-js).
jest.mock( '@wordpress/block-editor', () => ( {} ) );

// The editor payload is read at import time, so each case reloads the module.
const templateWith = data => {
	jest.resetModules();
	window.newspack_popups_blocks_data = data;
	return require( './edit' ).getTemplate();
};

describe( 'getTemplate', () => {
	it( 'inserts the donate block on a donations-native site', () => {
		expect( templateWith( { donations_native: true } )[ 1 ][ 0 ] ).toBe( 'newspack-blocks/donate' );
	} );

	it( 'seeds the button CTA from the site-wide label and destination', () => {
		const template = templateWith( {
			donations_native: false,
			contextual_prompts_button_text: 'Support us',
			contextual_prompts_button_url: 'https://example.com/give/',
		} );

		expect( template[ 1 ][ 2 ][ 0 ][ 1 ] ).toEqual( { text: 'Support us', url: 'https://example.com/give/' } );
	} );

	it( 'falls back to Donate and no destination when neither is set', () => {
		const template = templateWith( { donations_native: false } );

		expect( template[ 1 ][ 2 ][ 0 ][ 1 ] ).toEqual( { text: 'Donate', url: undefined } );
	} );
} );
