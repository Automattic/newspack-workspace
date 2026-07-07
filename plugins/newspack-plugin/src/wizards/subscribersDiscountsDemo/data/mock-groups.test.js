/**
 * Internal dependencies.
 */
import {
	requestSeatIncrease,
	applySeatIncrease,
	sendSeatUpgradeLink,
	paySeatUpgrade,
	clearSeatRequest,
	hasSeatRequest,
	canRequestSeats,
	setMemberRole,
	GROUPS,
} from './mock-groups';

const baseGroup = () => ( {
	id: 'grp_test',
	status: 'active',
	seatLimit: 10,
	members: [ { subscriberId: 's1', role: 'owner', joinedAt: '2026-01-01' } ],
	invites: [],
	seatRequest: null,
} );

describe( 'seat request helpers', () => {
	it( 'requestSeatIncrease records a pending request without changing the limit', () => {
		const next = requestSeatIncrease( baseGroup(), 15 );
		expect( next.seatLimit ).toBe( 10 );
		expect( next.seatRequest.target ).toBe( 15 );
		expect( next.seatRequest.status ).toBe( 'pending' );
		expect( next.seatRequest.requestedAt ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );
	} );

	it( 'applySeatIncrease raises the limit and clears the request', () => {
		const next = applySeatIncrease( requestSeatIncrease( baseGroup(), 15 ), 15 );
		expect( next.seatLimit ).toBe( 15 );
		expect( next.seatRequest ).toBeNull();
	} );

	it( 'sendSeatUpgradeLink stores amount and awaiting-payment without changing the limit', () => {
		const next = sendSeatUpgradeLink( requestSeatIncrease( baseGroup(), 15 ), 15, 50 );
		expect( next.seatLimit ).toBe( 10 );
		expect( next.seatRequest.status ).toBe( 'awaiting-payment' );
		expect( next.seatRequest.amount ).toBe( 50 );
		expect( next.seatRequest.target ).toBe( 15 );
		expect( next.seatRequest.linkSentAt ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );
	} );

	it( 'paySeatUpgrade applies the awaiting-payment target and clears the request', () => {
		const awaiting = sendSeatUpgradeLink( requestSeatIncrease( baseGroup(), 15 ), 15, 50 );
		const next = paySeatUpgrade( awaiting );
		expect( next.seatLimit ).toBe( 15 );
		expect( next.seatRequest ).toBeNull();
	} );

	it( 'clearSeatRequest drops the request with no seat change', () => {
		const next = clearSeatRequest( requestSeatIncrease( baseGroup(), 15 ) );
		expect( next.seatLimit ).toBe( 10 );
		expect( next.seatRequest ).toBeNull();
	} );

	it( 'canRequestSeats is false when a request already exists or the group is inactive', () => {
		expect( canRequestSeats( baseGroup() ) ).toBe( true );
		expect( canRequestSeats( requestSeatIncrease( baseGroup(), 15 ) ) ).toBe( false );
		expect( hasSeatRequest( requestSeatIncrease( baseGroup(), 15 ) ) ).toBe( true );
		expect( canRequestSeats( { ...baseGroup(), status: 'on-hold' } ) ).toBe( false );
	} );
} );

describe( 'manager role helpers', () => {
	const managedGroup = () => ( {
		...baseGroup(),
		ownerId: 's1',
		members: [
			{ subscriberId: 's1', role: 'owner', joinedAt: '2026-01-01' },
			{ subscriberId: 's2', role: 'member', joinedAt: '2026-02-01' },
		],
	} );

	it( 'setMemberRole promotes a member to manager and demotes back', () => {
		const promoted = setMemberRole( managedGroup(), 's2', 'manager' );
		expect( promoted.members.find( m => m.subscriberId === 's2' ).role ).toBe( 'manager' );
		const demoted = setMemberRole( promoted, 's2', 'member' );
		expect( demoted.members.find( m => m.subscriberId === 's2' ).role ).toBe( 'member' );
	} );

	it( 'setMemberRole never re-roles the owner', () => {
		const group = managedGroup();
		expect( setMemberRole( group, 's1', 'manager' ) ).toBe( group );
	} );

	it( 'seeds managers who are group members and never the owner', () => {
		const withManagers = GROUPS.filter( g => ( g.members || [] ).some( m => m.role === 'manager' ) );
		expect( withManagers.map( g => g.id ) ).toEqual( expect.arrayContaining( [ 'grp_acme', 'grp_riverside', 'grp_northside' ] ) );
		withManagers.forEach( g => g.members.filter( m => m.role === 'manager' ).forEach( m => expect( m.subscriberId ).not.toBe( g.ownerId ) ) );
	} );
} );
