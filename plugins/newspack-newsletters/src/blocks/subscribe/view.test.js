/**
 * Submission flow of the Subscribe block's frontend script, focused on reCAPTCHA v3 tokens.
 */

const flush = ( ms = 0 ) => new Promise( resolve => setTimeout( resolve, ms ) );

const renderBlock = () => {
	document.body.innerHTML = `
		<div class="newspack-newsletters-subscribe" data-success-message="Thanks">
			<form data-newspack-recaptcha="newspack_newsletter_signup" action="/subscribe">
				<input type="email" name="npe" value="reader@example.com" />
				<button type="submit">Sign up</button>
			</form>
			<div class="newspack-newsletters-subscribe__response">
				<div class="newspack-newsletters-subscribe__message"></div>
			</div>
		</div>
	`;
	const form = document.querySelector( 'form' );
	// view.js reads the email via the form's named-element access (form.npe), which browsers support but jsdom does not.
	Object.defineProperty( form, 'npe', { value: form.querySelector( '[name="npe"]' ) } );
	return form;
};

const submit = form => form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );

const jsonResponse = ( status, data ) => Promise.resolve( { status, json: () => Promise.resolve( data ) } );

const loadView = useCaptcha => {
	window.newspack_newsletters_subscribe_block = {
		invalid_email: 'Please enter a valid email address',
		use_captcha: useCaptcha ? '1' : '',
	};
	jest.isolateModules( () => {
		require( './view' );
	} );
};

describe( 'Subscribe block submission', () => {
	beforeEach( () => {
		delete window.newspack_grecaptcha;
		global.fetch = jest.fn();
	} );

	afterEach( () => {
		delete window.newspack_grecaptcha;
		delete global.fetch;
	} );

	it( 'waits for reCAPTCHA to load before submitting, then sends a token', async () => {
		const form = renderBlock();
		loadView( true );
		fetch.mockReturnValue( jsonResponse( 200, { message: 'ok', newspack_newsletters_subscribed: true } ) );

		submit( form );
		await flush( 300 );
		// The reCAPTCHA script hasn't loaded yet (e.g. delayed until first interaction), so nothing is sent.
		expect( fetch ).not.toHaveBeenCalled();

		window.newspack_grecaptcha = { version: 'v3', getV3Token: jest.fn().mockResolvedValue( 'fresh-token' ) };
		await flush( 300 );

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		const body = fetch.mock.calls[ 0 ][ 1 ].body;
		expect( body.get( 'g-recaptcha-response' ) ).toBe( 'fresh-token' );
		expect( body.get( 'npe' ) ).toBe( 'reader@example.com' );
	} );

	it( 'sends a new token when the reader retries after an error', async () => {
		const form = renderBlock();
		loadView( true );
		const getV3Token = jest.fn().mockResolvedValueOnce( 'token-1' ).mockResolvedValueOnce( 'token-2' );
		window.newspack_grecaptcha = { version: 'v3', getV3Token };
		fetch
			.mockReturnValueOnce( jsonResponse( 400, { message: 'You must select a list.' } ) )
			.mockReturnValueOnce( jsonResponse( 200, { message: 'ok', newspack_newsletters_subscribed: true } ) );

		submit( form );
		await flush( 50 );
		submit( form );
		await flush( 50 );

		expect( fetch ).toHaveBeenCalledTimes( 2 );
		expect( fetch.mock.calls[ 0 ][ 1 ].body.get( 'g-recaptcha-response' ) ).toBe( 'token-1' );
		expect( fetch.mock.calls[ 1 ][ 1 ].body.get( 'g-recaptcha-response' ) ).toBe( 'token-2' );
	} );

	it( 'submits right away without a token when reCAPTCHA is disabled', async () => {
		const form = renderBlock();
		loadView( false );
		fetch.mockReturnValue( jsonResponse( 200, { message: 'ok', newspack_newsletters_subscribed: true } ) );

		submit( form );
		await flush( 20 );

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect( fetch.mock.calls[ 0 ][ 1 ].body.has( 'g-recaptcha-response' ) ).toBe( false );
	} );
} );
