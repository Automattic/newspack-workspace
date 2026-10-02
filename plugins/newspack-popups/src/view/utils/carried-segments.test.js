import { CARRIED_SEGMENTS_NONE } from './carried-segments';

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

/**
 * Load the module as a new pageview would: only the cookie and sessionStorage
 * carry over from an earlier page.
 *
 * @return {Function} That page's getCarriedSegmentIds().
 */
const loadPage = () => {
	jest.resetModules();
	return require( './carried-segments' ).getCarriedSegmentIds;
};

describe( 'getCarriedSegmentIds', () => {
	let getCarriedSegmentIds;

	beforeEach( () => {
		window.sessionStorage.clear();
		clearCookie();
		getCarriedSegmentIds = loadPage();
	} );

	afterEach( () => {
		// Restore the Storage spies even when a test fails partway through.
		jest.restoreAllMocks();
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
		expect( loadPage()( [ '11', '22' ] ) ).toEqual( [ '11', '22' ] );
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
		expect( loadPage()( [ '5', '7' ] ) ).toEqual( [] );
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
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( getCarriedSegmentIds( [ '11' ] ) ).toEqual( [ '11' ] );
	} );

	it( 'gives a second caller on the landing page the same IDs when sessionStorage is unavailable', () => {
		setCookie( '11' );
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		// Segment reporting reads the handoff first, which deletes the cookie;
		// prompt display reads it next, on the same page.
		getCarriedSegmentIds( [ '11' ] );
		expect( getCarriedSegmentIds( [ '11' ] ) ).toEqual( [ '11' ] );
	} );

	it( 'fails closed when sessionStorage is fully unavailable and no cookie is present', () => {
		jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( getCarriedSegmentIds( [ '11' ] ) ).toEqual( [] );
	} );

	it( 'decodes a percent-encoded cookie value as PHP setcookie() produces it', () => {
		// PHP's setcookie() URL-encodes the value, so `5,7` arrives as `5%2C7`;
		// without decodeURIComponent(), multi-segment readers lose their carry.
		setCookie( '5%2C7' );
		expect( getCarriedSegmentIds( [ '5', '7' ] ) ).toEqual( [ '5', '7' ] );
	} );

	it( 'clears a cookie it cannot decode, and keeps what the session remembered', () => {
		setCookie( '11' );
		getCarriedSegmentIds( [ '11' ] );
		// A cut-off percent sequence is not a value the server writes, so it
		// says nothing about the reader. Left in place, it would fail the same
		// way on every page of the session.
		setCookie( '%E0%A4%A' );
		expect( loadPage()( [ '11' ] ) ).toEqual( [ '11' ] );
		expect( document.cookie ).not.toContain( COOKIE );
	} );
} );
