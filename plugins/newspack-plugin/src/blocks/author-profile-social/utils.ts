/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Options for the icon size SelectControl (value is pixels; 23 for Normal so
 * it's distinct from Default 24). Values are strings, as required by
 * SelectControl; the block attribute stores the numeric equivalent.
 *
 * @return Options for the SelectControl.
 */
export function getIconSizeOptions(): Array< { label: string; value: string } > {
	return [
		{ label: __( 'Default', 'newspack-plugin' ), value: '24' },
		{ label: __( 'Small', 'newspack-plugin' ), value: '16' },
		{ label: __( 'Normal', 'newspack-plugin' ), value: '23' },
		{ label: __( 'Large', 'newspack-plugin' ), value: '36' },
		{ label: __( 'Huge', 'newspack-plugin' ), value: '48' },
	];
}

/**
 * Round to nearest 2px for display (e.g. 17→18, 23→24).
 *
 * @param value Stored icon size.
 * @return Rounded pixel value.
 */
export function roundIconSize( value: number | undefined ): number {
	return Math.round( ( value ?? 24 ) / 2 ) * 2;
}

/**
 * Get the list of available services from author data.
 *
 * @param author Author data.
 * @return Array of service key strings.
 */
export function getAvailableServices( author: NewspackAuthorProfileData | null | undefined ): string[] {
	const services: string[] = [];

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
 * @param services List of service keys.
 * @return Block template array.
 */
export function buildTemplate( services: string[] ): Array< [ string, { service: string } ] > {
	return services.map( service => [ 'newspack/author-social-link', { service } ] );
}
