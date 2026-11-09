import { getColorForContrast, getMinimumDonation, usesSiteMinimumDonation } from './utils';

describe( 'usesSiteMinimumDonation', () => {
	// A manual block's own minimum is what its form enforces. Replacing it on
	// load was the bug that kept resetting publishers' per-block minimums.
	it.each( [ 1200, '1200', 0 ] )( 'keeps a manual block minimum of %p', minimumDonation => {
		expect( usesSiteMinimumDonation( { manual: true, minimumDonation } ) ).toBe( false );
	} );

	it.each( [ undefined, '' ] )( 'fills in a manual block minimum of %p', minimumDonation => {
		expect( usesSiteMinimumDonation( { manual: true, minimumDonation } ) ).toBe( true );
	} );

	// Without manual mode the front end ignores the block's minimum, so it should
	// track the site-wide value for whenever manual mode is turned on.
	it.each( [ undefined, false ] )( 'refreshes the minimum when manual is %p', manual => {
		expect( usesSiteMinimumDonation( { manual, minimumDonation: 5 } ) ).toBe( true );
	} );
} );

describe( 'getMinimumDonation', () => {
	it.each( [
		[ 1200, 1200 ],
		[ '1200', 1200 ],
		[ '12.5', 12.5 ],
		[ 0, 0 ],
	] )( 'reads %p as the minimum %p', ( value, expected ) => {
		expect( getMinimumDonation( value ) ).toBe( expected );
	} );

	// An unset minimum is what lets the editor fill in the site-wide value. A
	// number must never count as unset, or the block's own minimum is replaced.
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
		// APCA flip zone: WCAG2 said black, APCA says white decisively.
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
