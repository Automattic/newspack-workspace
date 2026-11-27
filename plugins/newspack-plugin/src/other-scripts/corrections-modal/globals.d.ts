/**
 * Ambient declarations for the `corrections-modal` entry. Global-script form
 * (no top-level imports/exports) so the Window merge applies globally.
 */

/**
 * A correction as localized by Corrections (includes/corrections/class-corrections.php):
 * a serialized correction post with type/date/priority attached. Only the subset
 * used by this entry is declared.
 */
interface NewspackCorrection {
	ID: number;
	post_content: string;
	correction_type: string;
	/** Post datetime in `Y-m-d H:i:s` format. */
	correction_date: string;
	correction_priority: string;
}

interface Window {
	/** Data localized by Corrections (includes/corrections/class-corrections.php). */
	NewspackCorrectionsData: {
		corrections: NewspackCorrection[];
		/** REST route for saving corrections, e.g. `/newspack/v1/corrections`. */
		restPath: string;
		/** The site timezone string, e.g. `America/New_York`. */
		siteTimezone: string;
	};
}
