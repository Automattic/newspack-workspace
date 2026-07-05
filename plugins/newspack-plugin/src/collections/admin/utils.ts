/**
 * Utility functions for Collections admin components.
 */

/**
 * Check if a string is a valid URL.
 *
 * @param {string} value The URL to validate.
 * @return {boolean} Whether the URL is valid.
 */
export const isValidUrl = value => {
	try {
		new URL( value );
		return true;
	} catch {
		return false;
	}
};
