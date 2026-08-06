/**
 * Session-scoped storage on top of the Reader Activation store.
 *
 * Values stored through here live in the RAS store — namespaced per site and
 * shared across a session's tabs — but expire on the session boundary: a value
 * resets when the GA4 session ID read from the `_ga_*` cookie changes, falling
 * back to a 30-minute sliding window when no GA cookie is readable. That
 * tracks the same session boundary GA4 reports group by, unlike
 * `sessionStorage`, whose lifetime is the tab.
 *
 * Reads and writes never throw: session state is best-effort bookkeeping, and
 * callers run inside the RAS queue drain, which has no error isolation of its
 * own — an exception there would abort prompt display.
 */

// GA4's session inactivity timeout, for the fallback session boundary.
export const SESSION_TIMEOUT = 30 * 60 * 1000;

/**
 * Read the GA4 session ID from the `_ga_<container>` cookie.
 *
 * Mirrors the server-side parse in newspack-plugin's
 * `GoogleSiteKit::extract_sid_from_cookies()` — the third dot-piece of the
 * cookie value — reduced to its leading digits so the GS1 and GS2 cookie
 * formats both yield the bare session ID (in GS2 the piece carries
 * `$`-delimited counters that change within a session).
 *
 * @return {string|null} Session ID, or null when no GA cookie is readable.
 */
const getGa4SessionId = () => {
	try {
		const cookies = document.cookie ? document.cookie.split( '; ' ) : [];
		for ( const cookie of cookies ) {
			const separator = cookie.indexOf( '=' );
			if ( 0 !== cookie.indexOf( '_ga_' ) || -1 === separator ) {
				continue;
			}
			const pieces = cookie.slice( separator + 1 ).split( '.' );
			const sid = pieces[ 2 ] ? ( pieces[ 2 ].match( /\d+/ ) || [] )[ 0 ] : null;
			if ( sid ) {
				return sid;
			}
		}
	} catch ( e ) {
		// document.cookie unreadable; use the time-based boundary instead.
	}
	return null;
};

/**
 * Read a session-scoped value, discarding it when the session boundary has
 * moved on: a new GA4 session ID, or — when no GA cookie is readable — more
 * than the session timeout since the last write.
 *
 * @param {Object} ras Reader Activation library object.
 * @param {string} key Store key.
 *
 * @return {Object} `{ sid, value }` — current boundary token and the stored
 *                  value (null when absent or expired).
 */
export const readSessionValue = ( ras, key ) => {
	const sid = getGa4SessionId();
	try {
		const state = ras.store.get( key ) || {};
		const value = undefined === state.value ? null : state.value;
		if ( sid ) {
			return { sid, value: state.sid === sid ? value : null };
		}
		const expired = ! state.ts || Date.now() - state.ts > SESSION_TIMEOUT;
		return { sid, value: expired ? null : value };
	} catch ( e ) {
		// Unreadable state degrades the caller to per-pageview behavior, which
		// costs volume but loses no data.
		return { sid, value: null };
	}
};

/**
 * Write a session-scoped value, stamping the boundary token: the GA4 session
 * ID and the time of the write, which keeps the fallback window sliding.
 *
 * @param {Object}      ras   Reader Activation library object.
 * @param {string}      key   Store key.
 * @param {string|null} sid   GA4 session ID from the paired readSessionValue().
 * @param {any}         value Value to store.
 */
export const writeSessionValue = ( ras, key, sid, value ) => {
	try {
		// `false`: session state is per-device bookkeeping; never sync it to
		// reader meta.
		ras.store.set( key, { sid, ts: Date.now(), value }, false );
	} catch ( e ) {
		// See readSessionValue: an unwritable state only costs the volume
		// saving — and must never unwind into the RAS queue drain.
	}
};
