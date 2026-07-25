import { framingForPosition } from './candidates';

describe( 'framingForPosition', () => {
	// Boundary cases mirroring get_placement() in
	// class-newspack-popups-contextual-prompt-block.php: the two must agree.
	it.each( [
		[ 0, 1, 'top' ],
		[ 0, 4, 'top' ],
		[ 1, 4, 'top' ], // ratio exactly 1/3.
		[ 2, 4, 'end' ], // ratio exactly 2/3.
		[ 3, 4, 'end' ],
		[ 2, 6, 'mid' ],
		[ 0, 10, 'top' ],
		[ 3, 10, 'top' ], // 3/9 = 1/3.
		[ 4, 10, 'mid' ],
		[ 6, 10, 'end' ], // 6/9 = 2/3.
		[ 9, 10, 'end' ],
	] )( 'index %i of %i → %s', ( index, total, expected ) => {
		expect( framingForPosition( index, total ) ).toEqual( expected );
	} );
} );
