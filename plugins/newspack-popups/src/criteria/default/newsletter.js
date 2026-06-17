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
