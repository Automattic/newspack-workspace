/* globals newspack_recaptcha_checkout_data */

import { getToken, needsToken, withToken } from './token';

/**
 * Adds a reCAPTCHA token to the Checkout block's place-order request. A middleware is
 * the one point that can wait for a token just before the request, and its fresh
 * token per attempt lets a reader retry after a declined card.
 */
window.wp.apiFetch.use( async ( options, next ) => {
	if ( ! needsToken( options ) ) {
		return next( options );
	}
	const { site_key: siteKey, version } = newspack_recaptcha_checkout_data;
	const token = await getToken( siteKey, version );
	return next( token ? withToken( options, token ) : options );
} );
