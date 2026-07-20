/**
 * WordPress dependencies
 */
import { safeHTML } from '@wordpress/dom';

/**
 * Get the SVG icon for a social service from REST API author data.
 *
 * @param service    Service key (e.g. 'facebook', 'email').
 * @param authorData Social data object with optional svg property.
 * @return SVG markup string or null.
 */
export function getSocialIconSvg( service: string | undefined, authorData: NewspackAuthorSocialData | null ): string | null {
	if ( authorData?.svg ) {
		return safeHTML( authorData.svg );
	}
	return null;
}
