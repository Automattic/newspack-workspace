/**
 * Closing the Google sign-in popup without finishing must release the busy
 * Google button, and a completed sign-in must not trigger that reset.
 */

describe( 'google oauth popup', () => {
	let form;
	let button;
	let authWindow;

	beforeEach( async () => {
		jest.useFakeTimers();
		document.body.innerHTML = '<form><button type="button" class="newspack-ui__button--google-oauth">Google</button></form>';
		form = document.querySelector( 'form' );
		form.startLoginFlow = jest.fn();
		form.endLoginFlow = jest.fn();
		button = form.querySelector( 'button' );
		authWindow = { closed: false, close: jest.fn(), location: '' };
		window.open = jest.fn( () => authWindow );
		global.fetch = jest.fn( url =>
			Promise.resolve( {
				status: 200,
				json: () => Promise.resolve( String( url ).includes( 'register' ) ? { message: 'ok', data: {} } : 'https://accounts.example.test' ),
			} )
		);
		jest.isolateModules( () => require( './google-oauth' ) );
		button.click();
		await Promise.resolve();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'ends the login flow when the popup is closed without signing in', () => {
		authWindow.closed = true;
		jest.advanceTimersByTime( 1500 );

		expect( form.endLoginFlow ).toHaveBeenCalledTimes( 1 );
		expect( form.endLoginFlow ).toHaveBeenCalledWith();
	} );

	it( 'does not reset after a successful sign-in closes the popup', async () => {
		window.dispatchEvent( new MessageEvent( 'message', { origin: window.location.origin, data: 'google-oauth-success' } ) );
		authWindow.closed = true;
		jest.advanceTimersByTime( 1500 );

		expect( form.endLoginFlow ).not.toHaveBeenCalledWith();
	} );
} );
