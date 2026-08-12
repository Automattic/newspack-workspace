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
	let warming = false;

	const rasConfig = window.newspack_ras_config || {};
	const isV3 = 'v3' === rasConfig.captcha_version && rasConfig.captcha_site_key;

	/**
	 * Run a callback once the reCAPTCHA API is available. grecaptcha is a
	 * deferred third-party script that can lose the race against a reader
	 * focusing an above-the-fold form (especially on slow connections), so
	 * when it hasn't landed yet, queue through Google's documented pre-load
	 * hook — api.js drains window.___grecaptcha_cfg.fns on load — instead of
	 * bailing with no retry.
	 *
	 * @param {Function} callback Called when the reCAPTCHA API is ready.
	 */
	const whenGrecaptchaReady = callback => {
		if ( window.grecaptcha ) {
			window.grecaptcha.ready( callback );
			return;
		}
		window.___grecaptcha_cfg = window.___grecaptcha_cfg || {};
		window.___grecaptcha_cfg.fns = window.___grecaptcha_cfg.fns || [];
		window.___grecaptcha_cfg.fns.push( callback );
	};

	/**
	 * Pre-acquire a reCAPTCHA v3 token while the reader interacts with a
	 * matched form, so a token is ready at submit time — the submit-time
	 * request must survive page navigation and cannot await acquisition.
	 * v2 flows render an interactive widget and cannot be warmed; the
	 * integration reports itself unsupported on v2 sites (see
	 * Form_Capture::get_unsupported_reason()).
	 */
	const warmCaptcha = () => {
		if ( ! isV3 ) {
			return;
		}
		if ( warmToken && Date.now() - warmToken.timestamp < CAPTCHA_TOKEN_TTL ) {
			return;
		}
		// The TTL check can't cover an acquisition still in flight — warmToken
		// stays null until one resolves — so tabbing through a form's fields
		// would queue a callback per focusin, each firing its own execute().
		if ( warming ) {
			return;
		}
		warming = true;
		const releaseWarming = () => {
			warming = false;
		};
		// try/catch, not only .finally(): a synchronous throw never produces a
		// promise to attach to, and would otherwise leave the flag set for the
		// rest of the pageview — no retry, and every later submit sent without
		// a token. Both frames are guarded, since ready() can throw before the
		// callback runs, and the callback can throw when ready() defers it.
		try {
			whenGrecaptchaReady( () => {
				try {
					window.grecaptcha
						.execute( rasConfig.captcha_site_key, { action: 'integration_registration' } )
						.then( token => {
							warmToken = { token, timestamp: Date.now() };
						} )
						.catch( () => {} )
						.finally( releaseWarming );
				} catch ( err ) {
					releaseWarming();
				}
			} );
		} catch ( err ) {
			releaseWarming();
		}
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
		readerActivation.register( email, 'form-capture', getNameValues( form ), options ).catch( error => {
			// Only failures that can succeed on a retry within this pageview
			// release the dedupe: a network error (the response never parsed,
			// so no code) or a server-side registration failure. Everything
			// else — existing reader, rate limit, invalid/rotated key, RAS
			// disabled — is permanent here, and retrying a 429 would work
			// against the rate limiter it just hit.
			if ( ! error?.code || 'registration_failed' === error.code ) {
				captured.delete( email );
				// Re-arm the warm token alongside the email: the consumed one
				// was single-use and nothing necessarily re-fires focusin
				// before a resubmit.
				warmCaptcha();
			}
		} );
	};

	const attach = () => {
		getMatchedForms( selectors ).forEach( form => {
			if ( attached.has( form ) ) {
				return;
			}
			attached.add( form );
			form.addEventListener( 'focusin', warmCaptcha );
			// No capture flag: submit always fires at the form itself, where
			// capture and bubble listeners run together in registration order,
			// so a capturing listener here cannot front-run a vendor handler.
			form.addEventListener( 'submit', handleSubmit );
		} );
	};

	attach();
	// Form plugins render/replace forms after load (multi-page, AJAX embeds).
	// Coalesce mutation bursts (infinite scroll, ad refresh) into one scan.
	let scanScheduled = false;
	const observer = new MutationObserver( mutations => {
		if ( scanScheduled ) {
			return;
		}
		// Only added elements can introduce forms — ignore removals and
		// text-only bursts so steady-state pages don't pay for re-scans.
		if ( ! mutations.some( mutation => Array.from( mutation.addedNodes ).some( node => 1 === node.nodeType ) ) ) {
			return;
		}
		scanScheduled = true;
		setTimeout( () => {
			scanScheduled = false;
			attach();
		}, 200 );
	} );
	observer.observe( document.body, { childList: true, subtree: true } );
} );
