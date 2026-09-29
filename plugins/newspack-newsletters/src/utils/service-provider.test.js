/**
 * Internal dependencies
 */
import { getServiceProviderSlug, isManualProvider, getNewsletterVisibilityDescriptions } from './service-provider';

// The slug is localized onto two different globals depending on the bundle: the
// block editor exposes `newspack_newsletters_data.service_provider`, the
// admin-shell list exposes `newspackNewslettersAdmin.serviceProvider`. A single
// helper has to resolve either, which is what these tests pin down.
describe( 'service-provider', () => {
	afterEach( () => {
		delete window.newspack_newsletters_data;
		delete window.newspackNewslettersAdmin;
	} );

	describe( 'getServiceProviderSlug', () => {
		it( 'reads the editor global', () => {
			window.newspack_newsletters_data = { service_provider: 'mailchimp' };
			expect( getServiceProviderSlug() ).toBe( 'mailchimp' );
		} );

		it( 'reads the admin-shell global', () => {
			window.newspackNewslettersAdmin = { serviceProvider: 'manual' };
			expect( getServiceProviderSlug() ).toBe( 'manual' );
		} );

		it( 'prefers the editor global when both are present', () => {
			window.newspack_newsletters_data = { service_provider: 'active_campaign' };
			window.newspackNewslettersAdmin = { serviceProvider: 'manual' };
			expect( getServiceProviderSlug() ).toBe( 'active_campaign' );
		} );

		it( 'falls back to an empty string when neither global is present', () => {
			expect( getServiceProviderSlug() ).toBe( '' );
		} );
	} );

	describe( 'isManualProvider', () => {
		it( 'is true only for the manual slug, from either global', () => {
			window.newspack_newsletters_data = { service_provider: 'manual' };
			expect( isManualProvider() ).toBe( true );
			delete window.newspack_newsletters_data;

			window.newspackNewslettersAdmin = { serviceProvider: 'manual' };
			expect( isManualProvider() ).toBe( true );
		} );

		it( 'is false for any other provider and when unset', () => {
			expect( isManualProvider() ).toBe( false );
			window.newspack_newsletters_data = { service_provider: 'mailchimp' };
			expect( isManualProvider() ).toBe( false );
		} );
	} );

	describe( 'getNewsletterVisibilityDescriptions', () => {
		it( 'drops the "sent by email" framing for the manual provider', () => {
			window.newspackNewslettersAdmin = { serviceProvider: 'manual' };
			const descriptions = getNewsletterVisibilityDescriptions();
			expect( descriptions.public ).toBe( 'Published as an article on your site.' );
			expect( descriptions.private ).toBe( 'Not visible on your site.' );
		} );

		it( 'keeps the email framing for a real ESP', () => {
			window.newspack_newsletters_data = { service_provider: 'mailchimp' };
			const descriptions = getNewsletterVisibilityDescriptions();
			expect( descriptions.public ).toBe( 'Sent by email and published as an article on your site.' );
			expect( descriptions.private ).toBe( 'Sent by email only; not visible on your site.' );
		} );
	} );
} );
