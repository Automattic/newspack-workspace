/**
 * How long to wait for newspack-plugin's reCAPTCHA script before submitting without a token.
 *
 * The script is a dependency of this block's script, but performance plugins that delay scripts
 * until the first interaction can break that order, so a reader who submits right away can get
 * here before it has loaded.
 */
export const RECAPTCHA_WAIT_MS = 10000;

const POLL_INTERVAL_MS = 100;

/**
 * Wait for newspack-plugin's reCAPTCHA script to expose its API.
 *
 * Stops waiting as soon as the API exists, even without `getV3Token` (an older newspack-plugin),
 * so the form doesn't stall on a token that can never arrive.
 *
 * @param {number} timeout Maximum time to wait, in milliseconds.
 *
 * @return {Promise<Object|null>} The API, or null if it didn't load in time.
 */
function waitForRecaptchaApi( timeout ) {
	return new Promise( resolve => {
		const start = Date.now();
		const check = () => {
			if ( window.newspack_grecaptcha ) {
				return resolve( window.newspack_grecaptcha );
			}
			if ( Date.now() - start >= timeout ) {
				return resolve( null );
			}
			setTimeout( check, POLL_INTERVAL_MS );
		};
		check();
	} );
}

/**
 * Get a fresh reCAPTCHA v3 token for a subscribe form, right before it is submitted.
 *
 * v3 tokens are single-use, so a fresh one is needed on every submission, including a retry
 * after a server-side error.
 *
 * @param {HTMLElement} form               The subscribe form.
 * @param {Object}      options
 * @param {boolean}     options.useCaptcha Whether the site has reCAPTCHA enabled.
 * @param {number}      options.timeout    Maximum time to spend getting a token, in milliseconds. Covers
 *                                         waiting for both scripts and the token request, so the form
 *                                         can never stay disabled longer than this.
 *
 * @return {Promise<string|null>} The token, or null when reCAPTCHA v3 isn't in use or no token arrived in time.
 */
export async function getRecaptchaToken( form, { useCaptcha = false, timeout = RECAPTCHA_WAIT_MS } = {} ) {
	if ( ! useCaptcha ) {
		return null;
	}
	let timer;
	const deadline = new Promise( resolve => {
		timer = setTimeout( () => resolve( null ), timeout );
	} );
	try {
		return await Promise.race( [ fetchToken( form, timeout ), deadline ] );
	} finally {
		clearTimeout( timer );
	}
}

/**
 * Wait for the reCAPTCHA API and fetch a v3 token.
 *
 * @param {HTMLElement} form    The subscribe form.
 * @param {number}      timeout Maximum time to wait for the reCAPTCHA script, in milliseconds.
 *
 * @return {Promise<string|null>} The token, or null when reCAPTCHA v3 isn't available.
 */
async function fetchToken( form, timeout ) {
	const api = await waitForRecaptchaApi( timeout );
	// reCAPTCHA v2 intercepts the submit event itself, and an older newspack-plugin has no getV3Token.
	if ( ! api || 'v3' !== api.version || typeof api.getV3Token !== 'function' ) {
		return null;
	}
	try {
		return ( await api.getV3Token( form ) ) || null;
	} catch ( e ) {
		return null;
	}
}
