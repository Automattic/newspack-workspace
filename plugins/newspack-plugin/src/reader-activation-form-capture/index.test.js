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
} );
