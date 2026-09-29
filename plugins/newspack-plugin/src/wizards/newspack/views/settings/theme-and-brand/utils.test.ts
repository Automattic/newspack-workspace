/**
 * Internal dependencies
 */
import { headerLogoSize } from './utils';

// Expected sizes come from running newspack-theme's `newspack_logo_resize_min_max()` on the same inputs.
describe( 'headerLogoSize', () => {
	it.each( [
		[ 'landscape at the smallest size', 400, 100, 0, 192, 48 ],
		[ 'landscape at half size', 400, 100, 50, 296, 74 ],
		[ 'landscape at full size', 400, 100, 100, 400, 100 ],
		[ 'landscape wider than 600px at full size', 1200, 300, 100, 600, 150 ],
		[ 'landscape wider than 600px at a quarter size', 1200, 300, 26, 300, 75 ],
		[ 'portrait at half size', 100, 400, 50, 74, 296 ],
		[ 'portrait wider than 600px at full size', 800, 1600, 100, 600, 1200 ],
		[ 'a logo shorter than the 48px minimum', 96, 40, 50, 106, 44 ],
	] )( 'matches the theme for %s', ( _, width, height, percent, expectedWidth, expectedHeight ) => {
		expect( headerLogoSize( { width, height }, percent ) ).toEqual( { width: expectedWidth, height: expectedHeight } );
	} );
} );
