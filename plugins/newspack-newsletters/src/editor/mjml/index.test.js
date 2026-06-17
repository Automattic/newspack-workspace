// Spy on the network layer and the MJML compiler; the module under test only
// branches on the localized `use_woo_renderer` flag, so both externals are
// mocked to keep the test fast and deterministic.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( 'mjml-browser', () => ( {
	__esModule: true,
	default: jest.fn( () => ( { html: '<mjml-html />' } ) ),
} ) );

import apiFetch from '@wordpress/api-fetch';
import mjml2html from 'mjml-browser';
import { refreshEmailHtml } from './index';

describe( 'refreshEmailHtml', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	afterEach( () => {
		delete global.newspack_email_editor_data;
	} );

	it( 'GETs the server-rendered HTML endpoint when the Woo renderer flag is on and editing the newsletter CPT', async () => {
		global.newspack_email_editor_data = {
			use_woo_renderer: true,
			current_post_type: 'newspack_nl_cpt',
			newsletter_post_type: 'newspack_nl_cpt',
		};
		apiFetch.mockResolvedValueOnce( { html: '<server-html />' } );

		const result = await refreshEmailHtml( 123, 'A title', '<p>body</p>' );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const { path, method } = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( path ).toBe( '/newspack-newsletters/v1/post-html?post_id=123' );
		// GET request: no explicit method, no MJML POST body.
		expect( method ).toBeUndefined();
		expect( mjml2html ).not.toHaveBeenCalled();
		expect( result ).toEqual( { result: 'success', html: '<server-html />' } );
	} );

	it( 'returns an error shape when the server render request rejects', async () => {
		global.newspack_email_editor_data = {
			use_woo_renderer: true,
			current_post_type: 'newspack_nl_cpt',
			newsletter_post_type: 'newspack_nl_cpt',
		};
		const error = new Error( 'boom' );
		apiFetch.mockRejectedValueOnce( error );

		const result = await refreshEmailHtml( 123, 'A title', '<p>body</p>' );

		expect( result ).toEqual( { result: 'error', error } );
	} );

	it( 'falls back to the MJML POST + compile path when the flag is off', async () => {
		global.newspack_email_editor_data = { use_woo_renderer: false };
		apiFetch.mockResolvedValueOnce( '<mjml />' );

		const result = await refreshEmailHtml( 123, 'A title', '<p>body</p>' );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-newsletters/v1/post-mjml',
			method: 'POST',
			data: { post_id: 123, title: 'A title', content: '<p>body</p>' },
		} );
		expect( mjml2html ).toHaveBeenCalledWith( '<mjml />', { keepComments: false, minify: true } );
		expect( result ).toEqual( { result: 'success', html: '<mjml-html />' } );
	} );

	it( 'falls back to MJML when the flag is on but the current post is not the newsletter CPT', async () => {
		// e.g. the Layout editor (newspack_nl_layo_cpt): /post-html only accepts the
		// newsletter CPT, so the gate must route these through the MJML path.
		global.newspack_email_editor_data = {
			use_woo_renderer: true,
			current_post_type: 'newspack_nl_layo_cpt',
			newsletter_post_type: 'newspack_nl_cpt',
		};
		apiFetch.mockResolvedValueOnce( '<mjml />' );

		const result = await refreshEmailHtml( 123, 'A title', '<p>body</p>' );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-newsletters/v1/post-mjml',
			method: 'POST',
			data: { post_id: 123, title: 'A title', content: '<p>body</p>' },
		} );
		// Did NOT hit the server-render endpoint.
		expect( apiFetch ).not.toHaveBeenCalledWith( expect.objectContaining( { path: expect.stringContaining( '/post-html' ) } ) );
		expect( mjml2html ).toHaveBeenCalledWith( '<mjml />', { keepComments: false, minify: true } );
		expect( result ).toEqual( { result: 'success', html: '<mjml-html />' } );
	} );
} );
