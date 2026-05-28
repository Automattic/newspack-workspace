/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Human-readable label for a service key (for block title, aria-label, etc.).
 * Only Email and Phone are translatable; company names are left as-is.
 *
 * @param {string} service Service key (e.g. 'facebook', 'email').
 * @return {string} Display label.
 */
export function getServiceLabel( service ) {
	if ( ! service ) {
		return '';
	}
	const labels = {
		facebook: 'Facebook',
		twitter: 'X',
		instagram: 'Instagram',
		linkedin: 'LinkedIn',
		youtube: 'YouTube',
		bluesky: 'Bluesky',
		pinterest: 'Pinterest',
		myspace: 'Myspace',
		soundcloud: 'SoundCloud',
		tumblr: 'Tumblr',
		wikipedia: 'Wikipedia',
		email: __( 'Email', 'newspack-plugin' ),
		phone: __( 'Phone', 'newspack-plugin' ),
	};
	return labels[ service ] || service;
}

/**
 * Get the URL for a service from author data.
 *
 * @param {Object} author  Author data object.
 * @param {string} service Service key.
 * @return {string|null} URL or null.
 */
export function getServiceUrl( author, service ) {
	if ( ! author || ! service ) {
		return null;
	}

	if ( service === 'email' ) {
		const email = author.email;
		if ( ! email ) {
			return null;
		}
		if ( typeof email === 'object' ) {
			return email.url || null;
		}
		return `mailto:${ email }`;
	}

	if ( service === 'phone' ) {
		const phone = author.newspack_phone_number;
		if ( ! phone ) {
			return null;
		}
		if ( typeof phone === 'object' ) {
			return phone.url || null;
		}
		return `tel:${ phone }`;
	}

	// Social services.
	const socialData = author.social?.[ service ];
	if ( ! socialData?.url ) {
		return null;
	}
	return socialData.url;
}

/**
 * Get the author data object for a service (for SVG lookup).
 *
 * @param {Object} author  Author data object.
 * @param {string} service Service key.
 * @return {Object|null} Service data with optional svg property.
 */
export function getServiceData( author, service ) {
	if ( ! author || ! service ) {
		return null;
	}

	if ( service === 'email' ) {
		const email = author.email;
		return typeof email === 'object' ? email : null;
	}

	if ( service === 'phone' ) {
		const phone = author.newspack_phone_number;
		return typeof phone === 'object' ? phone : null;
	}

	return author.social?.[ service ] || null;
}
