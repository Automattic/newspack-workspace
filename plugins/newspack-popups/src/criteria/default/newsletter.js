import { setMatchingFunction } from '../utils';

/**
 * Session-storage key used to remember that the reader arrived from a
 * newsletter email during the current browsing session.
 */
const FROM_EMAIL_KEY = 'newspack-popups-from-email';

/**
 * Whether the reader is visiting from a newsletter email.
 *
 * A reader clicking a link in a Newspack newsletter lands on a URL carrying
 * `utm_medium=email` (appended by the newsletter renderer). The URL param is an
 * authoritative signal on the landing page, so it is honored even when the
 * arrival cannot be persisted. When detected, the arrival is also remembered
 * for the rest of the browsing session via sessionStorage so the reader keeps
 * matching "subscribers" segments as they navigate to clean URLs. This is
 * segmentation-only and transient — it is never written to the persisted reader
 * data store, so it does not affect analytics or ad targeting, and never
 * persists across sessions.
 *
 * @return {boolean} True if the reader arrived from a newsletter email this session.
 */
export function isFromEmail() {
	// The URL param is authoritative on the landing page, even if nothing can
	// be persisted.
	const medium = new URLSearchParams( window.location.search ).get( 'utm_medium' );
	if ( medium?.toLowerCase() === 'email' ) {
		// Remember the arrival for the rest of the session so the reader keeps
		// matching after navigating to clean URLs. A write failure (e.g. private
		// mode) only costs the cross-navigation memory, not this detection.
		try {
			window.sessionStorage.setItem( FROM_EMAIL_KEY, '1' );
		} catch ( e ) {
			// sessionStorage unavailable; the URL signal still stands.
		}
		return true;
	}
	// No param on this page — fall back to whatever was remembered earlier.
	try {
		return window.sessionStorage.getItem( FROM_EMAIL_KEY ) === '1';
	} catch ( e ) {
		// sessionStorage unavailable. Fail closed.
		return false;
	}
}

/**
 * Matching function for the 'newsletter' criteria.
 *
 * @param {Object} config    The segment criteria config.
 * @param {Object} ras       The reader activation object.
 * @param {Object} ras.store The reader data library store.
 * @return {boolean} Whether the criteria matches.
 */
export function matchNewsletter( config, { store } ) {
	const isSubscriber = store.get( 'is_newsletter_subscriber' ) || isFromEmail();
	switch ( config.value ) {
		case 'subscribers':
			return isSubscriber;
		case 'non-subscribers':
			return ! isSubscriber;
	}
	// The empty "Subscribers and non-subscribers" value applies no filter, so a
	// segment using it never registers this criterion and never reaches here.
}

setMatchingFunction( 'newsletter', matchNewsletter );
