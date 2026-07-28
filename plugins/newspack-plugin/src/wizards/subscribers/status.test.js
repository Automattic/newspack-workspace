/**
 * Internal dependencies
 */
import { displayStatuses, STATUS_LABELS } from './status';

// The status-reduction rule these assertions pin is documented on the PHP side,
// in Subscribers_Wizard::reduced_status(); the endpoint's `status` filter
// resolves against the same rule, so the badge and the filter must agree.
describe( 'displayStatuses', () => {
	it( 'orders distinct statuses active-first', () => {
		expect( displayStatuses( [ 'on-hold', 'active', 'pending' ], '' ) ).toEqual( [ 'active', 'pending', 'on-hold' ] );
	} );

	it( 'collapses duplicates', () => {
		expect( displayStatuses( [ 'active', 'active' ], '' ) ).toEqual( [ 'active' ] );
	} );

	it( 'hides cancelled while any live subscription remains', () => {
		expect( displayStatuses( [ 'cancelled', 'active' ], '' ) ).toEqual( [ 'active' ] );
		expect( displayStatuses( [ 'cancelled', 'on-hold' ], '' ) ).toEqual( [ 'on-hold' ] );
		expect( displayStatuses( [ 'cancelled', 'pending' ], '' ) ).toEqual( [ 'pending' ] );
	} );

	it( 'shows cancelled only for a fully churned reader', () => {
		expect( displayStatuses( [ 'cancelled', 'cancelled' ], '' ) ).toEqual( [ 'cancelled' ] );
	} );

	it( 'falls back to the stored status when no subscription statuses are on file', () => {
		expect( displayStatuses( [], 'active' ) ).toEqual( [ 'active' ] );
		// A free reader has neither, and gets no badge at all.
		expect( displayStatuses( [], '' ) ).toEqual( [] );
	} );

	it( 'ignores falsy entries', () => {
		expect( displayStatuses( [ '', null, 'active' ], 'cancelled' ) ).toEqual( [ 'active' ] );
	} );

	it( 'labels every status a group or individual subscription can hold', () => {
		// A group awaiting its first payment is `pending`, so the badge must have a
		// label for it — an unlabeled badge renders empty.
		expect( Object.keys( STATUS_LABELS ) ).toEqual( [ 'active', 'pending', 'on-hold', 'cancelled' ] );
		Object.values( STATUS_LABELS ).forEach( label => expect( label ).toBeTruthy() );
	} );
} );
