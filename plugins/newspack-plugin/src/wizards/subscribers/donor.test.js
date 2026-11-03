/**
 * Internal dependencies
 */
import { DONOR_BADGE_INTENT, DONOR_LABELS, donorKind, donorsEnabled } from './donor';
import { STATUS_BADGE_INTENT } from './status';

describe( 'donorKind', () => {
	it( 'is empty when there is no row', () => {
		expect( donorKind( undefined ) ).toBe( '' );
	} );
} );

describe( 'donor labels and badge intents', () => {
	it( 'gives every labelled kind a badge intent', () => {
		expect( Object.keys( DONOR_BADGE_INTENT ).sort() ).toEqual( Object.keys( DONOR_LABELS ).sort() );
	} );

	it( 'borrows no colour that already means a subscription status', () => {
		// The badge sits beside the status indicators in the list and the status
		// badges on the profile; a shared colour would read as a status.
		const statusIntents = Object.values( STATUS_BADGE_INTENT );
		Object.values( DONOR_BADGE_INTENT ).forEach( intent => expect( statusIntents ).not.toContain( intent ) );
	} );
} );

describe( 'donorsEnabled', () => {
	afterEach( () => {
		delete window.newspackSubscribers;
	} );

	it( 'is off when the server sent a config without the flag', () => {
		window.newspackSubscribers = {};
		expect( donorsEnabled() ).toBe( false );
	} );
} );
