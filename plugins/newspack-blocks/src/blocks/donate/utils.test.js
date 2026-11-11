import { getColorForContrast, getMinimumDonation } from './utils';

describe( 'getMinimumDonation', () => {
	it.each( [
		[ 1200, 1200 ],
		[ '1200', 1200 ],
		[ '12.5', 12.5 ],
		[ 0, 0 ],
	] )( 'reads %p as the minimum %p', ( value, expected ) => {
		expect( getMinimumDonation( value ) ).toBe( expected );
	} );

	// An unset minimum leaves the block following the site-wide one. A number
	// must never count as unset, or the block's own minimum is dropped.
	it.each( [ undefined, null, '', 'abc' ] )( 'treats %p as no minimum', value => {
		expect( getMinimumDonation( value ) ).toBeUndefined();
	} );
} );

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
