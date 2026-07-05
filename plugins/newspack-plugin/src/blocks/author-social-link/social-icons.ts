/**
 * WordPress dependencies
 */
import { safeHTML } from '@wordpress/dom';

/**
 * Get the SVG icon for a social service from REST API author data.
 *
 * @param {string}      service    Service key (e.g. 'facebook', 'email').
 * @param {Object|null} authorData Social data object with optional svg property.
 * @return {string|null} SVG markup string or null.
 */
export function getSocialIconSvg( service, authorData ) {
	if ( authorData?.svg ) {
		return safeHTML( authorData.svg );
	}
	return null;
}
