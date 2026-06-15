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
 * `utm_medium=email` (appended by the newsletter renderer). When detected, the
 * arrival is remembered for the rest of the browsing session via sessionStorage
 * so the reader keeps matching "subscribers" segments as they navigate to clean
 * URLs. This is segmentation-only and transient — it is never written to the
 * persisted reader data store, so it does not affect analytics or ad targeting,
 * and never persists across sessions.
 *
 * @return {boolean} True if the reader arrived from a newsletter email this session.
 */
export function isFromEmail() {
	try {
		const medium = new URLSearchParams( window.location.search ).get( 'utm_medium' );
		if ( medium?.toLowerCase() === 'email' ) {
			window.sessionStorage.setItem( FROM_EMAIL_KEY, '1' );
		}
		return window.sessionStorage.getItem( FROM_EMAIL_KEY ) === '1';
	} catch ( e ) {
		// sessionStorage unavailable (e.g. private mode). Fail closed.
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
}

setMatchingFunction( 'newsletter', matchNewsletter );
