import { dropsMinimumDonation, getColorForContrast, getMinimumDonation } from './utils';

describe( 'dropsMinimumDonation', () => {
	// A manual block's minimum is what its form enforces, so loading the editor
	// must never change it. Without one, the block follows the site-wide minimum.
	it.each( [ 1200, '1200', 0, undefined ] )( 'keeps a manual block minimum of %p', minimumDonation => {
		expect( dropsMinimumDonation( { manual: true, minimumDonation } ) ).toBe( false );
	} );

	// Only manual blocks use their minimum on the front end. Dropping it from the
	// others means turning manual mode on starts out following the site minimum.
	it.each( [ undefined, false ] )( 'drops a stored minimum when manual is %p', manual => {
		expect( dropsMinimumDonation( { manual, minimumDonation: 5 } ) ).toBe( true );
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
