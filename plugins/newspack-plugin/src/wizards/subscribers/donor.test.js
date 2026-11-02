/**
 * Internal dependencies
 */
import { DONOR_BADGE_INTENT, DONOR_FILTER_ELEMENTS, DONOR_LABELS, donorKind, donorsEnabled } from './donor';
import { STATUS_BADGE_INTENT } from './status';

describe( 'donorKind', () => {
	it( 'reads the kind the endpoint sent', () => {
		expect( donorKind( { donor: { kind: 'recurring', lastDonation: null } } ) ).toBe( 'recurring' );
	} );

	it( 'is empty for a reader with no donor data', () => {
		expect( donorKind( {} ) ).toBe( '' );
		expect( donorKind( { donor: { kind: '', lastDonation: null } } ) ).toBe( '' );
		expect( donorKind( undefined ) ).toBe( '' );
	} );
} );

describe( 'donor labels and badge intents', () => {
	it( 'gives every labelled kind a badge intent', () => {
		expect( Object.keys( DONOR_BADGE_INTENT ).sort() ).toEqual( Object.keys( DONOR_LABELS ).sort() );
	} );

	it( 'shows Former as an inactive state, quieter than the two kinds who still give', () => {
		expect( DONOR_BADGE_INTENT.former ).toBe( 'none' );
		expect( DONOR_BADGE_INTENT[ 'one-time' ] ).toBe( 'informational' );
		expect( DONOR_BADGE_INTENT.recurring ).toBe( 'informational' );
	} );

	it( 'borrows no colour that already means a subscription status', () => {
		// The badge sits beside the status indicators in the list and the status
		// badges on the profile; a shared colour would read as a status.
		const statusIntents = Object.values( STATUS_BADGE_INTENT );
		Object.values( DONOR_BADGE_INTENT ).forEach( intent => expect( statusIntents ).not.toContain( intent ) );
	} );
} );

describe( 'DONOR_FILTER_ELEMENTS', () => {
	it( 'offers the four values the endpoint accepts, in order', () => {
		expect( DONOR_FILTER_ELEMENTS.map( element => element.value ) ).toEqual( [ 'one-time', 'recurring', 'former', 'none' ] );
	} );

	it( 'names the kinds the way the list does, with Not a donor for everyone else', () => {
		expect( DONOR_FILTER_ELEMENTS.map( element => element.label ) ).toEqual( [ 'One-time', 'Recurring', 'Former', 'Not a donor' ] );
	} );
} );

describe( 'donorsEnabled', () => {
	afterEach( () => {
		delete window.newspackSubscribers;
	} );

	it( 'follows the flag the server set', () => {
		window.newspackSubscribers = { donorsEnabled: true };
		expect( donorsEnabled() ).toBe( true );
		window.newspackSubscribers = { donorsEnabled: false };
		expect( donorsEnabled() ).toBe( false );
	} );

	it( 'is off when the server sent no config at all', () => {
		expect( donorsEnabled() ).toBe( false );
		window.newspackSubscribers = {};
		expect( donorsEnabled() ).toBe( false );
	} );
} );
