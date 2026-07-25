/**
 * Seat arithmetic for the group flows.
 *
 * The trap these pin down is that a seat limit of 0 means UNLIMITED, not "no
 * seats". Reading it as a number would disable the add and invite buttons on
 * exactly the groups that have the most room, so both helpers are tested against
 * it explicitly.
 */

import { seatCountText, seatsRemaining, normalizeEmails } from './capacity';

describe( 'seatsRemaining', () => {
	it( 'subtracts the committed seats from a capped limit', () => {
		expect( seatsRemaining( { seatLimit: 5, seatsReserved: 3 } ) ).toBe( 2 );
	} );

	it( 'treats a seat limit of 0 as unlimited, not as zero seats', () => {
		expect( seatsRemaining( { seatLimit: 0, seatsReserved: 42 } ) ).toBe( Infinity );
	} );

	it( 'never reports negative room when a group is over its limit', () => {
		// Reachable after an admin lowers a limit that outstanding invitations
		// later fill past; the flows slice arrays by this, so it must not go below 0.
		expect( seatsRemaining( { seatLimit: 3, seatsReserved: 5 } ) ).toBe( 0 );
	} );

	it( 'treats a missing group as having no room rather than throwing', () => {
		expect( seatsRemaining( undefined ) ).toBe( Infinity );
	} );
} );

describe( 'seatCountText', () => {
	it( 'counts committed seats (members + invites) so it agrees with seatsRemaining', () => {
		// 3 members + 2 pending invites = 5 reserved of 5 → full. Counting members
		// only would read "3 / 5" while Add/Invite are (correctly) disabled.
		const group = { members: 3, seatsReserved: 5, seatLimit: 5 };
		expect( seatCountText( group ) ).toBe( '5 / 5' );
		expect( seatsRemaining( group ) ).toBe( 0 );
	} );

	it( 'shows committed of Unlimited when the limit is 0', () => {
		expect( seatCountText( { members: 3, seatsReserved: 4, seatLimit: 0 } ) ).toBe( '4 / Unlimited' );
	} );

	it( 'falls back to the member count when seatsReserved is absent (L0 list)', () => {
		expect( seatCountText( { members: 3, seatLimit: 5 } ) ).toBe( '3 / 5' );
	} );
} );

describe( 'normalizeEmails', () => {
	it( 'trims, drops blanks and de-duplicates', () => {
		expect( normalizeEmails( [ ' a@example.com ', '', 'b@example.com', 'a@example.com', '   ' ] ) ).toEqual( [
			'a@example.com',
			'b@example.com',
		] );
	} );
} );
