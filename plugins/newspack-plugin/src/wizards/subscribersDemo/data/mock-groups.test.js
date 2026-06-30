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
