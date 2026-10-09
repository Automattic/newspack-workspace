/* globals grecaptcha */

/**
 * Internal dependencies
 */
import { getV3Token } from './utils';

describe( 'getV3Token', () => {
	let form;

	beforeEach( () => {
		global.newspack_recaptcha_data = { site_key: 'site-key', version: 'v3' };
		global.grecaptcha = {
			ready: jest.fn( callback => callback() ),
			execute: jest.fn().mockResolvedValue( 'fresh-token' ),
		};
		form = document.createElement( 'form' );
		form.setAttribute( 'data-newspack-recaptcha', 'newspack_newsletter_signup' );
	} );

	afterEach( () => {
		delete global.grecaptcha;
		delete global.newspack_recaptcha_data;
	} );

	it( "executes reCAPTCHA with the form's action and resolves with the token", async () => {
		await expect( getV3Token( form ) ).resolves.toBe( 'fresh-token' );
		expect( grecaptcha.execute ).toHaveBeenCalledWith( 'site-key', { action: 'newspack_newsletter_signup' } );
	} );

	it( 'defaults the action to submit', async () => {
		form.removeAttribute( 'data-newspack-recaptcha' );
		await getV3Token( form );
		expect( grecaptcha.execute ).toHaveBeenCalledWith( 'site-key', { action: 'submit' } );
	} );

	it( "writes the token to the form's hidden field when present", async () => {
		const field = document.createElement( 'input' );
		field.type = 'hidden';
		field.name = 'g-recaptcha-response';
		field.value = 'used-token';
		form.appendChild( field );
		await getV3Token( form );
		expect( field.value ).toBe( 'fresh-token' );
	} );

	it( 'waits for the reCAPTCHA API to be ready', async () => {
		let readyCallback;
		grecaptcha.ready = jest.fn( callback => {
			readyCallback = callback;
		} );
		const promise = getV3Token( form );
		expect( grecaptcha.execute ).not.toHaveBeenCalled();
		readyCallback();
		await expect( promise ).resolves.toBe( 'fresh-token' );
	} );

	it( 'rejects when reCAPTCHA fails', async () => {
		grecaptcha.execute.mockRejectedValue( new Error( 'network' ) );
		await expect( getV3Token( form ) ).rejects.toThrow( 'network' );
	} );

	it( 'rejects instead of hanging when execute throws inside a deferred ready callback', async () => {
		grecaptcha.ready = jest.fn( callback => setTimeout( callback, 0 ) );
		grecaptcha.execute = jest.fn( () => {
			throw new Error( 'Invalid site key' );
		} );
		await expect( getV3Token( form ) ).rejects.toThrow( 'Invalid site key' );
	} );

	it( "queues the request when Google's script loads after this one", async () => {
		delete global.grecaptcha;
		delete window.___grecaptcha_cfg;
		const promise = getV3Token( form );
		expect( window.___grecaptcha_cfg.fns ).toHaveLength( 1 );

		// Google's script loads: it defines the API and runs the queued functions.
		global.grecaptcha = { ready: jest.fn(), execute: jest.fn().mockResolvedValue( 'late-token' ) };
		window.___grecaptcha_cfg.fns.forEach( fn => fn() );

		await expect( promise ).resolves.toBe( 'late-token' );
		expect( grecaptcha.execute ).toHaveBeenCalledWith( 'site-key', { action: 'newspack_newsletter_signup' } );
		delete window.___grecaptcha_cfg;
	} );
} );
