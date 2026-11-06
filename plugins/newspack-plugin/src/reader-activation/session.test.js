describe( 'session hydration', () => {
	const originalFetch = window.fetch;

	const hydrate = async sessionData => {
		window.fetch = jest.fn().mockResolvedValue( { ok: true, json: async () => sessionData } );
		// Each call starts from a fresh module: a settled hydration is cached for the page.
		jest.resetModules();
		return require( './session' ).hydrateSession();
	};

	beforeEach( () => {
		document.cookie = 'newspack-cid=abc';
		window.newspack_reader_data = { nonce: 'pagenonce0', user_id: '7' };
	} );
	afterEach( () => {
		window.fetch = originalFetch;
	} );

	it( 'gives a page opened anonymously the identity the nonce refresh compares against', async () => {
		window.newspack_reader_data = {};

		await hydrate( { nonce: 'a1b2c3d4e5', user_id: 7 } );

		expect( window.newspack_reader_data ).toEqual( { nonce: 'a1b2c3d4e5', user_id: 7 } );
	} );

	it( 'keeps a page on the reader it was opened for when another reader logs in', async () => {
		const hydratedNonce = await hydrate( { nonce: 'b1b2c3d4e5', user_id: 8 } );

		expect( hydratedNonce ).toBeNull();
		expect( window.newspack_reader_data ).toEqual( { nonce: 'pagenonce0', user_id: '7', account_changed: true } );
	} );
} );
