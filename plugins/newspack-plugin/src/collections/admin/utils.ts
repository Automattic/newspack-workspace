/**
 * Utility functions for Collections admin components.
 */

/**
 * Check if a string is a valid URL.
 *
 * @param value The URL to validate.
 * @return Whether the URL is valid.
 */
export const isValidUrl = ( value: string ): boolean => {
	try {
		new URL( value );
		return true;
	} catch {
		return false;
	}
};
