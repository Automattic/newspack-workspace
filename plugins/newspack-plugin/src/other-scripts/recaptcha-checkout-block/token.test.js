import { getToken, needsToken, withToken } from './token';

describe( 'needsToken', () => {
	it( 'matches placing an order, however the path is written', () => {
		expect( needsToken( { method: 'POST', path: '/wc/store/v1/checkout' } ) ).toBe( true );
		expect( needsToken( { method: 'post', path: '/wc/store/checkout?_locale=user' } ) ).toBe( true );
		expect( needsToken( { method: 'POST', url: 'https://example.test/wp-json/wc/store/v1/checkout' } ) ).toBe( true );
	} );

	it( 'leaves other requests alone', () => {
		expect( needsToken( { method: 'GET', path: '/wc/store/v1/checkout' } ) ).toBe( false );
		expect( needsToken( { method: 'PUT', path: '/wc/store/v1/checkout' } ) ).toBe( false );
		expect( needsToken( { method: 'POST', path: '/wc/store/v1/checkout/5' } ) ).toBe( false );
		expect( needsToken( { method: 'POST', path: '/wc/store/v1/cart/add-item' } ) ).toBe( false );
	} );

	it( 'leaves wallet requests alone', () => {
		const path = '/wc/store/v1/checkout';
		expect( needsToken( { method: 'POST', path, headers: { 'X-WCSTRIPE-EXPRESS-CHECKOUT': true } } ) ).toBe( false );
		expect( needsToken( { method: 'POST', path, headers: { 'X-WooPayments-Tokenized-Cart': true } } ) ).toBe( false );
	} );
} );

describe( 'withToken', () => {
	it( 'keeps data and other extensions', () => {
		const options = { path: '/x', data: { payment_method: 'cheque', extensions: { other: { a: 1 } } } };
		expect( withToken( options, 't' ) ).toEqual( {
			path: '/x',
			data: { payment_method: 'cheque', extensions: { other: { a: 1 }, newspack: { recaptcha_token: 't' } } },
		} );
	} );

	it( 'works with no data', () => {
		expect( withToken( {}, 't' ).data.extensions.newspack.recaptcha_token ).toBe( 't' );
	} );
} );

describe( 'getToken', () => {
	afterEach( () => {
		delete global.grecaptcha;
		jest.useRealTimers();
	} );

	it( 'settles with no token when the API never loaded', async () => {
		await expect( getToken( 'key', 'v3' ) ).resolves.toBe( '' );
	} );

	it( 'gets a v3 token', async () => {
		global.grecaptcha = { ready: fn => fn(), execute: jest.fn().mockResolvedValue( 'token' ) };
		await expect( getToken( 'key', 'v3' ) ).resolves.toBe( 'token' );
		expect( global.grecaptcha.execute ).toHaveBeenCalledWith( 'key', { action: 'checkout' } );
	} );

	it( 'settles with no token when v3 rejects', async () => {
		global.grecaptcha = { ready: fn => fn(), execute: jest.fn().mockRejectedValue( new Error( 'no' ) ) };
		await expect( getToken( 'key', 'v3' ) ).resolves.toBe( '' );
	} );

	it( 'settles with no token when ready throws', async () => {
		global.grecaptcha = {
			ready: () => {
				throw new Error( 'no' );
			},
		};
		await expect( getToken( 'key', 'v3' ) ).resolves.toBe( '' );
	} );

	it( 'settles by timeout when v3 never answers', async () => {
		jest.useFakeTimers();
		global.grecaptcha = { ready: () => {} };
		const token = getToken( 'key', 'v3' );
		jest.advanceTimersByTime( 15000 );
		await expect( token ).resolves.toBe( '' );
	} );

	it( 'renders the v2 widget once, and takes a fresh token each time', async () => {
		let callback;
		global.grecaptcha = {
			ready: fn => fn(),
			render: jest.fn( ( container, options ) => {
				callback = options.callback;
				return 7;
			} ),
			reset: jest.fn(),
			execute: jest.fn( () => callback( `token-${ global.grecaptcha.execute.mock.calls.length }` ) ),
		};
		await expect( getToken( 'key', 'v2_invisible' ) ).resolves.toBe( 'token-1' );
		await expect( getToken( 'key', 'v2_invisible' ) ).resolves.toBe( 'token-2' );
		expect( global.grecaptcha.render ).toHaveBeenCalledTimes( 1 );
		expect( global.grecaptcha.reset ).toHaveBeenCalledWith( 7 );
	} );

	it( 'settles by timeout when a v2 challenge is dismissed', async () => {
		jest.useFakeTimers();
		global.grecaptcha = { ready: fn => fn(), reset: jest.fn(), execute: jest.fn() };
		const token = getToken( 'key', 'v2_invisible' );
		jest.advanceTimersByTime( 120000 );
		await expect( token ).resolves.toBe( '' );
	} );
} );
