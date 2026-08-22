// `newspack-components` is stubbed for these tests, so the glyphs are unreachable
// and the rule is asserted on names. `packages/components` pins the only two pairs
// that share a mark, so a column keeps the rule by using at most one half of a pair.

import { STATUS_KIND_STATUSES } from './fields';

const SHARED_MARKS = [
	[ 'active', 'done' ],
	[ 'cancelled', 'ended' ],
];

describe( 'STATUS_KIND_STATUSES', () => {
	it( 'names every kind the list can report', () => {
		expect( STATUS_KIND_STATUSES ).toEqual( { sent: 'done', scheduled: 'scheduled', draft: 'draft', trash: 'trash' } );
	} );

	it( 'gives no two kinds the same mark', () => {
		const names = Object.values( STATUS_KIND_STATUSES );
		expect( new Set( names ).size ).toBe( names.length );
		SHARED_MARKS.forEach( pair => expect( pair.filter( name => names.includes( name ) ).length ).toBeLessThan( 2 ) );
	} );

	it( 'reads a sent newsletter as finished', () => {
		expect( STATUS_KIND_STATUSES.sent ).toBe( 'done' );
	} );
} );
