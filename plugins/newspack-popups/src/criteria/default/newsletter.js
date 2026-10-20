import { setMatchingFunction } from '../utils';
import { rememberSessionSignal } from './session-signal';

/**
 * Session-storage key used to remember that the reader arrived from a
 * newsletter email during the current browsing session.
 */
const FROM_EMAIL_KEY = 'newspack-popups-from-email';

/**
 * Whether the reader is visiting from a newsletter email.
 *
 * A reader clicking a link in a Newspack newsletter lands on a URL carrying
 * `utm_medium=email` (appended by the newsletter renderer). The arrival is
 * detected from the param and remembered for the rest of the browsing session
 * so the reader keeps matching "subscribers" segments as they navigate to clean
 * URLs. See rememberSessionSignal() for the segmentation-only, transient
 * guarantees.
 *
 * @return {boolean} True if the reader arrived from a newsletter email this session.
 */
export function isFromEmail() {
	return rememberSessionSignal( {
		param: 'utm_medium',
		sessionKey: FROM_EMAIL_KEY,
		isPositive: value => value.toLowerCase() === 'email',
	} );
}

/**
 * Seconds a browser-side newsletter signup keeps matching "subscribers" segments.
 */
const SIGNUP_HINT_LIFETIME = 24 * 60 * 60;

/**
 * Whether the browser-side signup hint records a signup in the last day.
 *
 * The hint is the signup time in seconds. It is not cleared when a reader later
 * unsubscribes, so it only counts while recent; by then the server-managed
 * `is_newsletter_subscriber` has caught up. Any other value, including the
 * `true` older versions stored, is not a hint.
 *
 * @param {*} hint Stored `newsletter_signup_client` value.
 * @return {boolean} Whether the hint is a recent signup time.
 */
export function isRecentSignupHint( hint ) {
	if ( 'number' !== typeof hint || ! Number.isFinite( hint ) ) {
		return false;
	}
	const now = Date.now() / 1000;
	return hint <= now && now - hint < SIGNUP_HINT_LIFETIME;
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
	const isSubscriber = store.get( 'is_newsletter_subscriber' ) || isRecentSignupHint( store.get( 'newsletter_signup_client' ) ) || isFromEmail();
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
