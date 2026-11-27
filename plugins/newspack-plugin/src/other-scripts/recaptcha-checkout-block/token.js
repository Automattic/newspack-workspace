/* globals grecaptcha */

const CHECKOUT_PATH = /\/wc\/store(\/v\d+)?\/checkout\/?$/;
const WALLET_HEADERS = [ 'x-wcstripe-express-checkout', 'x-woopayments-tokenized-cart' ];

// A v2 reader may be solving a challenge. reCAPTCHA has no event for a dismissed one.
const V2_TIMEOUT = 120000;
const V3_TIMEOUT = 15000;

/**
 * Whether an apiFetch request places an order that needs a token. Wallet requests
 * are exempt on the server, and their buttons can't wait for a challenge.
 *
 * @param {Object} options         apiFetch options.
 * @param {string} options.method  Request method.
 * @param {string} options.path    Request path.
 * @param {string} options.url     Request URL, when there is no path.
 * @param {Object} options.headers Request headers.
 * @return {boolean} Whether the request needs a token.
 */
export function needsToken( { method, path, url, headers = {} } ) {
	if ( 'POST' !== method?.toUpperCase() ) {
		return false;
	}
	const pathname = ( path || url || '' ).split( /[?#]/ )[ 0 ];
	if ( ! CHECKOUT_PATH.test( pathname ) ) {
		return false;
	}
	return ! Object.keys( headers ).some( header => WALLET_HEADERS.includes( header.toLowerCase() ) );
}

/**
 * Add a token to apiFetch options, keeping any extension data already there.
 *
 * @param {Object} options apiFetch options.
 * @param {string} token   reCAPTCHA token.
 * @return {Object} New options.
 */
export function withToken( options, token ) {
	const extensions = options.data?.extensions;
	return {
		...options,
		data: {
			...options.data,
			extensions: { ...extensions, newspack: { ...extensions?.newspack, recaptcha_token: token } },
		},
	};
}

/**
 * Resolve to a promise's token, or to no token if it fails or takes too long.
 *
 * @param {Function} fetchToken Function returning a promise of a token.
 * @param {number}   timeout    Milliseconds to wait.
 * @return {Promise<string>} The token, or an empty string.
 */
function settle( fetchToken, timeout ) {
	return new Promise( resolve => {
		const timer = setTimeout( () => resolve( '' ), timeout );
		const finish = token => {
			clearTimeout( timer );
			resolve( token || '' );
		};
		try {
			fetchToken().then( finish, () => finish( '' ) );
		} catch {
			finish( '' );
		}
	} );
}

let v2Widget = null;
let v2Pending = () => {};

/**
 * Run the invisible v2 widget, rendering it the first time.
 *
 * @param {string} siteKey reCAPTCHA site key.
 * @return {Promise<string>} A token, or an empty string on a reCAPTCHA error.
 */
function getV2Token( siteKey ) {
	return new Promise( ( resolve, reject ) => {
		grecaptcha.ready( () => {
			try {
				if ( null === v2Widget ) {
					const container = document.createElement( 'div' );
					document.body.append( container );
					const fail = () => v2Pending( '' );
					v2Widget = grecaptcha.render( container, {
						sitekey: siteKey,
						size: 'invisible',
						isolated: true,
						callback: token => v2Pending( token ),
						'error-callback': fail,
						'expired-callback': fail,
					} );
				} else {
					grecaptcha.reset( v2Widget );
				}
				v2Pending = resolve;
				grecaptcha.execute( v2Widget );
			} catch ( error ) {
				reject( error );
			}
		} );
	} );
}

/**
 * Get a fresh token. Always settles, because the Checkout block waits on this: with
 * no token, the request goes ahead and the server's refusal is the reader's notice.
 *
 * @param {string} siteKey reCAPTCHA site key.
 * @param {string} version reCAPTCHA version setting.
 * @return {Promise<string>} A token, or an empty string.
 */
export function getToken( siteKey, version ) {
	if ( 'undefined' === typeof grecaptcha ) {
		return Promise.resolve( '' );
	}
	if ( 'v3' === version ) {
		const getV3Token = () =>
			new Promise( resolve => grecaptcha.ready( resolve ) ).then( () => grecaptcha.execute( siteKey, { action: 'checkout' } ) );
		return settle( getV3Token, V3_TIMEOUT );
	}
	return settle( () => getV2Token( siteKey ), V2_TIMEOUT );
}
