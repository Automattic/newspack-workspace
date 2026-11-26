import { matchNewsletter, isFromEmail } from './newsletter';

const nowInSeconds = () => Math.floor( Date.now() / 1000 );

/**
 * Build the second argument the matching function receives, with a stubbed
 * reader data library store returning the given subscriber value.
 *
 * @param {boolean} value        Value returned by store.get( 'is_newsletter_subscriber' ).
 * @param {*}       clientSignal Value returned by store.get( 'newsletter_signup_client' ).
 * @param {boolean} isLoggedIn   Whether the stored reader is authenticated.
 * @return {Object} Stubbed reader activation object.
 */
const makeRas = ( value, clientSignal = false, isLoggedIn = false ) => ( {
	store: {
		get: key => {
			if ( 'newsletter_signup_client' === key ) {
				return clientSignal;
			}
			if ( 'reader' === key ) {
				return { authenticated: isLoggedIn };
			}
			return value;
		},
	},
} );

describe( 'newsletter criteria matching', () => {
	beforeEach( () => {
		window.sessionStorage.clear();
		window.history.replaceState( {}, '', '/' );
	} );

	it( 'treats a non-subscriber arriving with no email param as a non-subscriber', () => {
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false ) ) ).toBe( false );
		expect( matchNewsletter( { value: 'non-subscribers' }, makeRas( false ) ) ).toBe( true );
	} );

	it( 'treats a reader with the stored subscriber flag as a subscriber', () => {
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( true ) ) ).toBe( true );
		expect( matchNewsletter( { value: 'non-subscribers' }, makeRas( true ) ) ).toBe( false );
	} );

	it( 'treats a reader with a recent browser-side signup signal as a subscriber', () => {
		const signedUpAnHourAgo = nowInSeconds() - 60 * 60;
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false, signedUpAnHourAgo ) ) ).toBe( true );
		expect( matchNewsletter( { value: 'non-subscribers' }, makeRas( false, signedUpAnHourAgo ) ) ).toBe( false );
	} );

	it( 'treats a logged-in reader with a recent signup signal as a subscriber', () => {
		const signedUpAnHourAgo = nowInSeconds() - 60 * 60;
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false, signedUpAnHourAgo, true ) ) ).toBe( true );
		expect( matchNewsletter( { value: 'non-subscribers' }, makeRas( false, signedUpAnHourAgo, true ) ) ).toBe( false );
	} );

	it( 'stops treating the signup signal of a logged-in reader as a subscriber after a day', () => {
		const signedUp25HoursAgo = nowInSeconds() - 25 * 60 * 60;
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false, signedUp25HoursAgo, true ) ) ).toBe( false );
		expect( matchNewsletter( { value: 'non-subscribers' }, makeRas( false, signedUp25HoursAgo, true ) ) ).toBe( true );
	} );

	it( 'keeps treating the signup signal of a logged-out reader as a subscriber after a day', () => {
		const signedUp25HoursAgo = nowInSeconds() - 25 * 60 * 60;
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false, signedUp25HoursAgo ) ) ).toBe( true );
		expect( matchNewsletter( { value: 'non-subscribers' }, makeRas( false, signedUp25HoursAgo ) ) ).toBe( false );
	} );

	it.each( [
		[ 'a boolean true', true ],
		[ 'a future time', nowInSeconds() + 60 * 60 ],
		[ 'a numeric string', String( nowInSeconds() ) ],
	] )( 'ignores %s as the browser-side signup signal', ( _label, hint ) => {
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false, hint ) ) ).toBe( false );
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false, hint, true ) ) ).toBe( false );
	} );

	it( 'treats a reader arriving with utm_medium=email as a subscriber', () => {
		window.history.replaceState( {}, '', '/?utm_medium=email' );
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false ) ) ).toBe( true );
		expect( matchNewsletter( { value: 'non-subscribers' }, makeRas( false ) ) ).toBe( false );
	} );

	it( 'remembers an email arrival for the rest of the session', () => {
		// Landing page carries the param and sets the session flag.
		window.history.replaceState( {}, '', '/?utm_medium=email' );
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false ) ) ).toBe( true );
		// Subsequent navigation to a clean URL still matches subscribers.
		window.history.replaceState( {}, '', '/some-article' );
		expect( matchNewsletter( { value: 'subscribers' }, makeRas( false ) ) ).toBe( true );
	} );

	it( 'matches utm_medium=email case-insensitively', () => {
		window.history.replaceState( {}, '', '/?utm_medium=Email' );
		expect( isFromEmail() ).toBe( true );
	} );

	it( 'ignores other utm_medium values', () => {
		window.history.replaceState( {}, '', '/?utm_medium=emailblast' );
		expect( isFromEmail() ).toBe( false );
	} );

	it( 'still matches subscribers on an email arrival when sessionStorage writes are blocked', () => {
		window.history.replaceState( {}, '', '/?utm_medium=email' );
		const setSpy = jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		// The URL param is authoritative; a failed write only costs cross-navigation memory.
		let result;
		expect( () => {
			result = matchNewsletter( { value: 'subscribers' }, makeRas( false ) );
		} ).not.toThrow();
		expect( result ).toBe( true );
		setSpy.mockRestore();
	} );

	it( 'does not throw when sessionStorage is fully unavailable', () => {
		window.history.replaceState( {}, '', '/some-article' );
		const setSpy = jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		const getSpy = jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( () => isFromEmail() ).not.toThrow();
		expect( isFromEmail() ).toBe( false );
		setSpy.mockRestore();
		getSpy.mockRestore();
	} );
} );
