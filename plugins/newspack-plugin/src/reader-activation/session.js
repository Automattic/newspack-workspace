/**
 * Internal dependencies
 */
import { getCookie } from './utils';
import { EVENTS, emit } from './events';

/**
 * Shared session state. Uses newspack_reader_data so that all webpack entry
 * points read and write the same nonce, reader ID, and account_changed flag.
 */
window.newspack_reader_data = window.newspack_reader_data || {};

/**
 * What refreshApiNonce resolves with when the reader has no session left.
 */
export const LOGGED_OUT = Symbol( 'logged-out' );

let pending = null;
let refreshing = null;

/**
 * Hydrate the current session by fetching a fresh wp_rest nonce.
 *
 * Call this after authentication to enable authenticated REST API requests
 * without a full page refresh. Concurrent calls share the same in-flight request.
 * If hydration fails, the promise is reset so future calls can retry.
 *
 * @return {Promise<string|null>} The nonce string, or null if hydration failed.
 */
export function hydrateSession() {
	if ( ! pending ) {
		pending = fetchSession()
			.then( data => {
				const nonce = adoptNonce( data );
				if ( nonce ) {
					emit( EVENTS.session, data );
				} else {
					pending = null;
				}
				return nonce;
			} )
			.catch( () => {
				pending = null;
				return null;
			} );
	}
	return pending;
}

/**
 * Get the page's current API nonce.
 *
 * @return {string|null} The nonce string, or null if the page has none.
 */
export function getApiNonce() {
	return window.newspack_reader_data?.nonce || null;
}

/**
 * Store a nonce from the server, unless it belongs to another reader.
 *
 * A page holds the data of the reader it was opened for, so a nonce for anyone
 * else is refused and recorded as final for this page: the store then keeps this
 * page's writes away from the storage it shares with that reader's tabs. Only a
 * reload clears it, even if the first reader logs back in.
 *
 * @param {Object|null} data         Server response.
 * @param {string}      data.nonce   Nonce.
 * @param {number}      data.user_id User the nonce belongs to.
 *
 * @return {string|null} The nonce, or null if it was not adopted.
 */
function adoptNonce( data ) {
	if ( ! data?.nonce ) {
		return null;
	}
	const pageUserId = window.newspack_reader_data.user_id;
	// wp_localize_script() delivers the page's ID as a string.
	if ( pageUserId && Number( data.user_id ) !== Number( pageUserId ) ) {
		window.newspack_reader_data.account_changed = true;
		return null;
	}
	window.newspack_reader_data.nonce = data.nonce;
	window.newspack_reader_data.user_id = data.user_id;
	return data.nonce;
}

/**
 * Replace the page's nonce with one for the current session.
 *
 * A nonce is tied to the session it was created in, so a page loses write access
 * when the reader's session changes. Unlike hydrateSession, this works at any
 * time after authentication. A failure is not final: the page's reader can log
 * back in, so each failed save asks again.
 *
 * @return {Promise<string|symbol|null>} The new nonce, LOGGED_OUT, or null on failure.
 */
export function refreshApiNonce() {
	if ( ! refreshing ) {
		refreshing = fetchApiNonce().finally( () => {
			refreshing = null;
		} );
	}
	return refreshing;
}

/**
 * Fetch a nonce from the nonce endpoint.
 *
 * The action has no logged-out handler, so admin-ajax answers a visitor with no
 * session with a 400 and a body of `0`. A cross-origin request carries no cookies
 * and gets the same answer, which says nothing about the reader.
 *
 * @return {Promise<string|symbol|null>} The nonce, LOGGED_OUT, or null on failure.
 */
async function fetchApiNonce() {
	const nonceUrl = window.newspack_reader_data?.nonce_url;
	if ( ! nonceUrl ) {
		return null;
	}
	try {
		const response = await fetch( nonceUrl, {
			credentials: 'same-origin',
			cache: 'no-store',
		} );
		if ( 400 === response.status ) {
			const isSameOrigin = new URL( nonceUrl, window.location.href ).origin === window.location.origin;
			return isSameOrigin && '0' === ( await response.text() ).trim() ? LOGGED_OUT : null;
		}
		return response.ok ? adoptNonce( await response.json() ) : null;
	} catch {
		return null;
	}
}

/**
 * Fetch session data from the hydration endpoint.
 *
 * @return {Promise<Object|null>} The response data, or null on failure.
 */
async function fetchSession() {
	const cid = getCookie( 'newspack-cid' );
	if ( ! cid ) {
		return null;
	}

	try {
		const sessionUrl = window.newspack_reader_data?.session_url || '/wp-json/newspack/v1/reader/session';
		const response = await fetch( sessionUrl, {
			credentials: 'same-origin',
		} );
		if ( ! response.ok ) {
			return null;
		}
		return await response.json();
	} catch {
		return null;
	}
}
