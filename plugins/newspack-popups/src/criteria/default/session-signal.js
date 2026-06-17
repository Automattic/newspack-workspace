/**
 * Detect a URL-param arrival signal and remember it for the browsing session.
 *
 * Several segmentation signals are delivered as a query param on the page a
 * reader lands on from a newsletter email (e.g. `utm_medium=email`,
 * `np_seg_donor`). The param is authoritative on the landing page; once a
 * positive value is seen it is remembered in sessionStorage so the reader keeps
 * matching as they navigate on to clean URLs.
 *
 * The flag is segmentation-only and transient: it is never written to the
 * persisted reader data store (so it cannot grant content access and does not
 * affect analytics or ad targeting) and never survives the browsing session.
 *
 * @param {Object}   options
 * @param {string}   options.param      Query-param name to read.
 * @param {string}   options.sessionKey sessionStorage key under which a positive arrival is remembered.
 * @param {Function} options.isPositive Predicate receiving the decoded (non-null) param value; returns whether it is a positive signal.
 * @return {boolean} Whether the signal is active for this browsing session.
 */
export function rememberSessionSignal( { param, sessionKey, isPositive } ) {
	// The URL param is authoritative on the landing page, even if nothing can be
	// persisted.
	const value = new URLSearchParams( window.location.search ).get( param );
	if ( value !== null && isPositive( value ) ) {
		// Remember the arrival for the rest of the session so the reader keeps
		// matching after navigating to clean URLs. A write failure (e.g. private
		// mode) only costs the cross-navigation memory, not this detection.
		try {
			window.sessionStorage.setItem( sessionKey, '1' );
		} catch ( e ) {
			// sessionStorage unavailable; the URL signal still stands.
		}
		return true;
	}
	// No positive param on this page — fall back to whatever was remembered earlier.
	try {
		return window.sessionStorage.getItem( sessionKey ) === '1';
	} catch ( e ) {
		// sessionStorage unavailable. Fail closed.
		return false;
	}
}
