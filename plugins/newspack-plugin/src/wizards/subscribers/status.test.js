/**
 * Internal dependencies
 */
import { displayStatuses, STATUS_BADGE_INTENT, STATUS_ICONS, STATUS_LABELS } from './status';

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

describe( 'STATUS_ICONS', () => {
	it( 'gives every labelled status a glyph', () => {
		expect( Object.keys( STATUS_ICONS ) ).toEqual( Object.keys( STATUS_LABELS ) );
		Object.values( STATUS_ICONS ).forEach( icon => expect( icon ).toBeTruthy() );
	} );

	// The list Status columns offer these as separate filters, so two statuses
	// sharing a glyph leaves the reader unable to tell apart the results of two
	// different filters.
	it( 'gives no two statuses the same glyph', () => {
		const icons = Object.values( STATUS_ICONS );
		expect( new Set( icons ).size ).toBe( icons.length );
	} );

	it( 'separates a live subscription from one needing attention and one gone', () => {
		expect( STATUS_ICONS.active ).not.toBe( STATUS_ICONS[ 'on-hold' ] );
		expect( STATUS_ICONS[ 'on-hold' ] ).not.toBe( STATUS_ICONS.cancelled );
	} );
} );

// The badge intents outlive the column: the profile card and the person header
// still badge a single status, where an attention marker is what a badge is for.
describe( 'STATUS_BADGE_INTENT', () => {
	it( 'gives every labelled status an intent', () => {
		expect( Object.keys( STATUS_BADGE_INTENT ) ).toEqual( Object.keys( STATUS_LABELS ) );
	} );

	it( 'reads pending as queued work rather than an idle state', () => {
		// `low` is the design system's "worth noticing, non-urgent"; `informational`
		// would read as settled context, which a payment still to clear is not.
		expect( STATUS_BADGE_INTENT.pending ).toBe( 'low' );
	} );

	it( 'separates a live subscription from one needing attention and one gone', () => {
		expect( STATUS_BADGE_INTENT.active ).toBe( 'stable' );
		expect( STATUS_BADGE_INTENT[ 'on-hold' ] ).toBe( 'medium' );
		expect( STATUS_BADGE_INTENT.cancelled ).toBe( 'high' );
	} );

	it( 'gives no two statuses the same intent', () => {
		const intents = Object.values( STATUS_BADGE_INTENT );
		expect( new Set( intents ).size ).toBe( intents.length );
	} );
} );
