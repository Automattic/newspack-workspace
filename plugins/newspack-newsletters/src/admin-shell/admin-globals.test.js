import { getAdminUrl, getCptSlug } from './admin-globals';

describe( 'admin-globals', () => {
	const ORIGINAL = window.newspackNewslettersAdmin;

	afterEach( () => {
		if ( undefined === ORIGINAL ) {
			delete window.newspackNewslettersAdmin;
		} else {
			window.newspackNewslettersAdmin = ORIGINAL;
		}
	} );

	it( 'returns the localised adminUrl when present', () => {
		window.newspackNewslettersAdmin = { adminUrl: 'https://example.test/wp-admin/' };
		expect( getAdminUrl() ).toBe( 'https://example.test/wp-admin/' );
	} );

	it( 'falls back to /wp-admin/ when the global is missing', () => {
		delete window.newspackNewslettersAdmin;
		expect( getAdminUrl() ).toBe( '/wp-admin/' );
	} );

	it( 'falls back when the global exists but adminUrl is unset', () => {
		window.newspackNewslettersAdmin = {};
		expect( getAdminUrl() ).toBe( '/wp-admin/' );
	} );

	it( 'returns the localised cptSlug when present', () => {
		window.newspackNewslettersAdmin = { cptSlug: 'something_else' };
		expect( getCptSlug() ).toBe( 'something_else' );
	} );

	it( 'falls back to newspack_nl_cpt when the global is missing', () => {
		delete window.newspackNewslettersAdmin;
		expect( getCptSlug() ).toBe( 'newspack_nl_cpt' );
	} );
} );
