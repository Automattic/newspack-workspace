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
 * Seconds a browser-side newsletter signup keeps matching "subscribers" segments
 * for a logged-in reader.
 */
const SIGNUP_HINT_LIFETIME = 24 * 60 * 60;

/**
 * Whether the browser-side signup hint counts as a signup.
 *
 * The hint is the signup time in seconds, and any value that isn't a number is
 * not a hint. It is not cleared when a reader later unsubscribes. For a
 * logged-in reader it only counts for a day. Where the site can check the ESP,
 * the server-managed `is_newsletter_subscriber` has reached the browser and
 * caught up by then; where it can't, the signup stops counting after the day.
 * A logged-out reader never receives that value, so the hint keeps counting.
 *
 * @param {*}       hint       Stored `newsletter_signup_client` value.
 * @param {boolean} isLoggedIn Whether the reader is authenticated.
 * @return {boolean} Whether the hint records a signup that still counts.
 */
export function isCountingSignupHint( hint, isLoggedIn ) {
	if ( 'number' !== typeof hint || ! Number.isFinite( hint ) ) {
		return false;
	}
	const now = Date.now() / 1000;
	if ( hint > now ) {
		return false;
	}
	return ! isLoggedIn || now - hint < SIGNUP_HINT_LIFETIME;
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
	const isLoggedIn = Boolean( store.get( 'reader' )?.authenticated );
	const isSubscriber =
		store.get( 'is_newsletter_subscriber' ) || isCountingSignupHint( store.get( 'newsletter_signup_client' ), isLoggedIn ) || isFromEmail();
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
