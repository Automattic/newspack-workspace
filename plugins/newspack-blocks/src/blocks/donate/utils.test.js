import { getColorForContrast, usesSiteButtonStyle } from './utils';

describe( 'getColorForContrast', () => {
	// Must stay in parity with Newspack_Blocks::get_color_for_contrast(), which
	// runs the same APCA math (see tests/test-color-contrast.php).
	it.each( [
		[ '#000000', '#ffffff' ],
		[ '#ffffff', '#000000' ],
		[ '#f0f0f0', '#000000' ],
		[ '#ffcc00', '#000000' ],
		// White reads better by APCA (Lc 74 against 36) though only black reaches 4.5:1.
		[ '#178f15', '#ffffff' ],
		[ '#3366cc', '#ffffff' ],
		[ '#dd3333', '#ffffff' ],
		[ '#fff', '#000000' ],
		[ '#36c', '#ffffff' ],
		[ 'not-a-color', '#000000' ],
		// Parity cases shared with the PHP fixture table.
		[ '3366cc', '#ffffff' ],
		[ '178f15', '#ffffff' ],
		[ '', '#000000' ],
		[ '#33333380', '#ffffff' ],
		[ '#FFCC00', '#000000' ],
		[ ' #178f15 ', '#ffffff' ],
		// White reads better by APCA (Lc 70 against 40) though only black reaches 4.5:1.
		[ '#e65100', '#ffffff' ],
	] )( 'picks readable text for %s', ( background, expected ) => {
		expect( getColorForContrast( background ) ).toBe( expected );
	} );

	it( 'falls back to black when no color is provided', () => {
		expect( getColorForContrast( undefined ) ).toBe( '#000000' );
	} );

	it( 'expands 3-digit hex the same as 6-digit hex', () => {
		expect( getColorForContrast( '#36c' ) ).toBe( getColorForContrast( '#3366cc' ) );
		expect( getColorForContrast( '#fff' ) ).toBe( getColorForContrast( '#ffffff' ) );
	} );
} );

describe( 'usesSiteButtonStyle', () => {
	it( 'is true for the default colour on a block theme, ignoring case', () => {
		expect( usesSiteButtonStyle( '#dd3333', true ) ).toBe( true );
		expect( usesSiteButtonStyle( '#DD3333', true ) ).toBe( true );
	} );

	it( 'is false for a picked colour', () => {
		expect( usesSiteButtonStyle( '#6c6c6c', true ) ).toBe( false );
		expect( usesSiteButtonStyle( undefined, true ) ).toBe( false );
	} );

	it( 'is false off a block theme', () => {
		expect( usesSiteButtonStyle( '#dd3333', false ) ).toBe( false );
	} );
} );
