/**
 * Ambient declarations for the `post-date-editor` entry. Global-script form
 * (no top-level imports/exports) so the Window merge applies globally.
 */

interface Window {
	/** Data localized by Post_Date (includes/class-post-date.php). */
	newspackPostDate?: {
		/** The updated-date display mode: 'hide' or 'show'. */
		mode: string;
		/** Post types the per-post toggle applies to. */
		postTypes: string[];
	};
}
