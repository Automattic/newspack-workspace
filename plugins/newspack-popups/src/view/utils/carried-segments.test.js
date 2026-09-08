import { getCarriedSegmentIds, CARRIED_SEGMENTS_NONE } from './carried-segments';

const COOKIE = 'np_carried_segments';
const SESSION_KEY = 'newspack-popups-carried-segments';

/**
 * Set the handoff cookie as the inbound redirect would.
 *
 * @param {string} value Comma-joined segment IDs.
 */
const setCookie = value => {
	document.cookie = `${ COOKIE }=${ value }; path=/`;
};

/**
 * Remove the handoff cookie.
 */
const clearCookie = () => {
	document.cookie = `${ COOKIE }=; path=/; max-age=0`;
};

describe( 'getCarriedSegmentIds', () => {
	beforeEach( () => {
		window.sessionStorage.clear();
		clearCookie();
	} );

	it( 'returns nothing when there is no cookie and nothing remembered', () => {
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [] );
	} );

	it( 'reads the cookie on the landing page', () => {
		setCookie( '11,22' );
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [ '11', '22' ] );
	} );

	it( 'deletes the cookie so no later request carries it', () => {
		setCookie( '11' );
		getCarriedSegmentIds( [ '11' ] );
		// A browser only honors a `max-age=0` delete when the Path matches the
		// original write; setCookie() here and deleteCookie() in the module both
		// use `path=/`.
		expect( document.cookie ).not.toContain( COOKIE );
	} );

	it( 'remembers the IDs for the rest of the session', () => {
		setCookie( '11,22' );
		getCarriedSegmentIds( [ '11', '22' ] );
		// Cookie is gone; a later pageview reads the remembered set.
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [ '11', '22' ] );
		expect( window.sessionStorage.getItem( SESSION_KEY ) ).toBe( '11,22' );
	} );

	it( 'overrides remembered segments when a later arrival resolves to none', () => {
		// First arrival: a real match, remembered for the rest of the session.
		setCookie( '5,7' );
		expect( getCarriedSegmentIds( [ '5', '7' ] ) ).toEqual( [ '5', '7' ] );
		expect( window.sessionStorage.getItem( SESSION_KEY ) ).toBe( '5,7' );

		// Second arrival resolves to zero segments: PHP hands off the
		// CARRIED_SEGMENTS_NONE sentinel, which overrides the remembered set.
		setCookie( CARRIED_SEGMENTS_NONE );
		expect( getCarriedSegmentIds( [ '5', '7' ] ) ).toEqual( [] );
		expect( window.sessionStorage.getItem( SESSION_KEY ) ).toBe( CARRIED_SEGMENTS_NONE );
	} );

	it( 'drops IDs the page does not know about', () => {
		setCookie( '11,999' );
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [ '11' ] );
	} );

	it( 'drops every ID when the page knows no segments', () => {
		setCookie( '11,22' );
		expect( getCarriedSegmentIds( [] ) ).toEqual( [] );
	} );

	it( 'tolerates whitespace and empty entries', () => {
		setCookie( ' 11 ,,22 ' );
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [ '11', '22' ] );
	} );

	it( 'still returns the landing-page IDs when the sessionStorage write is blocked', () => {
		setCookie( '11' );
		const setSpy = jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( getCarriedSegmentIds( [ '11' ] ) ).toEqual( [ '11' ] );
		setSpy.mockRestore();
	} );

	it( 'fails closed when sessionStorage is fully unavailable and no cookie is present', () => {
		const getSpy = jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( getCarriedSegmentIds( [ '11' ] ) ).toEqual( [] );
		getSpy.mockRestore();
	} );

	it( 'decodes a percent-encoded cookie value as PHP setcookie() produces it', () => {
		// PHP's setcookie() URL-encodes the value, so `5,7` arrives as `5%2C7`;
		// without decodeURIComponent(), multi-segment readers lose their carry.
		setCookie( '5%2C7' );
		expect( getCarriedSegmentIds( [ '5', '7' ] ) ).toEqual( [ '5', '7' ] );
	} );
} );
