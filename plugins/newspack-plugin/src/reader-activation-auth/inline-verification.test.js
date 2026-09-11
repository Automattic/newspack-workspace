/**
 * The inline verification box mails a code and then hands the reader to the auth
 * modal. These pin the parts a reader pays for when they are wrong: a code that
 * was never sent must not advance them to the code-entry step, a refusal must say
 * what the server said, and a box wired twice must not mail two codes on one click.
 */

import { sendVerificationOTP, wireInlineVerificationBox } from './inline-verification';

const CONFIG = { url: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'nonce-123' };

/**
 * Stub fetch with one JSON response.
 *
 * @param {Object}  json     The parsed JSON body to resolve with.
 * @param {boolean} [ok]     Whether the response is an HTTP success.
 * @param {string}  [status] Status text, used as the error message when not ok.
 */
function mockFetchJSON( json, ok = true, status = 'Internal Server Error' ) {
	global.fetch = jest.fn( () => Promise.resolve( { ok, statusText: status, json: () => Promise.resolve( json ) } ) );
}

/**
 * Render a box in the shape both markups share.
 *
 * @return {HTMLElement} The box element.
 */
function renderBox() {
	document.body.innerHTML = `
		<div class="box">
			<p>We'll send a verification code to <strong class="email-address">reader@example.test</strong>.</p>
			<p data-error-target role="status" hidden></p>
			<p><button type="button" data-send-otp>Send code</button></p>
		</div>
	`;
	return document.querySelector( '.box' );
}

/**
 * Let the click handler's promise chain settle.
 */
const flush = () => new Promise( resolve => setTimeout( resolve, 0 ) );

afterEach( () => {
	delete global.fetch;
	document.body.innerHTML = '';
} );

describe( 'sendVerificationOTP', () => {
	it( 'rejects on a wp_send_json_error body, which arrives as HTTP 200', async () => {
		mockFetchJSON( { success: false, data: 'Please wait a minute before requesting another authorization code.' } );

		await expect( sendVerificationOTP( CONFIG ) ).rejects.toMatchObject( {
			message: 'Please wait a minute before requesting another authorization code.',
			// Flags the message as the server's own localized wording, so a caller can
			// show it to the reader. An unexpected exception's message is not shown.
			isServerMessage: true,
		} );
	} );

	it( 'resolves on success', async () => {
		mockFetchJSON( { success: true, data: 'OTP sent' } );

		await expect( sendVerificationOTP( CONFIG ) ).resolves.toEqual( { success: true, data: 'OTP sent' } );
	} );
} );

describe( 'wireInlineVerificationBox', () => {
	it( 'sends one code per click however many times the box is wired', async () => {
		const box = renderBox();
		mockFetchJSON( { success: true } );

		expect( wireInlineVerificationBox( box, { ...CONFIG, onSent: () => true } ) ).toBe( true );
		expect( wireInlineVerificationBox( box, { ...CONFIG, onSent: () => true } ) ).toBe( false );

		box.querySelector( '[data-send-otp]' ).click();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( "shows the server's wording and returns the reader to a button they can press", async () => {
		const box = renderBox();
		const onSent = jest.fn();
		mockFetchJSON( { success: false, data: 'Please wait a minute before requesting another authorization code.' } );

		wireInlineVerificationBox( box, { ...CONFIG, onSent, errorText: 'Something went wrong. Please try again.' } );
		const button = box.querySelector( '[data-send-otp]' );
		button.click();
		await flush();

		expect( onSent ).not.toHaveBeenCalled();
		expect( box.querySelector( '[data-error-target]' ).textContent ).toBe( 'Please wait a minute before requesting another authorization code.' );
		expect( box.querySelector( '[data-error-target]' ).hidden ).toBe( false );
		// The line naming the reader's address is a separate node, so a failed send
		// leaves them still able to see which address the code is going to.
		expect( box.querySelector( '.email-address' ) ).not.toBeNull();
		expect( button.disabled ).toBe( false );
		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( document.activeElement ).toBe( button );
	} );

	it( "falls back to the caller's copy when the failure is not the server refusing", async () => {
		const box = renderBox();
		mockFetchJSON( {}, false, 'Bad Gateway' );

		wireInlineVerificationBox( box, { ...CONFIG, onSent: () => true, errorText: 'Something went wrong. Please try again.' } );
		box.querySelector( '[data-send-otp]' ).click();
		await flush();

		expect( box.querySelector( '[data-error-target]' ).textContent ).toBe( 'Something went wrong. Please try again.' );
	} );

	it( 'recovers the box when onSent cannot take the reader onward', async () => {
		const box = renderBox();
		mockFetchJSON( { success: true } );

		// The code is already in the reader's inbox at this point, so a false return
		// has to leave a pressable button rather than a dead one.
		wireInlineVerificationBox( box, { ...CONFIG, onSent: () => false, errorText: 'Something went wrong. Please try again.' } );
		const button = box.querySelector( '[data-send-otp]' );
		button.click();
		await flush();

		expect( button.disabled ).toBe( false );
		expect( box.querySelector( '[data-error-target]' ).textContent ).toBe( 'Something went wrong. Please try again.' );
	} );

	it( 'leaves the box alone when onSent takes over', async () => {
		const box = renderBox();
		mockFetchJSON( { success: true } );

		wireInlineVerificationBox( box, { ...CONFIG, onSent: () => true, errorText: 'Something went wrong. Please try again.' } );
		box.querySelector( '[data-send-otp]' ).click();
		await flush();

		expect( box.querySelector( '[data-error-target]' ).hidden ).toBe( true );
		expect( box.querySelector( '[data-send-otp]' ).disabled ).toBe( true );
	} );

	it( 'reports a box with no send button as unwired', () => {
		document.body.innerHTML = '<div class="box"></div>';

		expect( wireInlineVerificationBox( document.querySelector( '.box' ), CONFIG ) ).toBe( false );
	} );
} );
