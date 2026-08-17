/**
 * Segment IDs carried in from a newsletter click.
 *
 * A logged-out reader arriving from a newsletter is resolved server-side (from
 * the account ID the ESP substituted into the link) to their last-known matching
 * segments, handed to this script in a cookie, and redirected to the clean URL.
 * This module takes that handoff: it reads the cookie once, remembers the IDs in
 * sessionStorage so they keep applying as the reader navigates on, and deletes
 * the cookie so no later request carries it.
 *
 * These IDs are segmentation-only and transient. They are never written to the
 * persisted reader-data store — doing so would launder a forgeable,
 * link-supplied value into the snapshot the server trusts — and they never grant
 * content access.
 */

const COOKIE_NAME = 'np_carried_segments';
const SESSION_KEY = 'newspack-popups-carried-segments';

/**
 * Sentinel cookie/session value asserting that an account matched no
 * segments — distinct from an absent cookie, which means no handoff
 * happened at all.
 *
 * Mirrors Newspack_Popups_Segmentation::CARRIED_SEGMENTS_NONE in
 * class-newspack-popups-segmentation.php — keep the two in sync. PHP never
 * writes an empty string here: `setcookie()` treats an empty value as a
 * request to delete the cookie regardless of the expiry passed, which a real
 * browser would then never actually deliver.
 *
 * No special-casing is needed below to handle it: the sentinel is not a
 * segment ID (real IDs are always numeric term IDs), so filterKnown()
 * already drops it like any other unrecognised value, naturally yielding
 * `[]` — while it's still written to sessionStorage verbatim below, which is
 * what lets it override a previously-remembered non-empty set.
 */
export const CARRIED_SEGMENTS_NONE = 'none';

/**
 * Read the handoff cookie.
 *
 * @return {string|null} Raw cookie value, or null when absent.
 */
const readCookie = () => {
	const match = document.cookie.match( new RegExp( `(?:^|;\\s*)${ COOKIE_NAME }=([^;]*)` ) );
	return match ? decodeURIComponent( match[ 1 ] ) : null;
};

/**
 * Expire the handoff cookie.
 */
const deleteCookie = () => {
	document.cookie = `${ COOKIE_NAME }=; path=/; max-age=0`;
};

/**
 * Split a stored value into IDs the page actually knows about.
 *
 * An ID the page doesn't ship can't match anything, and the server already
 * filters to active segments — this is the client-side half of the same rule,
 * covering a stale sessionStorage value after a segment is deleted.
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
 * The segment IDs carried in from a newsletter click, for this browsing session.
 *
 * @param {string[]} knownIds Segment IDs present on the page.
 *
 * @return {string[]} Validated carried segment IDs.
 */
export const getCarriedSegmentIds = ( knownIds = [] ) => {
	const fromCookie = readCookie();
	if ( null !== fromCookie ) {
		// The cookie is authoritative on the landing page. Consume it either way:
		// leaving it set would keep it on every later request for nothing.
		deleteCookie();
		try {
			window.sessionStorage.setItem( SESSION_KEY, fromCookie );
		} catch ( e ) {
			// sessionStorage unavailable; the landing-page value still stands.
		}
		return filterKnown( fromCookie, knownIds );
	}

	try {
		return filterKnown( window.sessionStorage.getItem( SESSION_KEY ), knownIds );
	} catch ( e ) {
		// sessionStorage unavailable. Fail closed.
		return [];
	}
};
