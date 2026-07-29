/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Resolve the configured ESP slug, independent of which bundle is running.
 *
 * The provider slug is localized onto two different PHP globals depending on
 * the screen: the block editor exposes `newspack_newsletters_data.service_provider`
 * (class-newspack-newsletters-editor.php), and the admin-shell list exposes
 * `newspackNewslettersAdmin.serviceProvider` (class-admin-shell-assets.php).
 * Reading both lets a single helper answer the question in either bundle.
 *
 * @return {string} The provider slug, or '' when no global is present.
 */
export const getServiceProviderSlug = () => {
	if ( typeof window === 'undefined' ) {
		return '';
	}
	return window.newspack_newsletters_data?.service_provider || window.newspackNewslettersAdmin?.serviceProvider || '';
};

/**
 * Whether the configured provider is "manual" – the publisher copies the
 * rendered HTML and sends it themselves rather than sending through an ESP, so
 * the UI uses WordPress' native publish/published wording instead of send/sent.
 *
 * @return {boolean} True when the manual provider is active.
 */
export const isManualProvider = () => 'manual' === getServiceProviderSlug();

/**
 * Newsletter-visibility option descriptions, worded for the active provider.
 *
 * Shared so the editor visibility toggle and the list's Quick Edit panel can't
 * drift. For the manual provider the "sent by email" framing is dropped, since
 * nothing is sent through an ESP.
 *
 * @return {{public: string, private: string}} Description per visibility value.
 */
export const getNewsletterVisibilityDescriptions = () => {
	const isManual = isManualProvider();
	return {
		public: isManual
			? __( 'Published as an article on your site.', 'newspack-newsletters' )
			: __( 'Sent by email and published as an article on your site.', 'newspack-newsletters' ),
		private: isManual
			? __( 'Not visible on your site.', 'newspack-newsletters' )
			: __( 'Sent by email only; not visible on your site.', 'newspack-newsletters' ),
	};
};
