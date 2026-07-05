/**
 * Internal dependencies
 */
import { getCookie } from './utils';
import { EVENTS, emit } from './events';

/**
 * Shared nonce storage. Uses newspack_reader_data as the shared state so that
 * all webpack entry points read and write the same value.
 */
window.newspack_reader_data = window.newspack_reader_data || {};

let pending = null;

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
				if ( data?.nonce ) {
					window.newspack_reader_data.nonce = data.nonce;
					emit( EVENTS.session, data );
				} else {
					pending = null;
				}
				return data?.nonce || null;
			} )
			.catch( () => {
				pending = null;
				return null;
			} );
	}
	return pending;
}

/**
 * Get the cached API nonce from a previous hydrateSession call.
 *
 * @return {string|null} The nonce string, or null if not yet hydrated.
 */
export function getApiNonce() {
	return window.newspack_reader_data?.nonce || null;
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
