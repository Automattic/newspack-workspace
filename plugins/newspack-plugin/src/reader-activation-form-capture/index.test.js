import { getMatchedForms } from './utils';

/**
 * Harness for the capture client. window.newspackRAS is a plain callback
 * array before RAS loads, so the client's bootstrap can be invoked directly
 * with a fake readerActivation — no RAS internals involved.
 *
 * The DOM must be in place before loading: the client scans for opted-in
 * forms at bootstrap (later additions go through a debounced
 * MutationObserver rescan these tests don't rely on).
 *
 * @param {string} html      Markup for document.body.
 * @param {Object} rasConfig window.newspack_ras_config value.
 * @return {Object} The fake readerActivation, with a jest.fn() register.
 */
const loadCaptureClient = ( html, rasConfig = {} ) => {
	document.body.innerHTML = html;
	window.newspackRAS = [];
	window.newspack_form_capture = { selectors: [ '.newspack-form-capture' ] };
	window.newspack_ras_config = rasConfig;
	jest.isolateModules( () => require( './index' ) );
	const readerActivation = {
		register: jest.fn( () => Promise.resolve( {} ) ),
		getReader: jest.fn( () => ( {} ) ),
	};
	window.newspackRAS.forEach( callback => callback( readerActivation ) );
	return readerActivation;
};

const submit = form => form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
const focusin = form => form.dispatchEvent( new Event( 'focusin', { bubbles: true } ) );
const flush = () => new Promise( resolve => setTimeout( resolve, 0 ) );

const FORM = `<form class="newspack-form-capture"><input type="email" value="reader@example.com"></form>`;
const V3_CONFIG = { captcha_version: 'v3', captcha_site_key: 'site-key' };

describe( 'form-capture client', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		// Every client load leaves once-listeners on the shared jsdom document
		// for the GF late-hook retry (a real page runs one bootstrap, so they
		// can't accumulate there). Drain them while window.gform is absent —
		// the retry is inert then — so they can't fire into a later test's
		// fakes. Runs after the nested describe's afterEach deletes the fake.
		document.dispatchEvent( new Event( 'DOMContentLoaded', { bubbles: true } ) );
		window.dispatchEvent( new Event( 'load' ) );
		delete window.newspackRAS;
		delete window.newspack_form_capture;
		delete window.newspack_ras_config;
		delete window.grecaptcha;
		delete window.___grecaptcha_cfg;
	} );

	it( 'captures an opted-in form submit once per email per pageview', () => {
		const ras = loadCaptureClient( FORM );
		const form = document.querySelector( 'form' );
		submit( form );
		submit( form );
		expect( ras.register ).toHaveBeenCalledTimes( 1 );
		expect( ras.register ).toHaveBeenCalledWith( 'reader@example.com', 'form-capture', expect.any( Object ), expect.any( Object ) );
	} );

	it( 'keeps the dedupe after an existing-reader conflict', async () => {
		const ras = loadCaptureClient( FORM );
		ras.register.mockImplementation( () => Promise.reject( Object.assign( new Error( 'Exists.' ), { code: 'reader_already_exists' } ) ) );
		const form = document.querySelector( 'form' );
		submit( form );
		await flush();
		submit( form );
		expect( ras.register ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [ 'rate_limit_exceeded', 'invalid_integration_key', 'reader_activation_disabled', 'recaptcha_failed' ] )(
		'keeps the dedupe after %s, which cannot succeed on retry',
		async code => {
			const ras = loadCaptureClient( FORM );
			ras.register.mockImplementation( () => Promise.reject( Object.assign( new Error( 'Rejected.' ), { code } ) ) );
			const form = document.querySelector( 'form' );
			submit( form );
			await flush();
			submit( form );
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'releases the dedupe after a network failure (no error code)', async () => {
		const ras = loadCaptureClient( FORM );
		ras.register.mockImplementationOnce( () => Promise.reject( new Error( 'Network down.' ) ) );
		const form = document.querySelector( 'form' );
		submit( form );
		await flush();
		submit( form );
		expect( ras.register ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'releases the dedupe after a server-side registration failure (5xx)', async () => {
		const ras = loadCaptureClient( FORM );
		ras.register.mockImplementationOnce( () => Promise.reject( Object.assign( new Error( 'Server error.' ), { code: 'registration_failed' } ) ) );
		const form = document.querySelector( 'form' );
		submit( form );
		await flush();
		submit( form );
		expect( ras.register ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'skips forms whose email matches the current reader', () => {
		const ras = loadCaptureClient( FORM );
		ras.getReader.mockReturnValue( { email: 'reader@example.com' } );
		submit( document.querySelector( 'form' ) );
		expect( ras.register ).not.toHaveBeenCalled();
	} );

	it( 'passes the warm captcha token once and re-arms after a transient failure', async () => {
		window.grecaptcha = {
			ready: callback => callback(),
			execute: jest.fn().mockResolvedValueOnce( 'warm-token-1' ).mockResolvedValueOnce( 'warm-token-2' ),
		};
		const ras = loadCaptureClient( FORM, V3_CONFIG );
		ras.register.mockImplementationOnce( () => Promise.reject( new Error( 'Network down.' ) ) );
		const form = document.querySelector( 'form' );

		focusin( form );
		await flush();
		submit( form );
		expect( ras.register.mock.calls[ 0 ][ 3 ].captchaToken ).toBe( 'warm-token-1' );

		// The transient failure released the dedupe and re-armed the token —
		// the consumed (single-use) token must not be reused on the resubmit.
		await flush();
		submit( form );
		expect( ras.register ).toHaveBeenCalledTimes( 2 );
		expect( ras.register.mock.calls[ 1 ][ 3 ].captchaToken ).toBe( 'warm-token-2' );
		expect( window.grecaptcha.execute ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'queues captcha warm-up until the reCAPTCHA API loads', async () => {
		// grecaptcha is a deferred third-party script — a reader can focus the
		// form before it lands. The warm-up must queue, not silently bail.
		const ras = loadCaptureClient( FORM, V3_CONFIG );
		const form = document.querySelector( 'form' );
		focusin( form );
		expect( window.___grecaptcha_cfg.fns ).toHaveLength( 1 );

		// api.js lands and drains the documented pre-load queue.
		window.grecaptcha = {
			ready: callback => callback(),
			execute: jest.fn( () => Promise.resolve( 'late-token' ) ),
		};
		window.___grecaptcha_cfg.fns.forEach( fn => fn() );
		await flush();

		submit( form );
		expect( ras.register.mock.calls[ 0 ][ 3 ].captchaToken ).toBe( 'late-token' );
	} );

	it( 'queues one warm-up however many fields the reader tabs through', async () => {
		// warmToken stays null until an acquisition resolves, so the TTL check
		// can't cover the gap: without an in-flight flag every focusin before
		// api.js lands would queue another callback, and the drain would fire
		// one execute() per field touched.
		const ras = loadCaptureClient( FORM, V3_CONFIG );
		const form = document.querySelector( 'form' );
		focusin( form );
		focusin( form );
		focusin( form );
		expect( window.___grecaptcha_cfg.fns ).toHaveLength( 1 );

		window.grecaptcha = {
			ready: callback => callback(),
			execute: jest.fn( () => Promise.resolve( 'one-token' ) ),
		};
		window.___grecaptcha_cfg.fns.forEach( fn => fn() );
		await flush();
		expect( window.grecaptcha.execute ).toHaveBeenCalledTimes( 1 );

		submit( form );
		expect( ras.register.mock.calls[ 0 ][ 3 ].captchaToken ).toBe( 'one-token' );
	} );

	it( 'recovers from a synchronous throw in ready()', async () => {
		// Same failure class as the execute() throw below, one frame further
		// out: the flag is set before the readiness call, so a throw there must
		// release it too or warm-up never runs again this pageview.
		let shouldThrow = true;
		window.grecaptcha = {
			ready: callback => {
				if ( shouldThrow ) {
					throw new Error( 'grecaptcha.ready exploded.' );
				}
				callback();
			},
			execute: jest.fn( () => Promise.resolve( 'later-token' ) ),
		};
		const ras = loadCaptureClient( FORM, V3_CONFIG );
		const form = document.querySelector( 'form' );

		focusin( form );
		await flush();
		expect( window.grecaptcha.execute ).not.toHaveBeenCalled();

		shouldThrow = false;
		focusin( form );
		await flush();
		expect( window.grecaptcha.execute ).toHaveBeenCalledTimes( 1 );

		submit( form );
		expect( ras.register.mock.calls[ 0 ][ 3 ].captchaToken ).toBe( 'later-token' );
	} );

	it( 'recovers from a synchronous throw in execute()', async () => {
		// The in-flight flag must not survive a throw that never produces a
		// promise: it would block every later warm-up, and each submit would
		// go out without a token — the failure the flag exists to prevent.
		let shouldThrow = true;
		window.grecaptcha = {
			ready: callback => callback(),
			execute: jest.fn( () => {
				if ( shouldThrow ) {
					throw new Error( 'grecaptcha exploded.' );
				}
				return Promise.resolve( 'recovered-token' );
			} ),
		};
		const ras = loadCaptureClient( FORM, V3_CONFIG );
		const form = document.querySelector( 'form' );

		focusin( form );
		await flush();
		expect( window.grecaptcha.execute ).toHaveBeenCalledTimes( 1 );

		shouldThrow = false;
		focusin( form );
		await flush();
		expect( window.grecaptcha.execute ).toHaveBeenCalledTimes( 2 );

		submit( form );
		expect( ras.register.mock.calls[ 0 ][ 3 ].captchaToken ).toBe( 'recovered-token' );
	} );

	it( 'does not warm captcha on v2 sites', () => {
		window.grecaptcha = { ready: callback => callback(), execute: jest.fn() };
		loadCaptureClient( FORM, { captcha_version: 'v2_invisible', captcha_site_key: 'site-key' } );
		focusin( document.querySelector( 'form' ) );
		expect( window.grecaptcha.execute ).not.toHaveBeenCalled();
	} );

	it( 'attaches to forms added after load via the mutation observer rescan', async () => {
		const ras = loadCaptureClient( '<div id="root"></div>' );
		document.getElementById( 'root' ).innerHTML = FORM;
		// MutationObserver delivery is a microtask; the rescan is debounced 200ms.
		await new Promise( resolve => setTimeout( resolve, 250 ) );
		submit( document.querySelector( 'form' ) );
		expect( ras.register ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'getMatchedForms resolves an over-broad selector to every form (why bare selectors are rejected server-side)', () => {
		document.body.innerHTML = `<form id="a"></form><form id="b"></form>`;
		expect( getMatchedForms( [ 'form' ] ).map( f => f.id ) ).toEqual( [ 'a', 'b' ] );
	} );

	/**
	 * Gravity Forms (2.9+ theme framework) submits via programmatic
	 * form.submit(), which dispatches no submit event — and its own submit
	 * listener cancels any native submit event as an "unsupported flow" — so
	 * the generic submit listener can never fire on a GF form. Capture hooks
	 * GF's public async-filter bus instead.
	 */
	describe( 'gravity forms adapter', () => {
		/**
		 * Minimal stand-in for GF's gform.utils filter bus. submitViaGform
		 * replays what gform.submission.submitForm does: run the
		 * pre_submission filter chain, feeding each callback the previous
		 * one's return value — a callback that fails to return the payload
		 * hands undefined to GF and breaks the vendor's submission.
		 *
		 * @return {Object} { submitViaGform, addAsyncFilter } — the chain runner and the registration spy.
		 */
		const installFakeGform = () => {
			const filters = {};
			const addAsyncFilter = jest.fn( ( event, callback ) => {
				filters[ event ] = filters[ event ] || [];
				filters[ event ].push( callback );
			} );
			window.gform = { utils: { addAsyncFilter } };
			const submitViaGform = async ( form, data = {} ) => {
				let payload = { form, submissionType: 'submit', submissionMethod: 'postback', displayConfirmation: true, abort: false, ...data };
				for ( const callback of filters[ 'gform/submission/pre_submission' ] || [] ) {
					payload = await callback( payload );
				}
				return payload;
			};
			return { submitViaGform, addAsyncFilter };
		};

		afterEach( () => {
			delete window.gform;
		} );

		const GF_FORM = `<form id="gform_1" class="newspack-form-capture" novalidate><input type="email" name="input_2" value="gf-reader@example.com"></form>`;

		it( 'captures a matched form through the pre_submission filter and returns the payload to the chain', async () => {
			const { submitViaGform } = installFakeGform();
			const ras = loadCaptureClient( GF_FORM );
			const form = document.querySelector( 'form' );
			const payload = await submitViaGform( form );
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
			expect( ras.register ).toHaveBeenCalledWith( 'gf-reader@example.com', 'form-capture', expect.any( Object ), expect.any( Object ) );
			// The chain must receive the payload back intact or GF aborts.
			expect( payload ).toEqual( expect.objectContaining( { form, abort: false } ) );
		} );

		it( 'ignores submissions from GF forms that are not opted in', async () => {
			const { submitViaGform } = installFakeGform();
			const ras = loadCaptureClient( `${ GF_FORM }<form id="gform_2" novalidate><input type="email" value="other@example.com"></form>` );
			await submitViaGform( document.querySelector( '#gform_2' ) );
			expect( ras.register ).not.toHaveBeenCalled();
			// Positive control — the adapter is live, it just skipped the
			// un-opted-in form.
			await submitViaGform( document.querySelector( '#gform_1' ) );
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'returns the payload and keeps GF submitting even when capture throws', async () => {
			const { submitViaGform } = installFakeGform();
			const ras = loadCaptureClient( GF_FORM );
			ras.register.mockImplementation( () => {
				throw new Error( 'capture exploded' );
			} );
			const form = document.querySelector( 'form' );
			const payload = await submitViaGform( form );
			// The throw happened inside the filter…
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
			// …and the chain still got its payload back.
			expect( payload ).toEqual( expect.objectContaining( { form, abort: false } ) );
		} );

		it( 'hooks GF when its scripts land after the capture client, without double-registering', async () => {
			const ras = loadCaptureClient( GF_FORM );
			// GF's deferred scripts execute after ours; all are done by DOMContentLoaded.
			const { submitViaGform, addAsyncFilter } = installFakeGform();
			document.dispatchEvent( new Event( 'DOMContentLoaded', { bubbles: true } ) );
			window.dispatchEvent( new Event( 'load' ) );
			expect( addAsyncFilter ).toHaveBeenCalledTimes( 1 );
			await submitViaGform( document.querySelector( 'form' ) );
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does not double-register when GF was present at bootstrap and lifecycle events still fire', () => {
			const { addAsyncFilter } = installFakeGform();
			loadCaptureClient( GF_FORM );
			document.dispatchEvent( new Event( 'DOMContentLoaded', { bubbles: true } ) );
			window.dispatchEvent( new Event( 'load' ) );
			expect( addAsyncFilter ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'dedupes an email across the GF path and the generic submit path', async () => {
			const { submitViaGform } = installFakeGform();
			const ras = loadCaptureClient( GF_FORM );
			const form = document.querySelector( 'form' );
			await submitViaGform( form );
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
			submit( form );
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'captures on any submission type — a multi-page "next" is a submission, as it is on native-submitting tools', async () => {
			const { submitViaGform } = installFakeGform();
			const ras = loadCaptureClient( GF_FORM );
			await submitViaGform( document.querySelector( 'form' ), { submissionType: 'next' } );
			expect( ras.register ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'passes the warm captcha token on the GF path', async () => {
			window.grecaptcha = {
				ready: callback => callback(),
				execute: jest.fn( () => Promise.resolve( 'gf-warm-token' ) ),
			};
			const { submitViaGform } = installFakeGform();
			const ras = loadCaptureClient( GF_FORM, V3_CONFIG );
			const form = document.querySelector( 'form' );
			focusin( form );
			await flush();
			await submitViaGform( form );
			expect( ras.register.mock.calls[ 0 ][ 3 ].captchaToken ).toBe( 'gf-warm-token' );
		} );
	} );
} );
