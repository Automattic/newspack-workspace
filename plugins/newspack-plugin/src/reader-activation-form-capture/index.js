/**
 * Internal dependencies
 */
import '../shared/js/public-path';
import { getMatchedForms, getEmailValue, getNameValues } from './utils';

// v3 tokens expire at 120s; refresh with margin.
const CAPTCHA_TOKEN_TTL = 100 * 1000;

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( readerActivation => {
	const config = window.newspack_form_capture || {};
	const selectors = Array.isArray( config.selectors ) ? config.selectors : [];
	if ( ! selectors.length ) {
		return;
	}

	const captured = new Set();
	const attached = new WeakSet();
	let warmToken = null;

	const rasConfig = window.newspack_ras_config || {};
	const isV3 = 'v3' === rasConfig.captcha_version && rasConfig.captcha_site_key;

	/**
	 * Pre-acquire a reCAPTCHA v3 token while the reader interacts with a
	 * matched form, so a token is ready at submit time — the submit-time
	 * request must survive page navigation and cannot await acquisition.
	 * v2 flows render an interactive widget and cannot be warmed; for them
	 * register() falls back to submit-time acquisition (best-effort on
	 * non-AJAX forms).
	 */
	const warmCaptcha = () => {
		if ( ! isV3 || ! window.grecaptcha ) {
			return;
		}
		if ( warmToken && Date.now() - warmToken.timestamp < CAPTCHA_TOKEN_TTL ) {
			return;
		}
		window.grecaptcha.ready( () => {
			window.grecaptcha
				.execute( rasConfig.captcha_site_key, { action: 'integration_registration' } )
				.then( token => {
					warmToken = { token, timestamp: Date.now() };
				} )
				.catch( () => {} );
		} );
	};

	const handleSubmit = event => {
		const form = event.target;
		if ( form.checkValidity && ! form.checkValidity() ) {
			return;
		}
		const email = getEmailValue( form );
		if ( ! email || captured.has( email ) ) {
			return;
		}
		if ( readerActivation.getReader?.()?.email === email ) {
			return;
		}
		captured.add( email );
		const options = { keepalive: true };
		if ( warmToken && Date.now() - warmToken.timestamp < CAPTCHA_TOKEN_TTL ) {
			options.captchaToken = warmToken.token;
			// v3 tokens are single-use.
			warmToken = null;
		}
		readerActivation
			.register( email, 'form-capture', getNameValues( form ), options )
			.then( () => {
				// has_lists arrives as a stringy boolean ('1'/'') via
				// wp_localize_script — truthiness check only.
				if ( config.has_lists ) {
					readerActivation.store.set( 'is_newsletter_subscriber', true );
				}
			} )
			.catch( () => {} );
	};

	const attach = () => {
		getMatchedForms( selectors ).forEach( form => {
			if ( attached.has( form ) ) {
				return;
			}
			attached.add( form );
			form.addEventListener( 'focusin', warmCaptcha );
			form.addEventListener( 'submit', handleSubmit, true );
		} );
	};

	attach();
	// Form plugins render/replace forms after load (multi-page, AJAX embeds).
	const observer = new MutationObserver( attach );
	observer.observe( document.body, { childList: true, subtree: true } );
} );
