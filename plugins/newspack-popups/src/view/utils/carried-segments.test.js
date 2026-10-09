import { CARRIED_SEGMENTS_NONE } from './carried-segments';

const COOKIE = 'np_carried_segments';

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
 * Load the module as a new pageview, or a new tab, would: only the cookie
 * carries over from an earlier page.
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
		clearCookie();
		getCarriedSegmentIds = loadPage();
	} );

	it( 'returns nothing when there is no cookie', () => {
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [] );
	} );

	it( 'reads the cookie on the landing page', () => {
		setCookie( '11,22' );
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [ '11', '22' ] );
	} );

	it( 'keeps carrying the IDs on later pages and in other tabs', () => {
		setCookie( '11,22' );
		getCarriedSegmentIds( [ '11', '22' ] );
		// Every page and tab of the browsing session shares the session cookie,
		// so reading it must leave it in place.
		expect( loadPage()( [ '11', '22' ] ) ).toEqual( [ '11', '22' ] );
	} );

	it( 'lets a later arrival that resolves to none replace earlier segments', () => {
		setCookie( '5,7' );
		expect( getCarriedSegmentIds( [ '5', '7' ] ) ).toEqual( [ '5', '7' ] );
		// PHP hands off the sentinel for a reader with no stored segments.
		setCookie( CARRIED_SEGMENTS_NONE );
		expect( loadPage()( [ '5', '7' ] ) ).toEqual( [] );
	} );

	it( 'drops IDs the page does not know about', () => {
		setCookie( '11,999' );
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [ '11' ] );
	} );

	it( 'tolerates whitespace and empty entries', () => {
		setCookie( ' 11 ,,22 ' );
		expect( getCarriedSegmentIds( [ '11', '22' ] ) ).toEqual( [ '11', '22' ] );
	} );

	it( 'decodes a percent-encoded cookie value as PHP setcookie() produces it', () => {
		// PHP's setcookie() URL-encodes the value, so `5,7` arrives as `5%2C7`;
		// without decodeURIComponent(), multi-segment readers lose their carry.
		setCookie( '5%2C7' );
		expect( getCarriedSegmentIds( [ '5', '7' ] ) ).toEqual( [ '5', '7' ] );
	} );

	it( 'clears a cookie it cannot decode and carries nothing', () => {
		// A cut-off percent sequence is not a value the server writes, so it
		// says nothing about the reader. Left in place, it would fail the same
		// way on every page of the session.
		setCookie( '%E0%A4%A' );
		expect( getCarriedSegmentIds( [ '11' ] ) ).toEqual( [] );
		expect( document.cookie ).not.toContain( COOKIE );
	} );
} );
