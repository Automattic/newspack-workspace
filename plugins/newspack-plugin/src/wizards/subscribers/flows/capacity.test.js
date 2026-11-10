/**
 * Seat arithmetic for the group flows.
 *
 * The trap these pin down is that a seat limit of 0 means UNLIMITED, not "no
 * seats". Reading it as a number would disable the add and invite buttons on
 * exactly the groups that have the most room, so both helpers are tested against
 * it explicitly.
 */

import { normalizeEmails, pendingInvites, pendingInvitesText, seatCountText, seatsRemaining, seatUsageText } from './capacity';

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

	it( 'treats a missing group as having no room, not as unlimited', () => {
		// The safe default: with nothing loaded, the flows must withhold Add and
		// Invite rather than offer them against a group they know nothing about.
		expect( seatsRemaining( undefined ) ).toBe( 0 );
	} );
} );

describe( 'seatCountText', () => {
	it( 'counts members only, not the invitations holding seats', () => {
		const group = { members: 3, seatsReserved: 5, seatLimit: 5 };
		expect( seatCountText( group ) ).toBe( '3 / 5' );
		expect( seatsRemaining( group ) ).toBe( 0 );
	} );

	it( 'shows members of Unlimited when the limit is 0', () => {
		expect( seatCountText( { members: 3, seatsReserved: 4, seatLimit: 0 } ) ).toBe( '3 / Unlimited' );
	} );
} );

describe( 'pendingInvites', () => {
	it( 'is the seats reserved beyond the members', () => {
		expect( pendingInvites( { members: 6, seatsReserved: 8, seatLimit: 8 } ) ).toBe( 2 );
	} );

	it( 'is 0 without seatsReserved (L0 list)', () => {
		expect( pendingInvites( { members: 3, seatLimit: 5 } ) ).toBe( 0 );
	} );
} );

describe( 'seatUsageText', () => {
	it( 'counts members against the limit', () => {
		expect( seatUsageText( { members: 6, seatsReserved: 8, seatLimit: 8 } ) ).toBe( '6 of 8 seats used' );
	} );

	it( 'uses the singular for a one-seat group', () => {
		expect( seatUsageText( { members: 1, seatsReserved: 1, seatLimit: 1 } ) ).toBe( '1 of 1 seat used' );
	} );

	it( 'says there is no limit for an uncapped group', () => {
		expect( seatUsageText( { members: 1, seatsReserved: 3, seatLimit: 0 } ) ).toBe( '1 seat used, no limit' );
		expect( seatUsageText( { members: 4, seatsReserved: 4, seatLimit: 0 } ) ).toBe( '4 seats used, no limit' );
	} );
} );

describe( 'pendingInvitesText', () => {
	it( 'is empty with nothing pending', () => {
		expect( pendingInvitesText( { members: 6, seatsReserved: 6, seatLimit: 8 } ) ).toBe( '' );
	} );

	it( 'counts pending invitations while seats are free', () => {
		expect( pendingInvitesText( { members: 5, seatsReserved: 6, seatLimit: 8 } ) ).toBe( '1 invitation pending' );
		expect( pendingInvitesText( { members: 4, seatsReserved: 6, seatLimit: 8 } ) ).toBe( '2 invitations pending' );
	} );

	it( 'explains the block when invitations hold the last free seats', () => {
		expect( pendingInvitesText( { members: 6, seatsReserved: 8, seatLimit: 8 } ) ).toBe(
			'2 invitations are pending. Cancel one to free a seat.'
		);
		expect( pendingInvitesText( { members: 7, seatsReserved: 8, seatLimit: 8 } ) ).toBe( '1 invitation is pending. Cancel it to free a seat.' );
	} );

	it( 'never reports a block for an uncapped group', () => {
		expect( pendingInvitesText( { members: 6, seatsReserved: 8, seatLimit: 0 } ) ).toBe( '2 invitations pending' );
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
