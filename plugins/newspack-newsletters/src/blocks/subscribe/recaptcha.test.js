/**
 * Internal dependencies
 */
import { getRecaptchaToken } from './recaptcha';

describe( 'getRecaptchaToken', () => {
	let form;

	beforeEach( () => {
		form = document.createElement( 'form' );
		form.setAttribute( 'data-newspack-recaptcha', 'newspack_newsletter_signup' );
		delete window.newspack_grecaptcha;
	} );

	afterEach( () => {
		delete window.newspack_grecaptcha;
	} );

	it( 'returns null without waiting when reCAPTCHA is disabled', async () => {
		const start = Date.now();
		await expect( getRecaptchaToken( form, { useCaptcha: false, timeout: 1000 } ) ).resolves.toBeNull();
		expect( Date.now() - start ).toBeLessThan( 100 );
	} );

	it( 'returns a fresh v3 token for the form', async () => {
		const getV3Token = jest.fn().mockResolvedValue( 'token-1' );
		window.newspack_grecaptcha = { version: 'v3', getV3Token };
		await expect( getRecaptchaToken( form, { useCaptcha: true } ) ).resolves.toBe( 'token-1' );
		expect( getV3Token ).toHaveBeenCalledWith( form );
	} );

	it( 'waits for the reCAPTCHA script when it loads after the reader submits', async () => {
		const getV3Token = jest.fn().mockResolvedValue( 'late-token' );
		setTimeout( () => {
			window.newspack_grecaptcha = { version: 'v3', getV3Token };
		}, 250 );
		await expect( getRecaptchaToken( form, { useCaptcha: true, timeout: 2000 } ) ).resolves.toBe( 'late-token' );
	} );

	it( 'gives up after the timeout when the reCAPTCHA script never loads', async () => {
		await expect( getRecaptchaToken( form, { useCaptcha: true, timeout: 150 } ) ).resolves.toBeNull();
	} );

	it( 'does not wait for a token from an older newspack-plugin without getV3Token', async () => {
		window.newspack_grecaptcha = { version: 'v3', render: jest.fn() };
		const start = Date.now();
		await expect( getRecaptchaToken( form, { useCaptcha: true, timeout: 1000 } ) ).resolves.toBeNull();
		expect( Date.now() - start ).toBeLessThan( 100 );
	} );

	it( 'leaves reCAPTCHA v2 to its own submit handler', async () => {
		const getV3Token = jest.fn();
		window.newspack_grecaptcha = { version: 'v2_invisible', getV3Token };
		await expect( getRecaptchaToken( form, { useCaptcha: true } ) ).resolves.toBeNull();
		expect( getV3Token ).not.toHaveBeenCalled();
	} );

	it( 'gives up after the timeout when the token request never settles', async () => {
		window.newspack_grecaptcha = { version: 'v3', getV3Token: jest.fn( () => new Promise( () => {} ) ) };
		const start = Date.now();
		await expect( getRecaptchaToken( form, { useCaptcha: true, timeout: 200 } ) ).resolves.toBeNull();
		expect( Date.now() - start ).toBeLessThan( 1000 );
	} );

	it( 'counts the wait for the script against the same timeout', async () => {
		setTimeout( () => {
			window.newspack_grecaptcha = { version: 'v3', getV3Token: jest.fn( () => new Promise( () => {} ) ) };
		}, 150 );
		const start = Date.now();
		await expect( getRecaptchaToken( form, { useCaptcha: true, timeout: 300 } ) ).resolves.toBeNull();
		expect( Date.now() - start ).toBeLessThan( 600 );
	} );

	it( 'returns null when fetching the token fails', async () => {
		window.newspack_grecaptcha = { version: 'v3', getV3Token: jest.fn().mockRejectedValue( new Error( 'network' ) ) };
		await expect( getRecaptchaToken( form, { useCaptcha: true } ) ).resolves.toBeNull();
	} );
} );
