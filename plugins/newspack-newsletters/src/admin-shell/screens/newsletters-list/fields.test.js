/**
 * The Status column offers its kinds as separate filters, so two of them drawing
 * the same mark leaves the reader unable to tell apart the results of two
 * different filters.
 *
 * The glyph behind each name is pinned in `packages/components`, which also pins
 * the only two pairs that share one: `active`/`done` and `cancelled`/`ended`. A
 * column keeps the rule by using distinct names and at most one half of a pair,
 * which is what this asserts. It cannot read the glyphs directly, because
 * `newspack-components` is stubbed for these tests.
 */

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

	// A sent newsletter is finished, not live, which is the distinction `done`
	// carries over `active`.
	it( 'reads a sent newsletter as finished', () => {
		expect( STATUS_KIND_STATUSES.sent ).toBe( 'done' );
	} );
} );
