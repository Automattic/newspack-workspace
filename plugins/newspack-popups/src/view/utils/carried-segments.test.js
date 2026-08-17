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
		// jsdom's document.cookie is a simplified approximation of a real
		// browser's cookie jar, so this alone cannot prove real-browser
		// deletion — a browser only lets a `max-age=0` write remove a cookie
		// whose Path (and Domain) match the original exactly. What makes this
		// assertion meaningful is that setCookie() above and deleteCookie() in
		// carried-segments.js both write `path=/`, satisfying that requirement.
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

		// Second arrival: a different account resolves to zero segments. PHP
		// hands this off as the CARRIED_SEGMENTS_NONE sentinel — a session
		// cookie, never a past-expiry deletion, and never an empty string
		// either: PHP's setcookie() sends an empty value as a deletion
		// regardless of the expiry passed, which a real browser would then
		// never actually deliver. The sentinel is what makes this case
		// distinguishable from "no handoff happened" and lets it override what
		// an earlier arrival remembered. See
		// Newspack_Popups_Segmentation::get_carried_segments_cookie_value().
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
		// PHP's setcookie() URL-encodes the value it writes, so a two-segment
		// handoff of `5,7` arrives in document.cookie as `5%2C7`, not `5,7`.
		// readCookie() must decodeURIComponent() the captured group, or every
		// multi-segment reader silently loses their carried segments while
		// single-segment readers (no comma to encode) keep working.
		setCookie( '5%2C7' );
		expect( getCarriedSegmentIds( [ '5', '7' ] ) ).toEqual( [ '5', '7' ] );
	} );
} );
