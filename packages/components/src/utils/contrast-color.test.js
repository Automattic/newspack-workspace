import { getContrastColor, getContrastColorFromRGB } from './contrast-color';

describe( 'getContrastColor', () => {
	it.each( [
		[ '#000000', 'white' ],
		[ '#ffffff', 'black' ],
		[ '#f0f0f0', 'black' ],
		[ '#ffcc00', 'black' ],
		[ '#178f15', 'white' ],
		[ '#3366cc', 'white' ],
		[ '#dd3333', 'white' ],
		[ '#e65100', 'white' ],
		[ 'E65100', 'white' ],
		[ '#fff', 'black' ],
		[ '#000', 'white' ],
		[ '#3366CCFF', 'white' ],
		[ 'nonsense', 'black' ],
	] )( '%s gets %s text', ( color, expected ) => {
		expect( getContrastColor( color ) ).toBe( expected );
	} );

	it( 'accepts RGB channels', () => {
		expect( getContrastColorFromRGB( [ 0x33, 0x66, 0xcc ] ) ).toBe( 'white' );
		expect( getContrastColorFromRGB( [ 0xe6, 0x51, 0x00 ] ) ).toBe( 'white' );
		expect( getContrastColorFromRGB( [ 0xff, 0xcc, 0x00 ] ) ).toBe( 'black' );
	} );
} );
