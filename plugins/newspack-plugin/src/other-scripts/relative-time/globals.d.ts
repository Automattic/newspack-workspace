/**
 * Ambient declarations for the `relative-time` entry. Global-script form
 * (no top-level imports/exports) so every declaration lands in the global scope.
 */

/**
 * Data localized by Post_Date (includes/class-post-date.php).
 */
interface NewspackRelativeTimeConfig {
	/**
	 * Cutoff in seconds. wp_localize_script() casts top-level scalars to
	 * strings, so this arrives as a numeric string in production; tests
	 * assign a number.
	 */
	cutoff: number | string;
	/** The site locale, e.g. `en_US`. */
	locale: string;
}

interface Window {
	newspackRelativeTime?: NewspackRelativeTimeConfig;
}
