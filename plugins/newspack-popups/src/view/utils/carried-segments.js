/**
 * Segment IDs carried in from a newsletter click. The server resolves the
 * account ID in the link to the reader's last-known matched segments and hands
 * them off in a cookie; this module reads the cookie once, remembers the IDs in
 * sessionStorage for the rest of the session, and deletes the cookie.
 *
 * Segmentation-only and transient: never written to the reader-data store
 * (forgeable, link-supplied), never grants content access.
 */

const COOKIE_NAME = 'np_carried_segments';
const SESSION_KEY = 'newspack-popups-carried-segments';

/**
 * Cookie value asserting the account matched no segments, distinct from an
 * absent cookie (no handoff). Mirrors CARRIED_SEGMENTS_NONE in
 * class-newspack-popups-segmentation.php — keep in sync. Needs no
 * special-casing: filterKnown() drops it like any unknown ID, yielding `[]`,
 * while writing it to sessionStorage overrides a previously-remembered set.
 */
export const CARRIED_SEGMENTS_NONE = 'none';

/**
 * Expire the handoff cookie.
 */
const deleteCookie = () => {
	document.cookie = `${ COOKIE_NAME }=; path=/; max-age=0`;
};

/**
 * Read the handoff cookie.
 *
 * @return {string|null} Raw cookie value, or null when there is no handoff.
 */
const readCookie = () => {
	const match = document.cookie.match( new RegExp( `(?:^|;\\s*)${ COOKIE_NAME }=([^;]*)` ) );
	if ( ! match ) {
		return null;
	}
	try {
		return decodeURIComponent( match[ 1 ] );
	} catch ( e ) {
		// Not a value the server writes, so it says nothing about the reader.
		// Cleared, or it would fail the same way on every page of the session.
		deleteCookie();
		return null;
	}
};

/**
 * Split a stored value into IDs the page actually knows about, dropping stale
 * or unknown ones.
 *
 * @param {string}   value    Comma-joined segment IDs.
 * @param {string[]} knownIds Segment IDs present on the page.
 *
 * @return {string[]} Validated segment IDs.
 */
const filterKnown = ( value, knownIds ) =>
	String( value || '' )
		.split( ',' )
		.map( id => id.trim() )
		.filter( id => id && knownIds.includes( id ) );

/**
 * The handoff read from the cookie on this page. Segment reporting and prompt
 * display both read the handoff, and the first read deletes the cookie, so the
 * second would otherwise depend on sessionStorage being available.
 */
let landingValue = null;

/**
 * The segment IDs carried in from a newsletter click, for this browsing session.
 *
 * @param {string[]} knownIds Segment IDs present on the page.
 *
 * @return {string[]} Validated carried segment IDs.
 */
export const getCarriedSegmentIds = ( knownIds = [] ) => {
	const fromCookie = readCookie();
	if ( null !== fromCookie ) {
		deleteCookie();
		landingValue = fromCookie;
		try {
			window.sessionStorage.setItem( SESSION_KEY, fromCookie );
		} catch ( e ) {
			// sessionStorage unavailable; the landing-page value still stands.
		}
	}
	if ( null !== landingValue ) {
		return filterKnown( landingValue, knownIds );
	}

	try {
		return filterKnown( window.sessionStorage.getItem( SESSION_KEY ), knownIds );
	} catch ( e ) {
		// sessionStorage unavailable. Fail closed.
		return [];
	}
};
