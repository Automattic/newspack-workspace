/**
 * Settings Wizard: Connections > Webhooks > Utils
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { settings, check, close, reusableBlock } from '@wordpress/icons';

/**
 * Returns a shortened version of the URL for display purposes.
 *
 * @param url The URL to be shortened.
 * @return    The shortened URL.
 */
export function getDisplayUrl( url: string ): string {
	let displayUrl = url.slice( 8 );
	if ( url.length > 45 ) {
		displayUrl = `${ url.slice( 8, 38 ) }...${ url.slice( -10 ) }`;
	}
	return displayUrl;
}

/**
 * Returns the label or a shortened version of the URL for an endpoint.
 *
 * @param endpoint The endpoint object.
 * @return         The label or shortened URL.
 */
export function getEndpointLabel( endpoint: Endpoint ): string {
	const { label, url } = endpoint;
	return label || getDisplayUrl( url );
}

/**
 * Returns the title JSX for an endpoint.
 *
 * @param endpoint The endpoint object.
 * @return         The JSX for the endpoint title.
 */
export function getEndpointTitle( endpoint: Endpoint ): JSX.Element {
	const { label, url } = endpoint;
	return (
		<Fragment>
			{ label && <span className="newspack-webhooks__endpoint__label">{ label }: </span> }
			<span className="newspack-webhooks__endpoint__url">{ getDisplayUrl( url ) }</span>
		</Fragment>
	);
}

/**
 * Returns the icon for the request status.
 *
 * @param status The status of the request.
 * @return       The icon component for the request status.
 */
export function getRequestStatusIcon( status: 'pending' | 'finished' | 'killed' ) {
	const icons = {
		pending: reusableBlock,
		finished: check,
		killed: close,
	};
	return icons[ status ] || settings;
}

/**
 * Checks if an endpoint has any errors in its requests.
 *
 * @param endpoint The endpoint object.
 * @return         True if there are errors, false otherwise.
 */
export function hasEndpointErrors( endpoint: Endpoint ): boolean {
	return endpoint.requests.some( request => request.errors.length );
}

/**
 * Validate endpoint URL.
 *
 * @param url The URL to validate.
 * @return    Error message if URL is invalid, false otherwise.
 */
export function validateUrl( url: string ): string | false {
	if ( ! url ) {
		return __( 'URL is required.', 'newspack-plugin' );
	}
	try {
		const urlObject = new URL( url );
		if ( urlObject.protocol !== 'https:' ) {
			return __( 'HTTPS protocol is required for the endpoint URL.', 'newspack-plugin' );
		}
		return false;
	} catch ( error ) {
		return __( 'Invalid URL format.', 'newspack-plugin' );
	}
}

/**
 * Validate an endpoint.
 *
 * @param endpoint The endpoint to validate.
 * @return         An array of error messages.
 */
export function validateEndpoint( endpoint: Endpoint ): string[] {
	const errors = [];
	const urlError = validateUrl( endpoint.url );
	if ( urlError ) {
		errors.push( urlError );
	}
	if ( ! endpoint.actions || ! endpoint.actions.length ) {
		errors.push( __( 'At least one action is required.', 'newspack-plugin' ) );
	}
	return errors;
}
