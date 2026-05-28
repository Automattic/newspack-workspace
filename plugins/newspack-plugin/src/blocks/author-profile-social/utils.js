/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Options for the icon size SelectControl (value is pixel number; 23 for Normal so it's distinct from Default 24).
 *
 * @return {Array<{label: string, value: number}>} Options for the SelectControl.
 */
export function getIconSizeOptions() {
	return [
		{ label: __( 'Default', 'newspack-plugin' ), value: 24 },
		{ label: __( 'Small', 'newspack-plugin' ), value: 16 },
		{ label: __( 'Normal', 'newspack-plugin' ), value: 23 },
		{ label: __( 'Large', 'newspack-plugin' ), value: 36 },
		{ label: __( 'Huge', 'newspack-plugin' ), value: 48 },
	];
}

/**
 * Round to nearest 2px for display (e.g. 17→18, 23→24).
 *
 * @param {number} value Stored icon size.
 * @return {number} Rounded pixel value.
 */
export function roundIconSize( value ) {
	return Math.round( ( value ?? 24 ) / 2 ) * 2;
}

/**
 * Get the list of available services from author data.
 *
 * @param {Object} author Author data.
 * @return {Array} Array of service key strings.
 */
export function getAvailableServices( author ) {
	const services = [];

	if ( author?.social ) {
		Object.entries( author.social ).forEach( ( [ service, data ] ) => {
			if ( data?.url ) {
				services.push( service );
			}
		} );
	}

	if ( author?.email ) {
		services.push( 'email' );
	}

	if ( author?.newspack_phone_number ) {
		services.push( 'phone' );
	}

	return services;
}

/**
 * Build InnerBlocks template from available services.
 *
 * @param {Array} services List of service keys.
 * @return {Array} Block template array.
 */
export function buildTemplate( services ) {
	return services.map( service => [ 'newspack/author-social-link', { service } ] );
}
